<?php
/**
 * Pure logic tests. No WordPress, no database.
 *
 *     php tests/test-logic.php
 *
 * Every file under test defines a class and nothing else at load time, so
 * defining ABSPATH is all it takes to pull the pure helpers in here. Every
 * function under test touches only its arguments.
 *
 * @package TBT_Homework
 */

define( 'ABSPATH', dirname( __DIR__ ) . '/' );

require_once dirname( __DIR__ ) . '/includes/class-tbt-homework-db.php';
require_once dirname( __DIR__ ) . '/includes/class-tbt-homework-marks.php';
require_once dirname( __DIR__ ) . '/includes/class-tbt-homework-rest.php';
require_once dirname( __DIR__ ) . '/includes/class-tbt-homework-student.php';
require_once dirname( __DIR__ ) . '/includes/class-tbt-homework-teacher.php';

$tests_run    = 0;
$tests_failed = 0;

/**
 * Assert that two values match.
 *
 * @param mixed  $expected What it should be.
 * @param mixed  $actual   What it is.
 * @param string $label    What is being checked.
 */
function tbth_assert( $expected, $actual, string $label ): void {
	global $tests_run, $tests_failed;

	++$tests_run;

	if ( $expected === $actual ) {
		echo "  ok    {$label}\n";
		return;
	}

	++$tests_failed;
	echo '  FAIL  ' . $label . ' — expected ' . var_export( $expected, true ) .
		', got ' . var_export( $actual, true ) . "\n";
}

/**
 * Build a lesson context the way tbt_notes_lesson_context() would.
 *
 * @param bool   $can_view   Whether the caller may view.
 * @param bool   $can_manage Whether the caller manages the class.
 * @param string $class_title Class title; empty when the class was deleted.
 */
function tbth_context( bool $can_view, bool $can_manage, string $class_title = 'Group B' ): array {
	return array(
		'lesson_id'    => 412,
		'class_id'     => 12,
		'lesson_title' => 'Past simple',
		'class_title'  => $class_title,
		'created_at'   => '2026-09-18 10:00:00',
		'can_view'     => $can_view,
		'can_manage'   => $can_manage,
	);
}

echo "\nlesson_id must be a positive integer\n";

tbth_assert( true, TBT_Homework_REST::is_valid_lesson_id( 412 ), 'a positive int' );
tbth_assert( true, TBT_Homework_REST::is_valid_lesson_id( '412' ), 'digits as a string' );
tbth_assert( true, TBT_Homework_REST::is_valid_lesson_id( ' 412 ' ), 'digits with whitespace' );
tbth_assert( true, TBT_Homework_REST::is_valid_lesson_id( 1 ), 'one' );
tbth_assert( false, TBT_Homework_REST::is_valid_lesson_id( 0 ), 'zero' );
tbth_assert( false, TBT_Homework_REST::is_valid_lesson_id( -412 ), 'a negative int' );
tbth_assert( false, TBT_Homework_REST::is_valid_lesson_id( '-412' ), 'a negative string' );
tbth_assert( false, TBT_Homework_REST::is_valid_lesson_id( '412abc' ), 'digits with a tail' );
tbth_assert( false, TBT_Homework_REST::is_valid_lesson_id( 'abc' ), 'letters' );
tbth_assert( false, TBT_Homework_REST::is_valid_lesson_id( '' ), 'an empty string' );
tbth_assert( false, TBT_Homework_REST::is_valid_lesson_id( '4.5' ), 'a decimal string' );
tbth_assert( false, TBT_Homework_REST::is_valid_lesson_id( 4.5 ), 'a float with a fraction' );
tbth_assert( false, TBT_Homework_REST::is_valid_lesson_id( null ), 'null' );
tbth_assert( false, TBT_Homework_REST::is_valid_lesson_id( true ), 'a boolean' );
tbth_assert( false, TBT_Homework_REST::is_valid_lesson_id( array( 412 ) ), 'an array' );

echo "\nthe body: empty after trim, and the length cap\n";

tbth_assert( 'ok', TBT_Homework_REST::check_body( 'I did the exercise.' ), 'ordinary text' );
tbth_assert( 'empty', TBT_Homework_REST::check_body( '' ), 'an empty string' );
tbth_assert( 'empty', TBT_Homework_REST::check_body( '   ' ), 'spaces only' );
tbth_assert( 'empty', TBT_Homework_REST::check_body( "\n\n\t  \r\n" ), 'whitespace and newlines only' );
tbth_assert( 'ok', TBT_Homework_REST::check_body( '  hello  ' ), 'text with surrounding space' );
tbth_assert( 'ok', TBT_Homework_REST::check_body( str_repeat( 'a', 20000 ) ), 'exactly the cap' );
tbth_assert( 'too_long', TBT_Homework_REST::check_body( str_repeat( 'a', 20001 ) ), 'one over the cap' );
tbth_assert(
	'ok',
	TBT_Homework_REST::check_body( '  ' . str_repeat( 'a', 20000 ) . '  ' ),
	'the cap is measured after trimming'
);
tbth_assert(
	'ok',
	TBT_Homework_REST::check_body( str_repeat( 'ą', 20000 ) ),
	'the cap counts characters, not bytes'
);
tbth_assert( 20000, TBT_Homework_REST::length( str_repeat( 'ą', 20000 ) ), 'length counts characters' );

