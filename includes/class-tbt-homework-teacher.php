<?php
/**
 * [tbt_homework_teacher] — the page a teacher works from.
 *
 * Everything their students have sent, waiting ones first, with a comment box
 * on each. The list is rendered server-side and read whole; the script only
 * saves a comment, collapses a long submission and submits the bar's dropdown.
 *
 * Search and the filter are query parameters rather than a pass over rendered
 * rows, which is the one place this page does not copy the student's library.
 * It cannot: the queue is paged at 25, so filtering in the browser would search
 * the 25 rows in hand and quietly miss the rest, and "4 of 37" would be a lie.
 * The student's page is unpaged, so its own choice stays right for it.
 *
 * The decision helpers at the bottom of this class touch nothing outside their
 * arguments, so tests/test-logic.php runs them under plain PHP.
 *
 * @package TBT_Homework
 */

defined( 'ABSPATH' ) || exit;

/**
 * The teacher's queue: the Admin Bar, then their students' work, listed.
 */
class TBT_Homework_Teacher {

	/**
	 * The shortcode tag.
	 */
	const SHORTCODE = 'tbt_homework_teacher';

	/**
	 * The app's own id, which the Divi pin in the stylesheet is anchored on.
	 */
	const APP_ID = 'tbth-homework-teacher';

	/**
	 * Rows per page.
	 */
	const PER_PAGE = 25;

	/**
	 * tbt_notes_lessons_brief() is capped at 200 ids per call.
	 *
	 * A page of 25 never approaches it, but a search resolves titles across
	 * every lesson in scope, which can, so the batching helper 0.2.0 wrote
	 * earns its keep here rather than being assumed unnecessary.
	 */
	const BRIEF_MAX = 200;

	/**
	 * The dropdown's values. Waiting is the default, and it narrows the list:
	 * the page opens on the newest thing nobody has replied to, which is the
	 * job.
	 */
	const FILTERS = array( 'waiting', 'commented', 'all' );

	/**
	 * The filter the page opens on.
	 */
	const DEFAULT_FILTER = 'waiting';

	/**
	 * Query parameters. Prefixed like everything else, so they cannot collide
	 * with a page builder's own.
	 */
	const PARAM_STATUS = 'tbth_status';
	const PARAM_SEARCH = 'tbth_q';
	const PARAM_PAGE   = 'tbth_page';

	/**
	 * Register the shortcode.
	 */
	public static function init(): void {
		add_shortcode( self::SHORTCODE, array( __CLASS__, 'render' ) );
	}

	/**
	 * Render the queue.
	 *
	 * @param array|string $atts    Shortcode attributes; none are read.
	 * @param string|null  $content Enclosed content; there is none.
	 */
	public static function render( $atts = array(), $content = null ): string {
		// Nothing here is public, at any point.
		if ( ! is_user_logged_in() ) {
			return self::teachers_only();
		}

		// The dependency guard, at the point of use. Without TBT Notes there
		// is no way to know which classes this is, so the page says so rather
		// than guessing. Nothing is lost: the rows are in the table.
		if ( ! function_exists( 'tbt_notes_class_ids_for_manager' ) || ! function_exists( 'tbt_notes_lessons_brief' ) ) {
			return self::notice(
				__( 'The homework queue is temporarily unavailable. Nothing has been lost — please try again shortly.', 'tbt-homework' )
			);
		}

		// The classes you teach, every class for an administrator, an empty
		// array for everyone else. An empty array is the whole of "not a
		// teacher": no role and no capability is re-derived here.
		$class_ids = TBT_Homework_DB::positive_ids( (array) tbt_notes_class_ids_for_manager() );

		if ( ! $class_ids ) {
			return self::teachers_only();
		}

		$state = self::request_state();
		$page  = self::page( $class_ids, $state );

		self::enqueue();

		return self::markup( $state, $page );
	}

	/*
	 * ---------------------------------------------------------------------
	 * The queue itself.
	 * ---------------------------------------------------------------------
	 */

