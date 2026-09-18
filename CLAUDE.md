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

## The Admin Bar

Every library opens with the same one-row bar — a teacher's tools and a
student's own work alike. The bar does not change because the reader changed.

`tbt-components` ships primitives only (`.tbt-button`, `.tbt-input`,
`.tbt-select`, `.tbt-textarea`, `.tbt-card`, `.tbt-tag`, `.tbt-panel`) and
contains no bar. Each plugin carries its **own prefixed copy** — Notes has
`tbt-notes-libbar__*`, this plugin has `tbth-libbar__*` with `__title`,
`__heading`, `__line`, `__search`, `__filter`. Same shape, own prefix: sharing
one set of class names is what caused the Notes/Swipe clash.

One prefixed copy, not one per page: `assets/css/tbt-homework-library.css` holds
the bar and both library pages read it, so the student's and the teacher's
cannot drift apart. The Divi pin is anchored on both app ids.

Use the shared primitives where they exist — the search field is `.tbt-input`,
the dropdown is `.tbt-select` — and take colours from the Hub tokens without
defining local near-duplicates. The geometry is ours: 10px gaps, a 234px
minimum title zone so the search starts 244px in, a 300px search, a 300px
dropdown, 24px below the bar, and the reflow at 1100px and again at 580px.

Two things to keep copying from Notes:

- **Divi uppercases headings site-wide** from ID-scoped selectors. A
  class-scoped rule loses to them, so heading type is pinned on the app's own
  id (`#tbth-homework-student`), not on a class alone.
- **No button on the student's bar.** A student writes homework under a lesson
  note, so there is nowhere for one to go. The `--is-empty` modifier records
  the missing button and the line simply runs on to the end of the row; the
  line is never special-cased.

## Conventions

- Prefix everything `tbth-` (CSS) / `TBTHomework` (JS globals). Never reuse a
  class name from another tool's stylesheet.
- `class-tbt-homework-db.php` owns every query. No other file writes SQL.
- The form under a note is REST-driven, because it mounts into a slot Notes
  renders. The library pages are rendered server-side by their shortcodes. The
  student's script only filters what is already there — a student without
  JavaScript still reads their whole library. The teacher's queue is paged at
  25, so filtering in the browser would search only the rows in hand: there the
  search, the filter and the page are query parameters, and JavaScript is
  needed for saving a comment and nothing else.
- The teacher's queue is bounded by `class_id IN ( tbt_notes_class_ids_for_manager() )`
  and by nothing else. An empty array is the whole of "not a teacher".
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
node --check assets/js/tbt-homework-student.js
php tests/test-logic.php        # pure logic, no WordPress
```
