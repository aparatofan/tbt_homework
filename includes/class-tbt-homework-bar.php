<?php
/**
 * The Admin Bar's shared parts.
 *
 * The bar is one stylesheet both library pages read, so that the student's and
 * the teacher's cannot drift apart. The two pieces of its markup that are the
 * same on both pages — the decorative line and the search field, with its icon
 * and its × — are here for exactly that reason. Everything that genuinely
 * differs between the two bars, the title, the count and the dropdown's own
 * lines, stays on the page that owns it.
 *
 * Nothing here queries anything or reads any state. It returns markup.
 *
 * @package TBT_Homework
 */

defined( 'ABSPATH' ) || exit;

/**
 * The parts of the Admin Bar that both libraries draw the same way.
 */
class TBT_Homework_Bar {

	/**
	 * One of the bar's three lines.
	 *
	 * After the title, between the search and the dropdown, and from the
	 * dropdown to the end of the row. Every one of them is decoration: the
	 * flex rule gives them the width, and the reader is told nothing by them.
	 *
	 * @param bool $end Whether this is the line that runs to the end of the row.
	 */
	public static function line( bool $end = false ): string {
		return sprintf(
			'<span class="tbth-libbar__line%s" aria-hidden="true"></span>',
			$end ? ' tbth-libbar__line--end' : ''
		);
	}

	/**
	 * The search field: the magnifier, the input, and the × that clears it.
	 *
	 * type="text" rather than type="search", which is the choice tbt-students
	 * made and wrote down: every engine draws the search input's own clear
	 * affordance differently, none of them can be styled to match the bar, and
	 * two crosses in one field is worse than none. The bar supplies its own.
	 *
	 * The × ships hidden. The page's script shows it while there is text, and
	 * a page with no script simply never has one — the field still types and,
	 * on the teacher's queue, still submits.
	 *
	 * @param string $label The accessible name, which is also the placeholder.
	 * @param string $name  The query parameter to submit under, or '' for a
	 *                      field that is filtered in the browser and submits
	 *                      nothing.
	 * @param string $value The text the field opens with.
	 */
	public static function search_field( string $label, string $name = '', string $value = '' ): string {
		$html = '<div class="tbth-libbar__search">';

		$html .= self::icon();

		$html .= sprintf(
			'<input type="text" class="tbt-input tbth-libbar__input" data-tbth-search%1$s value="%2$s" placeholder="%3$s" aria-label="%3$s" autocomplete="off" spellcheck="false">',
			'' === $name ? '' : sprintf( ' name="%s"', esc_attr( $name ) ),
			esc_attr( $value ),
			esc_attr( $label )
		);

		$html .= sprintf(
			'<button type="button" class="tbth-libbar__clear" data-tbth-clear aria-label="%s" hidden>&times;</button>',
			esc_attr__( 'Clear search', 'tbt-homework' )
		);

		$html .= '</div>';

		return $html;
	}

	/**
	 * The magnifier, in TBT Blue and out of the accessibility tree.
	 *
	 * It sits inside the field's 300px, and the field's left padding is what
	 * makes room for it; it takes no pointer events, so clicking it puts the
	 * cursor in the box behind it.
	 */
	private static function icon(): string {
		return '<svg class="tbth-libbar__icon" width="18" height="18" viewBox="0 0 24 24" fill="none" aria-hidden="true" focusable="false">' .
			'<circle cx="11" cy="11" r="7" stroke="currentColor" stroke-width="2.2"/>' .
			'<path d="m20 20-3.6-3.6" stroke="currentColor" stroke-width="2.2" stroke-linecap="round"/>' .
			'</svg>';
	}
}