	/**
	 * One page of the queue, with the counts the bar and the summary need.
	 *
	 * Three queries when nothing is being searched for: the totals, the rows,
	 * and one WP_User_Query for the page's student names — plus one
	 * tbt_notes_lessons_brief() call for its lesson and class titles. Not one
	 * of each per row.
	 *
	 * A search costs more, and has to: the student's name, the lesson title
	 * and the class title are not columns in this table, so they are resolved
	 * to ids first and the rows query pages over the result. Doing it the
	 * other way — fetching a page and then filtering it — would drop matches
	 * on every page but the first.
	 *
	 * @param array $class_ids The classes this teacher manages.
	 * @param array $state     filter, search and page, already normalised.
	 */
	public static function page( array $class_ids, array $state ): array {
		$totals = TBT_Homework_DB::scope_totals( $class_ids );

		$user_ids   = array();
		$lesson_ids = array();

		if ( '' !== $state['search'] ) {
			$pairs      = TBT_Homework_DB::scope_pairs( $class_ids );
			$user_ids   = self::students_matching( $pairs['user_ids'], $state['search'] );
			$lesson_ids = self::lessons_matching( $pairs['lesson_ids'], $state['search'] );
		}

		// The totals query has already answered every unsearched filter, so a
		// second count is bought only when the reader has asked something it
		// cannot answer.
		$matched = self::matched_from_totals( $state, $totals );
		if ( null === $matched ) {
			$matched = TBT_Homework_DB::count_queue( $class_ids, $state['filter'], $state['search'], $user_ids, $lesson_ids );
		}

		$pages  = self::page_count( $matched, self::PER_PAGE );
		$number = self::clamp_page( $state['page'], $pages );

		$rows = $matched > 0
			? TBT_Homework_DB::get_queue(
				$class_ids,
				$state['filter'],
				$state['search'],
				$user_ids,
				$lesson_ids,
				self::PER_PAGE,
				( $number - 1 ) * self::PER_PAGE
			)
			: array();

		return array(
			'entries' => self::shape( $rows ),
			'total'   => $totals['total'],
			'waiting' => $totals['waiting'],
			'matched' => $matched,
			'page'    => $number,
			'pages'   => $pages,
		);
	}

	/**
	 * Name one page of rows: the lesson, the class and the student.
	 *
	 * @param array $rows Rows from the table.
	 */
	private static function shape( array $rows ): array {
		if ( ! $rows ) {
			return array();
		}

		$brief = self::brief_for( wp_list_pluck( $rows, 'lesson_id' ) );
		$names = self::names_for( TBT_Homework_DB::positive_ids( wp_list_pluck( $rows, 'user_id' ) ) );

		return self::attach( $rows, $brief, $names );
	}

	/**
	 * The lesson brief for a set of ids, in batches the brief will accept.
	 *
	 * @param array $lesson_ids Lesson ids, in any shape they arrived in.
	 */
	private static function brief_for( array $lesson_ids ): array {
		$brief = array();

		foreach ( TBT_Homework_Student::chunk_lesson_ids( $lesson_ids, self::BRIEF_MAX ) as $chunk ) {
			$batch = tbt_notes_lessons_brief( $chunk );
			if ( is_array( $batch ) ) {
				// Union by lesson id; the chunks never overlap.
				$brief += $batch;
			}
		}

		return $brief;
	}

	/**
	 * Student ids whose name matches the search.
	 *
	 * Bounded by `include`, which is the list of students who have actually
	 * sent this teacher something. The search can therefore never reach a user
	 * outside the queue's own scope, however broad the term.
	 *
	 * @param array  $user_ids Students in scope.
	 * @param string $search   Trimmed search text.
	 *
	 * @return array<int, int>
	 */
	private static function students_matching( array $user_ids, string $search ): array {
		if ( ! $user_ids || '' === $search ) {
			return array();
		}

		$query = new WP_User_Query(
			array(
				'include'        => $user_ids,
				'search'         => '*' . $search . '*',
				'search_columns' => array( 'display_name', 'user_login', 'user_nicename' ),
				'fields'         => 'ID',
				'number'         => count( $user_ids ),
			)
		);

		return TBT_Homework_DB::positive_ids( (array) $query->get_results() );
	}

	/**
	 * Lesson ids whose lesson title or class title matches the search.
	 *
	 * @param array  $lesson_ids Lessons in scope.
	 * @param string $search     Trimmed search text.
	 *
	 * @return array<int, int>
	 */
	private static function lessons_matching( array $lesson_ids, string $search ): array {
		if ( ! $lesson_ids || '' === $search ) {
			return array();
		}

		return self::titles_matching( self::brief_for( $lesson_ids ), $search );
	}

	/**
	 * display_name for a set of user ids.
	 *
	 * @param array $user_ids Students on this page.
	 *
	 * @return array<int, string>
	 */
	private static function names_for( array $user_ids ): array {
		if ( ! $user_ids ) {
			return array();
		}

		$query = new WP_User_Query(
			array(
				'include' => $user_ids,
				'fields'  => array( 'ID', 'display_name' ),
				'number'  => count( $user_ids ),
			)
		);

		$names = array();
		foreach ( (array) $query->get_results() as $user ) {
			$names[ (int) $user->ID ] = (string) $user->display_name;
		}

		return $names;
	}

	/*
	 * ---------------------------------------------------------------------
	 * Markup. The hero belongs to the page template, so this starts at the
	 * bar and nowhere higher.
	 * ---------------------------------------------------------------------
	 */

