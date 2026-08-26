# ADYPU Academic Dashboard — Design Spec

Reconstructed from the design session of 25 Aug 2026. The interactive canvas is at
`design/adypu-drilldown-design-options.html` (open in a browser — it is a bundled
export and not readable as source).

## What this is

A drill-down academic dashboard: **School → Year → Division → Student attendance**.
Separate project from `adypuacademicreport` (the monthly report site); this one is
the live academic/attendance view.

## Brand

Inherited from the existing ADYPU dashboard so the two sites feel like one product.

| Token | Value |
|---|---|
| Primary red | `#C21B27` |
| Secondary red | `#99151F` |
| Tint / active bg | `#fef2f2` |
| Total-row bg / text | `#fde3e6` / `#7a1017` |
| Surface | `#f8fafc` |
| Hairline border | `#f1f5f9` |
| Radius | 14px tiles, 18px cards |
| Card shadow | `0 20px 50px rgba(0,0,0,.2)` (modals) |

Gradient header, rounded cards, shadow hierarchy — same as the existing site.

## Top-level navigation (shared by both options)

Two tabs:

- **ADYPU** (default) — dropdown listing all 9 schools with icons:
  Engineering, Management, Law, Design, Science, Architecture, Hospitality,
  Liberal Arts, Film & Media.
  Active school: left border `#C21B27`, background `#fef2f2`, icon inverted
  (red fill, white glyph).
- **Knowledge Partner** — MoU partner list with metadata tags.
  Sample partners: Partner Institute of Technology, Global Skills Academy,
  Horizon Career Institute, Sunrise Polytechnic.

## Data-model constraint

**Year count varies per school.** School of Engineering has 4 years, School of Law
has 5. Never hardcode 4 — derive year tiles from the school's record.

## Option A — Native drill-down

Focused, progressive-disclosure path. Matches the current site's cards, dropdowns
and typography.

Flow: `ADYPU tab → school → year pills → division chips → student table`

- School header: name in `#C21B27`, description, "Live Sync Active" badge with
  pulse indicator.
- Three KPI cards: Total Strength (e.g. 312), Present Today (e.g. 50/60 with date +
  division context), Divisions Shown (e.g. 4).
- Year selector: pill buttons, one per year, active state styled.
- Division selector: chips (Div A–D) — **rendered only after a year is picked**
  (progressive disclosure, deliberate).
- Student table: Roll No. | Name | Division | Attendance. Status badges green
  (Present) / red (Absent). Total row at the bottom sums the division (e.g.
  50/60 Present) with distinct styling.

## Option B — Dashboard + modal

Everything on one scrolling page. Closer to the reference screenshots.

- Four gradient-red stat cards, each with an icon: Total Schools (9),
  Total Strength (3000+), Present Today (50/60), Attendance Date (25 Aug 2026).
- Schools section: 3-column tile grid, all 9 schools, centered icon + label.
- Years section: 4-column tile grid for the selected school ("School of
  Engineering — Years"), active year filled red.
- Section titles carry right-aligned metadata (e.g. "4 years · 2nd Year selected").
- Tiles: `#f8fafc` bg, `#f1f5f9` border, 14px radius; active = `#fef2f2` bg,
  `#C21B27` text and border.

### Division-wise attendance modal

Opens from the "Present Today" stat card.

- Header: title "Present Students Today" in `#C21B27`, refresh icon (circular red
  bg), close X.
- Subtitle: "School of Engineering — Division-wise Present Count (2nd Year)".
- 2-column grid, one row per division, label/value flex pair with
  `border-bottom: #f1f5f9`. Sample: Div A 25/30, Div B 50/60, Div C 18/30,
  Div D 7/30.
- Total row: `#fde3e6` bg, `#7a1017` text, aggregate (100/150) + date.
- Red "Report" button at the bottom with shadow.

## Decision

**Decided (26 Aug 2026): Option B** — dashboard with stat tiles, school/year
tile grids, and the division-wise attendance modal.
