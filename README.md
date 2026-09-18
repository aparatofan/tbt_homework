# TBT Homework

Private homework handover between a student and their teacher, under a TBT
Notes lesson.

**Version 0.3.1** — the bar and the cards: the search and the dropdown are
300px again on both library pages, the heading is TBT Blue Roboto Slab, and
every card carries a spine that says whether it is waiting or dealt with.

## What 0.3.1 fixes

The Admin Bar drew its search and its dropdown at around 540px rather than
300px, and the whole row lost its shape with them. `.tbt-input` and
`.tbt-select` set their own width, the theme sets more, and both beat a flex
basis declared on the wrapper around them. The widths are pinned one class
deeper now — `.tbth-student .tbth-libbar__select`, not `.tbth-libbar__select`
— which is how `tbt-students` solved the same thing, and the `!important` is
there for that reason and no other.

The rest of the bar came with it: three lines rather than one, so the gap after
the search and the gap after the dropdown are equal; the heading in TBT Blue
Roboto Slab 28px instead of near-black at 18px; a magnifier in the search box
and a × that appears with the text, clears the field and hands the focus back.
An empty library shows the title and one line, and nothing else.

The cards gained a spine — six pixels down the left edge, the shape Matching
Game and Swipe both draw. There it is a domain colour; homework belongs to no
one domain, so here it is a state: **TBT Blue while something is waiting for a
comment, muted grey once it has one**, the same on both pages, so blue means
open and grey means closed wherever you are. Not green — a comment is not a
success, and you may have just told a student their tenses collapsed in
paragraph two. And the theme's bullets are gone from beside the cards, without
the list ceasing to be a list.

## What 0.3.0 adds

Put `[tbt_homework_teacher]` on a page. A signed-in teacher sees every
submission from the classes they manage, newest first, with the student's name
as the strongest thing on each card, the lesson, the class and the date under
it, the homework itself, and a box to reply in.

The bar reads **Homework to check** — not "Your homework", because these are
not your items, they are your students'. Beside the title, a blue pill counts
what is waiting across every class you manage, whatever the dropdown is
currently showing. Zero waiting is no pill at all.

The dropdown is a filter: **Waiting** (the default) · Commented · All. It is
blue on load, and that is correct rather than a bug — the list genuinely is
filtered, and the page opens on the newest thing nobody has replied to, which
is the job. The search covers the student's name, the lesson title, the class
title, the submission and your comment; not the status, which the dropdown owns.

25 rows a page, with plain older and newer links.

Writing a comment is a real state change, not an annotation: it closes the
student's editing. An empty save clears the comment and hands the homework
back, asked about in words first. After a save the card stays where it is, with
the box open for a correction; on the next load it is where the filter says it
belongs.

### Search and filter here are server-side

The student's library filters in the browser over rows PHP has already written.
This page cannot: it is paged at 25, so filtering in hand would search the 25
rows on screen and quietly miss the rest, and the summary line's "4 of 37"
would be a lie. So search, filter and page are query parameters, the dropdown
submits the bar, and only saving needs JavaScript.

## What 0.2.0 added

Put `[tbt_homework_student]` on a page. A signed-in student sees their own
homework, newest first: the lesson it belongs to, the class, the date it was
sent, whether it is still waiting for a comment, the homework itself, and the
teacher's comment once there is one.

The page opens with the shared Admin Bar — the title, a search and a status
filter (All · Waiting for comment · Commented), both appearing once there is
something to search. There is no button on the bar: homework is written under a
lesson note, never from this page, so the page is read-only.

The hero belongs to the page template. The shortcode starts at the bar.

## What 0.1.0 did

Log in as a student, open a lesson note, write homework, send it, reload: it is
still there, with its date. Edit it and save: the new text. Each note holds its
own submission.

Nothing is public at any point.

## Release map