	/**
	 * The whole page.
	 *
	 * @param array $state filter, search and page.
	 * @param array $page  The queue and its counts.
	 */
	private static function markup( array $state, array $page ): string {
		$base = self::base_url();

		$html  = '<div id="' . esc_attr( self::APP_ID ) . '" class="tbth-teacher">';
		$html .= self::bar( $base, $state, $page );
		$html .= self::summary( $base, $state, $page );

		if ( $page['entries'] ) {
			$html .= self::list_markup( $page['entries'] );
			$html .= self::pager( $base, $state, $page );
		} else {
			$html .= self::empty_state( $state, $page );
		}

		$html .= '</div>';

		return $html;
	}

	/**
	 * The Admin Bar.
	 *
	 * The same one-row bar every library opens with, under this plugin's own
	 * prefix, in the same order as the student's: the title and its line, then
	 * the filter group — the search, a line, the dropdown — then the line that
	 * runs to the end of the row. The geometry is the one the stylesheet pins:
	 * a 234px minimum title zone so the search begins 244px in, a 300px
	 * search, a 300px dropdown.
	 *
	 * The title reads "Homework to check" rather than "Your {items}": these
	 * are not your items, they are your students'. There is no button, because
	 * you do not create homework, and the end line simply runs on into the
	 * space one would have taken. The is-empty modifier is the other case — an
	 * empty queue, with no search and no dropdown to put a line between.
	 *
	 * The bar is a GET form, because search and filter are query parameters
	 * here. Pressing Enter in the search submits it; the dropdown is submitted
	 * by the script, and by the visually hidden button behind it when there is
	 * no script. The bar's visible button slot stays empty either way.
	 *
	 * @param string $base  The page's own URL, with our parameters stripped.
	 * @param array  $state filter, search and page.
	 * @param array  $page  The queue and its counts.
	 */
	private static function bar( string $base, array $state, array $page ): string {
		$html  = sprintf(
			'<form class="tbth-libbar%s" method="get" action="%s" data-tbth-bar>',
			$page['total'] > 0 ? '' : ' tbth-libbar--is-empty',
			esc_url( self::form_action( $base ) )
		);
		$html .= self::hidden_fields( $base );

		$html .= '<div class="tbth-libbar__title">';
		$html .= '<h2 class="tbth-libbar__heading">' . esc_html__( 'Homework to check', 'tbt-homework' ) . '</h2>';
		$html .= self::count_pill( $page['waiting'] );
		$html .= TBT_Homework_Bar::line();
		$html .= '</div>';

		// An empty queue has nothing to search and nothing to filter, exactly
		// as an empty student library has neither control.
		if ( $page['total'] > 0 ) {
			// The search landmark is the group, not the whole form: the form
			// also carries whatever else was on the URL.
			$html .= '<div class="tbth-libbar__filter" role="search">';

			$html .= TBT_Homework_Bar::search_field(
				__( 'Search your students’ homework', 'tbt-homework' ),
				self::PARAM_SEARCH,
				$state['search']
			);

			$html .= TBT_Homework_Bar::line();

			$html .= sprintf(
				'<select class="tbt-select tbth-libbar__select%s" name="%s" aria-label="%s" data-tbth-filter>',
				// Blue on Waiting and on Commented, because both narrow the
				// list; not blue on All, which is the only one that does not.
				// Blue on load is correct here — the list genuinely is filtered.
				'all' === $state['filter'] ? '' : ' tbth-is-set',
				esc_attr( self::PARAM_STATUS ),
				esc_attr__( 'Filter homework by status', 'tbt-homework' )
			);

			foreach ( self::filter_labels() as $value => $label ) {
				$html .= sprintf(
					'<option value="%s"%s>%s</option>',
					esc_attr( $value ),
					selected( $value, $state['filter'], false ),
					esc_html( $label )
				);
			}

			$html .= '</select>';

			// No button belongs on this bar, so the one that submits it
			// without a script is there for keyboards and screen readers and
			// takes no space in the row. It sits after the dropdown, which is
			// where a keyboard reaches it.
			$html .= '<button type="submit" class="tbth-libbar__go">' .
				esc_html__( 'Apply', 'tbt-homework' ) . '</button>';

			$html .= '</div>';
		}

		$html .= TBT_Homework_Bar::line( true );
		$html .= '</form>';

		return $html;
	}

	/**
	 * The dropdown's three lines.
	 */
	private static function filter_labels(): array {
		return array(
			'waiting'   => __( 'Waiting', 'tbt-homework' ),
			'commented' => __( 'Commented', 'tbt-homework' ),
			'all'       => __( 'All', 'tbt-homework' ),
		);
	}

