<?php
/**
 * The routes, and the pure validation logic behind them.
 *
 * The decision helpers at the bottom of this class touch nothing outside their
 * arguments, which is what lets tests/test-logic.php run them under plain PHP
 * with no WordPress and no database.
 *
 * @package TBT_Homework
 */

defined( 'ABSPATH' ) || exit;

/**
 * The student's own homework, and the teacher's queue over it.
 */
class TBT_Homework_REST {

	/**
	 * REST namespace. ("NAMESPACE" is a PHP keyword, hence the prefix.)
	 */
	const REST_NAMESPACE = 'tbt-homework/v1';

	/**
	 * Maximum length of a submission, in characters.
	 */
	const BODY_MAX = 20000;

	/**
	 * Maximum length of a teacher's comment, in characters.
	 *
	 * The same cap as a submission, written separately because it answers a
	 * different question and may one day want a different answer.
	 */
	const COMMENT_MAX = 20000;

	/**
	 * Register the routes.
	 */
	public static function init(): void {
		add_action( 'rest_api_init', array( __CLASS__, 'register_routes' ) );
	}

	/**
	 * Two routes for the student, two for the teacher.
	 *
	 * permission_callback is is_user_logged_in and nothing more. Being logged
	 * in says nothing about which class you are in, nor which classes you
	 * manage, so authorisation happens inside the callbacks, in the order the
	 * spec sets out.
	 *
	 * No 'args' schema is declared on purpose: WordPress would reject a bad
	 * lesson_id with its own 400 before the callback ran, and a missing TBT
	 * Notes has to answer 503 first.
	 */
	public static function register_routes(): void {
		register_rest_route(
			self::REST_NAMESPACE,
			'/submission',
			array(
				array(
					'methods'             => WP_REST_Server::READABLE,
					'callback'            => array( __CLASS__, 'handle_get' ),
					'permission_callback' => 'is_user_logged_in',
				),
				array(
					'methods'             => WP_REST_Server::CREATABLE,
					'callback'            => array( __CLASS__, 'handle_post' ),
					'permission_callback' => 'is_user_logged_in',
				),
			)
		);

		// The teacher's two. Same permission_callback, same reasoning: being
		// logged in says nothing about which classes you manage, so the scope
		// is resolved inside the callbacks and nothing else is trusted.
		register_rest_route(
			self::REST_NAMESPACE,
			'/queue',
			array(
				array(
					'methods'             => WP_REST_Server::READABLE,
					'callback'            => array( __CLASS__, 'handle_queue' ),
					'permission_callback' => 'is_user_logged_in',
				),
			)
		);

		register_rest_route(
			self::REST_NAMESPACE,
			'/comment',
			array(
				array(
					'methods'             => WP_REST_Server::CREATABLE,
					'callback'            => array( __CLASS__, 'handle_comment' ),
					'permission_callback' => 'is_user_logged_in',
				),
			)
		);
	}

	/**
	 * GET /submission?lesson_id=412
	 *
	 * Steps 1-4 of the validation order, then the caller's own row or null.
	 *
	 * @param WP_REST_Request $request The request.
	 *
	 * @return WP_REST_Response|WP_Error
	 */
	public static function handle_get( $request ) {
		$context = self::resolve_context( $request->get_param( 'lesson_id' ), 'read' );
		if ( is_wp_error( $context ) ) {
			return $context;
		}

		$row = TBT_Homework_DB::get_submission( get_current_user_id(), (int) $context['lesson_id'] );

		return rest_ensure_response( self::present( $row ) );
	}

