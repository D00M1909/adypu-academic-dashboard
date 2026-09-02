#!/usr/bin/env bash
# Stages the files an upload needs into upload/, keeping their paths, so the
# whole tree can be dragged into the server's htdocs in one drop instead of
# opening every folder on both sides and waiting for the host to catch up.
#
#   tools/stage-upload.sh           changed since the last commit (incl. uncommitted)
#   tools/stage-upload.sh HEAD~3    changed over the last 3 commits
#   tools/stage-upload.sh --all     every deployable file, for a fresh install
#
# Never staged, per the deploy notes: tools/ and tests/ (apps-script.gs is
# pasted into the Apps Script editor by hand), and includes/config.local.php,
# which lives only on the server — copying a local one over it would blank the
# INGEST_SECRET and every push would start answering 403.
set -euo pipefail
cd "$(dirname "$0")/.."

OUT=upload
rm -rf "$OUT"

if [ "${1:-}" = "--all" ]; then
  files=$(find index.php api includes css js img -type f)
else
  # One ref, not a range: this diffs the ref against the WORKING TREE, so a file
  # edited but not yet committed still gets staged. Uploading a file the repo
  # has not seen is the normal case here, not a mistake.
  files=$(git diff --name-only --diff-filter=d "${1:-HEAD~1}")
fi

n=0
while IFS= read -r f; do
  # An allowlist, not a blocklist: these six paths are exactly what the server
  # serves, so a new README, dotfile or generator can never ride along by
  # accident. Add a path here if the server ever needs one (an .htaccess, say).
  case "$f" in
    index.php|api/*|includes/*|css/*|js/*|img/*) ;;
    *) continue ;;
  esac
  # Server-only, and gitignored, so this should never match — but staging it
  # would blank the INGEST_SECRET and every push would start answering 403.
  case "$f" in */config.local.php) continue ;; esac
  [ -f "$f" ] || continue
  mkdir -p "$OUT/$(dirname "$f")"
  cp "$f" "$OUT/$f"
  echo "  $f"
  n=$((n + 1))
done <<< "$files"

echo
if [ "$n" -eq 0 ]; then
  echo "nothing to upload."
  rmdir "$OUT" 2>/dev/null || true
  exit
fi
echo "$n file(s) staged in $OUT/"
echo "Open that folder, select all, drop it on the server's htdocs root."

# The Apps Script is not an upload, and forgetting it is why a change can look
# deployed and still do nothing.
if git diff --name-only "${1:-HEAD~1}" 2>/dev/null | grep -q 'tools/apps-script.gs'; then
  echo
  echo "NOTE: tools/apps-script.gs also changed — paste it into the Apps Script"
  echo "      editor by hand, then re-set INGEST_URL and INGEST_SECRET."
fi