echo "\nwhat a context's flags permit\n";

// Reading the row is not permission. These flags are.
tbth_assert( 'ok', TBT_Homework_REST::gate( tbth_context( true, false ), 'write' ), 'a student in the class may write' );
tbth_assert( 'ok', TBT_Homework_REST::gate( tbth_context( true, false ), 'read' ), 'a student in the class may read' );
tbth_assert( 'not_found', TBT_Homework_REST::gate( null, 'write' ), 'no such lesson, writing' );
tbth_assert( 'not_found', TBT_Homework_REST::gate( null, 'read' ), 'no such lesson, reading' );
tbth_assert(
	'forbidden',
	TBT_Homework_REST::gate( tbth_context( false, false ), 'write' ),
	'a student from another class may not write'
);
tbth_assert(
	'forbidden',
	TBT_Homework_REST::gate( tbth_context( false, false ), 'read' ),
	'a student from another class may not read'
);
tbth_assert(
	'forbidden',
	TBT_Homework_REST::gate( tbth_context( false, false, '' ), 'write' ),
	'a lesson whose class was deleted'
);
tbth_assert(
	'not_for_teachers',
	TBT_Homework_REST::gate( tbth_context( true, true ), 'write' ),
	'a teacher does not submit homework to themselves'
);
tbth_assert(
	'ok',
	TBT_Homework_REST::gate( tbth_context( true, true ), 'read' ),
	'a teacher may read the route, and finds nothing of their own'
);
tbth_assert(
	'forbidden',
	TBT_Homework_REST::gate( tbth_context( false, true ), 'write' ),
	'can_view is checked before can_manage'
);
tbth_assert( 'forbidden', TBT_Homework_REST::gate( array(), 'write' ), 'a context with no flags at all' );
tbth_assert(
	'ok',
	TBT_Homework_REST::gate( array( 'can_view' => true ), 'write' ),
	'can_view alone is enough to write'
);

echo "\ncomment IS NULL means new\n";

tbth_assert( false, TBT_Homework_REST::is_closed( null ), 'nothing sent yet is not closed' );
tbth_assert( false, TBT_Homework_REST::is_closed( array( 'comment' => null ) ), 'a null comment is not closed' );
tbth_assert( true, TBT_Homework_REST::is_closed( array( 'comment' => 'Well done' ) ), 'a comment closes the row' );
tbth_assert( true, TBT_Homework_REST::is_closed( array( 'comment' => '' ) ), 'even an empty comment closes the row' );
tbth_assert( false, TBT_Homework_REST::is_closed( array( 'body' => 'x' ) ), 'a row with no comment column' );


echo "\nbatching lesson ids for the brief\n";

// tbt_notes_lessons_brief() is capped at 200 ids per call.
tbth_assert( array(), TBT_Homework_Student::chunk_lesson_ids( array() ), 'no ids at all' );
tbth_assert(
	array( array( 1, 2, 3 ) ),
	TBT_Homework_Student::chunk_lesson_ids( array( 1, 2, 3 ) ),
	'a handful fits in one call'
);
tbth_assert(
	1,
	count( TBT_Homework_Student::chunk_lesson_ids( range( 1, 200 ) ) ),
	'exactly 200 is one call'
);
tbth_assert(
	2,
	count( TBT_Homework_Student::chunk_lesson_ids( range( 1, 201 ) ) ),
	'201 needs a second call'
);
tbth_assert(
	3,
	count( TBT_Homework_Student::chunk_lesson_ids( range( 1, 500 ) ) ),
	'500 needs three'
);
tbth_assert(
	200,
	count( TBT_Homework_Student::chunk_lesson_ids( range( 1, 500 ) )[0] ),
	'no batch exceeds the cap'
);
tbth_assert(
	array( array( 7, 9 ) ),
	TBT_Homework_Student::chunk_lesson_ids( array( 7, 9, 7, 9 ) ),
	'ids are asked for once each'
);
tbth_assert(
	array( array( 12 ) ),
	TBT_Homework_Student::chunk_lesson_ids( array( '12', 0, -3, 'x', null, 12 ) ),
	'only positive integers survive'
);
tbth_assert(
	array( array( 1, 2 ), array( 3 ) ),
	TBT_Homework_Student::chunk_lesson_ids( array( 1, 2, 3 ), 2 ),
	'the batch size is respected'
);

echo "\nthe brief decides what appears\n";

$tbth_rows = array(
	array( 'lesson_id' => 412, 'class_id' => 12, 'body' => 'newest', 'comment' => null, 'commented_at' => null, 'submitted_at' => '2026-09-18 10:00:00' ),
	array( 'lesson_id' => 500, 'class_id' => 99, 'body' => 'gone', 'comment' => null, 'commented_at' => null, 'submitted_at' => '2026-09-17 10:00:00' ),
	array( 'lesson_id' => 600, 'class_id' => 12, 'body' => 'oldest', 'comment' => 'Well done', 'commented_at' => '2026-09-16 12:00:00', 'submitted_at' => '2026-09-16 10:00:00' ),
);

