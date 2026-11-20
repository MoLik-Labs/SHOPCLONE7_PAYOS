#!/usr/bin/env bash
# Every legacy payment entry point must stop with HTTP 410 before any DB/API work while payOS is on.
# tests/legacy_payment_gate_test.php launches each probe with proc_open(), which the wasm PHP does not
# have; this script owns the process boundary instead and does the same assertion.
set -uo pipefail
cd "$(dirname "$0")/../.."
# libs/db.php includes vendor/autoload.php before the guard runs; the checkout has no vendor tree.
if [ ! -f vendor/autoload.php ]; then
  mkdir -p vendor && cp tools/dev/vendor-stub.php vendor/autoload.php
fi
fail=0
probe() {
  out=$(node tools/dev/phpcl.mjs tests/legacy_payment_gate_test.php --probe "$1" ${2:+"$2"} 2>/tmp/probe.err)
  err=$(cat /tmp/probe.err)
  if ! grep -q '^HTTP_STATUS=410$' <<<"$out" || [ -n "$err" ]; then
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
