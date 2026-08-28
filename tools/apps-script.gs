/**
 * Pushes the Form's response sheet to the dashboard.
 *
 * Google pushes to us rather than the dashboard pulling from Google because
 * InfinityFree's free plan blocks outbound HTTP from PHP. Inbound is fine —
 * except that InfinityFree also serves an anti-bot JavaScript challenge to some
 * requests, at random. That challenge returns HTTP 200 with an HTML body, so a
 * naive check of the status code reports success while storing nothing.
 *
 * The strategy is retry, not defeat: a blocked run gives up quietly and the
 * next scheduled run tries again from a different Google IP. Attendance is a
 * once-a-day number, so being up to 15 minutes late costs nothing. What does
 * cost something is not noticing a permanent outage, so consecutive failures
 * are counted and eventually raised as a real error.
 *
 * Setup (once):
 *   1. Open the Form's response Sheet -> Extensions -> Apps Script.
 *   2. Paste this file in, replacing whatever is there.
 *   3. Set INGEST_URL and INGEST_SECRET below. The secret must match the
 *      INGEST_SECRET in includes/config.local.php on the server.
 *   4. Run pushNow() once and approve the permissions prompt.
 *   5. Triggers (clock icon) -> Add Trigger:
 *        - pushNow / From spreadsheet / On form submit
 *        - pushNow / Time-driven / Minutes timer / Every 5 minutes
 *      The first is instant when it gets through; the second is the safety net
 *      that eventually gets through. Five minutes rather than fifteen because
 *      only about a third of runs get past the host's bot check, so the gap
 *      between successes is several times the trigger interval.
 *   6. Run pushStatus() any time to see when the last push actually landed.
 *
 * The Form needs a Date question in its first section, shared by every school,
 * so faculty can report a class they held yesterday. Its column is what the
 * dashboard's date range filters on; without it a row falls back to the day it
 * was submitted. See toCsv(): Date cells are formatted yyyy-MM-dd for the
 * parser, and that must not change.
 */

const INGEST_URL = 'https://YOUR-SITE.rf.gd/api/ingest.php';
const INGEST_SECRET = 'paste-the-same-secret-as-config.local.php';

// Attempts within a single run. Measured on 27 Aug 2026 across ~20 runs: when
// the first attempt was blocked, attempts 2 and 3 were blocked every single
// time. The challenge tracks the calling IP, and a retry three seconds later
// comes from the same one — so in-run retries bought nothing and cost ~8s per
// blocked run. Recovery comes from the NEXT run, minutes later, on a different
// Google IP. Raise this only if that stops being true.
const ATTEMPTS_PER_RUN = 1;
const RETRY_PAUSE_MS = 3000;

// Consecutive failed runs before this starts throwing. Keep this at roughly two
// hours' worth of runs: long enough to ride out a bad streak of the host's bot
// check, short enough to catch a genuinely broken site the same morning.
// At one run per 5 minutes, 24 runs is two hours.
const FAILURES_BEFORE_ALERT = 24;

function pushNow() {
  if (!/\/api\/ingest\.php$/.test(INGEST_URL)) {
    throw new Error('INGEST_URL must end in /api/ingest.php — got: ' + INGEST_URL);
  }
  if (!INGEST_SECRET || INGEST_SECRET.indexOf('paste-the-same') === 0) {
    throw new Error('INGEST_SECRET is still the placeholder');
  }

  const sheet = SpreadsheetApp.getActiveSpreadsheet().getSheets()[0];
  const values = sheet.getDataRange().getValues();
  if (values.length < 2) {
    Logger.log('nothing to push: sheet has no data rows');
    return;
  }
  const payload = toCsv(values);

  let lastProblem = '';
  for (let attempt = 1; attempt <= ATTEMPTS_PER_RUN; attempt++) {
    const outcome = attemptPush(payload);
    if (outcome.ok) {
      recordSuccess(outcome.result);
      return;
    }
    lastProblem = outcome.problem;
    Logger.log('attempt ' + attempt + ' of ' + ATTEMPTS_PER_RUN + ' failed: ' + lastProblem);
    if (attempt < ATTEMPTS_PER_RUN) Utilities.sleep(RETRY_PAUSE_MS);
  }

  recordFailure(lastProblem);
}