	/**
	 * The count beside the title.
	 *
	 * What is waiting across every class you manage, whatever the dropdown is
	 * showing — a count that changed with the filter would be useless. TBT
	 * Blue, white text, fully rounded: a count is a genuine pill, which is what
	 * the radius rule reserves the shape for.
	 *
	 * Zero waiting is no pill at all, not a grey zero. The script puts one back
	 * if clearing a comment takes the count up from zero.
	 *
	 * @param int $waiting Rows with no comment, in scope.
	 */
	private static function count_pill( int $waiting ): string {
		if ( $waiting < 1 ) {
			return '';
		}

		return sprintf(
			'<span class="tbth-count" data-tbth-count="%1$d" aria-label="%2$s">%3$s</span>',
			$waiting,
			esc_attr( self::count_label( $waiting ) ),
			esc_html( number_format_i18n( $waiting ) )
		);
	}

	/**
	 * "4 waiting", for the pill's label.
	 *
	 * @param int $waiting How many are waiting.
	 */
	private static function count_label( int $waiting ): string {
		return sprintf(
			/* translators: %s: a number of pieces of homework. */
			__( '%s waiting', 'tbt-homework' ),
			number_format_i18n( $waiting )
		);
	}

	/**
	 * "4 of 37 · Clear filters", when a search or a non-default filter is on.
	 *
	 * @param string $base  The page's own URL.
	 * @param array  $state filter, search and page.
	 * @param array  $page  The queue and its counts.
	 */
	private static function summary( string $base, array $state, array $page ): string {
		if ( ! self::filters_active( $state['filter'], $state['search'] ) ) {
			return '';
		}

		return sprintf(
			'<p class="tbth-summary">%1$s · <a class="tbth-summary__clear" href="%2$s">%3$s</a></p>',
			esc_html(
				sprintf(
					/* translators: 1: matching rows, 2: rows in total. */
					__( '%1$s of %2$s', 'tbt-homework' ),
					number_format_i18n( $page['matched'] ),
					number_format_i18n( $page['total'] )
				)
			),
			// Clearing resets the search and returns the dropdown to Waiting,
			// which is what the bare page is.
			esc_url( $base ),
			esc_html__( 'Clear filters', 'tbt-homework' )
		);
	}

	/**
	 * The list.
	 *
	 * @param array $entries Shaped entries, newest first.
	 */
	private static function list_markup( array $entries ): string {
		$html = '<ul class="tbth-list tbth-queue" data-tbth-queue>';

		foreach ( $entries as $entry ) {
			$html .= self::card( $entry );
		}

		$html .= '</ul>';

		return $html;
	}

	/**
	 * One submission, with its comment box.
	 *
	 * The student's name is the strongest thing on the card: you scan for
	 * people, not for lessons. The submission is in Roboto Slab because it is
	 * the student's writing, and so is the comment box, because a comment is
	 * writing a student will read as part of their learning.
	 *
	 * Plain text in, esc_html() plus nl2br() out, on both.
	 *
	 * @param array $entry One shaped entry.
	 */
	private static function card( array $entry ): string {
		$field_id = 'tbth-comment-' . (int) $entry['id'];

		$html = sprintf(
			'<li class="tbt-card tbth-item" data-tbth-item data-id="%1$d" data-status="%2$s">',
			(int) $entry['id'],
			esc_attr( $entry['status'] )
		);

		$html .= '<div class="tbth-item__head">';
		$html .= '<h3 class="tbth-item__student">' . esc_html( self::student_name( $entry ) ) . '</h3>';
		$html .= sprintf(
			'<span class="tbt-tag tbth-item__status tbth-item__status--%1$s" data-tbth-status>%2$s</span>',
			esc_attr( $entry['status'] ),
			esc_html( self::status_label( $entry ) )
		);
		$html .= '</div>';

		$html .= '<p class="tbth-meta">' . esc_html( self::meta_line( $entry ) ) . '</p>';
		$html .= '<div class="tbth-body tbth-item__body" data-tbth-body>' .
			nl2br( esc_html( $entry['body'] ) ) . '</div>';

		$html .= '<div class="tbth-item__comment">';
		$html .= sprintf(
			'<label class="tbth-item__label" for="%1$s">%2$s</label>',
			esc_attr( $field_id ),
			esc_html__( 'Your comment', 'tbt-homework' )
		);
		$html .= sprintf(
			'<textarea id="%1$s" class="tbth-textarea tbth-item__box" data-tbth-comment rows="3" maxlength="%2$d">%3$s</textarea>',
			esc_attr( $field_id ),
			TBT_Homework_REST::COMMENT_MAX,
			esc_textarea( (string) $entry['comment'] )
		);

		$html .= '<p class="tbth-error" data-tbth-error hidden role="alert"></p>';

		$html .= '<div class="tbth-actions">';
		$html .= '<button type="button" class="tbth-btn tbth-btn--primary" data-tbth-save>' .
			esc_html__( 'Save comment', 'tbt-homework' ) . '</button>';
		$html .= '</div>';
		$html .= '</div>';

		$html .= '</li>';

		return $html;
	}