$tbth_brief = array(
	412 => array( 'lesson_title' => 'Past simple', 'class_id' => 12, 'class_title' => 'Group B', 'created_at' => '2026-09-18 09:00:00' ),
	600 => array( 'lesson_title' => 'Articles', 'class_id' => 12, 'class_title' => 'Group B', 'created_at' => '2026-09-16 09:00:00' ),
);

$tbth_entries = TBT_Homework_Student::attach_brief( $tbth_rows, $tbth_brief );

// The brief omits every lesson the student may not view, so its silence is
// the permission answer.
tbth_assert( 2, count( $tbth_entries ), 'a lesson the brief omits does not appear' );
tbth_assert( 412, $tbth_entries[0]['lesson_id'], 'order is preserved, newest first' );
tbth_assert( 600, $tbth_entries[1]['lesson_id'], 'and the oldest stays last' );
tbth_assert( 'Past simple', $tbth_entries[0]['lesson_title'], 'the lesson is named from the brief' );
tbth_assert( 'Group B', $tbth_entries[0]['class_title'], 'the class is named from the brief' );
tbth_assert( 'waiting', $tbth_entries[0]['status'], 'no comment yet means waiting' );
tbth_assert( 'commented', $tbth_entries[1]['status'], 'a comment means commented' );
tbth_assert( null, $tbth_entries[0]['comment'], 'no comment comes back as null' );
tbth_assert( 'Well done', $tbth_entries[1]['comment'], "the teacher's comment is carried through" );
tbth_assert( null, $tbth_entries[0]['commented_at'], 'an empty comment date is null' );
tbth_assert( 'newest', $tbth_entries[0]['body'], 'the body is carried through untouched' );
tbth_assert( array(), TBT_Homework_Student::attach_brief( array(), $tbth_brief ), 'no rows, no entries' );
tbth_assert(
	array(),
	TBT_Homework_Student::attach_brief( $tbth_rows, array() ),
	'an empty brief hides everything'
);
tbth_assert(
	0,
	count( TBT_Homework_Student::attach_brief( array( array( 'lesson_id' => 0 ) ), $tbth_brief ) ),
	'a row with no lesson id is dropped'
);
tbth_assert(
	'',
	TBT_Homework_Student::attach_brief(
		array( array( 'lesson_id' => 412, 'body' => 'x' ) ),
		array( 412 => array( 'lesson_title' => 'Past simple' ) )
	)[0]['class_title'],
	'a brief entry with no class title yields an empty one, not a notice'
);

echo "\nstatus, on its own\n";

tbth_assert( 'waiting', TBT_Homework_Student::status_for( array( 'comment' => null ) ), 'a null comment is waiting' );
tbth_assert( 'waiting', TBT_Homework_Student::status_for( array( 'body' => 'x' ) ), 'no comment column is waiting' );
tbth_assert( 'commented', TBT_Homework_Student::status_for( array( 'comment' => 'Nice' ) ), 'a comment is commented' );
tbth_assert( 'commented', TBT_Homework_Student::status_for( array( 'comment' => '' ) ), 'even an empty comment is commented' );

echo "\nthe scope clause — the only thing bounding the teacher's queue\n";

// No id from the browser ever reaches a WHERE clause: what comes back is a
// placeholder per surviving id, and the ids, in the same order.
$tbth_scope = TBT_Homework_DB::class_scope( array( 12, 18 ) );
tbth_assert( 'class_id IN ( %d, %d )', $tbth_scope['sql'], 'one placeholder per class' );
tbth_assert( array( 12, 18 ), $tbth_scope['ids'], 'and the ids that fill them' );
tbth_assert( 'class_id IN ( %d )', TBT_Homework_DB::class_scope( array( 12 ) )['sql'], 'one class' );

// An empty set is "nothing in scope", never "no WHERE at all" — which is what
// anyone who is not a teacher has.
tbth_assert( '', TBT_Homework_DB::class_scope( array() )['sql'], 'no classes yields no clause' );
tbth_assert( array(), TBT_Homework_DB::class_scope( array() )['ids'], 'and no ids' );
tbth_assert(
	'',
	TBT_Homework_DB::class_scope( array( 0, -3, 'x', null ) )['sql'],
	'nothing usable is the same as nothing'
);
tbth_assert(
	array( 12 ),
	TBT_Homework_DB::class_scope( array( '12', 12, 0, -1, 'abc' ) )['ids'],
	'only positive integers survive, once each'
);
tbth_assert(
	2,
	substr_count( TBT_Homework_DB::class_scope( array( 4, 4, 9 ) )['sql'], '%d' ),
	'the placeholder count matches the ids, not the input'
);

tbth_assert( array( 5, 7 ), TBT_Homework_DB::positive_ids( array( 5, 7, 5, 0, -2 ) ), 'ids are cast, deduped and reindexed' );
tbth_assert( array(), TBT_Homework_DB::positive_ids( array() ), 'no ids at all' );

echo "\nthe row's class is checked against the teacher's classes\n";

