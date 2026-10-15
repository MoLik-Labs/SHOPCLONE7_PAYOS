#!/usr/bin/env python3
"""Frontend-audit self-test driver.

Runs tools/frontend-audit.php against tests/audit-fixture/ and compares the reported rule -> file pairs (and
their counts) with tests/audit-fixture/expected.json. One fixture per rule, both wrong and right, so a rule
that stops firing — or starts firing on correct code — is caught.

    python3 tests/audit-fixture/run_selftest.py

The audit tool is a CLI script, so it needs a PHP 7.4 binary. This checkout is verified with the PHP-WASM
harness used for the other suites; point the driver at your own runner if you have a PHP CLI:

    python3 tests/audit-fixture/run_selftest.py --harness /path/to/run.sh

The runner must accept `<hostDir> <specJson>`, where the spec is
{"inject": [{hostPath, dest}], "lint": [...], "run": [{script, env}]}: it injects `audit_runner.php`
(AUDIT_ARGS -> $argv), then runs it from the checkout mounted at /app.

Exit code 0 when every expectation matches.
"""

import argparse
import json
import os
import subprocess
import sys

DEFAULT_HARNESS = os.environ.get("HARNESS_RUN", "/home/user/harness/run.sh")
FIXTURE = "tests/audit-fixture"


def run_audit(harness, host, runner, args):
    spec = {
        "lint": [],
        "inject": [{"hostPath": runner, "dest": "/audit.php"}],
        "run": [{"script": "/audit.php", "env": {"AUDIT_ARGS": args}}],
    }
    completed = subprocess.run([harness, host, json.dumps(spec)], capture_output=True, text=True, timeout=600)
    return completed.stdout + completed.stderr


def extract_json(output):
    """Pull the audit's JSON object out of the harness transcript (brace counting, string aware)."""
    start = output.find("{")
    while start != -1:
        depth = 0
        in_string = False
        escaped = False
        for index in range(start, len(output)):
            char = output[index]
            if in_string:
                if escaped:
                    escaped = False
                elif char == "\\":
                    escaped = True
                elif char == '"':
                    in_string = False
                continue
            if char == '"':
                in_string = True
            elif char == "{":
                depth += 1
            elif char == "}":
                depth -= 1
                if depth == 0:
                    candidate = output[start:index + 1]
                    try:
                        return json.loads(candidate)
                    except json.JSONDecodeError:
                        break
        start = output.find("{", start + 1)
    return None


def main():
    here = os.path.dirname(os.path.abspath(__file__))
    default_host = os.path.dirname(os.path.dirname(here))
    parser = argparse.ArgumentParser(description=__doc__.splitlines()[0])
    parser.add_argument("--host", default=default_host,
                        help="repository to audit (default: the checkout this file lives in)")
    parser.add_argument("--harness", default=DEFAULT_HARNESS,
                        help="run.sh of the PHP harness (or set HARNESS_RUN)")
    parser.add_argument("--fixture", default=FIXTURE,
                        help="fixture directory, relative to the host")
    parser.add_argument("--runner", default=os.path.join(here, "audit_runner.php"),
                        help="the CLI-argument shim injected into the harness")
    options = parser.parse_args()

    with open(f"{options.host}/{options.fixture}/expected.json", encoding="utf-8") as handle:
        expected = json.load(handle)

    transcript = run_audit(options.harness, options.host, options.runner, f"--path={options.fixture} --json")
    report = extract_json(transcript)
    if report is None:
        print("FAIL  could not read the audit's JSON report")
        print(transcript[-2000:])
        return 2

    seen = {}
    seen_count = {}
    for detail in report["details"]:
        seen.setdefault(detail["file"], set()).add(detail["rule"])
        seen_count[detail["file"]] = seen_count.get(detail["file"], 0) + 1

    passed = 0
    failed = 0
    for path in sorted(expected["findings"]):
        want = set(expected["findings"][path])
        got = seen.pop(path, set())
        want_count = expected.get("counts", {}).get(path, len(want))
        got_count = seen_count.get(path, 0)
        seen_count.pop(path, None)
        if want == got and want_count == got_count:
            passed += 1
            print(f"  ok   {path}: {', '.join(sorted(want)) or 'clean'}"
                  + (f" x{got_count}" if got_count > 1 else ""))
        else:
            failed += 1
            print(f"  FAIL {path}: expected {sorted(want) or 'clean'} "
                  f"(x{want_count}), got {sorted(got) or 'clean'} (x{got_count})")

    for path in expected.get("clean", []):
        got = seen.pop(path, set())
        seen_count.pop(path, None)
        if got:
            failed += 1
            print(f"  FAIL {path}: expected clean, got {sorted(got)}")
        else:
            passed += 1
            print(f"  ok   {path}: clean")

    for path, rules in sorted(seen.items()):
        failed += 1
        print(f"  FAIL {path}: unexpected finding(s) {sorted(rules)} (x{seen_count.get(path, 0)})")
    for path, count in sorted(seen_count.items()):
        if path not in seen:
            failed += 1
            print(f"  FAIL {path}: reported {count} extra finding(s)")

    print(f"\naudit self-test: {passed} passed, {failed} failed "
          f"({report['files']} file(s) scanned, {report['findings']} finding(s) as designed)")
    return 1 if failed else 0


if __name__ == "__main__":
    sys.exit(main())
