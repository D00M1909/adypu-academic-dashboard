# ADYPU Academic Dashboard — Project Spec

How the project works as built. First written 26 Aug 2026 as a plan; rewritten
23 Oct 2026 to match what ships. Read this first.

---

## 1. What this is

Daily attendance, room timetables and teaching workload for Ajeenkya D Y Patil
University, live at <https://adypu-daybook.fast-page.org/>.

It is a separate project from `adypuacademicreport` (the monthly chairman's
report site). The two share branding and should feel like one product, but they
are separate repos with separate data.

## 2. Pages

| Page | Who | What |
|---|---|---|
| `index.php` | everyone | Attendance dashboard. `?view=partners` is the Knowledge Partner tab |
| `rooms.php` | everyone | Room timetable: Rooms, Free slots, All rooms, Overlapping slots, Dean and Faculty workload |
| `login.php` | faculty | Sign in, sign up with a school join code |
| `mark.php` | faculty | Mark attendance from a phone: tick absentees, or type a count |
| `account.php` | faculty | Name and password |
| `admin.php` | admin | Faculty accounts, the signup queue, join codes, temporary passwords |
| `stats.php` | admin | Usage: visitors, page views, pushes received, classes reported |
| `activity.php` | admin | Per-account log, a leaderboard of who files, the accounts list |
| `source.php` | local only | A timetable cell in its source document (off on the live site) |

Header: Attendance and Timetable tabs, Mark attendance (signed in), a theme
switch, the account menu. No emoji anywhere: icons are the inline SVG sprite.

## 3. Brand

| Token | Value |
|---|---|
| Primary red | `#C21B27` |
| Secondary red | `#99151F` |
| Tint / active background | `#fef2f2` |
| Total-row background / text | `#fde3e6` / `#7a1017` |
| Fonts | IBM Plex Sans (text), IBM Plex Mono (numbers), Playfair Display (titles) |

Light and dark themes; the choice is remembered. Tab bars are grey pills with
red text on the selected tab. Every page fits a phone; wide grids scroll
inside themselves.

## 4. Schools and partners

Nine schools, ids shared with the sibling project:
`eng` `mgmt` `law` `design` `science` `arch` `hosp` `lib` `film`.

**Knowledge partners are their own tree** (partner → year → program →
division), ids `kp-<name>`. They never count toward a school tile, the
university percentage or the classes-reported pill, and show only on the
Partners tab with their own totals.

## 5. Data model

**`includes/structure.php` is canonical**: every School → Year → Branch →
Division that exists, and its strength. Faculty submit only a present count;
the denominator never comes from a submission. A school with no branches uses
an empty branch and the UI skips that step. Years vary per school, never
hardcoded. Schools whose headcounts have not arrived stay in
`placeholder_schools()` rather than show a percentage over invented numbers.

**A reading is one present count per class per lecture**:
`days[date][classKey][HH:MM] = present`, with a parallel `faculty` map for who
filed it. A whole-day answer files under the slot `day`.

- A day's number for a class is its **latest** reading; earlier ones are kept
  and listed under the class.
- A date range **averages** each class's days, never sums, so a week reads like
  a day; a class that reported once says so.
- Percentages divide by the **classes that reported**, and are always shown
  beside the `R/N classes reported` pill.
- With no dates in the URL the page opens on the newest day that has data.

**Rosters** (`data/roster/<school>.php`) are per school, keyed by class. A class
with a roster gets a tick list in `mark.php` and `present` is computed on the
server; one without gets a number box, clamped to its strength.

## 6. Data pipeline

```
Google Form -> response Sheet -> Apps Script (tools/apps-script.gs)
                                    | POSTs the whole sheet as CSV,
                                    | secret in X-Ingest-Secret
                                    v
                               api/ingest.php -> cache/attendance.json (replaced every push)

Faculty phone -> mark.php -> data/submissions.php

cache/attendance.json + data/submissions.php -> merge_readings() -> index.php, api/division.php
```

- **Push, not pull.** The host blocks outbound HTTP from PHP, so Google sends
  the sheet to us. The cache file is the database; losing it costs nothing,
  since the next push rebuilds it in full.
- **Two writers, two files.** Ingest replaces its file on every push, so the app
  writes its own; the two are merged on read, the app winning on the same class,
  day and lecture.
- **The Form** asks School (which jumps to that school's section), Class (one
  dropdown per section, options from `php tools/form-options.php`), Present,
  Date of class, Faculty name and Time slot. Question titles are not
  load-bearing: a cell that parses as a real class label is the class.
- **Rows naming no known class are dropped** and named in the Apps Script log
  and the cache's `push` block.
- **MySQL (`db/schema.sql`) is not read from.** `get_db()` is lazy and the app
  runs without a database.
- A fresh checkout with nothing pushed shows bundled sample rows.

## 7. Accounts

The dashboard and timetable are open to everyone; only writing attendance needs
a login. There is no email on the host, so:

- Signup takes a per-school **join code** (instant); a wrong code queues the
  request for an admin instead of refusing it.
- Recovery is an admin generating a temporary password, shown once.
- The first admin is set in `includes/config.local.php` (server only).
- Sessions carry only the email and re-read the account each request, so
  disabling an account takes effect on its next click. A 30-day remember token
  sits beside the session.

Everything the live site writes for itself lives in `data/`: JSON behind a
`<?php exit; ?>` first line, so requesting one over the web returns a blank page.
It is gitignored and never uploaded.

## 8. Room timetable

Built offline from each school's timetable workbooks and PDFs by
`tools/timetable/build.php` into `timetable/rooms.php`. The source documents and
transcriptions carry faculty names, so they stay out of the repo
(`tools/timetable/data/`, gitignored) and so does the built file.

- **Rooms**: one room's week, picked by school, building and room. Opens on the
  whole week.
- **Free slots**: the same grid with free time in green, worked out by the clock
  across the schools' different period times, 8.00 to 5.45.
- **All rooms**: every room in a building as rows, one day at a time.
- **Overlapping slots**: a room booked twice at once, or a teacher down for two
  rooms at once.
- **Dean / Faculty workload**: one person's week across every room and their
  weekly hours, plus a per-school list of every teacher's hours.
- **Classes with no room** (minors, sheets that name no room) count toward a
  teacher's week and hours but never appear on a room grid.
- Tapping a class shows its attendance for that lecture.

Design's timetables are dated calendars rather than weekly grids, so the week
shown follows the date.

## 9. Tech and deploy

- PHP 8, no framework, no Composer. Plain `<script src>` JavaScript, no build
  step. Charts are hand-drawn SVG (`js/charts.js`).
- Hosted on InfinityFree: FTP only. `tools/stage-upload.sh [ref|--all]` copies
  exactly the files to upload into `upload/`, paths intact. Never uploaded:
  `tools/`, `tests/`, `data/`, `includes/config.local.php`.
- Server-only config (`includes/config.local.php`): `INGEST_SECRET`, which must
  match the Apps Script, and the first admin.
- Pages send `Cache-Control: no-store`, because the host's edge caches pages.
- Export PDF is the browser's print dialog over a print stylesheet.
- Tests are assert scripts in `tests/`, no framework. Run PHP ones with
  `php -d zend.assertions=1 -d assert.exception=1 tests/<file>.php`.

## 10. Open items

1. Real headcounts for Engineering 1st Year and the placeholder schools: the
   one thing left that makes every percentage true.
2. Student lists for the schools that have not sent one; each class moves from
   the number box to a tick list as its list lands.
3. Timetables for the schools not yet transcribed, and the abbreviations no
   sheet explains.