	/**
	 * The student's name, or what to call one whose account has gone.
	 *
	 * @param array $entry One shaped entry.
	 */
	private static function student_name( array $entry ): string {
		return '' !== $entry['student'] ? $entry['student'] : __( 'A former student', 'tbt-homework' );
	}

	/**
	 * "Waiting", or "Commented 18 September".
	 *
	 * @param array $entry One shaped entry.
	 */
	private static function status_label( array $entry ): string {
		if ( 'commented' !== $entry['status'] ) {
			return __( 'Waiting', 'tbt-homework' );
		}

		if ( ! $entry['commented_at'] ) {
			return __( 'Commented', 'tbt-homework' );
		}

		return sprintf(
			/* translators: %s: a date, such as 18 September. */
			__( 'Commented %s', 'tbt-homework' ),
			TBT_Homework_REST::display_date( (string) $entry['commented_at'] )
		);
	}

	/**
	 * "Past simple · Group B · Sent 18 September".
	 *
	 * A lesson the brief could not name drops out of the line rather than out
	 * of the list: the scope here is class_id, not the brief.
	 *
	 * @param array $entry One shaped entry.
	 */
	private static function meta_line( array $entry ): string {
		$parts = array();

		if ( '' !== $entry['lesson_title'] ) {
			$parts[] = $entry['lesson_title'];
		}

		if ( '' !== $entry['class_title'] ) {
			$parts[] = $entry['class_title'];
		}

		$parts[] = sprintf(
			/* translators: %s: a date, such as 18 September. */
			__( 'Sent %s', 'tbt-homework' ),
			TBT_Homework_REST::display_date( (string) $entry['submitted_at'] )
		);

		return implode( ' · ', $parts );
	}

	/**
	 * Older and newer, and where you are.
	 *
	 * Newest first, so newer is the page before and older the page after.
	 *
	 * @param string $base  The page's own URL.
	 * @param array  $state filter, search and page.
	 * @param array  $page  The queue and its counts.
	 */
	private static function pager( string $base, array $state, array $page ): string {
		if ( $page['pages'] < 2 ) {
			return '';
		}

		$html = sprintf(
			'<nav class="tbth-pager" aria-label="%s">',
			esc_attr__( 'Homework pages', 'tbt-homework' )
		);

		if ( $page['page'] > 1 ) {
			$html .= sprintf(
				'<a class="tbth-pager__link" href="%s">%s</a>',
				esc_url( self::link( $base, $state, $page['page'] - 1 ) ),
				esc_html__( 'Newer', 'tbt-homework' )
			);
		}

		$html .= '<span class="tbth-pager__where">' . esc_html(
			sprintf(
				/* translators: 1: current page, 2: number of pages. */
				__( 'Page %1$s of %2$s', 'tbt-homework' ),
				number_format_i18n( $page['page'] ),
				number_format_i18n( $page['pages'] )
			)
		) . '</span>';

		if ( $page['page'] < $page['pages'] ) {
			$html .= sprintf(
				'<a class="tbth-pager__link" href="%s">%s</a>',
				esc_url( self::link( $base, $state, $page['page'] + 1 ) ),
				esc_html__( 'Older', 'tbt-homework' )
			);
		}

		$html .= '</nav>';

		return $html;
	}

	/**
	 * The quiet states: nothing at all, nothing waiting, nothing matching.
	 *
	 * @param array $state filter, search and page.
	 * @param array $page  The queue and its counts.
	 */
	private static function empty_state( array $state, array $page ): string {
		if ( $page['total'] < 1 ) {
			return self::notice( __( 'No homework has been sent yet.', 'tbt-homework' ) );
		}

		if ( '' !== $state['search'] ) {
			return self::notice( __( 'No homework matches your search.', 'tbt-homework' ) );
		}

		if ( self::DEFAULT_FILTER === $state['filter'] ) {
			return self::notice( __( 'Nothing waiting. All caught up.', 'tbt-homework' ) );
		}

		return self::notice( __( 'No homework matches that filter.', 'tbt-homework' ) );
	}

	/**
	 * The one line anybody who is not a teacher sees.
	 *
	 * No count, no name, no class title — nothing about anyone's homework.
	 */
	private static function teachers_only(): string {
		return self::notice( __( 'This page is for teachers.', 'tbt-homework' ) );
	}

	/**
	 * A single quiet line, for the states that are not a queue at all.
	 *
	 * @param string $message Already translated.
	 */
	private static function notice( string $message ): string {
		return '<p class="tbth-empty">' . esc_html( $message ) . '</p>';
	}

