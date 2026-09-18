<?php
/**
 * Asset enqueue.
 *
 * @package TBT_Homework
 */

defined( 'ABSPATH' ) || exit;

/**
 * Puts the script and stylesheet on exactly the pages that have a lesson on
 * them, and nowhere else.
 */
class TBT_Homework_Frontend {

	/**
	 * Hang on Notes' own asset hook.
	 *
	 * Not wp_enqueue_scripts: that would put the script on every page of the
	 * site. tbt_notes_frontend_assets fires only where TBT Notes actually
	 * loads, which is the third half of the dependency guard — no TBT Notes,
	 * no hook, no assets.
	 */
	public static function init(): void {
		add_action( 'tbt_notes_frontend_assets', array( __CLASS__, 'enqueue' ), 10, 1 );
	}

	/**
	 * Enqueue the form.
	 *
	 * @param array $args Passed by TBT Notes: [ 'is_manager' => bool ].
	 */
	public static function enqueue( $args = array() ): void {
		if ( ! is_user_logged_in() ) {
			return;
		}

		// Teachers never get a slot, so they never need the script either.
		if ( is_array( $args ) && ! empty( $args['is_manager'] ) ) {
			return;
		}

		wp_enqueue_style(
			'tbt-homework',
			TBT_HOMEWORK_URL . 'assets/css/tbt-homework.css',
			array( 'tbt-components' ),
			TBT_HOMEWORK_VERSION
		);

		wp_enqueue_script(
			'tbt-homework',
			TBT_HOMEWORK_URL . 'assets/js/tbt-homework.js',
			array(),
			TBT_HOMEWORK_VERSION,
			true
		);

		wp_localize_script(
			'tbt-homework',
			'TBTHomeworkData',
			array(
				// The full endpoint URL, not a base to concatenate: on a site
				// with plain permalinks rest_url() returns ?rest_route=...,
				// which a naive join would corrupt.
				'submission' => esc_url_raw( rest_url( TBT_Homework_REST::REST_NAMESPACE . '/submission' ) ),
				'nonce'      => wp_create_nonce( 'wp_rest' ),
				'maxLength'  => TBT_Homework_REST::BODY_MAX,
				'i18n'       => self::strings(),
			)
		);
	}

	/**
	 * Every string the card can show.
	 */
	private static function strings(): array {
		return array(
			'heading'        => __( 'Homework', 'tbt-homework' ),
			'helper'         => __( 'Only your teacher can see this', 'tbt-homework' ),
			'placeholder'    => __( 'Write your homework here…', 'tbt-homework' ),
			'send'           => __( 'Send homework', 'tbt-homework' ),
			'save'           => __( 'Save changes', 'tbt-homework' ),
			'edit'           => __( 'Edit', 'tbt-homework' ),
			'cancel'         => __( 'Cancel', 'tbt-homework' ),
			'loading'        => __( 'Loading your homework…', 'tbt-homework' ),
			'saving'         => __( 'Saving…', 'tbt-homework' ),
			'retry'          => __( 'Retry', 'tbt-homework' ),
			'loadFailed'     => __( 'Your homework could not be loaded.', 'tbt-homework' ),
			'genericError'   => __( 'Something went wrong. Please try again.', 'tbt-homework' ),
			'emptyError'     => __( 'Write something before you send it.', 'tbt-homework' ),
			/* translators: %s: a date, such as 18 September. */
			'sent'           => __( 'Sent %s', 'tbt-homework' ),
			/* translators: %s: a date, such as 18 September. */
			'updated'        => __( 'Updated %s', 'tbt-homework' ),
			'commentEyebrow' => __( 'Your teacher’s comment', 'tbt-homework' ),
			'closed'         => __( 'Your teacher has commented, so this homework is closed for changes.', 'tbt-homework' ),
		);
	}
}
