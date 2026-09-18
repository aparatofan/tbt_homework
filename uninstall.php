<?php
/**
 * Runs on delete, never on deactivate.
 *
 * Deletion is the only destructive path in this plugin, matching how TBT Notes
 * does it: deactivating keeps the table and every row in it.
 *
 * @package TBT_Homework
 */

defined( 'WP_UNINSTALL_PLUGIN' ) || exit;

global $wpdb;

$tbt_homework_table = $wpdb->prefix . 'tbt_homework';

// Table names cannot be passed through prepare(); the name is built from the
// trusted $wpdb prefix and a literal.
// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.DirectDatabaseQuery
$wpdb->query( "DROP TABLE IF EXISTS `{$tbt_homework_table}`" );

delete_option( 'tbt_homework_db_version' );