	/**
	 * POST /submission { lesson_id, body }
	 *
	 * class_id is not a parameter. If the browser sends one it is ignored: the
	 * only trustworthy source is the lesson context.
	 *
	 * @param WP_REST_Request $request The request.
	 *
	 * @return WP_REST_Response|WP_Error
	 */
	public static function handle_post( $request ) {
		// Steps 1 to 5.
		$context = self::resolve_context( $request->get_param( 'lesson_id' ), 'write' );
		if ( is_wp_error( $context ) ) {
			return $context;
		}

		// Step 6: plain text in, plain text stored.
		$body   = trim( sanitize_textarea_field( (string) $request->get_param( 'body' ) ) );
		$verdict = self::check_body( $body );

		if ( 'empty' === $verdict ) {
			return new WP_Error(
				'tbt_homework_empty_body',
				__( 'Write something before you send it.', 'tbt-homework' ),
				array( 'status' => 400 )
			);
		}

		if ( 'too_long' === $verdict ) {
			return new WP_Error(
				'tbt_homework_body_too_long',
				sprintf(
					/* translators: %s: maximum number of characters. */
					__( 'Homework can be at most %s characters long.', 'tbt-homework' ),
					number_format_i18n( self::BODY_MAX )
				),
				array( 'status' => 400 )
			);
		}

		$user_id  = get_current_user_id();
		$lesson_id = (int) $context['lesson_id'];

		// Step 7: once the teacher has commented, the note is closed.
		$existing = TBT_Homework_DB::get_submission( $user_id, $lesson_id );
		if ( self::is_closed( $existing ) ) {
			return new WP_Error(
				'tbt_homework_closed',
				__( 'Your teacher has already commented on this homework, so it is closed for changes.', 'tbt-homework' ),
				array( 'status' => 409 )
			);
		}

		// Step 8: upsert, with class_id from the context.
		$row = TBT_Homework_DB::upsert_submission( $user_id, (int) $context['class_id'], $lesson_id, $body );

		if ( null === $row ) {
			return new WP_Error(
				'tbt_homework_not_saved',
				__( 'Your homework could not be saved. Please try again.', 'tbt-homework' ),
				array( 'status' => 500 )
			);
		}

		return rest_ensure_response( self::present( $row ) );
	}

	/**
	 * GET /queue?status=waiting&search=ania&page=1
	 *
	 * Everything the teacher's students have sent, waiting ones first. The
	 * page is rendered server-side by the shortcode, so nothing in the plugin
	 * calls this route; it exists because the queue is a real resource, and
	 * because it has to answer 403 to a student who tries it by hand.
	 *
	 * @param WP_REST_Request $request The request.
	 *
	 * @return WP_REST_Response|WP_Error
	 */
	public static function handle_queue( $request ) {
		$class_ids = self::manager_scope();
		if ( is_wp_error( $class_ids ) ) {
			return $class_ids;
		}

		$page = TBT_Homework_Teacher::page( $class_ids, array(
			'filter' => TBT_Homework_Teacher::normalise_filter( $request->get_param( 'status' ) ),
			'search' => TBT_Homework_Teacher::normalise_search( $request->get_param( 'search' ) ),
			'page'   => TBT_Homework_Teacher::normalise_page( $request->get_param( 'page' ) ),
		) );

		$entries = array();
		foreach ( $page['entries'] as $entry ) {
			$entries[] = self::present_entry( $entry );
		}

		return rest_ensure_response(
			array(
				'entries'  => $entries,
				'waiting'  => $page['waiting'],
				'total'    => $page['total'],
				'matched'  => $page['matched'],
				'page'     => $page['page'],
				'pages'    => $page['pages'],
				'per_page' => TBT_Homework_Teacher::PER_PAGE,
			)
		);
	}

	/**
	 * POST /comment { id, comment }
	 *
	 * Validation, first failure wins, in the order the spec sets out. Step 3
	 * is the one that matters: the row's own class_id is tested against the
	 * classes this caller manages, so one teacher can never reach another's
	 * students. It is done against the row in the database, never against
	 * anything the browser sent.
	 *
	 * @param WP_REST_Request $request The request.
	 *
	 * @return WP_REST_Response|WP_Error
	 */
	public static function handle_comment( $request ) {
		// 1. TBT Notes missing — checked here, at the point of use.
		if ( ! function_exists( 'tbt_notes_class_ids_for_manager' ) ) {
			return self::unavailable();
		}

		// 2. A positive integer, and a row that exists.
		$raw = $request->get_param( 'id' );
		$row = self::is_valid_id( $raw ) ? TBT_Homework_DB::get_row( (int) $raw ) : null;

		if ( null === $row ) {
			return new WP_Error(
				'tbt_homework_no_submission',
				__( 'That homework does not exist.', 'tbt-homework' ),
				array( 'status' => 404 )
			);
		}

		// 3. The scope check, against the stored row.
		$class_ids = TBT_Homework_DB::positive_ids( (array) tbt_notes_class_ids_for_manager() );

		if ( ! TBT_Homework_Teacher::in_scope( (int) $row['class_id'], $class_ids ) ) {
			return new WP_Error(
				'tbt_homework_not_your_class',
				__( 'That homework is not from one of your classes.', 'tbt-homework' ),
				array( 'status' => 403 )
			);
		}

		// 4 and 5. Plain text in, and an empty save clears the comment.
		$comment = trim( sanitize_textarea_field( (string) $request->get_param( 'comment' ) ) );
		$verdict = self::check_comment( $comment );

		if ( 'too_long' === $verdict ) {
			return new WP_Error(
				'tbt_homework_comment_too_long',
				sprintf(
					/* translators: %s: maximum number of characters. */
					__( 'A comment can be at most %s characters long.', 'tbt-homework' ),
					number_format_i18n( self::COMMENT_MAX )
				),
				array( 'status' => 400 )
			);
		}

		// 6. Write. Clearing sets both the comment and its date to NULL, which
		// returns the row to waiting and lets the student edit again.
		$saved = TBT_Homework_DB::save_comment( (int) $row['id'], 'clear' === $verdict ? null : $comment );

		if ( null === $saved ) {
			return new WP_Error(
				'tbt_homework_comment_not_saved',
				__( 'Your comment could not be saved. Please try again.', 'tbt-homework' ),
				array( 'status' => 500 )
			);
		}

		return rest_ensure_response( self::present_saved_comment( $saved ) );
	}