// The check that keeps one teacher out of another's students.
tbth_assert( true, TBT_Homework_Teacher::in_scope( 12, array( 12, 18 ) ), 'a class you manage' );
tbth_assert( false, TBT_Homework_Teacher::in_scope( 99, array( 12, 18 ) ), 'a class you do not' );
tbth_assert( true, TBT_Homework_Teacher::in_scope( 18, array( '18' ) ), 'ids compare as integers' );
tbth_assert( false, TBT_Homework_Teacher::in_scope( 12, array() ), 'anyone who is not a teacher manages nothing' );
tbth_assert( false, TBT_Homework_Teacher::in_scope( 0, array( 12 ) ), 'a row with no class' );
tbth_assert( false, TBT_Homework_Teacher::in_scope( -12, array( -12 ) ), 'a negative class matches nothing' );

echo "\nthe dropdown has three values and Waiting is the default\n";

tbth_assert( 'waiting', TBT_Homework_Teacher::normalise_filter( 'waiting' ), 'waiting' );
tbth_assert( 'commented', TBT_Homework_Teacher::normalise_filter( 'commented' ), 'commented' );
tbth_assert( 'all', TBT_Homework_Teacher::normalise_filter( 'all' ), 'all' );
tbth_assert( 'waiting', TBT_Homework_Teacher::normalise_filter( null ), 'nothing asked for opens on waiting' );
tbth_assert( 'waiting', TBT_Homework_Teacher::normalise_filter( '' ), 'an empty value opens on waiting' );
tbth_assert( 'waiting', TBT_Homework_Teacher::normalise_filter( 'everything' ), 'a value that is not one of the three' );
tbth_assert( 'commented', TBT_Homework_Teacher::normalise_filter( ' COMMENTED ' ), 'case and space do not matter' );
tbth_assert( 'waiting', TBT_Homework_Teacher::normalise_filter( array( 'all' ) ), 'an array is not a filter' );

echo "\nthe summary line appears only when the reader narrowed the list\n";

// Waiting narrows the list, but it is not a choice the reader made.
tbth_assert( false, TBT_Homework_Teacher::filters_active( 'waiting', '' ), 'the page as it opens' );
tbth_assert( true, TBT_Homework_Teacher::filters_active( 'commented', '' ), 'a filter the reader chose' );
tbth_assert( true, TBT_Homework_Teacher::filters_active( 'all', '' ), 'all is a choice too' );
tbth_assert( true, TBT_Homework_Teacher::filters_active( 'waiting', 'ania' ), 'a search on the default filter' );
tbth_assert( false, TBT_Homework_Teacher::filters_active( 'waiting', '   ' ), 'spaces are not a search' );

echo "\nthe totals already answer every unsearched filter\n";

$tbth_totals = array( 'total' => 37, 'waiting' => 4 );

tbth_assert(
	4,
	TBT_Homework_Teacher::matched_from_totals( array( 'filter' => 'waiting', 'search' => '' ), $tbth_totals ),
	'waiting is one of the two numbers'
);
tbth_assert(
	33,
	TBT_Homework_Teacher::matched_from_totals( array( 'filter' => 'commented', 'search' => '' ), $tbth_totals ),
	'commented is what is left'
);
tbth_assert(
	37,
	TBT_Homework_Teacher::matched_from_totals( array( 'filter' => 'all', 'search' => '' ), $tbth_totals ),
	'all is the other'
);
tbth_assert(
	null,
	TBT_Homework_Teacher::matched_from_totals( array( 'filter' => 'waiting', 'search' => 'ania' ), $tbth_totals ),
	'a search needs a count of its own'
);
tbth_assert(
	0,
	TBT_Homework_Teacher::matched_from_totals( array( 'filter' => 'commented', 'search' => '' ), array( 'total' => 0, 'waiting' => 0 ) ),
	'an empty queue has nothing commented either'
);

echo "\npaging — 25 a page, newest first\n";

tbth_assert( 1, TBT_Homework_Teacher::page_count( 0 ), 'an empty queue is still one page' );
tbth_assert( 1, TBT_Homework_Teacher::page_count( 25 ), 'exactly a page' );
tbth_assert( 2, TBT_Homework_Teacher::page_count( 26 ), 'one over needs a second' );
tbth_assert( 3, TBT_Homework_Teacher::page_count( 51 ), 'fifty-one needs three' );
tbth_assert( 4, TBT_Homework_Teacher::page_count( 7, 2 ), 'the page size is respected' );

tbth_assert( 1, TBT_Homework_Teacher::normalise_page( null ), 'no page asked for is the first' );
tbth_assert( 3, TBT_Homework_Teacher::normalise_page( '3' ), 'digits as a string' );
tbth_assert( 1, TBT_Homework_Teacher::normalise_page( '0' ), 'zero is the first' );
tbth_assert( 1, TBT_Homework_Teacher::normalise_page( '-4' ), 'a negative is the first' );
tbth_assert( 1, TBT_Homework_Teacher::normalise_page( '2abc' ), 'digits with a tail' );
tbth_assert( 1, TBT_Homework_Teacher::normalise_page( array( 2 ) ), 'an array is not a page' );

// Past the end comes back to the last page: commenting on the last row should
// not leave you looking at nothing.
tbth_assert( 2, TBT_Homework_Teacher::clamp_page( 2, 5 ), 'a page that exists' );
tbth_assert( 5, TBT_Homework_Teacher::clamp_page( 9, 5 ), 'past the end is the last page' );
tbth_assert( 1, TBT_Homework_Teacher::clamp_page( 0, 5 ), 'before the start is the first' );
tbth_assert( 1, TBT_Homework_Teacher::clamp_page( 3, 0 ), 'no pages at all is still the first' );

