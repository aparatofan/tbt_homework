<?php
/**
 * Marks: a teacher's short notes on a student's words.
 *
 * The UI says "note". The code says "mark", because "note" is TBT Notes' word
 * everywhere and sharing it is how names collide.
 *
 * A mark is a range of Unicode code points into the canonical body — the
 * stored body with \r\n and then \r turned into \n — plus the words it covers
 * and the teacher's note. Nothing rewrites the stored body; the canonical form
 * exists only while offsets are counted, quotes are compared, the body is
 * hashed, and the body is rendered.
 *
 * The pure methods at the top touch nothing outside their arguments, so
 * tests/test-logic.php runs them under plain PHP. render() is the one place a
 * body with marks becomes HTML, and assets/js/tbt-homework-marks.js mirrors it
 * exactly: the teacher's script counts offsets on the markup either of them
 * writes, so the two must never drift apart.
 *
 * @package TBT_Homework
 */

defined( 'ABSPATH' ) || exit;

/**
 * Validation, storage shape and rendering for marks.
 */
class TBT_Homework_Marks {

	/**
	 * At most this many marks on one submission.
	 */
	const MAX_MARKS = 100;

	/**
	 * A note is at most this many code points.
	 */
	const NOTE_MAX = 200;

	/**
	 * A note is drawn at most this many pixels wide. CSS and JS only.
	 */
	const NOTE_WIDTH = 240;

	/**
	 * Whitespace, as one explicit class rather than \s.
	 *
	 * PCRE's \s and JavaScript's \s disagree at the edges (U+0085, U+FEFF), so
	 * this is JavaScript's set written out, and the script uses the same list.
	 * A quote that one side calls trimmed and the other does not would be a
	 * mark the browser offers and the server refuses.
	 */
	const SPACE_CLASS = '[\x{0009}-\x{000D}\x{0020}\x{00A0}\x{1680}\x{2000}-\x{200A}\x{2028}\x{2029}\x{202F}\x{205F}\x{3000}\x{FEFF}]';

	/*
	 * ---------------------------------------------------------------------
	 * Pure logic. No WordPress, no database, no globals.
	 * ---------------------------------------------------------------------
	 */

	/**
	 * The body every offset counts into: \r\n, then \r, become \n.
	 *
	 * @param string $body The stored body.
	 */
	public static function canonical( string $body ): string {
		return str_replace( "\r", "\n", str_replace( "\r\n", "\n", $body ) );
	}

	/**
	 * Length in code points.
	 *
	 * @param string $text Any text.
	 */
	public static function length( string $text ): int {
		return function_exists( 'mb_strlen' ) ? mb_strlen( $text, 'UTF-8' ) : count( self::points( $text ) );
	}

	/**
	 * md5 of the canonical body.
	 *
	 * The teacher's card carries it and the save route checks it, so a mark is
	 * never saved against text the student changed while the page was open.
	 *
	 * @param string $body The stored body.
	 */
	public static function hash( string $body ): string {
		return md5( self::canonical( $body ) );
	}

	/**
	 * Stored JSON to a list of marks. Never throws.
	 *
	 * null, '', invalid JSON and anything that is not a list all come back as
	 * an empty array, which is what "no notes" means. An element that is not a
	 * well-formed mark is dropped, not repaired.
	 *
	 * @param string|null $json The marks column.
	 *
	 * @return array<int, array{start:int, end:int, quote:string, note:string}>
	 */
	public static function decode( ?string $json ): array {
		if ( null === $json || '' === $json ) {
			return array();
		}

		$list = json_decode( $json, true );

		if ( ! is_array( $list ) || ! self::is_list( $list ) ) {
			return array();
		}

		$marks = array();

		foreach ( $list as $mark ) {
			if ( self::well_formed( $mark ) ) {
				$marks[] = self::clean( $mark );
			}
		}

		return $marks;
	}

	/**
	 * Does this row carry at least one mark?
	 *
	 * @param array|null $row A row from the table, or null when there is none.
	 */
	public static function has_marks( ?array $row ): bool {
		if ( ! is_array( $row ) || ! isset( $row['marks'] ) || ! is_string( $row['marks'] ) ) {
			return false;
		}

		return array() !== self::decode( $row['marks'] );
	}