	/**
	 * The classes this caller manages, or the error that stops them.
	 *
	 * tbt_notes_class_ids_for_manager() returns the classes you teach, every
	 * class for an administrator, and an empty array for everyone else — so an
	 * empty array is the whole of "not a teacher", and no role or capability
	 * is re-derived here.
	 *
	 * @return array|WP_Error
	 */
	private static function manager_scope() {
		if ( ! function_exists( 'tbt_notes_class_ids_for_manager' ) || ! function_exists( 'tbt_notes_lessons_brief' ) ) {
			return self::unavailable();
		}

		$class_ids = TBT_Homework_DB::positive_ids( (array) tbt_notes_class_ids_for_manager() );

		if ( ! $class_ids ) {
			return new WP_Error(
				'tbt_homework_not_a_teacher',
				__( 'This is for teachers. There is nothing here to check.', 'tbt-homework' ),
				array( 'status' => 403 )
			);
		}

		return $class_ids;
	}

	/**
	 * The 503 every route answers when TBT Notes is not active.
	 */
	private static function unavailable(): WP_Error {
		return new WP_Error(
			'tbt_homework_unavailable',
			__( 'Homework is unavailable right now because TBT Notes is not active. Nothing already submitted has been lost.', 'tbt-homework' ),
			array( 'status' => 503 )
		);
	}

	/**
	 * One queue entry, shaped for the wire.
	 *
	 * Plain text out, escaped exactly as the submission body is: esc_html()
	 * plus nl2br(), so pasted markup is text on this route too.
	 *
	 * @param array $entry A shaped entry from the teacher's page.
	 */
	private static function present_entry( array $entry ): array {
		$comment = $entry['comment'];

		return array(
			'id'                   => (int) $entry['id'],
			'student'              => (string) $entry['student'],
			'lesson_title'         => (string) $entry['lesson_title'],
			'class_title'          => (string) $entry['class_title'],
			'body'                 => (string) $entry['body'],
			'body_html'            => nl2br( esc_html( (string) $entry['body'] ) ),
			'status'               => (string) $entry['status'],
			'submitted_at'         => (string) $entry['submitted_at'],
			'submitted_at_display' => self::display_date( (string) $entry['submitted_at'] ),
			'comment'              => $comment,
			'comment_html'         => null === $comment ? null : nl2br( esc_html( $comment ) ),
			'commented_at'         => $entry['commented_at'],
			'commented_at_display' => null === $entry['commented_at'] ? null : self::display_date( (string) $entry['commented_at'] ),
		);
	}

	/**
	 * What the card needs after a comment is saved or cleared.
	 *
	 * The status is what the script adjusts the waiting count from, so it is
	 * the one field the card cannot work out for itself.
	 *
	 * @param array $row The stored row.
	 */
	private static function present_saved_comment( array $row ): array {
		$comment = isset( $row['comment'] ) && null !== $row['comment'] ? (string) $row['comment'] : null;

		return array(
			'id'                   => (int) $row['id'],
			'status'               => null === $comment ? 'waiting' : 'commented',
			'comment'              => $comment,
			'comment_html'         => null === $comment ? null : nl2br( esc_html( $comment ) ),
			'commented_at'         => empty( $row['commented_at'] ) ? null : (string) $row['commented_at'],
			'commented_at_display' => empty( $row['commented_at'] ) ? null : self::display_date( (string) $row['commented_at'] ),
			'updated_at'           => (string) $row['updated_at'],
		);
	}

