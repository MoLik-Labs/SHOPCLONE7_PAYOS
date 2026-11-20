#!/usr/bin/env bash
# Full regression sweep: parse-check every tracked PHP file, then run every tests/*_test.php.
#
#   bash tools/dev/run.sh              # lint + all suites
#   bash tools/dev/run.sh --lint-only
#   bash tools/dev/run.sh --tests-only
#   bash tools/dev/run.sh tests/payos_client_test.php   # named suites only
#
# A suite that exits non-zero because a disposable database is not configured is reported as
# NOT RUN (never as passing). tests/legacy_payment_gate_test.php needs proc_open(), so its process
# boundary is owned by tools/dev/legacy-gate.sh instead.
set -uo pipefail
ROOT="$(cd "$(dirname "$0")/../.." && pwd)"
cd "$ROOT"
MODE=all
if [ "${1:-}" = "--lint-only" ]; then MODE=lint; shift; fi
if [ "${1:-}" = "--tests-only" ]; then MODE=tests; shift; fi
fails=0; runs=0; notrun=0

# node_modules is excluded from the workspace snapshot, so a fresh checkout has the PHP harness but
# not its runtime. Install it once instead of failing every suite with ERR_MODULE_NOT_FOUND.
if [ ! -d "tools/dev/node_modules/@php-wasm/universal" ]; then
  echo "=== bootstrap: installing the PHP harness runtime"
  if ! npm install --prefix tools/dev --silent; then
    echo "FAILED: npm install --prefix tools/dev (the lint and test runner need @php-wasm/universal)"
    exit 1
  fi
fi

if [ "$MODE" != "tests" ]; then
  # Tracked files plus anything newly created: a brand-new page must be linted before it is committed.
  { git ls-files -z '*.php'; git ls-files -z --others --exclude-standard '*.php'; } \
    | python3 -c 'import sys,json; names=sorted(set(p for p in sys.stdin.buffer.read().decode().split("\0") if p)); print(json.dumps(names))' > /tmp/shopclone7-lint.json
  node tools/dev/phpcl.mjs tools/dev/lintlist.php /tmp/shopclone7-lint.json || fails=$((fails+1))

  # Static security sweep: unescaped request data reaching SQL/HTML, missing guards, retired endpoints.
  echo "=== security_scan"
  if ! node tools/dev/phpcl.mjs tools/security-scan.php --fail-on=high; then
    fails=$((fails+1)); echo "FAILED: security_scan"
  fi

  # Shipped JavaScript is not linted by anything else: a syntax error would only surface in a browser.
  echo "=== js_syntax"
  for js in public/client/orbit/*.js public/common/*.js; do
    [ -f "$js" ] || continue
    if node --check "$js" >/dev/null 2>&1; then
      echo "  ok   $js"
    else
      fails=$((fails+1)); echo "FAILED: node --check $js"; node --check "$js"
    fi
  done
fi

if [ "$MODE" != "lint" ]; then
  if [ "$#" -gt 0 ]; then
    SUITES="$*"
  else
    SUITES=$(ls tests/*_test.php | grep -v 'legacy_payment_gate_test.php' | sort)
  fi
  for suite in $SUITES; do
    runs=$((runs+1))
    label=$(basename "$suite" _test.php)
    echo "=== $label"
    out=$(node tools/dev/phpcl.mjs "$suite" 2>&1); status=$?
    echo "$out"
    if [ "$status" -ne 0 ]; then
      if grep -qE 'SKIP: |Set PAYOS_TEST_DB' <<<"$out"; then
        notrun=$((notrun+1)); echo "NOT RUN (no disposable database): $label"
      else
        fails=$((fails+1)); echo "FAILED: $label"
      fi
    fi
  done
  if [ "$#" -eq 0 ]; then
    runs=$((runs+1))
    echo "=== legacy_payment_gate"
    if ! bash tools/dev/legacy-gate.sh; then fails=$((fails+1)); echo "FAILED: legacy_payment_gate"; fi
  fi
fi

echo "=== SUMMARY: $runs suites, $notrun not run, $fails failures"
[ "$fails" -eq 0 ]
