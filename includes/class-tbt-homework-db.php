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
 * The one table, and the handful of things the routes and the shortcode need
 * from it.
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
	 * Every row belonging to one student, newest first.
	 *
	 * Keyed on user_id, so this can only ever return the caller's own work.
	 * The rows come back whole; deciding which of them the student may still
	 * see is not this file's job — that is the lesson brief's answer, applied
	 * by the caller.
	 *
	 * @param int $user_id The student.
	 *
	 * @return array<int, array> Rows as associative arrays.
	 */
	public static function get_submissions_for_student( int $user_id ): array {
		global $wpdb;

		$table = self::table();

		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.DirectDatabaseQuery
		$rows = $wpdb->get_results(
			$wpdb->prepare(
				// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
				"SELECT * FROM `{$table}` WHERE user_id = %d ORDER BY submitted_at DESC, id DESC",
				$user_id
			),
			ARRAY_A
		);

		return is_array( $rows ) ? $rows : array();
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

	/*
	 * ---------------------------------------------------------------------
	 * The teacher's queue.
	 *
	 * Every read below is bounded by class_scope(), which is built from
	 * tbt_notes_class_ids_for_manager() at the call site. No id from the
	 * browser ever reaches a WHERE clause, and there is no join into Notes'
	 * tables: a submission whose class has since been deleted simply stops
	 * matching, because its class_id is no longer in the set.
	 * ---------------------------------------------------------------------
	 */

	/**
	 * One row by id, for the comment route.
	 *
	 * Deliberately unscoped: the route reads the row first so a bad id can
	 * answer 404, then checks the row's own class_id against the teacher's
	 * classes. The check is against the stored row, never against anything
	 * the browser sent.
	 *
	 * @param int $id The submission id.
	 */
	public static function get_row( int $id ): ?array {
		global $wpdb;

		$table = self::table();

		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.DirectDatabaseQuery
		$row = $wpdb->get_row(
			$wpdb->prepare(
				// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
				"SELECT * FROM `{$table}` WHERE id = %d",
				$id
			),
			ARRAY_A
		);

		return $row ? $row : null;
	}

	/**
	 * How many rows there are in scope, and how many of them are waiting.
	 *
	 * Both numbers in one query: the pill counts what is waiting whatever the
	 * dropdown is showing, and the summary line needs the total to say "4 of
	 * 37", so neither can be derived from the page in hand.
	 *
	 * @param array $class_ids The classes this teacher manages.
	 *
	 * @return array{total:int, waiting:int}
	 */
	public static function scope_totals( array $class_ids ): array {
		global $wpdb;

		$scope = self::class_scope( $class_ids );
		if ( '' === $scope['sql'] ) {
			return array(
				'total'   => 0,
				'waiting' => 0,
			);
		}

		$table = self::table();

		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.DirectDatabaseQuery
		$row = $wpdb->get_row(
			$wpdb->prepare(
				// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
				"SELECT COUNT(*) AS total, SUM( CASE WHEN comment IS NULL THEN 1 ELSE 0 END ) AS waiting
				FROM `{$table}` WHERE {$scope['sql']}",
				$scope['ids']
			),
			ARRAY_A
		);

		return array(
			'total'   => (int) ( $row['total'] ?? 0 ),
			'waiting' => (int) ( $row['waiting'] ?? 0 ),
		);
	}

	/**
	 * Every distinct student and lesson in scope.
	 *
	 * Asked for only when a search is running. The search matches names and
	 * titles, which live in WordPress and in TBT Notes rather than in this
	 * table, so those have to be resolved to ids before the rows query can
	 * page over the result honestly.
	 *
	 * @param array $class_ids The classes this teacher manages.
	 *
	 * @return array{user_ids:array<int,int>, lesson_ids:array<int,int>}
	 */
	public static function scope_pairs( array $class_ids ): array {
		global $wpdb;

		$scope = self::class_scope( $class_ids );
		if ( '' === $scope['sql'] ) {
			return array(
				'user_ids'   => array(),
				'lesson_ids' => array(),
			);
		}

		$table = self::table();

		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.DirectDatabaseQuery
		$rows = $wpdb->get_results(
			$wpdb->prepare(
				// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
				"SELECT DISTINCT user_id, lesson_id FROM `{$table}` WHERE {$scope['sql']}",
				$scope['ids']
			),
			ARRAY_A
		);

		$users   = array();
		$lessons = array();

		foreach ( is_array( $rows ) ? $rows : array() as $row ) {
			$user_id   = (int) $row['user_id'];
			$lesson_id = (int) $row['lesson_id'];

			if ( $user_id > 0 ) {
				$users[ $user_id ] = $user_id;
			}

			if ( $lesson_id > 0 ) {
				$lessons[ $lesson_id ] = $lesson_id;
			}
		}

		return array(
			'user_ids'   => array_values( $users ),
			'lesson_ids' => array_values( $lessons ),
		);
	}

	/**
	 * How many rows the current filter and search match.
	 *
	 * @param array  $class_ids  The classes this teacher manages.
	 * @param string $filter     'waiting', 'commented' or 'all'.
	 * @param string $search     Trimmed search text; '' for none.
	 * @param array  $user_ids   Students whose name matches the search.
	 * @param array  $lesson_ids Lessons whose title or class matches it.
	 */
	public static function count_queue( array $class_ids, string $filter, string $search, array $user_ids = array(), array $lesson_ids = array() ): int {
		global $wpdb;

		$where = self::queue_where( $class_ids, $filter, $search, $user_ids, $lesson_ids );
		if ( null === $where ) {
			return 0;
		}

		$table = self::table();

		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.DirectDatabaseQuery
		return (int) $wpdb->get_var(
			$wpdb->prepare(
				// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
				"SELECT COUNT(*) FROM `{$table}` WHERE {$where['sql']}",
				$where['args']
			)
		);
	}

	/**
	 * One page of the queue, newest first.
	 *
	 * ORDER BY submitted_at DESC, id DESC — always, whatever the filter, so
	 * the page opens on the newest thing nobody has replied to.
	 *
	 * @param array  $class_ids  The classes this teacher manages.
	 * @param string $filter     'waiting', 'commented' or 'all'.
	 * @param string $search     Trimmed search text; '' for none.
	 * @param array  $user_ids   Students whose name matches the search.
	 * @param array  $lesson_ids Lessons whose title or class matches it.
	 * @param int    $limit      Page size.
	 * @param int    $offset     Rows to skip.
	 *
	 * @return array<int, array> Rows as associative arrays.
	 */
	public static function get_queue( array $class_ids, string $filter, string $search, array $user_ids, array $lesson_ids, int $limit, int $offset ): array {
		global $wpdb;

		$where = self::queue_where( $class_ids, $filter, $search, $user_ids, $lesson_ids );
		if ( null === $where ) {
			return array();
		}

		$table = self::table();
		$args  = array_merge( $where['args'], array( max( 1, $limit ), max( 0, $offset ) ) );

		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.DirectDatabaseQuery
		$rows = $wpdb->get_results(
			$wpdb->prepare(
				// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
				"SELECT * FROM `{$table}` WHERE {$where['sql']}
				ORDER BY submitted_at DESC, id DESC
				LIMIT %d OFFSET %d",
				$args
			),
			ARRAY_A
		);

		return is_array( $rows ) ? $rows : array();
	}

	/**
	 * Write, replace or clear one comment.
	 *
	 * commented_at is set on the first comment only — COALESCE keeps the
	 * original, so commenting twice replaces the text and leaves the date
	 * alone. updated_at is always touched. Passing null clears both, which
	 * returns the row to waiting and lets the student edit again.
	 *
	 * The caller has already checked that this row's class is one the caller
	 * manages; this method takes the id it is given.
	 *
	 * @param int         $id      The submission id.
	 * @param string|null $comment Sanitised plain text, or null to clear.
	 *
	 * @return array|null The stored row, or null if the write failed.
	 */
	public static function save_comment( int $id, ?string $comment ): ?array {
		global $wpdb;

		$table = self::table();
		$now   = current_time( 'mysql', true );

		if ( null === $comment ) {
			// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.DirectDatabaseQuery
			$result = $wpdb->query(
				$wpdb->prepare(
					// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
					"UPDATE `{$table}` SET comment = NULL, commented_at = NULL, updated_at = %s WHERE id = %d",
					$now,
					$id
				)
			);
		} else {
			// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.DirectDatabaseQuery
			$result = $wpdb->query(
				$wpdb->prepare(
					// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
					"UPDATE `{$table}`
					SET comment = %s, commented_at = COALESCE( commented_at, %s ), updated_at = %s
					WHERE id = %d",
					$comment,
					$now,
					$now,
					$id
				)
			);
		}

		if ( false === $result ) {
			return null;
		}

		return self::get_row( $id );
	}

	/**
	 * The scope clause and the ids that fill it.
	 *
	 * Ids are cast, deduplicated and reindexed, and anything that is not a
	 * positive integer is dropped: what comes back is a placeholder for each
	 * surviving id and that id, in the same order, never a value interpolated
	 * into SQL. An empty set yields an empty clause, and every caller treats
	 * that as "nothing in scope" rather than as "no WHERE at all".
	 *
	 * @param array $class_ids The classes this teacher manages.
	 *
	 * @return array{sql:string, ids:array<int,int>}
	 */
	public static function class_scope( array $class_ids ): array {
		$clean = array();

		foreach ( $class_ids as $id ) {
			$id = (int) $id;
			if ( $id > 0 ) {
				$clean[ $id ] = $id;
			}
		}

		if ( ! $clean ) {
			return array(
				'sql' => '',
				'ids' => array(),
			);
		}

		$ids = array_values( $clean );

		return array(
			'sql' => 'class_id IN ( ' . implode( ', ', array_fill( 0, count( $ids ), '%d' ) ) . ' )',
			'ids' => $ids,
		);
	}

	/**
	 * Scope, status and search, as one prepared clause.
	 *
	 * Returns null when nothing is in scope, which is the one case where no
	 * query should run at all.
	 *
	 * @param array  $class_ids  The classes this teacher manages.
	 * @param string $filter     'waiting', 'commented' or 'all'.
	 * @param string $search     Trimmed search text; '' for none.
	 * @param array  $user_ids   Students whose name matches the search.
	 * @param array  $lesson_ids Lessons whose title or class matches it.
	 *
	 * @return array{sql:string, args:array}|null
	 */
	private static function queue_where( array $class_ids, string $filter, string $search, array $user_ids, array $lesson_ids ): ?array {
		global $wpdb;

		$scope = self::class_scope( $class_ids );
		if ( '' === $scope['sql'] ) {
			return null;
		}

		$sql  = $scope['sql'];
		$args = $scope['ids'];

		if ( 'waiting' === $filter ) {
			$sql .= ' AND comment IS NULL';
		} elseif ( 'commented' === $filter ) {
			$sql .= ' AND comment IS NOT NULL';
		}

		if ( '' !== $search ) {
			$like = '%' . $wpdb->esc_like( $search ) . '%';

			// The submission and the comment are here; the student's name and
			// the lesson and class titles are not, so they arrive already
			// resolved to ids.
			$parts = array( 'body LIKE %s', 'comment LIKE %s' );
			$args  = array_merge( $args, array( $like, $like ) );

			$users = self::positive_ids( $user_ids );
			if ( $users ) {
				$parts[] = 'user_id IN ( ' . implode( ', ', array_fill( 0, count( $users ), '%d' ) ) . ' )';
				$args    = array_merge( $args, $users );
			}

			$lessons = self::positive_ids( $lesson_ids );
			if ( $lessons ) {
				$parts[] = 'lesson_id IN ( ' . implode( ', ', array_fill( 0, count( $lessons ), '%d' ) ) . ' )';
				$args    = array_merge( $args, $lessons );
			}

			$sql .= ' AND ( ' . implode( ' OR ', $parts ) . ' )';
		}

		return array(
			'sql'  => $sql,
			'args' => $args,
		);
	}

	/**
	 * Cast, drop and deduplicate a list of ids.
	 *
	 * @param array $ids Ids in any shape they arrived in.
	 *
	 * @return array<int, int>
	 */
	public static function positive_ids( array $ids ): array {
		$clean = array();

		foreach ( $ids as $id ) {
			$id = (int) $id;
			if ( $id > 0 ) {
				$clean[ $id ] = $id;
			}
		}

		return array_values( $clean );
	}
}
