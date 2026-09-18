<?php
/**
 * The two routes, and the pure validation logic behind them.
 *
 * The decision helpers at the bottom of this class touch nothing outside their
 * arguments, which is what lets tests/test-logic.php run them under plain PHP
 * with no WordPress and no database.
 *
 * @package TBT_Homework
 */

defined( 'ABSPATH' ) || exit;

/**
 * GET and POST for the caller's own homework.
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
	 * Register the routes.
	 */
	public static function init(): void {
		add_action( 'rest_api_init', array( __CLASS__, 'register_routes' ) );
	}

	/**
	 * Two routes on one path, both about the caller's own work.
	 *
	 * permission_callback is is_user_logged_in and nothing more. Being logged
	 * in says nothing about which class you are in, so authorisation happens
	 * per lesson inside the callbacks, in the order the spec sets out.
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
			return new WP_Error(
				'tbt_homework_unavailable',
				__( 'Homework is unavailable right now because TBT Notes is not active. Nothing already submitted has been lost.', 'tbt-homework' ),
				array( 'status' => 503 )
			);
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
	 * @param string $mysql_utc A 'Y-m-d H:i:s' string in UTC.
	 */
	private static function display_date( string $mysql_utc ): string {
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
	 * A positive integer, or a string of digits that is one. Anything else —
	 * zero, negative, a float, "12abc", an array, null — is not.
	 *
	 * @param mixed $raw The value as it arrived.
	 */
	public static function is_valid_lesson_id( $raw ): bool {
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