	/**
	 * Steps 1 to 5 of the validation order, first failure wins.
	 *
	 * @param mixed  $raw_lesson_id The lesson_id as it arrived.
	 * @param string $intent        'read' or 'write'.
	 *
	 * @return array|WP_Error The lesson context on success.
	 */
	private static function resolve_context( $raw_lesson_id, string $intent ) {
		// 1. TBT Notes missing — checked here, at the point of use.
		if ( ! function_exists( 'tbt_notes_lesson_context' ) ) {
			return self::unavailable();
		}

		// 2. lesson_id must be a positive integer.
		if ( ! self::is_valid_lesson_id( $raw_lesson_id ) ) {
			return new WP_Error(
				'tbt_homework_invalid_lesson',
				__( 'A valid lesson id is required.', 'tbt-homework' ),
				array( 'status' => 400 )
			);
		}

		$lesson_id = (int) $raw_lesson_id;
		$context   = tbt_notes_lesson_context( $lesson_id );

		// 3, 4 and 5. Reading the row is not permission; the flags are.
		switch ( self::gate( $context, $intent ) ) {
			case 'not_found':
				return new WP_Error(
					'tbt_homework_no_lesson',
					__( 'That lesson does not exist.', 'tbt-homework' ),
					array( 'status' => 404 )
				);

			case 'forbidden':
				return new WP_Error(
					'tbt_homework_forbidden',
					__( 'You do not have access to this lesson.', 'tbt-homework' ),
					array( 'status' => 403 )
				);

			case 'not_for_teachers':
				return new WP_Error(
					'tbt_homework_not_for_teachers',
					__( 'Homework is submitted by students. You manage this class, so there is nothing to hand in.', 'tbt-homework' ),
					array( 'status' => 403 )
				);
		}

		return array(
			'lesson_id' => $lesson_id,
			'class_id'  => (int) ( $context['class_id'] ?? 0 ),
		);
	}

	/**
	 * Shape a row for the wire, or pass null straight through.
	 *
	 * The body is plain text. It goes out raw for a textarea, and pre-escaped
	 * for display — esc_html() plus nl2br(), the same pairing the script
	 * applies on its own side.
	 *
	 * attachment is reserved for a later release: nothing reads it, so it is
	 * not exposed here.
	 *
	 * @param array|null $row A row from the table.
	 */
	private static function present( ?array $row ): ?array {
		if ( null === $row ) {
			return null;
		}

		$body    = (string) $row['body'];
		$comment = isset( $row['comment'] ) && null !== $row['comment'] ? (string) $row['comment'] : null;

		return array(
			'lesson_id'             => (int) $row['lesson_id'],
			'class_id'              => (int) $row['class_id'],
			'body'                  => $body,
			'body_html'             => nl2br( esc_html( $body ) ),
			'submitted_at'          => (string) $row['submitted_at'],
			'submitted_at_display'  => self::display_date( (string) $row['submitted_at'] ),
			'updated_at'            => (string) $row['updated_at'],
			'updated_at_display'    => self::display_date( (string) $row['updated_at'] ),
			'comment'               => $comment,
			'comment_html'          => null === $comment ? null : nl2br( esc_html( $comment ) ),
			'commented_at'          => empty( $row['commented_at'] ) ? null : (string) $row['commented_at'],
			'commented_at_display'  => empty( $row['commented_at'] ) ? null : self::display_date( (string) $row['commented_at'] ),
			'closed'                => null !== $comment,
		);
	}

	/**
	 * A stored UTC datetime, rendered in the site's timezone.
	 *
	 * Stored in UTC, displayed with wp_date(), so homework sent late on a
	 * Sunday in Warsaw does not show as Monday. The year is dropped when it is
	 * the current one.
	 *
	 * Public because it is the one place a stored datetime becomes a display
	 * string: the library page formats its dates through here too, so the two
	 * screens can never drift apart.
	 *
	 * @param string $mysql_utc A 'Y-m-d H:i:s' string in UTC.
	 */
	public static function display_date( string $mysql_utc ): string {
		$timestamp = strtotime( $mysql_utc . ' +0000' );
		if ( ! $timestamp ) {
			return '';
		}

		$format = wp_date( 'Y', $timestamp ) === wp_date( 'Y' ) ? 'j F' : 'j F Y';

		return (string) wp_date( $format, $timestamp );
	}