	/*
	 * ---------------------------------------------------------------------
	 * The page's own URL, and the state carried on it.
	 * ---------------------------------------------------------------------
	 */

	/**
	 * filter, search and page, as they arrived.
	 *
	 * Read from the query string, which is what a GET form leaves behind. Each
	 * one is normalised by a pure function below; nothing else is trusted, and
	 * no id of any kind is read from here.
	 */
	private static function request_state(): array {
		// phpcs:disable WordPress.Security.NonceVerification.Recommended -- a
		// public GET form that only narrows a list the caller may already see.
		return array(
			'filter' => self::normalise_filter( $_GET[ self::PARAM_STATUS ] ?? null ),
			'search' => self::normalise_search( isset( $_GET[ self::PARAM_SEARCH ] ) ? wp_unslash( $_GET[ self::PARAM_SEARCH ] ) : null ),
			'page'   => self::normalise_page( $_GET[ self::PARAM_PAGE ] ?? null ),
		);
		// phpcs:enable WordPress.Security.NonceVerification.Recommended
	}

	/**
	 * This page's own address, with our three parameters stripped.
	 *
	 * remove_query_arg() with no URL works on the current request and hands
	 * back a relative URI, which is all a form action or a pager link needs
	 * and safer than rebuilding an absolute one by hand.
	 */
	private static function base_url(): string {
		return remove_query_arg( array( self::PARAM_STATUS, self::PARAM_SEARCH, self::PARAM_PAGE ) );
	}

	/**
	 * The path a GET form may post to.
	 *
	 * A GET form throws away the query string in its action, so the path goes
	 * here and everything else comes back as hidden fields.
	 *
	 * @param string $base The page's own URL.
	 */
	private static function form_action( string $base ): string {
		$parts = wp_parse_url( $base );

		return ( is_array( $parts ) && ! empty( $parts['path'] ) ) ? $parts['path'] : $base;
	}

	/**
	 * Whatever else was already on the URL — page_id on a plain-permalink
	 * site, most often — so submitting the bar does not navigate away.
	 *
	 * @param string $base The page's own URL.
	 */
	private static function hidden_fields( string $base ): string {
		$parts = wp_parse_url( $base );

		if ( ! is_array( $parts ) || empty( $parts['query'] ) ) {
			return '';
		}

		$pairs = array();
		wp_parse_str( $parts['query'], $pairs );

		$html = '';
		foreach ( $pairs as $key => $value ) {
			// A GET form cannot carry an array back anyway.
			if ( ! is_scalar( $value ) ) {
				continue;
			}

			$html .= sprintf(
				'<input type="hidden" name="%s" value="%s">',
				esc_attr( $key ),
				esc_attr( (string) $value )
			);
		}

		return $html;
	}

	/**
	 * A link to one page of the queue, carrying the current filter and search.
	 *
	 * The default filter and the first page are left off: a clean URL is the
	 * one the page opens on.
	 *
	 * @param string $base   The page's own URL.
	 * @param array  $state  filter, search and page.
	 * @param int    $number The page to link to.
	 */
	private static function link( string $base, array $state, int $number ): string {
		$args = array();

		if ( self::DEFAULT_FILTER !== $state['filter'] ) {
			$args[ self::PARAM_STATUS ] = $state['filter'];
		}

		if ( '' !== $state['search'] ) {
			$args[ self::PARAM_SEARCH ] = $state['search'];
		}

		if ( $number > 1 ) {
			$args[ self::PARAM_PAGE ] = $number;
		}

		return $args ? add_query_arg( $args, $base ) : $base;
	}

