# Converts "Biomedical Timetable.xlsx" (one tab per class, each period two rows
# deep: the time row and a "Room no." row, Monday to Friday across) into the
# hand-transcription format build.php reads, so its merged cells (a
# two-hour lab) survive: read_xlsx() in the dashboard does not see merges.
#
#   python convert-biomedical.py "Biomedical Timetable.xlsx" biomedical-2026-09-30.json
#
# Needs openpyxl. Cells read "CODE (INITIALS) - ULC 1 809". Rooms keep their
# ULC ("ULC 5 106"); anything with no room (PROJECT, MDMC) is printed.
# A merge counts the periods it covers completely, so a cell that also spills
# half into the next period (Sem III Wednesday) does not claim it.
import json, os, re, sys
import openpyxl

DAYS = ['Monday', 'Tuesday', 'Wednesday', 'Thursday', 'Friday']
TABS = {
    'SEM III': 'B.Tech Biomedical Sem III', 'SEM V': 'B.Tech Biomedical Sem V',
    'SEMVII': 'B.Tech Biomedical Sem VII', 'MTech': 'M.Tech Biomedical Sem I',
}
# Initials -> name. Faculty names, so kept in the gitignored data folder.
FACULTY = json.load(open(os.path.join(os.path.dirname(os.path.abspath(__file__)), 'data', 'biomedical-faculty.json'), encoding='utf-8'))
# Codes are per tab: MDMC is a different course in Sem III and Sem V.
SUBJECTS = {
    'SEM III': {'S&S': 'Signals and Systems', 'EM 3': 'Engineering Mathematics 3',
                'ADE': 'Analog and Digital Integrated Electronics', 'IP': 'Introduction to Programming',
                'BSM': 'Biomedical Sensors and Measurements', 'MDMC': 'Fundamentals of Biomedical Instrumentation (MDMC)'},
    'SEM V': {'HTMD': 'Healthcare Technologies and Medical Devices', 'MM': 'Microprocessor and Microcontroller',
              'TE': 'Therapeutic Equipment', 'BPD': 'Biomedical Product Development',
              'BSP': 'Biomedical Signal Processing', 'MDMC': 'Biomedical Sensors and Transducers (MDMC)'},
    'SEMVII': {'RE': 'Rehabilitation Engineering', 'AIHM': 'AI in Healthcare Management',
               'RAM': 'Robotics and Automation in Medicine', 'MIS': 'Medical Imaging Systems',
               'Minor': 'Minor: Medical Imaging Systems'},
    'MTech': {'ICDBI': 'Integrated Circuit Design for Biomedical Instrumentation', 'AHP': 'Anatomy and Human Physiology',
              'AB': 'Advanced Biomaterials', 'BSPA': 'Biomedical Signal Processing & Analysis',
              'BSPA LAB': 'Biomedical Signal Processing & Analysis Lab',
              'ABRE': 'Advanced Biomechanics and Rehabilitation Engineering'},
}
CELL = re.compile(r'^(.*?)\s*\(\s*([A-Z]+)\s*\)\s*-?\s*ULC\s*(\d)\s*-?\s*(\d{3})(?:\s*/\s*(\d{3}))?\s*$')

wb = openpyxl.load_workbook(sys.argv[1])
out = {'source': 'Biomedical Timetable.xlsx',
       'note': 'Converted by convert-biomedical.py from the four class tabs (version 4, W.E.F 30/09/2026; M.Tech version 2, W.E.F 28/09/2026). The MASTER TIME TABLE tab is still headed 2025-26 and disagrees in places, so it is not used.',
       'faculty': {k: v for k, v in FACULTY.items()}, 'subjects': {}, 'classes': []}
out['faculty'][''] = ''
for tab, label in TABS.items():
    ws = wb[tab]
    head = next(r for r in range(1, 20) if str(ws.cell(r, 1).value or '').startswith('Time / Day'))
    cols = {c: str(ws.cell(head, c).value).strip() for c in range(2, 7)}
    # period rows: a time label in column A; each period owns its row and the next
    periods = []
    for r in range(head + 1, head + 20):
        v = str(ws.cell(r, 1).value or '').strip()
        if re.match(r'^\d{1,2}\.\d{2}\s*to\s*\d{1,2}\.\d{2}$', v):
            periods.append((r, v))
    rows_of = {}
    for i, (r, v) in enumerate(periods):
        nxt = periods[i + 1][0] if i + 1 < len(periods) else r + 2
        rows_of[v] = (r, nxt - 1)
    merges = {}
    for m in ws.merged_cells.ranges:
        merges[(m.min_row, m.min_col)] = (m.max_row, m.max_col)
    entries = []
    for c, day in cols.items():
        for r, start in periods:
            val = str(ws.cell(r, c).value or '').strip()
            top = r
            if not val:
                # a merge that starts on the "Room no." row of this period (a
                # one-row LUNCH or BREAK period has no such row)
                rr = r + 1
                if rr > rows_of[start][1]: continue
                val = str(ws.cell(rr, c).value or '').strip()
                if not val or (rr, c) not in merges: continue
                top = rr
            if (top, c) in merges and merges[(top, c)][1] > c: continue  # LUNCH / BREAK across days
            last = merges.get((top, c), (top, c))[0]
            covered = [s for s, (a, b) in rows_of.items() if a >= top and b <= last]
            teaching = [s for s in covered if not re.match(r'^(12\.30 to 1\.15|3\.15 to 3\.30)$', s)]
            m = CELL.match(val)
            if not m:
                if val.upper() not in ('LUNCH', 'BREAK'): print(f'  {tab} {day} {start}: "{val}" has no ULC 1 room, left out')
                continue
            code, who, ulc, room, alt = m.groups()
            code = code.strip(' -')
            if ulc != '1':
                room = f'ULC {ulc} {room}'   # the build files it under that ULC
            if not teaching:
                continue
            note = f'{room} or {alt}' if alt else ''
            if alt: print(f'  {tab} {day} {teaching[0]}: "{val}" names two rooms, used {room}')
            if len(covered) != len(teaching): print(f'  {tab} {day} {teaching[0]}: "{val}" runs into a break row')
            key = f'{tab}|{code}'
            out['subjects'][key] = SUBJECTS[tab].get(code, code)
            lab = 'LAB' in code.upper()
            entries.append([day, teaching[0].split(' to ')[0], len(teaching), key, room, who, lab, note])
    out['classes'].append({'class': label, 'page': tab, 'entries': entries})
    print(f'{label}: {len(entries)} bookings')
json.dump(out, open(sys.argv[2], 'w', encoding='utf-8'), indent=1, ensure_ascii=False)