	/*
	 * ---------------------------------------------------------------------
	 * Pure logic below this line. No WordPress, no database, no globals —
	 * so tests/test-logic.php can run it directly.
	 * ---------------------------------------------------------------------
	 */

	/**
	 * Is this a usable lesson id?
	 *
	 * @param mixed $raw The value as it arrived.
	 */
	public static function is_valid_lesson_id( $raw ): bool {
		return self::is_valid_id( $raw );
	}

	/**
	 * Is this a usable row id?
	 *
	 * A positive integer, or a string of digits that is one. Anything else —
	 * zero, negative, a float, "12abc", an array, null — is not. Lesson ids
	 * and submission ids answer to the same rule, and there is one copy of it.
	 *
	 * @param mixed $raw The value as it arrived.
	 */
	public static function is_valid_id( $raw ): bool {
		if ( is_bool( $raw ) || is_array( $raw ) || is_object( $raw ) || null === $raw ) {
			return false;
		}

		if ( is_int( $raw ) ) {
			return $raw > 0;
		}

		if ( is_float( $raw ) ) {
			return $raw > 0 && floor( $raw ) === $raw;
		}

		if ( is_string( $raw ) ) {
			$trimmed = trim( $raw );

			return '' !== $trimmed && (bool) preg_match( '/^[0-9]+$/', $trimmed ) && (int) $trimmed > 0;
		}

		return false;
	}

	/**
	 * The length cap and the empty-after-trim rule.
	 *
	 * Takes text that has already been through sanitize_textarea_field().
	 *
	 * @param string $body Sanitised text.
	 *
	 * @return string 'ok', 'empty' or 'too_long'.
	 */
	public static function check_body( string $body ): string {
		$body = trim( $body );

		if ( '' === $body ) {
			return 'empty';
		}

		if ( self::length( $body ) > self::BODY_MAX ) {
			return 'too_long';
		}

		return 'ok';
	}

	/**
	 * The comment's own rule: empty means clear it.
	 *
	 * Takes text that has already been through sanitize_textarea_field(). An
	 * empty save is not an error — it is how a comment saved on the wrong card
	 * is undone, which is the only way back from a one-way door for the
	 * student.
	 *
	 * @param string $comment Sanitised text.
	 *
	 * @return string 'ok', 'clear' or 'too_long'.
	 */
	public static function check_comment( string $comment ): string {
		$comment = trim( $comment );

		if ( '' === $comment ) {
			return 'clear';
		}

		if ( self::length( $comment ) > self::COMMENT_MAX ) {
			return 'too_long';
		}

		return 'ok';
	}

	/**
	 * Length in characters, not bytes.
	 *
	 * @param string $text Any text.
	 */
	public static function length( string $text ): int {
		return function_exists( 'mb_strlen' ) ? mb_strlen( $text, 'UTF-8' ) : strlen( $text );
	}

	/**
	 * What a lesson context's flags permit.
	 *
	 * The whole security model on this side: a row comes back for a lesson the
	 * caller may not touch, with both flags false. Reading the row is not
	 * permission — these flags are.
	 *
	 * @param array|null $context The result of tbt_notes_lesson_context().
	 * @param string     $intent  'read' or 'write'.
	 *
	 * @return string 'ok', 'not_found', 'forbidden' or 'not_for_teachers'.
	 */
	public static function gate( ?array $context, string $intent ): string {
		if ( null === $context ) {
			return 'not_found';
		}

		// Covers a student from another class, and a lesson whose class has
		// since been deleted.
		if ( empty( $context['can_view'] ) ) {
			return 'forbidden';
		}

		// You do not submit homework to yourself, and an administrator cannot
		// create a row by accident. The test is that the caller *cannot
		// manage* the class, not merely that they can see it.
		if ( 'write' === $intent && ! empty( $context['can_manage'] ) ) {
			return 'not_for_teachers';
		}

		return 'ok';
	}

	/**
	 * Has the teacher commented? Then the row is closed for changes.
	 *
	 * @param array|null $row A row from the table, or null when there is none.
	 */
	public static function is_closed( ?array $row ): bool {
		return is_array( $row ) && array_key_exists( 'comment', $row ) && null !== $row['comment'];
	}
}
