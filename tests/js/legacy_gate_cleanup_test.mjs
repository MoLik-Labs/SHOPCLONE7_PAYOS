/* Run the gate in a throwaway copy with a fake PHP runner. Never touch the real vendor directory. */
import assert from "node:assert/strict";
import fs from "node:fs";
import os from "node:os";
import path from "node:path";
import { spawnSync } from "node:child_process";

const root = fs.mkdtempSync(path.join(os.tmpdir(), "orbit-gate-test-"));
try {
  fs.mkdirSync(path.join(root, "tools/dev"), { recursive: true });
  fs.mkdirSync(path.join(root, "bin"));
  fs.copyFileSync(
    "tools/dev/legacy-gate.sh",
    path.join(root, "tools/dev/legacy-gate.sh"),
  );
  fs.copyFileSync(
    "tools/dev/vendor-stub.php",
    path.join(root, "tools/dev/vendor-stub.php"),
  );
  const runner = path.join(root, "bin/node");
  fs.writeFileSync(runner, '#!/bin/sh\nprintf "HTTP_STATUS=410\\n"\n', {
    mode: 0o755,
  });
  const env = {
    ...process.env,
    PATH: path.join(root, "bin") + ":" + process.env.PATH,
  };
  const run = () =>
    spawnSync("bash", ["tools/dev/legacy-gate.sh"], {
      cwd: root,
      env,
      encoding: "utf8",
    });
  let result = run();
  assert.equal(result.status, 0, result.stderr);
  assert.equal(
    fs.existsSync(path.join(root, "vendor/autoload.php")),
    false,
    "temporary stub is removed after success",
  );
  fs.mkdirSync(path.join(root, "vendor"), { recursive: true });
  fs.writeFileSync(path.join(root, "vendor/autoload.php"), "existing vendor");
  result = run();
  assert.equal(result.status, 0, result.stderr);
  assert.equal(
    fs.readFileSync(path.join(root, "vendor/autoload.php"), "utf8"),
    "existing vendor",
    "existing vendor is never changed",
  );
  fs.unlinkSync(path.join(root, "vendor/autoload.php"));
  fs.writeFileSync(path.join(root, "vendor/keep.txt"), "keep");
  result = run();
  assert.equal(result.status, 0);
  assert.equal(
    fs.readFileSync(path.join(root, "vendor/keep.txt"), "utf8"),
    "keep",
    "other vendor files are preserved",
  );
  fs.writeFileSync(runner, "#!/bin/sh\nexit 1\n", { mode: 0o755 });
  result = run();
  assert.notEqual(result.status, 0, "gate reports failing probes");
  assert.equal(
    fs.existsSync(path.join(root, "vendor/autoload.php")),
    false,
    "temporary stub is removed after failure",
  );
  console.log(
    "legacy_gate_cleanup_test: 8 checks, 0 failed (temporary workspace)",
  );
} finally {
  fs.rmSync(root, { recursive: true, force: true });
}
