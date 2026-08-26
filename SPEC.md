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
| Code written | none yet |
| Data source for attendance | **unresolved — see §8, this is the main blocker** |
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

**Recommended: plain HTML/CSS/JS, no build step** — matching the sibling project,
which does exactly this and works fine. Concretely, how the sibling is put
together:

- One `index.html`, a `css/` folder, a `js/` folder of plain `<script src>` files
  loaded in dependency order. **Not** ES modules — everything lives on `window`
  because inline `onclick=` handlers need it. There's an explicit comment in the
  sibling's `index.html` saying so.
- Libraries from CDN: SheetJS (`xlsx` 0.18.5), Chart.js, chartjs-plugin-datalabels.
- Excel is `fetch`ed as an arrayBuffer and parsed client-side with
  `XLSX.read(data, {type:'array'})`; sheets are flattened to row objects.
- Total sibling codebase is ~3,100 lines. This project should be smaller.

This is a default, not a mandate. A build step is justified if the attendance data
turns out to need a real backend (see §8) — reassess then.

## 8. Open questions — resolve before or during implementation

1. **Where does attendance data come from? (blocker)** The mockups show live
   per-student, per-division attendance. The sibling project's data is monthly
   chairman-report workbooks with no attendance in them at all. So this dashboard
   has no data source yet. Options: another Excel export from the university's
   ERP, a real API, or hardcoded sample data to build the UI against first.
   Recommended: build against sample data shaped like §6, keep the loading behind
   one function, swap it later.
2. **"Live Sync Active" and the modal's refresh button** imply polling a live
   source. Decorative for now, or real? Depends on (1).
3. **The Report button** in the modal — what does it produce? The sibling project
   has a 575-line `js/reports.js` doing print-style report generation; that is the
   obvious thing to borrow from.
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
