<?php
/**
 * Plugin Name:       TBT Homework
 * Description:       Private homework handover between a student and their teacher, under a TBT Notes lesson.
 * Version:           0.2.0
 * Requires PHP:      8.0
 * Author:            TBT
 * License:           GPL-2.0-or-later
 * License URI:       https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain:       tbt-homework
 *
 * @package TBT_Homework
 */

defined( 'ABSPATH' ) || exit;

define( 'TBT_HOMEWORK_VERSION', '0.2.0' );
define( 'TBT_HOMEWORK_DB_VERSION', '1' );
define( 'TBT_HOMEWORK_DIR', plugin_dir_path( __FILE__ ) );
define( 'TBT_HOMEWORK_URL', plugin_dir_url( __FILE__ ) );

require_once TBT_HOMEWORK_DIR . 'includes/class-tbt-homework-db.php';
require_once TBT_HOMEWORK_DIR . 'includes/class-tbt-homework-rest.php';
require_once TBT_HOMEWORK_DIR . 'includes/class-tbt-homework-frontend.php';
require_once TBT_HOMEWORK_DIR . 'includes/class-tbt-homework-student.php';

/**
 * Create the table and remember the schema version.
 *
 * Activation is the only place the schema runs. Deactivation does nothing at
 * all: rows survive it, and survive a missing TBT Notes.
 */
function tbt_homework_activate(): void {
	TBT_Homework_DB::install();
	update_option( 'tbt_homework_db_version', TBT_HOMEWORK_DB_VERSION );
}
register_activation_hook( __FILE__, 'tbt_homework_activate' );

/**
 * Wire everything up, after TBT Notes has had its turn on plugins_loaded.
 */
function tbt_homework_bootstrap(): void {
	TBT_Homework_REST::init();
	TBT_Homework_Frontend::init();
	TBT_Homework_Student::init();

	add_action( 'admin_notices', 'tbt_homework_dependency_notice' );
}
add_action( 'plugins_loaded', 'tbt_homework_bootstrap', 20 );

/**
 * The admin half of the dependency guard.
 *
 * TBT Notes can be deactivated at any time, so the check happens here, at the
 * point of use, and never once at load. The REST routes answer 503 on their
 * own, and the front end simply never enqueues, because the Notes asset hook
 * does not fire. Nothing about this is fatal and no submission is lost by it.
 *
 * Shown on the Plugins screen only: that is where somebody has just switched
 * TBT Notes off, and it is a screen no student can open.
 */
function tbt_homework_dependency_notice(): void {
	if ( function_exists( 'tbt_notes_lesson_context' ) ) {
		return;
	}

	$screen = function_exists( 'get_current_screen' ) ? get_current_screen() : null;
	if ( ! $screen || ! in_array( $screen->id, array( 'plugins', 'plugins-network' ), true ) ) {
		return;
	}

	printf(
		'<div class="notice notice-warning is-dismissible"><p>%s</p></div>',
		esc_html__(
			'TBT Homework needs TBT Notes 1.18.0 or newer. Homework already submitted is safe and will reappear once TBT Notes is active again.',
			'tbt-homework'
		)
	);
}
