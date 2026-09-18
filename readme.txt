=== TBT Homework ===
Contributors: tbt
Requires at least: 6.0
Tested up to: 6.6
Requires PHP: 8.0
Stable tag: 0.1.0
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Private homework handover between a student and their teacher, under a TBT Notes lesson.

== Description ==

TBT Homework adds a homework card under a TBT Notes lesson note. A student
writes their homework there and sends it; their teacher, in a later release,
reads and comments on it.

Everything is private between one student and their teacher. No submission is
ever shown on a public page, in a feed, or to another student.

This release, 0.1.0, is the plugin's foundation plus one working thing: the
form under the note, and the storage behind it.

* One submission per student per lesson.
* Edit until the teacher comments; after that the note is closed for changes.
* Plain text only.
* Times stored in UTC, displayed in the site's timezone.

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

No. There is no route in this release that returns anyone else's work, and
lookups are keyed on the logged-in user.

= What happens if I delete the plugin? =

Deleting drops the table and its options. Deactivating keeps everything.

== Changelog ==

= 0.1.0 =
* First release: plugin scaffolding, the `tbt_homework` table, two REST routes,
  and the homework form under a TBT Notes lesson note.
