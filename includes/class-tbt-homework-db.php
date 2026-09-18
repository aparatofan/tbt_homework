<?php
/**
 * Schema and row access.
 *
 * Every query in this plugin lives in this file. No other file writes SQL, and
 * nothing here ever reads a TBT Notes table.
 *
 * @package TBT_Homework
 */

defined( 'ABSPATH' ) || exit;

/**
 * The one table, and the two things the routes need from it.
 */
class TBT_Homework_DB {

	/**
	 * Fully qualified table name.
	 */
	public static function table(): string {
		global $wpdb;

		return $wpdb->prefix . 'tbt_homework';
	}

	/**
	 * Create or update the table.
	 *
	 * dbDelta is fussy: lowercase types, two spaces after PRIMARY KEY, KEY
	 * rather than INDEX, one field per line. `comment` is nullable because
	 * longtext cannot carry a DEFAULT in MySQL, and NULL is what "nobody has
	 * commented yet" means anyway.
	 */
	public static function install(): void {
		global $wpdb;

		require_once ABSPATH . 'wp-admin/includes/upgrade.php';

		$table           = self::table();
		$charset_collate = $wpdb->get_charset_collate();

		$sql = "CREATE TABLE {$table} (
			id bigint(20) unsigned NOT NULL auto_increment,
			user_id bigint(20) unsigned NOT NULL,
			class_id bigint(20) unsigned NOT NULL,
			lesson_id bigint(20) unsigned NOT NULL,
			body longtext NOT NULL,
			attachment varchar(255) NOT NULL default '',
			comment longtext NULL,
			commented_at datetime NULL,
			submitted_at datetime NOT NULL,
			updated_at datetime NOT NULL,
			PRIMARY KEY  (id),
			UNIQUE KEY student_lesson (user_id, lesson_id),
			KEY class_pending (class_id, commented_at),
			KEY lesson (lesson_id)
		) {$charset_collate};";

		dbDelta( $sql );
	}

	/**
	 * One student's row for one lesson, or null.
	 *
	 * The lookup is keyed on user_id, which is why no route in this release can
	 * return somebody else's work even by accident.
	 *
	 * @param int $user_id   The student.
	 * @param int $lesson_id The lesson.
	 */
	public static function get_submission( int $user_id, int $lesson_id ): ?array {
		global $wpdb;

		$table = self::table();

		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.DirectDatabaseQuery
		$row = $wpdb->get_row(
			$wpdb->prepare(
				// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
				"SELECT * FROM `{$table}` WHERE user_id = %d AND lesson_id = %d",
				$user_id,
				$lesson_id
			),
			ARRAY_A
		);

		return $row ? $row : null;
	}

	/**
	 * Create or update one student's row for one lesson.
	 *
	 * A single INSERT ... ON DUPLICATE KEY UPDATE against the student_lesson
	 * unique key, rather than a read followed by a write: a double-tap on Send
	 * cannot produce two rows. submitted_at is set on insert only; updated_at
	 * always. comment and commented_at are never touched here — they belong to
	 * the teacher, in a later release.
	 *
	 * $class_id comes from tbt_notes_lesson_context() at the call site and
	 * never from the browser.
	 *
	 * @param int    $user_id   The student.
	 * @param int    $class_id  Copied in from the lesson context.
	 * @param int    $lesson_id The lesson.
	 * @param string $body      Sanitised, trimmed plain text.
	 *
	 * @return array|null The stored row, or null if the write failed.
	 */
	public static function upsert_submission( int $user_id, int $class_id, int $lesson_id, string $body ): ?array {
		global $wpdb;

		$table = self::table();
		$now   = current_time( 'mysql', true );

		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.DirectDatabaseQuery
		$result = $wpdb->query(
			$wpdb->prepare(
				// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
				"INSERT INTO `{$table}`
					(user_id, class_id, lesson_id, body, attachment, submitted_at, updated_at)
				VALUES (%d, %d, %d, %s, '', %s, %s)
				ON DUPLICATE KEY UPDATE
					class_id = VALUES(class_id),
					body = VALUES(body),
					updated_at = VALUES(updated_at)",
				$user_id,
				$class_id,
				$lesson_id,
				$body,
				$now,
				$now
			)
		);

		if ( false === $result ) {
			return null;
		}

		return self::get_submission( $user_id, $lesson_id );
	}
}