	/**
	 * Validate what the browser sent against the stored body.
	 *
	 * Each note has already been through sanitize_text_field() and trim() at
	 * the call site, which keeps this pure. An empty list is valid: it clears
	 * every mark.
	 *
	 * @param mixed  $raw  The list as it arrived — an array, or a JSON string.
	 * @param string $body The stored body.
	 *
	 * @return array|string The clean list sorted by start, or one error code:
	 *                      not_a_list, too_many, bad_range, quote_mismatch,
	 *                      crosses_line, untrimmed, overlap, note_empty,
	 *                      note_too_long.
	 */
	public static function check( $raw, string $body ) {
		if ( is_string( $raw ) ) {
			$raw = json_decode( $raw, true );
		}

		if ( ! is_array( $raw ) || ! self::is_list( $raw ) ) {
			return 'not_a_list';
		}

		if ( count( $raw ) > self::MAX_MARKS ) {
			return 'too_many';
		}

		$points = self::points( self::canonical( $body ) );
		$clean  = array();

		foreach ( $raw as $mark ) {
			if ( ! is_array( $mark ) ) {
				return 'bad_range';
			}

			$problem = self::problem( $mark, $points );
			if ( '' !== $problem ) {
				return $problem;
			}

			$note = isset( $mark['note'] ) && is_string( $mark['note'] ) ? trim( $mark['note'] ) : '';

			if ( '' === $note ) {
				return 'note_empty';
			}

			if ( self::length( $note ) > self::NOTE_MAX ) {
				return 'note_too_long';
			}

			$clean[] = array(
				'start' => $mark['start'],
				'end'   => $mark['end'],
				'quote' => $mark['quote'],
				'note'  => $note,
			);
		}

		$clean = self::sorted( $clean );

		for ( $i = 1, $n = count( $clean ); $i < $n; $i++ ) {
			if ( $clean[ $i ]['start'] < $clean[ $i - 1 ]['end'] ) {
				return 'overlap';
			}
		}

		return $clean;
	}

	/**
	 * Split the canonical body into plain and marked pieces, in order.
	 *
	 * The one source of truth both renderers follow. A mark that does not fit
	 * the body — out of range, a quote that no longer matches, across a line,
	 * untrimmed, or overlapping one before it — is left out rather than drawn
	 * on the wrong words.
	 *
	 * @param string $body  The stored body.
	 * @param array  $marks Decoded marks.
	 *
	 * @return array<int, array{text:string, mark:array|null}>
	 */
	public static function segments( string $body, array $marks ): array {
		$points = self::points( self::canonical( $body ) );
		$length = count( $points );
		$usable = array();

		foreach ( $marks as $mark ) {
			if ( is_array( $mark ) && isset( $mark['note'] ) && is_string( $mark['note'] ) && '' === self::problem( $mark, $points ) ) {
				$usable[] = $mark;
			}
		}

		$segments = array();
		$pos      = 0;

		foreach ( self::sorted( $usable ) as $mark ) {
			if ( $mark['start'] < $pos ) {
				continue;
			}

			if ( $mark['start'] > $pos ) {
				$segments[] = array(
					'text' => implode( '', array_slice( $points, $pos, $mark['start'] - $pos ) ),
					'mark' => null,
				);
			}

			$segments[] = array(
				'text' => implode( '', array_slice( $points, $mark['start'], $mark['end'] - $mark['start'] ) ),
				'mark' => $mark,
			);

			$pos = $mark['end'];
		}

		if ( $pos < $length || ! $segments ) {
			$segments[] = array(
				'text' => implode( '', array_slice( $points, $pos ) ),
				'mark' => null,
			);
		}

		return $segments;
	}

	/**
	 * A string as a list of code points.
	 *
	 * @param string $text Any text.
	 *
	 * @return array<int, string>
	 */
	public static function points( string $text ): array {
		if ( '' === $text ) {
			return array();
		}

		$points = preg_split( '//u', $text, -1, PREG_SPLIT_NO_EMPTY );

		// Invalid UTF-8 cannot be split by code point. Bodies arrive through
		// sanitize_textarea_field(), which refuses it, so this is a guard.
		return is_array( $points ) ? $points : str_split( $text );
	}

	/**
	 * Is this one code point whitespace?
	 *
	 * @param string $point One code point.
	 */
	public static function is_space( string $point ): bool {
		return (bool) preg_match( '/^' . self::SPACE_CLASS . '$/u', $point );
	}

	/**
	 * The quote up to its first whitespace — what the anchor holds.
	 *
	 * @param string $quote A mark's quote.
	 */
	public static function first_word( string $quote ): string {
		return preg_match( '/^(?:(?!' . self::SPACE_CLASS . ').)+/u', $quote, $match ) ? $match[0] : $quote;
	}

	/**
	 * What is wrong with one mark's range and quote, or ''.
	 *
	 * @param array $mark   One mark.
	 * @param array $points The canonical body, as code points.
	 */
	private static function problem( array $mark, array $points ): string {
		$start = $mark['start'] ?? null;
		$end   = $mark['end'] ?? null;

		if ( ! is_int( $start ) || ! is_int( $end ) || $start < 0 || $start >= $end || $end > count( $points ) ) {
			return 'bad_range';
		}

		$words = array_slice( $points, $start, $end - $start );

		if ( ! isset( $mark['quote'] ) || ! is_string( $mark['quote'] ) || implode( '', $words ) !== $mark['quote'] ) {
			return 'quote_mismatch';
		}

		if ( in_array( "\n", $words, true ) ) {
			return 'crosses_line';
		}

		if ( self::is_space( $words[0] ) || self::is_space( $words[ count( $words ) - 1 ] ) ) {
			return 'untrimmed';
		}

		return '';
	}

