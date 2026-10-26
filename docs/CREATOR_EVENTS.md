# Creator Events 1.0

Install the bundled official plugin in **Custom Plugins → Install Creator Events**.
Only the server owner can install, enable, disable or manage contests. Installation
creates five dedicated tables; disabling retains contests, entries and reviews.
No remote PHP package is downloaded or executed. Custom Plugins' external catalog
remains a discovery preview.

Create a draft with a title, plain-text rules, a future UTC deadline and 1–20
required reviews. Assign active player accounts to the jury, then open submissions.
Rules, deadline and jury membership lock when submissions open. A contest accepts
up to 200 entries. The admin list shows the latest 100 contests.

Share `/extensions/creator-events` (on shared hosting, `/mc/extensions/creator-events`).
Players sign each submission with their username/password over HTTPS. The portal
stores only a CSRF token in its session. No modified game client is required.
An author may submit or replace one public, non-deleted level they own, or withdraw
it before the deadline. Judges cannot enter their own contest.

After the deadline, select **Start judging**. Assigned judges use the same portal
to score an entry's Design, Gameplay and Originality from 0–10 and leave a note.
A judge can revise their review while judging is open. The admin workspace shows
review progress and scorecards. Level content hashes are checked during review
and publication. Changed, deleted, private or transferred levels cannot publish
with stale scores; the owner can disqualify an entry with a visible reason.

**Publish results** requires the minimum reviews on every eligible entry. The
result is the mean of judges' 0–30 totals. Equal results share competition rank
(e.g. 1, 1, 3). Published scores/ranks are frozen; cancellation is available before
publication. Publication does not alter game ratings, currency or other rewards.

This is a bundled SDK plugin with `routes` and `database` capabilities. Its code
updates with the reviewed core; its installation/enabled state and data live in
the database. Keep the core's global plugin switch enabled. Shared hosting also
honors the account and write switches; GET remains available in read-only mode.
