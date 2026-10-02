#!/usr/bin/env bash
#
# The whole baseline suite.
#
#   bash tools/run-all.sh
#
# Lints every PHP file, runs the three PHP suites, renders the page, checks it
# in a real browser and verifies the request-count baseline. Exits non-zero if
# any part fails, so it works as a pre-commit gate and in CI.
#
# Order matters: template-render.test.php writes classic.html, which
# dom.test.mjs then loads. Running the browser test alone against a stale dump
# would quietly test the previous version of the page.

set -uo pipefail

cd "$(dirname "$0")/.."

PASS=0
FAIL=0
SUITES_FAILED=()

run() {
  local label="$1"; shift
  echo
  echo "=============================================================="
  echo "  $label"
  echo "=============================================================="

  local output
  output="$("$@" 2>&1)"
  local status=$?

  # Provider error logs are expected noise — the suites deliberately exercise
  # failure paths — so only results are echoed unless something actually broke.
  echo "$output" | grep -vE '^\[thb\]'

  local p f
  p=$(echo "$output" | grep -c '^PASS' || true)
  f=$(echo "$output" | grep -c '^FAIL' || true)
  PASS=$((PASS + p))
  FAIL=$((FAIL + f))

  if [ $status -ne 0 ]; then
    SUITES_FAILED+=("$label")
  # A suite that exits 0 WITHOUT printing its closing line ended early — an
  # `exit` reached from inside a handler, say. Every assertion after that point
  # silently never ran, and counting only the ones that did would report green.
  elif ! echo "$output" | grep -qE 'passed\.|match the baseline'; then
    echo "  ENDED EARLY: no closing line — assertions after the exit point never ran"
    SUITES_FAILED+=("$label (ended early)")
  fi
}

# ---- lint -----------------------------------------------------------------
echo "=============================================================="
echo "  PHP lint"
echo "=============================================================="
LINT_ERRORS=0
while IFS= read -r file; do
  if ! php -l "$file" > /dev/null 2>&1; then
    echo "  SYNTAX ERROR: $file"
    php -l "$file" 2>&1 | head -3
    LINT_ERRORS=$((LINT_ERRORS + 1))
  fi
done < <(find thehybit-coins tools -name '*.php' 2>/dev/null)

if [ $LINT_ERRORS -eq 0 ]; then
  echo "  all PHP files parse cleanly"
else
  SUITES_FAILED+=("PHP lint")
fi

# ---- suites ---------------------------------------------------------------
run "Pure layer — Derive, Scoring, Format, Context, config" \
    php tools/php-test.php

run "Runtime — cache, budget, lock, collectors, pipeline, scheduler" \
    php tools/wp-runtime.test.php

THB_DUMP_HTML="$PWD/classic.html" \
  run "Template render — the classic page" \
      php tools/template-render.test.php

if [ -f node_modules/playwright/package.json ]; then
  run "Browser — RTL, responsive, progressive enhancement" \
      node tools/dom.test.mjs
else
  echo
  echo "  SKIPPED: browser tests (run 'npm install playwright' first)"
fi

run "Request-count baseline" \
    php tools/perf-probe.php

# ---- summary --------------------------------------------------------------
echo
echo "=============================================================="
printf "  %d checks passed" "$PASS"
[ "$FAIL" -gt 0 ] && printf ", %d FAILED" "$FAIL"
echo
if [ ${#SUITES_FAILED[@]} -gt 0 ]; then
  echo "  failing suites: ${SUITES_FAILED[*]}"
  echo "=============================================================="
  exit 1
fi
echo "  everything green"
echo "=============================================================="
exit 0