echo "\nsearching a title, which is not a column in this table\n";

$tbth_titles = array(
	412 => array( 'lesson_title' => 'Past simple', 'class_title' => 'Group B' ),
	600 => array( 'lesson_title' => 'Articles', 'class_title' => 'Beginners' ),
);

tbth_assert( array( 412 ), TBT_Homework_Teacher::titles_matching( $tbth_titles, 'past' ), 'a lesson title, lowercased' );
tbth_assert( array( 412 ), TBT_Homework_Teacher::titles_matching( $tbth_titles, 'GROUP' ), 'a class title, uppercased' );
tbth_assert( array( 600 ), TBT_Homework_Teacher::titles_matching( $tbth_titles, 'begin' ), 'part of a word' );
tbth_assert( array( 412, 600 ), TBT_Homework_Teacher::titles_matching( $tbth_titles, 's' ), 'a letter both share' );
tbth_assert( array(), TBT_Homework_Teacher::titles_matching( $tbth_titles, 'irregular' ), 'a word neither has' );
tbth_assert( array(), TBT_Homework_Teacher::titles_matching( $tbth_titles, '   ' ), 'spaces match nothing' );
tbth_assert( array(), TBT_Homework_Teacher::titles_matching( array(), 'past' ), 'an empty brief matches nothing' );
tbth_assert(
	array( 1 ),
	TBT_Homework_Teacher::titles_matching( array( 1 => array( 'lesson_title' => 'Ćwiczenia' ) ), 'ćwicz' ),
	'folding is not ASCII-only'
);

echo "\na comment: empty clears it, and the cap is the same as a submission's\n";

tbth_assert( 'ok', TBT_Homework_REST::check_comment( 'Well done, Ania.' ), 'ordinary text' );
tbth_assert( 'clear', TBT_Homework_REST::check_comment( '' ), 'an empty save clears the comment' );
tbth_assert( 'clear', TBT_Homework_REST::check_comment( '   ' ), 'spaces only clear it too' );
tbth_assert( 'clear', TBT_Homework_REST::check_comment( "\n\n\t  \r\n" ), 'whitespace and newlines only' );
tbth_assert( 'ok', TBT_Homework_REST::check_comment( str_repeat( 'a', 20000 ) ), 'exactly the cap' );
tbth_assert( 'too_long', TBT_Homework_REST::check_comment( str_repeat( 'a', 20001 ) ), 'one over the cap' );
tbth_assert(
	'ok',
	TBT_Homework_REST::check_comment( '  ' . str_repeat( 'a', 20000 ) . '  ' ),
	'the cap is measured after trimming'
);
tbth_assert(
	'ok',
	TBT_Homework_REST::check_comment( str_repeat( 'ą', 20000 ) ),
	'the cap counts characters, not bytes'
);

echo "\nan id is an id, wherever it came from\n";

tbth_assert( true, TBT_Homework_REST::is_valid_id( 12 ), 'a positive int' );
tbth_assert( true, TBT_Homework_REST::is_valid_id( '12' ), 'digits as a string' );
tbth_assert( false, TBT_Homework_REST::is_valid_id( 0 ), 'zero' );
tbth_assert( false, TBT_Homework_REST::is_valid_id( '-12' ), 'a negative string' );
tbth_assert( false, TBT_Homework_REST::is_valid_id( null ), 'null' );

echo "\nnaming a page of the queue\n";

$tbth_queue_rows = array(
	array( 'id' => 9, 'user_id' => 3, 'lesson_id' => 412, 'class_id' => 12, 'body' => 'newest', 'comment' => null, 'commented_at' => null, 'submitted_at' => '2026-09-18 10:00:00' ),
	array( 'id' => 4, 'user_id' => 5, 'lesson_id' => 700, 'class_id' => 12, 'body' => 'unnamed', 'comment' => 'Nice', 'commented_at' => '2026-09-17 12:00:00', 'submitted_at' => '2026-09-17 10:00:00' ),
);

$tbth_queue = TBT_Homework_Teacher::attach(
	$tbth_queue_rows,
	array( 412 => array( 'lesson_title' => 'Past simple', 'class_title' => 'Group B' ) ),
	array( 3 => 'Ania Kowalska' )
);

// Unlike the student's library, the brief's silence is not the permission
// answer here — the class scope already is — so a row it cannot name keeps its
// place and loses only its title.
tbth_assert( 2, count( $tbth_queue ), 'a lesson the brief omits still appears' );
tbth_assert( 'Past simple', $tbth_queue[0]['lesson_title'], 'the lesson is named from the brief' );
tbth_assert( '', $tbth_queue[1]['lesson_title'], 'and an unnamed one is simply empty' );
tbth_assert( 'Ania Kowalska', $tbth_queue[0]['student'], 'the student is named from the user query' );
tbth_assert( '', $tbth_queue[1]['student'], 'a student whose account has gone comes back nameless' );
tbth_assert( 9, $tbth_queue[0]['id'], 'the row id is carried through, for the comment route' );
tbth_assert( 'waiting', $tbth_queue[0]['status'], 'no comment yet means waiting' );
tbth_assert( 'commented', $tbth_queue[1]['status'], 'a comment means commented' );
tbth_assert( 'Nice', $tbth_queue[1]['comment'], 'the comment is carried through' );
tbth_assert( null, $tbth_queue[0]['commented_at'], 'an empty comment date is null' );
tbth_assert( array(), TBT_Homework_Teacher::attach( array(), array(), array() ), 'no rows, no entries' );


