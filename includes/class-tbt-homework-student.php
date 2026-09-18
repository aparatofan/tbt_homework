<?php
/**
 * [tbt_homework_student] — the student's own library.
 *
 * The page is rendered server-side and read whole: a student writes homework
 * under a lesson note, never from here, so there is nothing on this page to
 * submit and nothing to fetch after load. The script that ships with it only
 * filters what is already on the page.
 *
 * The decision helpers at the bottom of this class touch nothing outside their
 * arguments, so tests/test-logic.php runs them under plain PHP.
 *
 * @package TBT_Homework
 */

defined( 'ABSPATH' ) || exit;

/**
 * The student library: the Admin Bar, then the student's own work, listed.
 */
class TBT_Homework_Student {

	/**
	 * The shortcode tag.
	 */
	const SHORTCODE = 'tbt_homework_student';

	/**
	 * The app's own id, which the Divi pin in the stylesheet is anchored on.
	 *
	 * One per page: the shortcode is not meant to appear twice.
	 */
	const APP_ID = 'tbth-homework-student';

	/**
	 * tbt_notes_lessons_brief() is capped at 200 ids per call, so ids are
	 * asked for in batches of that size.
	 */
	const BRIEF_MAX = 200;

	/**
	 * Register the shortcode.
	 */
	public static function init(): void {
		add_shortcode( self::SHORTCODE, array( __CLASS__, 'render' ) );
	}

	/**
	 * Render the library.
	 *
	 * @param array|string $atts    Shortcode attributes; none are read.
	 * @param string|null  $content Enclosed content; there is none.
	 */
	public static function render( $atts = array(), $content = null ): string {
		// Nothing here is public, at any point.
		if ( ! is_user_logged_in() ) {
			return self::notice( __( 'Sign in to see your homework.', 'tbt-homework' ) );
		}

		// The dependency guard, at the point of use. Without TBT Notes there
		// is no way to name a lesson or to know which ones this student may
		// still see, so the page says so rather than guessing. Nothing is
		// lost: the rows are in the table and come back when Notes does.
		if ( ! function_exists( 'tbt_notes_lessons_brief' ) ) {
			return self::notice(
				__( 'Your homework is temporarily unavailable. Nothing has been lost — please try again shortly.', 'tbt-homework' )
			);
		}

		self::enqueue();

		return self::markup( self::entries( get_current_user_id() ) );
	}

	/**
	 * The student's own submissions, named and filtered by the lesson brief.
	 *
	 * A row whose lesson the student may no longer view — a deleted class,
	 * most often — is simply not in the brief, and drops out of the list here.
	 * No orphan lookup, no error, exactly as the row's own release intended.
	 *
	 * @param int $user_id The student.
	 */
	private static function entries( int $user_id ): array {
		$rows = TBT_Homework_DB::get_submissions_for_student( $user_id );

		if ( ! $rows ) {
			return array();
		}

		$brief = array();
		foreach ( self::chunk_lesson_ids( wp_list_pluck( $rows, 'lesson_id' ), self::BRIEF_MAX ) as $chunk ) {
			$batch = tbt_notes_lessons_brief( $chunk );
			if ( is_array( $batch ) ) {
				// Union by lesson id; the chunks never overlap.
				$brief += $batch;
			}
		}

		return self::attach_brief( $rows, $brief );
	}

	/**
	 * Enqueue the page's assets.
	 *
	 * The shared sheet carries the card, the body type and the comment block,
	 * all of which this page reuses; the library sheet carries the Admin Bar,
	 * which the teacher's page reads from the same file rather than from a
	 * second copy of it; the student sheet adds the list. None of them hangs
	 * on the TBT Notes asset hook, because this page is not a lesson page and
	 * Notes may not be on it at all.
	 */
	private static function enqueue(): void {
		wp_enqueue_style(
			'tbt-homework',
			TBT_HOMEWORK_URL . 'assets/css/tbt-homework.css',
			array( 'tbt-components' ),
			TBT_HOMEWORK_VERSION
		);

		wp_enqueue_style(
			'tbt-homework-library',
			TBT_HOMEWORK_URL . 'assets/css/tbt-homework-library.css',
			array( 'tbt-components', 'tbt-homework' ),
			TBT_HOMEWORK_VERSION
		);

		wp_enqueue_style(
			'tbt-homework-student',
			TBT_HOMEWORK_URL . 'assets/css/tbt-homework-student.css',
			array( 'tbt-components', 'tbt-homework', 'tbt-homework-library' ),
			TBT_HOMEWORK_VERSION
		);

		wp_enqueue_script(
			'tbt-homework-student',
			TBT_HOMEWORK_URL . 'assets/js/tbt-homework-student.js',
			array(),
			TBT_HOMEWORK_VERSION,
			true
		);
	}

	/*
	 * ---------------------------------------------------------------------
	 * Markup. The hero belongs to the page template, so this starts at the
	 * bar and nowhere higher.
	 * ---------------------------------------------------------------------
	 */

