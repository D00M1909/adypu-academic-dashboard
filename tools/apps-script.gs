/**
 * Pushes the Form's response sheet to the dashboard.
 *
 * Google pushes to us rather than the dashboard pulling from Google because
 * InfinityFree's free plan blocks outbound HTTP from PHP. Inbound is fine.
 *
 * Setup (once):
 *   1. Open the Form's response Sheet -> Extensions -> Apps Script.
 *   2. Paste this file in, replacing whatever is there.
 *   3. Set INGEST_URL and INGEST_SECRET below. The secret must match the
 *      INGEST_SECRET in includes/config.local.php on the server.
 *   4. Run pushNow() once and approve the permissions prompt. Check the
 *      execution log says ok:true.
 *   5. Triggers (clock icon) -> Add Trigger -> pushNow -> From spreadsheet ->
 *      On form submit. Add a second one: Time-driven -> Hour timer -> every
 *      hour, as a safety net for a submission that arrives while the site is
 *      down.
 */

const INGEST_URL = 'https://YOUR-SITE.rf.gd/api/ingest.php';
const INGEST_SECRET = 'paste-the-same-secret-as-config.local.php';

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

  const response = UrlFetchApp.fetch(INGEST_URL, {
    method: 'post',
    contentType: 'text/csv',
    payload: toCsv(values),
    headers: { 'X-Ingest-Secret': INGEST_SECRET },
    muteHttpExceptions: true,
  });

  const code = response.getResponseCode();
  const body = response.getContentText();
  if (code !== 200) {
    // Surfaces in the Apps Script execution log and emails the sheet owner on
    // a failed trigger run, so a broken push doesn't go unnoticed for weeks.
    throw new Error('ingest failed: ' + code + ' ' + body);
  }

  // A 200 is not proof of anything. If INGEST_URL points at the site root
  // instead of api/ingest.php, the dashboard renders and returns 200 HTML —
  // the push would look successful forever while storing nothing.
  let result;
  try {
    result = JSON.parse(body);
  } catch (e) {
    throw new Error(
      'ingest returned HTML, not JSON — INGEST_URL must end in /api/ingest.php. Got: ' +
      body.slice(0, 120)
    );
  }
  if (!result.ok) {
    throw new Error('ingest refused the push: ' + body);
  }

  Logger.log(
    'pushed ' + result.rows + ' rows across ' + result.schools +
    ' schools — ' + result.present + '/' + result.strength + ' present'
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