echo "\nmarks: the canonical body and code points\n";

tbth_assert( "a\nb\nc\n", TBT_Homework_Marks::canonical( "a\r\nb\rc\n" ), '\r\n and \r become \n' );
tbth_assert( "a\n\nb", TBT_Homework_Marks::canonical( "a\n\r\nb" ), '\r\n is replaced before \r' );
tbth_assert( 8, TBT_Homework_Marks::length( 'Niżyński' ), 'Niżyński is 8 code points' );
tbth_assert( 1, TBT_Homework_Marks::length( "\u{1F600}" ), 'an emoji counts as 1' );
tbth_assert( md5( "a\nb" ), TBT_Homework_Marks::hash( "a\r\nb" ), 'the hash is of the canonical body' );

echo "\nmarks: decoding what is stored\n";

$tbth_mark = array( 'start' => 0, 'end' => 3, 'quote' => 'I’m', 'note' => 'fine' );

tbth_assert( array(), TBT_Homework_Marks::decode( null ), 'null is no marks' );
tbth_assert( array(), TBT_Homework_Marks::decode( '' ), 'an empty string is no marks' );
tbth_assert( array(), TBT_Homework_Marks::decode( 'not json' ), 'invalid JSON is no marks' );
tbth_assert( array(), TBT_Homework_Marks::decode( '{"a":1}' ), 'an object is no marks' );
tbth_assert( array(), TBT_Homework_Marks::decode( '[]' ), 'an empty list is no marks' );
tbth_assert( array( $tbth_mark ), TBT_Homework_Marks::decode( json_encode( array( $tbth_mark ) ) ), 'a valid list round-trips' );
tbth_assert(
	array( $tbth_mark ),
	TBT_Homework_Marks::decode( json_encode( array( $tbth_mark, array( 'start' => 'x' ), 5, array( 'start' => 4, 'end' => 2, 'quote' => 'a', 'note' => 'b' ) ) ) ),
	'elements that are not marks are dropped'
);
tbth_assert( false, TBT_Homework_Marks::has_marks( null ), 'no row has no marks' );
tbth_assert( false, TBT_Homework_Marks::has_marks( array( 'marks' => null ) ), 'a NULL column has no marks' );
tbth_assert( false, TBT_Homework_Marks::has_marks( array( 'marks' => '[]' ) ), "'[]' has no marks" );
tbth_assert( true, TBT_Homework_Marks::has_marks( array( 'marks' => json_encode( array( $tbth_mark ) ) ) ), 'a stored mark is a mark' );

echo "\nmarks: what the browser sends is checked against the body\n";

$tbth_body = 'I’m broadcast engineer in a big station.';

/**
 * A mark on the first occurrence of $quote in $body, counted in code points.
 *
 * @param string $body  The body.
 * @param string $quote The words.
 * @param string $note  The note.
 */
function tbth_mark( string $body, string $quote, string $note = 'a note' ): array {
	$start = mb_strpos( $body, $quote, 0, 'UTF-8' );

	return array(
		'start' => $start,
		'end'   => $start + mb_strlen( $quote, 'UTF-8' ),
		'quote' => $quote,
		'note'  => $note,
	);
}

$tbth_one = tbth_mark( $tbth_body, 'broadcast engineer', 'use a with jobs' );

tbth_assert( 4, $tbth_one['start'], 'the curly apostrophe before the mark is one code point' );
tbth_assert( array(), TBT_Homework_Marks::check( array(), $tbth_body ), 'an empty list is valid, and clears every mark' );
tbth_assert( array( $tbth_one ), TBT_Homework_Marks::check( array( $tbth_one ), $tbth_body ), 'one valid mark' );
tbth_assert( array( $tbth_one ), TBT_Homework_Marks::check( json_encode( array( $tbth_one ) ), $tbth_body ), 'a JSON list is read as a list' );

$tbth_adjacent = array(
	tbth_mark( $tbth_body, 'broadcast' ),
	tbth_mark( $tbth_body, ' engineer' ),
);
$tbth_adjacent[1]['start'] += 1;
$tbth_adjacent[1]['quote']  = 'engineer';
tbth_assert( 'array', gettype( TBT_Homework_Marks::check( $tbth_adjacent, $tbth_body ) ), 'two marks with a space between them' );

$tbth_touching = array(
	array( 'start' => 4, 'end' => 9, 'quote' => 'broad', 'note' => 'x' ),
	array( 'start' => 9, 'end' => 13, 'quote' => 'cast', 'note' => 'y' ),
);
tbth_assert( 2, count( TBT_Homework_Marks::check( $tbth_touching, $tbth_body ) ), 'two adjacent marks: one ends where the next starts' );