	/**
	 * Enqueue the page's assets.
	 *
	 * The shared sheet carries the card, the body type, the comment box and
	 * the buttons; the library sheet carries the Admin Bar, which is one copy
	 * shared with the student's page rather than a second one written here;
	 * the teacher sheet adds the count, the queue and the pager.
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
			'tbt-homework-teacher',
			TBT_HOMEWORK_URL . 'assets/css/tbt-homework-teacher.css',
			array( 'tbt-components', 'tbt-homework', 'tbt-homework-library' ),
			TBT_HOMEWORK_VERSION
		);

		wp_enqueue_script(
			'tbt-homework-teacher',
			TBT_HOMEWORK_URL . 'assets/js/tbt-homework-teacher.js',
			array(),
			TBT_HOMEWORK_VERSION,
			true
		);

		wp_localize_script(
			'tbt-homework-teacher',
			'TBTHomeworkTeacherData',
			array(
				// The full endpoint URL, not a base to concatenate: on a site
				// with plain permalinks rest_url() returns ?rest_route=...,
				// which a naive join would corrupt.
				'comment'   => esc_url_raw( rest_url( TBT_Homework_REST::REST_NAMESPACE . '/comment' ) ),
				'nonce'     => wp_create_nonce( 'wp_rest' ),
				'maxLength' => TBT_Homework_REST::COMMENT_MAX,
				'i18n'      => self::strings(),
			)
		);
	}

	/**
	 * Every string the script can show.
	 */
	private static function strings(): array {
		return array(
			'saving'       => __( 'Saving…', 'tbt-homework' ),
			'save'         => __( 'Save comment', 'tbt-homework' ),
			'saveFailed'   => __( 'Your comment was not saved. Your text is still here — try again.', 'tbt-homework' ),
			'genericError' => __( 'Something went wrong. Please try again.', 'tbt-homework' ),
			'confirmClear' => __( 'Clear your comment? The student will be able to edit their homework again.', 'tbt-homework' ),
			'waiting'      => __( 'Waiting', 'tbt-homework' ),
			'commented'    => __( 'Commented', 'tbt-homework' ),
			/* translators: %s: a date, such as 18 September. */
			'commentedOn'  => __( 'Commented %s', 'tbt-homework' ),
			/* translators: %s: a number of pieces of homework. */
			'countLabel'   => __( '%s waiting', 'tbt-homework' ),
			'showMore'     => __( 'Show more', 'tbt-homework' ),
			'showLess'     => __( 'Show less', 'tbt-homework' ),
		);
	}

	/*
	 * ---------------------------------------------------------------------
	 * Pure logic below this line. No WordPress, no database, no globals.
	 * ---------------------------------------------------------------------
	 */

	/**
	 * Which of the three the dropdown is on.
	 *
	 * Anything that is not one of the three is Waiting, which is the default
	 * and the one the job starts from.
	 *
	 * @param mixed $raw The value as it arrived.
	 */
	public static function normalise_filter( $raw ): string {
		if ( ! is_string( $raw ) && ! is_int( $raw ) ) {
			return self::DEFAULT_FILTER;
		}

		$value = strtolower( trim( (string) $raw ) );

		return in_array( $value, self::FILTERS, true ) ? $value : self::DEFAULT_FILTER;
	}

	/**
	 * The search text, trimmed, or '' for none.
	 *
	 * @param mixed $raw The value as it arrived.
	 */
	public static function normalise_search( $raw ): string {
		if ( ! is_string( $raw ) && ! is_int( $raw ) && ! is_float( $raw ) ) {
			return '';
		}

		$value = trim( (string) $raw );

		return function_exists( 'sanitize_text_field' ) ? trim( sanitize_text_field( $value ) ) : $value;
	}

	/**
	 * Which page was asked for. Anything unusable is the first.
	 *
	 * @param mixed $raw The value as it arrived.
	 */
	public static function normalise_page( $raw ): int {
		if ( is_int( $raw ) ) {
			return $raw > 0 ? $raw : 1;
		}

		if ( is_string( $raw ) && preg_match( '/^[0-9]+$/', trim( $raw ) ) ) {
			return max( 1, (int) trim( $raw ) );
		}

		return 1;
	}

	/**
	 * Is this row's class one the caller manages?
	 *
	 * The whole of the queue's security model in one line: the class_id comes
	 * from the stored row, the ids come from tbt_notes_class_ids_for_manager(),
	 * and nothing from the browser takes part. An empty set of classes — which
	 * is what anyone who is not a teacher has — can never contain anything.
	 *
	 * @param int   $class_id  The row's own class.
	 * @param array $class_ids The classes the caller manages.
	 */
	public static function in_scope( int $class_id, array $class_ids ): bool {
		if ( $class_id < 1 || ! $class_ids ) {
			return false;
		}

		foreach ( $class_ids as $id ) {
			if ( (int) $id === $class_id ) {
				return true;
			}
		}

		return false;
	}

	/**
	 * Is the list narrowed, so the summary line has something to say?
	 *
	 * Waiting is the default and still narrows the list, but it is not a
	 * choice the reader made, so it does not put a summary line on the page.
	 *
	 * @param string $filter The current filter.
	 * @param string $search The current search.
	 */
	public static function filters_active( string $filter, string $search ): bool {
		return '' !== trim( $search ) || self::DEFAULT_FILTER !== $filter;
	}

	/**
	 * The matching count, when the totals already contain it.
	 *
	 * With no search running, every filter is a slice of the two numbers the
	 * totals query returned: Waiting is one of them, All is the other, and
	 * Commented is what is left. A search is the only thing that needs a count
	 * of its own.
	 *
	 * @param array $state  filter, search and page.
	 * @param array $totals total and waiting, in scope.
	 *
	 * @return int|null The count, or null when it has to be asked for.
	 */
	public static function matched_from_totals( array $state, array $totals ): ?int {
		if ( '' !== trim( (string) ( $state['search'] ?? '' ) ) ) {
			return null;
		}

		$total   = (int) ( $totals['total'] ?? 0 );
		$waiting = (int) ( $totals['waiting'] ?? 0 );

		switch ( $state['filter'] ?? self::DEFAULT_FILTER ) {
			case 'waiting':
				return $waiting;

			case 'commented':
				return max( 0, $total - $waiting );

			case 'all':
				return $total;
		}

		return null;
	}

