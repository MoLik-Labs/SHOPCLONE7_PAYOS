#!/usr/bin/env bash
# Every legacy payment entry point must stop with HTTP 410 before any DB/API work while payOS is on.
# tests/legacy_payment_gate_test.php launches each probe with proc_open(), which the wasm PHP does not
# have; this script owns the process boundary instead and does the same assertion.
set -uo pipefail
cd "$(dirname "$0")/../.."
# The probe needs a Dotenv stub only when Composer dependencies are absent. Never leave a
# test-only autoloader in the deployable tree, and never overwrite or remove a real autoloader.
created_stub=0
probe_err=$(mktemp "${TMPDIR:-/tmp}/orbit-legacy-probe.XXXXXX") || exit 1
cleanup() {
  rm -f "$probe_err"
  if [ "$created_stub" -eq 1 ] && cmp -s vendor/autoload.php tools/dev/vendor-stub.php; then
    rm -f vendor/autoload.php
    rmdir vendor 2>/dev/null || true
  fi
}
trap cleanup EXIT
if [ ! -e vendor/autoload.php ] && [ ! -L vendor/autoload.php ]; then
  if [ -L vendor ]; then echo "Refusing to create a test stub in a symlinked vendor directory." >&2; exit 1; fi
  mkdir -p vendor || exit 1
  if ! cp tools/dev/vendor-stub.php vendor/autoload.php; then exit 1; fi
  created_stub=1
fi
fail=0
probe() {
  out=$(node tools/dev/phpcl.mjs tests/legacy_payment_gate_test.php --probe "$1" ${2:+"$2"} 2>"$probe_err")
  rc=$?
  err=$(cat "$probe_err")
  if [ "$rc" -ne 0 ] || ! grep -q '^HTTP_STATUS=410$' <<<"$out" || [ -n "$err" ]; then
    echo "FAIL $1 ${2:-} -> $(tr '\n' ' ' <<<"$out") $(tr '\n' ' ' <<<"$err")"
    fail=1
  fi
}
probe api/callback_card.php
probe api/callback_crypto.php
probe api/callback_squadco.php
probe api/callback_flutterwave.php
probe api/callback-perfectmoney.php
probe api/callback_toyyibpay.php
probe api/webhook_web2m.php
probe cron/bank.php
probe cron/bankWeb2m.php
probe cron/momo.php
probe ajaxs/client/create.php nap_the
probe ajaxs/client/create.php CreateInvoiceCrypto
probe ajaxs/client/create.php CreateToyyibpay
probe ajaxs/client/update.php confirmPaypal
[ "$fail" -eq 0 ] && echo "14 legacy payment entry points stop with HTTP 410 when payOS is enabled."
exit "$fail"