$tbth_polish = 'Przemek Niżyński żółć napisał to źle.';
$tbth_pl     = tbth_mark( $tbth_polish, 'napisał' );
tbth_assert( 22, $tbth_pl['start'], 'Polish characters before the mark count once each' );
tbth_assert( array( $tbth_pl ), TBT_Homework_Marks::check( array( $tbth_pl ), $tbth_polish ), 'a body with Polish characters before the mark' );

$tbth_crlf = "First line.\r\nSecond line.";
tbth_assert(
	1,
	count( TBT_Homework_Marks::check( array( array( 'start' => 12, 'end' => 18, 'quote' => 'Second', 'note' => 'x' ) ), $tbth_crlf ) ),
	'offsets count \r\n as one \n'
);

$tbth_sorted = TBT_Homework_Marks::check( array( tbth_mark( $tbth_body, 'station' ), $tbth_one ), $tbth_body );
tbth_assert( array( 4, 32 ), array( $tbth_sorted[0]['start'], $tbth_sorted[1]['start'] ), 'the list comes back sorted by start' );

tbth_assert(
	array( 'start', 'end', 'quote', 'note' ),
	array_keys( TBT_Homework_Marks::check( array( $tbth_one + array( 'extra' => 1 ) ), $tbth_body )[0] ),
	'only the four stored fields are kept'
);

tbth_assert( 'not_a_list', TBT_Homework_Marks::check( 'hello', $tbth_body ), 'a string' );
tbth_assert( 'not_a_list', TBT_Homework_Marks::check( '{"a":1}', $tbth_body ), 'a JSON object' );
tbth_assert( 'not_a_list', TBT_Homework_Marks::check( null, $tbth_body ), 'nothing at all' );
tbth_assert( 'not_a_list', TBT_Homework_Marks::check( array( 'a' => $tbth_one ), $tbth_body ), 'an array with keys' );
tbth_assert( 'too_many', TBT_Homework_Marks::check( array_fill( 0, 101, $tbth_one ), $tbth_body ), '101 marks' );
tbth_assert( 'bad_range', TBT_Homework_Marks::check( array( array_merge( $tbth_one, array( 'end' => 4 ) ) ), $tbth_body ), 'start >= end' );
tbth_assert( 'bad_range', TBT_Homework_Marks::check( array( array_merge( $tbth_one, array( 'end' => 999 ) ) ), $tbth_body ), 'end past the body' );
tbth_assert( 'bad_range', TBT_Homework_Marks::check( array( array_merge( $tbth_one, array( 'start' => '4' ) ) ), $tbth_body ), 'a start that is not an integer' );
tbth_assert( 'bad_range', TBT_Homework_Marks::check( array( array_merge( $tbth_one, array( 'start' => -1 ) ) ), $tbth_body ), 'a negative start' );
tbth_assert( 'bad_range', TBT_Homework_Marks::check( array( 'x' ), $tbth_body ), 'an element that is not a mark' );
tbth_assert( 'quote_mismatch', TBT_Homework_Marks::check( array( array_merge( $tbth_one, array( 'quote' => 'broadcast engineeR' ) ) ), $tbth_body ), 'a quote one character off' );
tbth_assert( 'quote_mismatch', TBT_Homework_Marks::check( array( array_merge( $tbth_one, array( 'quote' => null ) ) ), $tbth_body ), 'no quote at all' );

$tbth_lines = "One line.\nTwo lines.";
tbth_assert(
	'crosses_line',
	TBT_Homework_Marks::check( array( array( 'start' => 4, 'end' => 13, 'quote' => "line.\nTwo", 'note' => 'x' ) ), $tbth_lines ),
	'a range over \n'
);
tbth_assert(
	'untrimmed',
	TBT_Homework_Marks::check( array( array( 'start' => 3, 'end' => 13, 'quote' => ' broadcast', 'note' => 'x' ) ), $tbth_body ),
	'a leading space'
);
tbth_assert(
	'untrimmed',
	TBT_Homework_Marks::check( array( array( 'start' => 4, 'end' => 14, 'quote' => 'broadcast ', 'note' => 'x' ) ), $tbth_body ),
	'a trailing space'
);
tbth_assert(
	'untrimmed',
	TBT_Homework_Marks::check( array( array( 'start' => 0, 'end' => 2, 'quote' => "a\u{00A0}", 'note' => 'x' ) ), "a\u{00A0}b" ),
	'a trailing no-break space'
);
tbth_assert(
	'overlap',
	TBT_Homework_Marks::check( array( $tbth_one, tbth_mark( $tbth_body, 'engineer in' ) ), $tbth_body ),
	'two overlapping ranges'
);
tbth_assert( 'note_empty', TBT_Homework_Marks::check( array( array_merge( $tbth_one, array( 'note' => '' ) ) ), $tbth_body ), 'an empty note' );
tbth_assert( 'note_empty', TBT_Homework_Marks::check( array( array_merge( $tbth_one, array( 'note' => '   ' ) ) ), $tbth_body ), 'a note of spaces' );
tbth_assert( 'note_empty', TBT_Homework_Marks::check( array( array_diff_key( $tbth_one, array( 'note' => 1 ) ) ), $tbth_body ), 'no note at all' );
tbth_assert( 'array', gettype( TBT_Homework_Marks::check( array( array_merge( $tbth_one, array( 'note' => str_repeat( 'ż', 200 ) ) ) ), $tbth_body ) ), 'a 200-code-point note' );
tbth_assert( 'note_too_long', TBT_Homework_Marks::check( array( array_merge( $tbth_one, array( 'note' => str_repeat( 'ż', 201 ) ) ) ), $tbth_body ), 'a 201-code-point note' );