function attemptPush(payload) {
  const response = UrlFetchApp.fetch(INGEST_URL, {
    method: 'post',
    contentType: 'text/csv',
    payload: payload,
    headers: { 'X-Ingest-Secret': INGEST_SECRET },
    muteHttpExceptions: true,
    followRedirects: true,
  });

  const code = response.getResponseCode();
  const body = response.getContentText();

  if (code !== 200) {
    return { ok: false, problem: 'HTTP ' + code + ': ' + body.slice(0, 200) };
  }

  // A 200 proves nothing here. InfinityFree's challenge and the dashboard's own
  // homepage both answer 200 with HTML; only our endpoint answers with JSON.
  let result;
  try {
    result = JSON.parse(body);
  } catch (e) {
    const blocked = body.indexOf('__test') !== -1 || body.indexOf('aes.js') !== -1;
    return {
      ok: false,
      problem: blocked
        ? "blocked by the host's bot check (not our endpoint) — will retry"
        : 'expected JSON, got HTML — is INGEST_URL pointing at api/ingest.php? ' + body.slice(0, 120),
    };
  }

  if (!result.ok) {
    return { ok: false, problem: 'ingest refused the push: ' + body.slice(0, 200) };
  }
  return { ok: true, result: result };
}

function recordSuccess(result) {
  const props = PropertiesService.getScriptProperties();
  props.setProperty('lastSuccess', new Date().toISOString());
  props.setProperty('consecutiveFailures', '0');
  Logger.log(
    'pushed ' + result.rows + ' rows across ' + result.days + ' days. ' +
    'On ' + result.latest + ' (the day the dashboard opens on): ' +
    result.present + '/' + result.strength + ' present, ' +
    result.reported + '/' + result.classes + ' classes reported'
  );
  // Rows the sheet holds that name no class the dashboard knows. They are not
  // an error the push can fix, but they are silently missing numbers, so say so.
  if (result.skipped && result.skipped.length) {
    Logger.log(
      'IGNORED ' + result.skipped.length + ' row(s) naming no known class:\n  ' +
      result.skipped.join('\n  ')
    );
  }
}

function recordFailure(problem) {
  const props = PropertiesService.getScriptProperties();
  const failures = Number(props.getProperty('consecutiveFailures') || '0') + 1;
  props.setProperty('consecutiveFailures', String(failures));
  props.setProperty('lastProblem', problem);

  const since = props.getProperty('lastSuccess') || 'never';
  const summary = failures + ' consecutive failed runs (last success: ' + since + '). ' + problem;

  if (failures >= FAILURES_BEFORE_ALERT) {
    // Throwing makes Apps Script email the sheet owner. Worth it now: this is
    // no longer a random block, it is an outage.
    throw new Error('attendance push has been failing for a while — ' + summary);
  }
  Logger.log('giving up this run, next run will retry. ' + summary);
}

/** Run by hand to see whether pushes are actually landing. */
function pushStatus() {
  const props = PropertiesService.getScriptProperties();
  Logger.log(
    'last successful push: ' + (props.getProperty('lastSuccess') || 'never') +
    '\nconsecutive failures: ' + (props.getProperty('consecutiveFailures') || '0') +
    '\nlast problem: ' + (props.getProperty('lastProblem') || 'none')
  );
}

/**
 * The header row is what the PHP parser matches on, so it is lowercased here
 * and nowhere else. Dates become yyyy-mm-dd because that is the format the
 * parser compares when deciding which of a class's readings is the latest.
 */
function toCsv(values) {
  return values
    .map((row, i) =>
      row
        .map(cell => quote(i === 0 ? String(cell).trim().toLowerCase() : format(cell)))
        .join(',')
    )
    .join('\n');
}

function format(cell) {
  if (cell instanceof Date) {
    return Utilities.formatDate(cell, Session.getScriptTimeZone(), 'yyyy-MM-dd');
  }
  return String(cell);
}

function quote(value) {
  return '"' + value.replace(/"/g, '""') + '"';
}