| Release | What it is |
| --- | --- |
| 0.1.0 | Scaffolding, table, REST, the form under the note |
| 0.2.0 | `[tbt_homework_student]` — the student's dossier page |
| 0.3.0 | `[tbt_homework_teacher]` — the teacher's queue, filters, the count |
| **0.3.1** | The bar's real widths, its search field, and the cards' spine |
| later | Audio recording |

## Requirements

- WordPress with TBT Notes 1.18.0 or newer active
- PHP 8.0+

TBT Notes can be deactivated at any time without loss. The admin gets a notice
on the Plugins screen, the REST routes answer `503`, no assets load, and every
submitted row survives to reappear when Notes comes back.

## How it hangs off TBT Notes

Three published functions, one DOM slot, one event, one action hook — nothing
else. No Notes table is queried and no Notes class is referenced.

- `tbt_notes_lesson_context()` supplies the lesson, its class, and the
  `can_view` / `can_manage` flags that are the whole security model.
- `tbt_notes_lessons_brief()` names the lessons on both library pages. On the
  student's page it omits every lesson they may not view, and that silence is
  the permission answer: a submission it does not mention does not appear. On
  the teacher's queue the class scope is already the answer, so a row it cannot
  name keeps its place and loses only its title. It is capped at 200 ids, so
  both pages ask in batches of 200.
- `tbt_notes_class_ids_for_manager()` returns the classes you teach, every class
  for an administrator, and an empty array for everyone else — which is the
  whole of "not a teacher". It is the only thing that bounds the queue.
- `<div class="tbt-notes-slot" data-tbt-slot="lesson-foot">` is where the card
  mounts.
- `tbt-notes:lesson-view` on `document` says a slot has just been rendered.
- `tbt_notes_frontend_assets` is where the script and stylesheet enqueue, so
  they land only on pages where Notes actually loads.

## REST

Namespace `tbt-homework/v1`. Every route requires a logged-in user and nothing
more from `permission_callback`: being logged in says nothing about which class
you are in, nor which classes you manage, so authorisation happens inside the
callbacks.

```
GET  /submission?lesson_id=412       → the caller's row for that lesson, or null
POST /submission { lesson_id, body } → create or update the caller's row
GET  /queue?status=&search=&page=    → the caller's students' work, newest first
POST /comment { id, comment }        → write, replace or clear one comment
```

POST `/submission` fails, first failure winning: `503` when Notes is missing,
`400` for a lesson id that is not a positive integer, `404` when the lesson does
not exist, `403` when `can_view` is false, `403 tbt_homework_not_for_teachers`
when the caller manages the class, `400` for an empty or over-long body (20,000
characters), `409 tbt_homework_closed` once the teacher has commented.

POST `/comment` fails in the same style: `503` when Notes is missing, `404` for
an id that is not a positive integer or names no row, `403
tbt_homework_not_your_class` when the row's own `class_id` is not one the caller
manages, `400` over 20,000 characters. An empty comment is not a failure — it
clears the comment and its date, and the student can edit again. `GET /queue`
answers `403 tbt_homework_not_a_teacher` to anyone who manages no classes.

`class_id` is not a parameter on any route: on `/submission` the server takes it
from the lesson context, and on `/comment` the scope check reads it from the row
in the database. Neither ever reads one the browser sent.

## The library page

`[tbt_homework_student]` renders server-side and is read whole — there is
nothing to submit on it and nothing to fetch after load. Its script only
filters what is already on the page, so a student without JavaScript still
reads their entire library.

A submission whose lesson the student may no longer view — a deleted class,
most often — drops out of the list, because the lesson brief does not mention
it. No orphan lookup, no error, and the row stays in the table.

Signed out, the page says so and shows nothing. With TBT Notes inactive, it
says the page is temporarily unavailable, because without the brief there is no
way to name a lesson or to know which ones the student may still see.

## The queue page

