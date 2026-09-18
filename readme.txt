=== TBT Homework ===
Contributors: tbt
Requires at least: 6.0
Tested up to: 6.6
Requires PHP: 8.0
Stable tag: 0.3.0
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Private homework handover between a student and their teacher, under a TBT Notes lesson.

== Description ==

TBT Homework adds a homework card under a TBT Notes lesson note. A student
writes their homework there and sends it; their teacher reads and comments on it
from a queue of their own.

Everything is private between one student and their teacher. No submission is
ever shown on a public page, in a feed, or to another student.

* One submission per student per lesson.
* Edit until the teacher comments; after that the note is closed for changes.
* Plain text only.
* Times stored in UTC, displayed in the site's timezone.

= The student's library =

Put the shortcode `[tbt_homework_student]` on a page and a signed-in student
sees their own homework, newest first, with a search and a status filter. It is
read-only: homework is written under a lesson note, never from this page.

= The teacher's queue =

Put the shortcode `[tbt_homework_teacher]` on a page and a signed-in teacher
sees every submission from the classes they manage, newest first, with a comment
box on each. A count beside the title says how many are waiting. The dropdown
filters Waiting · Commented · All, the search covers names, titles, the homework
and the comment, and the list is paged at 25.

Writing a comment closes the student's editing; clearing it — an empty save,
asked about first — hands the homework back.

Anyone who manages no classes sees one line saying the page is for teachers.

= Requires TBT Notes =

TBT Notes 1.18.0 or newer must be active. TBT Homework talks to it only
through its three published functions; it never touches its tables.

If TBT Notes is deactivated, nothing is lost: an admin notice appears, the REST
routes answer 503, the card does not load, and every stored submission returns
when TBT Notes does.

== Installation ==

1. Upload the `tbt-homework` folder to `/wp-content/plugins/`.
2. Activate TBT Homework through the Plugins screen. Activation creates the
   table.
3. Make sure TBT Notes 1.18.0 or newer is active.

== Frequently Asked Questions ==

= Can another student see my homework? =

No. Only the teacher of your class can, on their own queue page. There is no
route that returns another student's work to a student, and nothing here is ever
rendered on a public page, in a feed, or to another student.

= Can one teacher see another teacher's students? =

No. Every read is bounded by the classes that teacher manages, and commenting
checks the stored row's own class before writing anything.

= What happens if I delete the plugin? =

Deleting drops the table and its options. Deactivating keeps everything.

== Changelog ==

= 0.3.0 =
* New: `[tbt_homework_teacher]`, the teacher's queue, with the waiting count,
  a Waiting / Commented / All filter, search, and 25 rows a page.
* New: `GET /queue` and `POST /comment`. Every read is bounded by the classes
  the caller manages, and the comment route checks the stored row's own class.
* New: clearing a comment returns the homework to the student to edit.
* The Admin Bar now lives in one stylesheet both library pages read.

= 0.2.0 =
* New: `[tbt_homework_student]`, the student's own library, with the shared
  Admin Bar, a search and an All / Waiting for comment / Commented filter.
* Submissions whose lesson a student may no longer view drop out of the list
  rather than erroring.

= 0.1.0 =
* First release: plugin scaffolding, the `tbt_homework` table, two REST routes,
  and the homework form under a TBT Notes lesson note.
