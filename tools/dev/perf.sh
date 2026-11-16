#!/usr/bin/env bash
# Run tools/perf-probe.php N times per page and print the median line. Usage:
#   bash tools/dev/perf.sh [runs] home product:slug blogs faq
set -uo pipefail
cd "$(dirname "$0")/../.."
runs=${1:-5}; shift || true
targets=("$@")
[ "${#targets[@]}" -eq 0 ] && targets=(home blogs faq)
for target in "${targets[@]}"; do
  page=${target%%:*}; arg=""
  [ "$target" != "$page" ] && arg=${target#*:}
  for ((i=0;i<runs;i++)); do
    node tools/dev/phpcl.mjs tools/perf-probe.php "$page" ${arg:+"$arg"}
  done | python3 -c '
import sys, json
rows=[json.loads(l) for l in sys.stdin if l.strip()]
if not rows:
    print("no output"); raise SystemExit(1)
rows.sort(key=lambda r: r["ms"])
med=rows[len(rows)//2]
print(f"{med[\"page\"]:8} queries={med[\"queries\"]:3} settings_loads={med[\"settings_loads\"]} bytes={med[\"bytes\"]:6} ms={med[\"ms\"]:7} peak_mb={med[\"peak_mb\"]} (median of {len(rows)})")
'
done