	/**
	 * The whole library.
	 *
	 * @param array $entries Shaped entries, newest first.
	 */
	private static function markup( array $entries ): string {
		$html  = '<div id="' . esc_attr( self::APP_ID ) . '" class="tbth-student">';
		$html .= self::bar( count( $entries ) > 0 );
		$html .= $entries ? self::list_markup( $entries ) : self::empty_state();
		$html .= '</div>';

		return $html;
	}

	/**
	 * The Admin Bar.
	 *
	 * Every library opens with this same one-row bar, a teacher's or a
	 * student's: the title and its line, then the filter group — the search,
	 * a line, the dropdown — then the line that runs to the end of the row.
	 * Two slots differ here and each difference is real: the title reads
	 * "Your homework", and the dropdown filters by status.
	 *
	 * There is no button, because a student creates homework under a lesson
	 * note and there is nowhere for a button to go. Nothing records that: the
	 * end line simply runs on into the space a button would have taken, which
	 * is what a line whose whole job is to take what is left already does.
	 *
	 * The is-empty modifier is a different case — an empty library, with no
	 * search and no dropdown, where the title's line is the only one left and
	 * the end line would double up behind it.
	 *
	 * The title zone holds its 234px minimum so the search begins 244px in,
	 * where a student finds it on any other library page.
	 *
	 * Search and filter appear once the library has entries.
	 *
	 * @param bool $has_entries Whether there is anything to search.
	 */
	private static function bar( bool $has_entries ): string {
		$html  = sprintf(
			'<div class="tbth-libbar%s">',
			$has_entries ? '' : ' tbth-libbar--is-empty'
		);
		$html .= '<div class="tbth-libbar__title">';
		$html .= '<h2 class="tbth-libbar__heading">' . esc_html__( 'Your homework', 'tbt-homework' ) . '</h2>';
		$html .= TBT_Homework_Bar::line();
		$html .= '</div>';

		if ( $has_entries ) {
			$html .= '<div class="tbth-libbar__filter" role="search">';

			$html .= TBT_Homework_Bar::search_field( __( 'Search your homework', 'tbt-homework' ) );

			$html .= TBT_Homework_Bar::line();

			$html .= sprintf(
				'<select class="tbt-select tbth-libbar__select" data-tbth-filter aria-label="%s">',
				esc_attr__( 'Filter homework by status', 'tbt-homework' )
			);
			$html .= '<option value="all">' . esc_html__( 'All', 'tbt-homework' ) . '</option>';
			$html .= '<option value="waiting">' . esc_html__( 'Waiting for comment', 'tbt-homework' ) . '</option>';
			$html .= '<option value="commented">' . esc_html__( 'Commented', 'tbt-homework' ) . '</option>';
			$html .= '</select>';

			$html .= '</div>';
		}

		$html .= TBT_Homework_Bar::line( true );
		$html .= '</div>';

		return $html;
	}

	/**
	 * The list, plus the line shown when a filter matches nothing.
	 *
	 * @param array $entries Shaped entries.
	 */
	private static function list_markup( array $entries ): string {
		$html = '<ul class="tbth-list tbth-entries" data-tbth-entries>';

		foreach ( $entries as $entry ) {
			$html .= self::entry_markup( $entry );
		}

		$html .= '</ul>';

		$html .= '<p class="tbth-noresults" data-tbth-noresults hidden role="status">' .
			esc_html__( 'No homework matches that search or filter.', 'tbt-homework' ) . '</p>';

		return $html;
	}

	/**
	 * One submission.
	 *
	 * Plain text in, esc_html() plus nl2br() out. The card does not link back
	 * to its note: the lesson brief gives a title, a class and a date, and
	 * nothing in the contract gives a lesson's address — inventing one would
	 * mean reading Notes' own data, which this plugin does not do.
	 *
	 * @param array $entry One shaped entry.
	 */
	private static function entry_markup( array $entry ): string {
		$commented = 'commented' === $entry['status'];

		$status_label = $commented
			? __( 'Commented', 'tbt-homework' )
			: __( 'Waiting for comment', 'tbt-homework' );

		$html  = sprintf(
			'<li class="tbt-card tbth-entry" data-tbth-entry data-status="%s">',
			esc_attr( $entry['status'] )
		);

		$html .= '<div class="tbth-entry__head">';
		$html .= '<h3 class="tbth-entry__lesson">' . esc_html( $entry['lesson_title'] ) . '</h3>';
		$html .= sprintf(
			'<span class="tbt-tag tbth-entry__status tbth-entry__status--%s">%s</span>',
			esc_attr( $entry['status'] ),
			esc_html( $status_label )
		);
		$html .= '</div>';

		$html .= '<p class="tbth-meta">' . esc_html( self::meta_line( $entry ) ) . '</p>';
		$html .= '<div class="tbth-body">' . nl2br( esc_html( $entry['body'] ) ) . '</div>';

		if ( $commented ) {
			$html .= '<div class="tbth-comment">';
			$html .= '<p class="tbth-comment__eyebrow">' . esc_html__( 'Your teacher’s comment', 'tbt-homework' ) . '</p>';
			$html .= '<div class="tbth-comment__body">' . nl2br( esc_html( (string) $entry['comment'] ) ) . '</div>';

			if ( $entry['commented_at'] ) {
				$html .= '<p class="tbth-comment__date">' .
					esc_html( TBT_Homework_REST::display_date( $entry['commented_at'] ) ) . '</p>';
			}

			$html .= '</div>';
		}

		$html .= '</li>';

		return $html;
	}