echo "\nmarks: segments, the one source both renderers follow\n";

tbth_assert(
	array( array( 'text' => "a\nb", 'mark' => null ) ),
	TBT_Homework_Marks::segments( "a\r\nb", array() ),
	'no marks is one plain segment, the canonical body'
);
tbth_assert(
	array( array( 'text' => '', 'mark' => null ) ),
	TBT_Homework_Marks::segments( '', array() ),
	'an empty body is one empty segment'
);

$tbth_edges      = 'Hello there friend';
$tbth_edge_marks = array(
	array( 'start' => 12, 'end' => 18, 'quote' => 'friend', 'note' => 'b' ),
	array( 'start' => 0, 'end' => 5, 'quote' => 'Hello', 'note' => 'a' ),
);
$tbth_segs = TBT_Homework_Marks::segments( $tbth_edges, $tbth_edge_marks );
tbth_assert( array( 'Hello', ' there ', 'friend' ), array_column( $tbth_segs, 'text' ), 'marks at the very start and the very end' );
tbth_assert( 'a', $tbth_segs[0]['mark']['note'], 'the first segment is the first mark' );
tbth_assert( null, $tbth_segs[1]['mark'], 'the middle is plain' );
tbth_assert( 'b', $tbth_segs[2]['mark']['note'], 'the last segment is the last mark' );

$tbth_segs = TBT_Homework_Marks::segments( $tbth_body, $tbth_touching );
tbth_assert( array( 'I’m ', 'broad', 'cast', ' engineer in a big station.' ), array_column( $tbth_segs, 'text' ), 'two adjacent marks, with no empty piece between them' );

$tbth_segs = TBT_Homework_Marks::segments( $tbth_body, array( array_merge( $tbth_one, array( 'quote' => 'changed words here' ) ) ) );
tbth_assert( 1, count( $tbth_segs ), 'a mark whose quote no longer matches is not drawn' );

tbth_assert( 'broadcast', TBT_Homework_Marks::first_word( 'broadcast engineer' ), 'the anchor holds the first word' );
tbth_assert( 'nad', TBT_Homework_Marks::first_word( 'nad' ), 'a one-word quote is all anchor' );

echo "\nclosed is not the same as commented\n";

$tbth_marks_json = json_encode( array( $tbth_one ) );

tbth_assert( true, TBT_Homework_REST::is_closed( array( 'comment' => 'Nice', 'marks' => null ) ), 'comment only: closed' );
tbth_assert( true, TBT_Homework_REST::has_comment( array( 'comment' => 'Nice', 'marks' => null ) ), 'comment only: has a comment' );
tbth_assert( true, TBT_Homework_REST::is_closed( array( 'comment' => null, 'marks' => $tbth_marks_json ) ), 'marks only: closed' );
tbth_assert( false, TBT_Homework_REST::has_comment( array( 'comment' => null, 'marks' => $tbth_marks_json ) ), 'marks only: no comment' );
tbth_assert( false, TBT_Homework_REST::is_closed( array( 'comment' => null, 'marks' => '[]' ) ), "'[]' and no comment: open" );
tbth_assert( false, TBT_Homework_REST::is_closed( array( 'comment' => null, 'marks' => null ) ), 'NULL marks and no comment: open' );
tbth_assert( 'marks', TBT_Homework_REST::closed_reason( array( 'comment' => null, 'marks' => $tbth_marks_json ) ), 'closed by marks' );
tbth_assert( 'comment', TBT_Homework_REST::closed_reason( array( 'comment' => 'Nice', 'marks' => $tbth_marks_json ) ), 'the comment wins when both' );
tbth_assert( null, TBT_Homework_REST::closed_reason( array( 'comment' => null, 'marks' => '[]' ) ), 'open has no reason' );
tbth_assert( 'waiting', TBT_Homework_Student::status_for( array( 'comment' => null, 'marks' => $tbth_marks_json ) ), 'marks only: still waiting' );
tbth_assert( 'commented', TBT_Homework_Student::status_for( array( 'comment' => 'Nice', 'marks' => $tbth_marks_json ) ), 'marks and a comment: commented' );

tbth_assert(
	array( $tbth_one ),
	TBT_Homework_Student::attach_brief(
		array( array( 'lesson_id' => 412, 'body' => $tbth_body, 'marks' => $tbth_marks_json ) ),
		array( 412 => array( 'lesson_title' => 'Past simple' ) )
	)[0]['marks'],
	"the student's library carries the marks through"
);
tbth_assert(
	array(),
	TBT_Homework_Teacher::attach( array( array( 'id' => 1, 'body' => 'x' ) ), array(), array() )[0]['marks'],
	"the queue carries no marks as an empty list"
);

echo "\n";

if ( $tests_failed > 0 ) {
	echo "{$tests_failed} of {$tests_run} assertions failed.\n\n";
	exit( 1 );
}

echo "All {$tests_run} assertions passed.\n\n";
exit( 0 );
