# ADYPU Academic Dashboard — Project Spec

Single source of truth for this project. Written 26 Aug 2026, before implementation
started. Read this first.

---

## 1. What this is

A live academic dashboard for Ajeenkya D Y Patil University: drill down from
**School → Year → Division → individual student attendance**.

It is a **separate project** from `adypuacademicreport` (the sibling folder). That
one is a monthly chairman's report site — admissions, placements, research,
events, rankings, rendered from monthly Excel workbooks. This one is the live
day-to-day academic/attendance view. They share branding and should feel like one
product, but they are separate repos with separate data.

## 2. Status

| | |
|---|---|
| Layout decision | **Option B** (see §5) — decided 26 Aug 2026 |
| Code written | initial implementation in progress |
| Data source for attendance | **resolved — see §8.1**: Google Form → Sheet, PHP reads it server-side |
| Visual mockups | `design/adypu-drilldown-design-options.html`, untracked, open in a browser |

## 3. Brand tokens

Inherited from the existing report site so the two match.

| Token | Value |
|---|---|
| Primary red | `#C21B27` |
| Secondary red | `#99151F` |
| Tint / active background | `#fef2f2` |
| Total-row background / text | `#fde3e6` / `#7a1017` |
| Surface | `#f8fafc` |
| Hairline border | `#f1f5f9` |
| Radius | 14px tiles, 18px cards |
| Modal shadow | `0 20px 50px rgba(0,0,0,.2)` |

Gradient header, rounded cards, layered shadows — same visual language as the
sibling site's `css/dashboard.css`.

## 4. Navigation shell

Two top-level tabs:

- **ADYPU** (default) — all nine schools.
- **Knowledge Partner** — MoU partner institutions with metadata tags. Sample
  names from the mockup: Partner Institute of Technology, Global Skills Academy,
  Horizon Career Institute, Sunrise Polytechnic.

The nine schools, with the ids the sibling project already uses (reuse these so
data can be cross-referenced later):

| id | School |
|---|---|
| `eng` | School of Engineering |
| `mgmt` | School of Management |
| `law` | School of Law |
| `design` | School of Design |
| `science` | School of Science |
| `arch` | School of Architecture |
| `hosp` | School of Hospitality |
| `lib` | School of Liberal Arts |
| `film` | School of Film & Media |

The sibling project also carries legacy aliases (`SOD`, `SOHM`, `SOS`, `SOFM`)
mapping onto design/hospitality/science/film. Don't reproduce those here; they are
a data-cleanliness wart, not a feature.

## 5. Layout — Option B (decided)

One scrolling dashboard page, detail in a modal. Chosen over Option A because it
matches the reference screenshots from the stakeholder meeting.

**Top: four gradient-red stat cards**, each with an icon —
Total Schools (9) · Total Strength (3000+) · Present Today (50/60) ·
Attendance Date (25 Aug 2026).

**Schools section** — 3-column tile grid, all nine schools, centered icon above
label. Section title with right-aligned metadata (`9 schools`).

**Years section** — appears for the selected school, titled
`School of Engineering — Years`, 4-column tile grid, metadata reads
`4 years · 2nd Year selected`. Active year filled red.

**Tile styling** — background `#f8fafc`, border `#f1f5f9`, radius 14px.
Active: background `#fef2f2`, text and border `#C21B27`.

### Division-wise attendance modal

Opens from the **Present Today** stat card.

- Header: title "Present Students Today" in `#C21B27`, circular red refresh
  button, close X.
- Subtitle carries the context: `School of Engineering — Division-wise Present
  Count (2nd Year)`.
- 2-column grid, one row per division, label/value flex pair separated by
  `border-bottom: #f1f5f9`. Mockup values: Div A 25/30, Div B 50/60,
  Div C 18/30, Div D 7/30.
- Total row: background `#fde3e6`, text `#7a1017`, aggregate `100/150` plus the
  date.
- Red **Report** button at the bottom with a drop shadow.

### Option A — rejected, kept for reference

A focused drill-down: school header with a "Live Sync Active" pulse badge, three
KPI cards (Total Strength 312 · Present Today 50/60 · Divisions Shown 4), year
pills, then division chips revealed **only after** a year is picked, then a
student table (Roll No. | Name | Division | Attendance) with green/red status
badges and a totals row.

