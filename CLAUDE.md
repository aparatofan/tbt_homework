# TBT Homework

A WordPress plugin. Private homework handover between a student and their
teacher, under a TBT Notes lesson.

PHP 8.0+, vanilla JS, no build step. Nothing here is compiled, bundled or
transpiled: the files that ship are the files in the repo.

## TBT Notes is a contract, not a dependency to read

Three published functions, live since TBT Notes 1.18.0, are the **only**
permitted route to Notes data:

```
tbt_notes_lesson_context( int $lesson_id, int $user_id = 0 ): ?array
tbt_notes_class_ids_for_manager( int $user_id = 0 ): array
tbt_notes_lessons_brief( array $lesson_ids, int $user_id = 0 ): array
```

Never query a Notes table. Never reference a `TBT_Notes_` class. Never
re-derive who may see what from roles, capabilities or class membership — the
context's flags already answer that.

`tbt_notes_lesson_context()` returns a row for a lesson the caller may not
touch, with `can_view` and `can_manage` both false. **Reading the row is not
permission. Check the flags.**

Notes can be deactivated at any time, so `function_exists( 'tbt_notes_lesson_context' )`
is checked at the point of use, never once at load. Missing Notes is never
fatal and never loses a submission: the admin gets a notice, REST answers
`503 tbt_homework_unavailable`, and the front end simply never enqueues.

## Ownership is server-side

Authorisation lives in the REST callbacks, per lesson. Hiding UI is never the
boundary. `permission_callback` is only `is_user_logged_in` — being logged in
says nothing about which class you are in.

The POST route is for students: the check is that the caller **cannot manage**
the class, not merely that they can see it. A teacher or administrator posting
homework gets `403 tbt_homework_not_for_teachers`.

`class_id` is never a parameter. It is copied in at write time from the lesson
context, which is the only trustworthy source, and a `class_id` sent by the
browser is ignored.

## Private, always

Everything here is private between one student and their teacher. No
submission is ever rendered on a public page, in a feed, or to another
student. There is no route that returns anyone else's work.

## Conventions

- Prefix everything `tbth-` (CSS) / `TBTHomework` (JS globals). Never reuse a
  class name from another tool's stylesheet.
- `class-tbt-homework-db.php` owns every query. No other file writes SQL.
- Bodies are plain text: `sanitize_textarea_field()` in, `esc_html()` plus
  `nl2br()` out, in both PHP and JS. No rich text, no pasted markup.
- Times are stored in UTC via `current_time( 'mysql', true )` and displayed
  with `wp_date()`.
- Colours come from the shared Hub tokens; geometry comes from the Style Book.
  Nothing here invents a value.
- Buttons are sentence case. Uppercase is reserved for major learner moments
  and the Admin Bar's library CTA.

## Checks

```
php -l <file>                  # every PHP file
node --check assets/js/tbt-homework.js
php tests/test-logic.php        # pure logic, no WordPress
```
