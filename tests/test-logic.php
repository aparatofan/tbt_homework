<?php
/**
 * Pure logic tests. No WordPress, no database.
 *
 *     php tests/test-logic.php
 *
 * class-tbt-homework-rest.php defines its class and nothing else at load time,
 * so defining ABSPATH is all it takes to pull the pure helpers in here. Every
 * function under test touches only its arguments.
 *
 * @package TBT_Homework
 */

define( 'ABSPATH', dirname( __DIR__ ) . '/' );

require_once dirname( __DIR__ ) . '/includes/class-tbt-homework-rest.php';

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

echo "\n";

if ( $tests_failed > 0 ) {
	echo "{$tests_failed} of {$tests_run} assertions failed.\n\n";
	exit( 1 );
}

echo "All {$tests_run} assertions passed.\n\n";
exit( 0 );