	/**
	 * How many pages a number of rows makes. Always at least one.
	 *
	 * @param int $matched How many rows match.
	 * @param int $per     Rows per page.
	 */
	public static function page_count( int $matched, int $per = self::PER_PAGE ): int {
		if ( $per < 1 ) {
			$per = 1;
		}

		return $matched < 1 ? 1 : (int) ceil( $matched / $per );
	}

	/**
	 * A page number that exists.
	 *
	 * Past the end comes back to the last page rather than to an empty one:
	 * commenting on the last row of the last page should not leave you looking
	 * at nothing.
	 *
	 * @param int $number Which page was asked for.
	 * @param int $pages  How many there are.
	 */
	public static function clamp_page( int $number, int $pages ): int {
		if ( $pages < 1 ) {
			$pages = 1;
		}

		if ( $number < 1 ) {
			return 1;
		}

		return $number > $pages ? $pages : $number;
	}

	/**
	 * Lesson ids whose lesson title or class title contains the search.
	 *
	 * Case-insensitive, and folded with mb_strtolower where it exists so a
	 * Polish name matches whichever case it was typed in.
	 *
	 * @param array  $brief  lesson_id => [ lesson_title, class_title, … ].
	 * @param string $needle The search text.
	 *
	 * @return array<int, int>
	 */
	public static function titles_matching( array $brief, string $needle ): array {
		$needle = self::fold( trim( $needle ) );

		if ( '' === $needle ) {
			return array();
		}

		$ids = array();

		foreach ( $brief as $lesson_id => $named ) {
			$lesson_id = (int) $lesson_id;

			if ( $lesson_id < 1 || ! is_array( $named ) ) {
				continue;
			}

			$haystack = self::fold(
				(string) ( $named['lesson_title'] ?? '' ) . ' ' . (string) ( $named['class_title'] ?? '' )
			);

			if ( false !== strpos( $haystack, $needle ) ) {
				$ids[] = $lesson_id;
			}
		}

		return $ids;
	}

	/**
	 * Lowercase, in whatever alphabet.
	 *
	 * @param string $text Any text.
	 */
	public static function fold( string $text ): string {
		return function_exists( 'mb_strtolower' ) ? mb_strtolower( $text, 'UTF-8' ) : strtolower( $text );
	}

	/**
	 * Name each row from the brief and the student list.
	 *
	 * Unlike the student's library, a row the brief does not mention is kept:
	 * there the brief's silence is the permission answer, here the class scope
	 * already is, and dropping a row because Notes could not name its lesson
	 * would hide work a teacher is owed a reply on. It loses its title, not
	 * its place.
	 *
	 * @param array $rows  Rows from the table.
	 * @param array $brief lesson_id => [ lesson_title, class_title, … ].
	 * @param array $names user_id => display_name.
	 *
	 * @return array<int, array> Shaped entries.
	 */
	public static function attach( array $rows, array $brief, array $names ): array {
		$entries = array();

		foreach ( $rows as $row ) {
			$lesson_id = (int) ( $row['lesson_id'] ?? 0 );
			$user_id   = (int) ( $row['user_id'] ?? 0 );
			$named     = isset( $brief[ $lesson_id ] ) ? (array) $brief[ $lesson_id ] : array();
			$comment   = isset( $row['comment'] ) && null !== $row['comment'] ? (string) $row['comment'] : null;

			$entries[] = array(
				'id'           => (int) ( $row['id'] ?? 0 ),
				'user_id'      => $user_id,
				'lesson_id'    => $lesson_id,
				'class_id'     => (int) ( $row['class_id'] ?? 0 ),
				// A student whose account has gone comes back nameless; the
				// card decides what to call them, so this stays free of
				// WordPress and testable on its own.
				'student'      => (string) ( $names[ $user_id ] ?? '' ),
				'lesson_title' => (string) ( $named['lesson_title'] ?? '' ),
				'class_title'  => (string) ( $named['class_title'] ?? '' ),
				'body'         => (string) ( $row['body'] ?? '' ),
				'comment'      => $comment,
				'status'       => TBT_Homework_Student::status_for( $row ),
				'submitted_at' => (string) ( $row['submitted_at'] ?? '' ),
				'commented_at' => empty( $row['commented_at'] ) ? null : (string) $row['commented_at'],
			);
		}

		return $entries;
	}
}