Not being built, but two ideas from it are worth keeping: the progressive
disclosure of divisions, and the student-level table — Option B's modal stops at
division counts and gives no per-student view. Decide whether that table appears
later as a second modal or a sub-page.

## 6. Data model

**Year count varies per school.** Engineering has 4 years, Law has 5. Never
hardcode 4 — derive the year tiles from the school's own record. This was the one
constraint called out explicitly during the design session.

Rough shape:

```
School { id, name, icon, years[] }
Year   { label, divisions[] }
Division { label, strength, presentToday }
Student  { rollNo, name, division, present }   // if per-student view is built
```

## 7. Tech stack

**PHP, no framework** — matching `automatic-timetable-generator`, the sibling
project's own project (plain PHP, `includes/config.php` pattern, deployed by
copying files onto XAMPP locally and InfinityFree for a shared link). Concretely:

- `index.php` server-renders the shell (stat cards, school/year tile grids) from
  data provided by `includes/attendance.php`.
- `api/division.php` — a small JSON endpoint the modal's JS calls for a given
  school + year's division breakdown; also used by the modal's refresh button to
  force a fresh pull past the cache.
- `css/dashboard.css` + `js/dashboard.js` — plain non-module JS (`<script src>`,
  functions on `window` for inline handlers), no CDN libraries needed for v1.
- `includes/config.php` — MySQL connection (mysqli), lazy (`get_db()` only
  connects when called) so the app doesn't require a DB to run — see §7.1.
- No Composer dependencies for v1; add one only if a real need shows up (e.g.
  parsing a non-CSV export format).

### 7.1 Data pipeline (resolves §8.1)

- **Collection:** one Google Form, filled once a day per division by faculty —
  Date, School, Year, Division, Strength, Present.
- **Storage of record:** the Google Sheet the Form feeds *is* the source of
  truth. `includes/attendance.php` fetches its published-to-web CSV export
  server-side (`file_get_contents`), parses it (`str_getcsv`, no library), and
  aggregates rows into School → Year → Division, keeping only the latest
  submission per school/year/division/date (a resubmission overwrites same-day
  data). Result is cached to `cache/attendance.json` with a 5-minute TTL so the
  dashboard isn't hitting Google on every request.
- **Sample data fallback:** until `SHEET_CSV_URL` is configured (the Form/Sheet
  don't exist yet) or a fetch fails, `includes/attendance.php` serves bundled
  sample rows shaped like real submissions, so the UI is buildable and testable
  right now. Swapping to the real Sheet is a one-line env var, no code change.
- **MySQL — nice to have, not read from yet.** `db/schema.sql` defines `schools`
  (seeded with the nine ids from §4) and `attendance_records` (same shape as a
  Sheet row: school, year, division, date, strength, present). The app never
  queries these tables in v1 — they exist so a daily sync job can start writing
  attendance history into them later (for trends over time) without a schema
  change or an app-flow rewrite. `includes/config.php` connects lazily so
  running the app doesn't require the DB to exist.
- **Error handling:** if the Sheet is unreachable or a row is malformed, fall
  back to the last good cached data rather than breaking the page — a
  transient Google outage shouldn't take down a once-a-day dashboard.

## 8. Open questions — resolve before or during implementation

1. ~~**Where does attendance data come from?**~~ Resolved — see §7.1.
2. **"Live Sync Active" and the modal's refresh button** imply polling a live
   source. Decorative for now, or real? Depends on (1).
3. **The Report button** — removed from the modal for now (26 Aug 2026); it was
   a stub with no defined output. Re-add only once its report format is decided
   (PDF? printable view? CSV export?).
4. **Per-student table** — Option B has no per-student view. Needed for v1?
5. **Knowledge Partner tab** — the mockup shows a partner list with tags, but no
   drill-down was designed. What happens when a partner is clicked?
6. **Total Strength "3000+"** — is the real number known, or is the rounded
   figure intentional?

## 9. Suggested build order

1. Static shell: header, two tabs, four stat cards with hardcoded numbers.
2. School tile grid, selection state.
3. Year tile grid, driven by the selected school's record (variable year counts).
4. Division modal off the Present Today card.
5. Swap hardcoded data for whatever §8.1 resolves to.
6. Report button, once §8.3 is answered.
