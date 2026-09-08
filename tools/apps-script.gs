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
 *   3. Set TARGETS below: one entry per site, each with the INGEST_SECRET from
 *      that site's own includes/config.local.php. The first entry is production
 *      and is the only one whose failures raise an alert.
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
 *
 * A question whose title starts with "Faculty" is picked up as who filed the
 * row, and shown against the division in the drill-down modal. It is optional
 * and purely presentational: rows without one count exactly the same.
 *
 * A question whose title starts with "Time" is which lecture the reading is
 * for, so a class its faculty report after each of their own lectures keeps
 * every reading instead of the last one replacing the rest. Also optional: with
 * no such question the submission time stands in, rounded to the hour. It must
 * not be titled "Timestamp", which is the sheet's own submission column.
 */

// Every site to push the sheet to. The FIRST one is production and is the only
// one whose outcome counts: it drives the success and failure record, and so
// the alert. The rest are pushed best-effort and only logged, because a broken
// staging site must never be able to email you that attendance is down.
//
// Each entry needs the secret from that site's own includes/config.local.php.
// They are different sites and must have different secrets: reusing production's
// on a staging box means anyone who can read the staging config can write to
// production.
const TARGETS = [
  { name: 'live', url: 'https://YOUR-SITE.rf.gd/api/ingest.php', secret: 'paste-the-live-secret' },
  // { name: 'dev', url: 'https://YOUR-DEV-SITE.rf.gd/api/ingest.php', secret: 'paste-the-dev-secret' },
];

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
  if (!TARGETS.length) throw new Error('TARGETS is empty — nothing to push to');
  TARGETS.forEach(function (target) {
    if (!/\/api\/ingest\.php$/.test(target.url)) {
      throw new Error(target.name + ': url must end in /api/ingest.php — got: ' + target.url);
    }
    if (!target.secret || target.secret.indexOf('paste-the-') === 0) {
      throw new Error(target.name + ': secret is still the placeholder');
    }
  });

  const sheet = SpreadsheetApp.getActiveSpreadsheet().getSheets()[0];
  const values = sheet.getDataRange().getValues();
  if (values.length < 2) {
    Logger.log('nothing to push: sheet has no data rows');
    return;
  }
  const payload = toCsv(values);

  // Everything after production is best-effort: pushed first so a slow or dead
  // staging box cannot delay the one push that matters, and never allowed to
  // throw. Its failures are a log line, nothing more.
  TARGETS.slice(1).forEach(function (target) {
    try {
      const extra = attemptPush(payload, target);
      Logger.log(target.name + ': ' + (extra.ok ? 'pushed ' + extra.result.rows + ' rows' : extra.problem));
    } catch (e) {
      Logger.log(target.name + ': ' + e.message);
    }
  });

  let lastProblem = '';
  for (let attempt = 1; attempt <= ATTEMPTS_PER_RUN; attempt++) {
    const outcome = attemptPush(payload, TARGETS[0]);
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

function attemptPush(payload, target) {
  const response = UrlFetchApp.fetch(target.url, {
    method: 'post',
    contentType: 'text/csv',
    payload: payload,
    headers: { 'X-Ingest-Secret': target.secret },
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
        : 'expected JSON, got HTML — is the url pointing at api/ingest.php? ' + body.slice(0, 120),
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
 * parser compares when deciding which of a class's readings is the latest, and
 * they keep their clock because that is what separates one lecture's reading
 * from the next one's. row_date() reads the date and ignores the rest.
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
    return Utilities.formatDate(cell, Session.getScriptTimeZone(), 'yyyy-MM-dd HH:mm');
  }
  return String(cell);
}

function quote(value) {
  return '"' + value.replace(/"/g, '""') + '"';
}
