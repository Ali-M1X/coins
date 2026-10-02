#!/usr/bin/env bash
#
# Build an installable plugin zip.
#
#   bash tools/package.sh            -> thehybit-coins-<version>.zip
#
# Refuses to package unless the whole suite is green, because a zip is the
# thing that reaches the live site and the suite is the only thing standing
# between a regression and the people reading it.

set -euo pipefail
cd "$(dirname "$0")/.."

VERSION=$(grep -oP "define\('THB_COINS_VERSION', '\K[^']+" thehybit-coins/thehybit-coins.php)
OUT="thehybit-coins-${VERSION}.zip"

if ! bash tools/run-all.sh > /tmp/thb-package-suite.log 2>&1; then
  echo "Suite is not green — not packaging. See /tmp/thb-package-suite.log"
  tail -6 /tmp/thb-package-suite.log
  exit 1
fi

rm -f "$OUT"
zip -qr "$OUT" thehybit-coins -x '*.DS_Store'

# The zip must hold the plugin and nothing else — no tests, no fixtures, no
# keys. Checked rather than trusted.
if unzip -l "$OUT" | grep -qE 'tools/|\.test\.|wp-stubs|node_modules'; then
  echo "Zip contains test files — refusing." >&2
  exit 1
fi

echo "Built $OUT ($(unzip -l "$OUT" | tail -1 | awk '{print $2}') files, $(du -h "$OUT" | cut -f1))"