`[tbt_homework_teacher]` renders server-side too. Every read is bounded by
`class_id IN ( <the classes you manage> )` — no id from the browser ever reaches
a `WHERE` clause, and a submission whose class has since been deleted falls out
of the list on its own, because its `class_id` is no longer in the set.

A student, or anyone else who manages no classes, sees one line saying the page
is for teachers: no count, no name, no class title.

Unsearched, a page costs three queries — the totals, the rows, and one
`WP_User_Query` for the page's student names — plus one `tbt_notes_lessons_brief()`
call for its titles. A search costs more, and has to: a student's name and a
lesson title are not columns in this table, so they are resolved to ids first
and the rows query pages over the result.

## The Admin Bar

Both library pages read the same bar from `assets/css/tbt-homework-library.css`,
and the two pieces of its markup that are the same on both — the line and the
search field — come from `TBT_Homework_Bar`. The geometry is the 234px minimum
title zone so the search starts 244px in, the 300px search, the 300px dropdown,
the 10px gaps, 24px below, and the reflow at 1100px and 580px. One copy, so the
two pages cannot drift apart, and the Divi heading pin is anchored on both app
ids.

The widths are pinned one class deeper than the shared primitives, because a
primitive that sets its own width — and a theme that sets more — beats a flex
basis on the wrapper. That is the only place in this plugin where `!important`
is used, and it is used for that.

Neither bar has a button: a student writes homework under a lesson note, and a
teacher does not create homework at all. Nothing records that — the end line
takes the space a button would have had, which is what a line whose whole job
is to take what is left already does. The `--is-empty` modifier is a different
case, an empty library with no search and no dropdown, where the title's line
is the only one left and the end line would double up behind it.

## The cards

A submission is a list item, and stays one — a screen reader should say how
many there are — but the theme's disc is killed on the app's own id, on the
list, the item and its marker.

Each card carries a 6px spine on its left edge. Matching Game's is `#660000`,
which means Learn English; homework belongs to no domain, so this one carries
state from the state palette instead: TBT Blue for waiting, muted grey for
commented, the same two on both pages. A hover rule written with the
`border-color` shorthand would repaint it, so the spine is re-asserted in the
hover state rather than trusted.

## The table

One table, `{prefix}tbt_homework`, one row per student per lesson, enforced by
a unique key rather than by reading before writing — a double-tap on Send
cannot create two rows. `comment IS NULL` means new. `class_id` is copied in at
write time. `attachment` is reserved and still always empty — nothing reads or
writes it — so audio arrives later as a feature rather than a migration.

Created on activation, dropped on delete. Deactivating keeps everything.

## Checks

```
php -l tbt-homework.php            # and every other PHP file
node --check assets/js/tbt-homework.js
node --check assets/js/tbt-homework-student.js
node --check assets/js/tbt-homework-teacher.js
php tests/test-logic.php
```

0.3.1 changes no schema, no route and no query, so `TBT_HOMEWORK_DB_VERSION`
stays at `1` and there is nothing to migrate.

## Deployment

`.github/workflows/deploy.yml` deploys over FTPS to `/tbt-homework/`. A version
tag is what makes a commit a release:

```
git tag v0.3.1 && git push origin v0.3.1
```

An ordinary push to a branch deploys nothing. The manual trigger — Actions →
Deploy → Run workflow — is still there for redeploying without cutting a new
tag.

Before copying anything, the workflow checks the tag against both places the
version is written, the plugin header and `TBT_HOMEWORK_VERSION`. If the three
disagree it fails without deploying, so a tag can never quietly put the wrong
version live.

FTP sync copies files; it does not run WordPress activation. That only matters
for a release that changes the schema — bump `TBT_HOMEWORK_DB_VERSION` and give
it a migration path rather than relying on the activation hook.

For a hand install instead, zip the plugin folder without `tests/`, `docs/`,
`.github/` and the `.md` files — the same exclusions the workflow uses — and
upload it under Plugins → Add New → Upload Plugin.