	/**
	 * Is this element a mark at all, before it meets any body?
	 *
	 * @param mixed $mark One stored element.
	 */
	private static function well_formed( $mark ): bool {
		return is_array( $mark )
			&& isset( $mark['start'], $mark['end'], $mark['quote'], $mark['note'] )
			&& is_int( $mark['start'] )
			&& is_int( $mark['end'] )
			&& $mark['start'] >= 0
			&& $mark['end'] > $mark['start']
			&& is_string( $mark['quote'] )
			&& '' !== $mark['quote']
			&& is_string( $mark['note'] )
			&& '' !== $mark['note'];
	}

	/**
	 * Exactly the four stored fields.
	 *
	 * @param array $mark A well-formed mark.
	 */
	private static function clean( array $mark ): array {
		return array(
			'start' => $mark['start'],
			'end'   => $mark['end'],
			'quote' => $mark['quote'],
			'note'  => $mark['note'],
		);
	}

	/**
	 * Sorted by start, then end. usort is stable from PHP 8.0.
	 *
	 * @param array $marks Marks.
	 */
	private static function sorted( array $marks ): array {
		usort(
			$marks,
			static function ( $a, $b ) {
				return ( $a['start'] <=> $b['start'] ) ?: ( $a['end'] <=> $b['end'] );
			}
		);

		return $marks;
	}

	/**
	 * Keys 0..n-1 in order. array_is_list() is PHP 8.1.
	 *
	 * @param array $list Any array.
	 */
	private static function is_list( array $list ): bool {
		$i = 0;

		foreach ( $list as $key => $unused ) {
			if ( $key !== $i ) {
				return false;
			}
			++$i;
		}

		return true;
	}

	/*
	 * ---------------------------------------------------------------------
	 * Rendering. Uses WordPress escaping; not under test.
	 * ---------------------------------------------------------------------
	 */

	/**
	 * The body as HTML, with its marks.
	 *
	 * A plain piece is esc_html() with each \n written as "<br />\n" — what
	 * nl2br() produces, so the newline stays in the text and the teacher's
	 * script counts it. With no marks, this is byte for byte what
	 * nl2br( esc_html( $body ) ) produces on a canonical body.
	 *
	 * A marked piece carries its note in data-note and in title. The title is
	 * the no-JavaScript fallback: hovering a highlight shows its note. The
	 * script removes it once the note layer is drawn.
	 *
	 * @param string $body  The stored body.
	 * @param array  $marks Decoded marks.
	 */
	public static function render( string $body, array $marks ): string {
		$html = '';

		foreach ( self::segments( $body, $marks ) as $segment ) {
			if ( null === $segment['mark'] ) {
				$html .= str_replace( "\n", "<br />\n", esc_html( $segment['text'] ) );
				continue;
			}

			$mark  = $segment['mark'];
			$quote = $segment['text'];
			$first = self::first_word( $quote );
			$note  = esc_attr( $mark['note'] );

			$html .= sprintf(
				'<mark class="tbth-mark" data-tbth-mark data-start="%1$d" data-end="%2$d" data-note="%3$s" title="%3$s">' .
				'<span class="tbth-mark__anchor"><span class="tbth-mark__strut" aria-hidden="true"></span>%4$s</span>%5$s</mark>',
				$mark['start'],
				$mark['end'],
				$note,
				esc_html( $first ),
				esc_html( substr( $quote, strlen( $first ) ) )
			);
		}

		return $html;
	}

	/**
	 * Kalam, the marks stylesheet and the marks script.
	 *
	 * Called only by the three places a body with marks can appear: the
	 * teacher's queue, the student's library, and the card under the lesson.
	 */
	public static function enqueue(): void {
		static $done = false;

		wp_enqueue_style(
			'tbt-homework-kalam',
			'https://fonts.googleapis.com/css2?family=Kalam:wght@400&display=swap',
			array(),
			null // phpcs:ignore WordPress.WP.EnqueuedResourceParameters.MissingVersion -- Google sets its own.
		);

		wp_enqueue_style(
			'tbt-homework-marks',
			TBT_HOMEWORK_URL . 'assets/css/tbt-homework-marks.css',
			array( 'tbt-homework', 'tbt-homework-kalam' ),
			TBT_HOMEWORK_VERSION
		);

		wp_enqueue_script(
			'tbt-homework-marks',
			TBT_HOMEWORK_URL . 'assets/js/tbt-homework-marks.js',
			array(),
			TBT_HOMEWORK_VERSION,
			true
		);

		if ( $done ) {
			return;
		}
		$done = true;

		wp_localize_script(
			'tbt-homework-marks',
			'TBTHomeworkMarksData',
			array(
				'noteWidth' => self::NOTE_WIDTH,
				'i18n'      => array(
					'readMore' => __( 'Read the full note', 'tbt-homework' ),
					/* translators: %s: the teacher's note. */
					'editNote' => __( 'Edit note: %s', 'tbt-homework' ),
				),
			)
		);
	}
}