	/**
	 * "Group B · Sent 18 September".
	 *
	 * @param array $entry One shaped entry.
	 */
	private static function meta_line( array $entry ): string {
		$sent = sprintf(
			/* translators: %s: a date, such as 18 September. */
			__( 'Sent %s', 'tbt-homework' ),
			TBT_Homework_REST::display_date( $entry['submitted_at'] )
		);

		if ( '' === $entry['class_title'] ) {
			return $sent;
		}

		return $entry['class_title'] . ' · ' . $sent;
	}

	/**
	 * Nothing sent yet, anywhere.
	 */
	private static function empty_state(): string {
		return '<p class="tbth-empty">' .
			esc_html__( 'You have not sent any homework yet. Open a lesson note and write it there — it will appear here once you have.', 'tbt-homework' ) .
			'</p>';
	}

	/**
	 * A single quiet line, for the states that are not a library at all.
	 *
	 * @param string $message Already translated.
	 */
	private static function notice( string $message ): string {
		return '<p class="tbth-empty">' . esc_html( $message ) . '</p>';
	}

	/*
	 * ---------------------------------------------------------------------
	 * Pure logic below this line. No WordPress, no database, no globals.
	 * ---------------------------------------------------------------------
	 */

	/**
	 * Split lesson ids into batches the brief will accept.
	 *
	 * tbt_notes_lessons_brief() is capped at 200 ids, so a student with more
	 * lessons than that is asked for in several calls rather than silently
	 * losing the tail. Ids are deduplicated and reindexed; anything that is
	 * not a positive integer is dropped.
	 *
	 * @param array $ids  Lesson ids, in any shape the database handed over.
	 * @param int   $size Batch size.
	 *
	 * @return array<int, array<int, int>> Batches of ids.
	 */
	public static function chunk_lesson_ids( array $ids, int $size = self::BRIEF_MAX ): array {
		if ( $size < 1 ) {
			$size = 1;
		}

		$clean = array();
		foreach ( $ids as $id ) {
			$id = (int) $id;
			if ( $id > 0 ) {
				$clean[ $id ] = $id;
			}
		}

		if ( ! $clean ) {
			return array();
		}

		return array_chunk( array_values( $clean ), $size );
	}

	/**
	 * Name each row from the lesson brief, dropping the ones it omits.
	 *
	 * The brief leaves out every lesson the student may not view, so its
	 * silence is the permission answer: a row it does not mention does not
	 * appear. Order is preserved, newest first, as the query returned it.
	 *
	 * @param array $rows  Rows from the table.
	 * @param array $brief lesson_id => [ lesson_title, class_id, class_title, created_at ].
	 *
	 * @return array<int, array> Shaped entries.
	 */
	public static function attach_brief( array $rows, array $brief ): array {
		$entries = array();

		foreach ( $rows as $row ) {
			$lesson_id = (int) ( $row['lesson_id'] ?? 0 );

			if ( $lesson_id < 1 || ! isset( $brief[ $lesson_id ] ) ) {
				continue;
			}

			$named   = (array) $brief[ $lesson_id ];
			$comment = isset( $row['comment'] ) && null !== $row['comment'] ? (string) $row['comment'] : null;

			$entries[] = array(
				'lesson_id'    => $lesson_id,
				'class_id'     => (int) ( $row['class_id'] ?? 0 ),
				'lesson_title' => (string) ( $named['lesson_title'] ?? '' ),
				'class_title'  => (string) ( $named['class_title'] ?? '' ),
				'body'         => (string) ( $row['body'] ?? '' ),
				'comment'      => $comment,
				'status'       => self::status_for( $row ),
				'submitted_at' => (string) ( $row['submitted_at'] ?? '' ),
				'commented_at' => empty( $row['commented_at'] ) ? null : (string) $row['commented_at'],
			);
		}

		return $entries;
	}

	/**
	 * Which side of the filter a row falls on.
	 *
	 * comment IS NULL means new, and that single condition is the whole of
	 * this page's status.
	 *
	 * @param array|null $row A row from the table.
	 *
	 * @return string 'waiting' or 'commented'.
	 */
	public static function status_for( ?array $row ): string {
		return TBT_Homework_REST::is_closed( $row ) ? 'commented' : 'waiting';
	}
}
