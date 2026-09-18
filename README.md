# TBT Homework

Private homework handover between a student and their teacher, under a TBT
Notes lesson.

**Version 0.1.0** — scaffolding, the table, the REST routes and the form under
the note. A student writes homework under a lesson note and it is saved. That
is the whole of this release.

## What 0.1.0 does

Log in as a student, open a lesson note, write homework, send it, reload: it is
still there, with its date. Edit it and save: the new text. Each note holds its
own submission.

You cannot yet read it anywhere but under that note. Nothing is public at any
point.

## Release map

| Release | What it is |
| --- | --- |
| **0.1.0** | Scaffolding, table, REST, the form under the note |
| 0.2.0 | `[tbt_homework_student]` — the student's dossier page |
| 0.3.0 | `[tbt_homework_teacher]` — the teacher's queue, filters, the badge |
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
- `<div class="tbt-notes-slot" data-tbt-slot="lesson-foot">` is where the card
  mounts.
- `tbt-notes:lesson-view` on `document` says a slot has just been rendered.
- `tbt_notes_frontend_assets` is where the script and stylesheet enqueue, so
  they land only on pages where Notes actually loads.

## REST

Namespace `tbt-homework/v1`. Both routes are about the caller's own work, and
both require a logged-in user; authorisation happens per lesson inside the
callback.

```
GET  /submission?lesson_id=412       → the caller's row for that lesson, or null
POST /submission { lesson_id, body } → create or update the caller's row
```

`class_id` is not a parameter — the server takes it from the lesson context and
ignores anything the browser sends.

POST fails, first failure winning: `503` when Notes is missing, `400` for a
lesson id that is not a positive integer, `404` when the lesson does not exist,
`403` when `can_view` is false, `403 tbt_homework_not_for_teachers` when the
caller manages the class, `400` for an empty or over-long body (20,000
characters), `409 tbt_homework_closed` once the teacher has commented.

## The table

One table, `{prefix}tbt_homework`, one row per student per lesson, enforced by
a unique key rather than by reading before writing — a double-tap on Send
cannot create two rows. `comment IS NULL` means new. `class_id` is copied in at
write time. `attachment` is reserved and always empty in 0.1.0, so audio
arrives later as a feature rather than a migration.

Created on activation, dropped on delete. Deactivating keeps everything.

## Checks

```
php -l tbt-homework.php            # and every other PHP file
node --check assets/js/tbt-homework.js
php tests/test-logic.php
```

## Deployment

`.github/workflows/deploy.yml` deploys over FTPS to `/tbt-homework/`, on manual
trigger only. Nothing deploys on push.
