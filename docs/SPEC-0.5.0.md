# TBT Homework 0.5.0 — A score when the teacher marks, reported to TBT Results

The teacher can give a homework a score next to their comment: **"Score: __ / 10"**, where the maximum is 10 by default and can be changed for that homework (e.g. 13 for a matura essay). The student sees the score with the comment. When a score is saved, the homework is reported to the shared activity log in TBT Notes, so it appears in **TBT Results** ("7 / 10 · 70%").

Decided by Mariusz (14 Nov 2026): the teacher chooses the maximum each time; /10 is the default. No prototype: this spec describes the small UI change in words.

## Scope

| In | Out |
|---|---|
| `score` and `score_max` columns on the submissions table | Changing or removing a score in TBT Results after it was first reported (needs an update call in Notes; later) |
| Score field beside the teacher's comment; saved with it | Scores without a comment being possible is fine (see Task 3) |
| Score shown to the student with the comment | Points (TBT Points) |
| One report to Notes the **first** time a score is saved | Inline marks (`/marks`) — unchanged |

## Phase 0 — read-only inventory (stop and report)

1. **Table and migration.** The submissions table and how `TBT_HOMEWORK_DB_VERSION` (`2`) drives `dbDelta`. Confirm adding two nullable columns is a plain `dbDelta` change.
2. **Saving a comment.** `/comment` → `handle_comment()` → `TBT_Homework_DB::save_comment()`: what it receives, the permission/scope check (stored row's class), what it returns, and how `tbt-homework-teacher.js` sends it and redraws the item. The score must travel with this same request.
3. **Clearing.** What happens today when the teacher empties the comment (comment and `commented_at` go back to NULL). Say how a score should behave then (proposal: kept; see Task 3).
4. **Student view.** Where `class-tbt-homework-student.php` (~L295) and `tbt-homework-student.js` show the comment and its date, so the score goes beside it.
5. **Notes.** Confirm `tbt_notes_record_activity()` exists (Notes 1.22.0) and that the lesson title is available from the row or `tbt_notes_lesson_context()`.
6. Every place the submission row is shaped for the browser (`rest.php` ~L503, ~L535, ~L632; `student.php` ~L430), so the two new fields are added everywhere.

**Hard stop** if anything contradicts this spec. Otherwise end with the report and wait for Mariusz to say go.

---

## Task 1 — Schema (`class-tbt-homework-db.php`, `tbt-homework.php`)

- Add `score smallint unsigned NULL` and `score_max smallint unsigned NULL` and `activity_id bigint unsigned NULL` to the submissions table. Bump `TBT_HOMEWORK_DB_VERSION` to `3` (real schema change).
- NULL means "not scored". 0 is a real score.

## Task 2 — Saving (`class-tbt-homework-rest.php`, `class-tbt-homework-db.php`)

- `/comment` accepts two optional fields: `score` and `score_max`.
- Rules: both empty → score cleared (NULL, NULL). Otherwise `score_max` is an integer 1–100 (default 10 when only `score` is sent) and `score` an integer 0–`score_max`. Anything else → 400 with "The score must be a whole number between 0 and the maximum." and nothing is saved.
- Saved in the same write as the comment, same permission check, same response shape plus `score` and `score_max`.

## Task 3 — Reporting to Notes (`class-tbt-homework-rest.php`)

After a successful save, when the row now has a score **and** `activity_id` is NULL:

```php
$id = tbt_notes_record_activity( array(
  'user_id'      => <the submission's student>,
  'tool'         => 'homework',
  'object_ref'   => (string) <lesson_id>,
  'object_title' => <lesson title>,
  'score'        => <score>,
  'score_max'    => <score_max>,
) );
```

- On an integer result, store it in `activity_id`. On `WP_Error`, or when the function does not exist (Notes inactive/older), do nothing: marking never fails because of Notes.
- `object_ref` is the lesson ID: Results groups "who did this homework" by lesson.
- A later change or removal of the score updates Homework's own row only; Results keeps the first reported score. (Known limit, out of scope.)
- Clearing the comment does not clear the score.

## Task 4 — Teacher UI (`class-tbt-homework-teacher.php`, `tbt-homework-teacher.js`, CSS)

Under "Your comment", on the same line as "Save comment", before the button:

`Score` [ 7 ] `/` [ 10 ]

- Two small number inputs (score empty by default, max 10 by default), labelled for screen readers ("Score", "Out of"). Existing score/max are filled in.
- "Save comment" saves both. Enter in either field saves too.
- The validation message from Task 2 shows under the line, in the existing error style. Existing tokens and button styles only; tablet and desktop only.
- The item's header, where it shows it has been commented, also shows the score, e.g. "7 / 10".

## Task 5 — Student view

Beside the teacher's comment date: **"Score: 7 / 10"**, only when scored. Same style as the date line; nothing when not scored.

## Task 6 — CLAUDE.md

The contract section lists three Notes functions as the only route to Notes. Add the fourth, published in Notes 1.22.0: `tbt_notes_record_activity( array $args ): int|WP_Error`, the single write, used only by Task 3, checked with `function_exists()` at the point of use.

## Task 7 — Version and tests

`TBT_HOMEWORK_VERSION` → `0.5.0` (header too). Add test cases in `tests/test-logic.php` for the score rules (empty/empty clears; score only defaults max 10; 0 allowed; score above max, negative, non-integer and max 0 or 101 refused). Changelog line in `readme.txt` if present.

## Validation

As in `CLAUDE.md` (`php tests/test-logic.php`, `php -l` on changed files, `node --check` on changed JS).

---

## Acceptance checklist (live, after merge and deploy)

- [ ] Homework To Check: each submission shows "Score [ ] / [10]" next to "Save comment".
- [ ] Save a comment with 7 / 10: the item shows 7 / 10; reload keeps it.
- [ ] Change the maximum to 13 and the score to 11 on another submission: saved as 11 / 13.
- [ ] 12 / 10, −1, 7.5 are refused with the message; nothing is saved.
- [ ] Save a comment with no score: works exactly as before; nothing appears in Results.
- [ ] The student sees "Score: 7 / 10" under your comment on My Homework.
- [ ] TBT Results shows a **Homework** row for that student with the lesson title and 7 / 10 · 70%.
- [ ] Changing that score later changes it in Homework but adds no second row in Results.
- [ ] Inline marks and notes work as before.
- [ ] Hard refresh shows 0.5.0 assets.
