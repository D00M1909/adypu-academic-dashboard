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
  Logger.log(code + ' ' + response.getContentText());
  if (code !== 200) {
    // Surfaces in the Apps Script execution log and emails the sheet owner on
    // a failed trigger run, so a broken push doesn't go unnoticed for weeks.
    throw new Error('ingest failed: ' + code + ' ' + response.getContentText());
  }
}

/**
 * The header row is what the PHP parser matches on, so it is lowercased here
 * and nowhere else. Dates become yyyy-mm-dd because that is what the parser's
 * "latest row per class per date" grouping compares.
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
