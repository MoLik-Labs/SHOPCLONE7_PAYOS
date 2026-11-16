import { PHP } from '@php-wasm/universal';
import { loadNodeRuntime, useHostFilesystem } from '@php-wasm/node';

/* Run a PHP file with a REAL PHP 8.2 (WebAssembly) CLI and the repository mounted at its real path.
   Why: this sandbox has no native php. Under the CLI SAPI STDOUT/STDERR, getenv(), __DIR__,
   http_response_code() and session files behave as they do on a server. Not: proc_open(), mysqli.

     node tools/dev/phpcl.mjs tests/foo_test.php [args] */
const php = new PHP(await loadNodeRuntime('8.2'));
useHostFilesystem(php);
const args = process.argv.slice(2);
if (!args[0]) {
  console.error('usage: node tools/dev/phpcl.mjs <script.php> [args]');
  process.exit(2);
}
const stream = await php.cli(['php', '-d', 'session.save_path=/tmp', ...args]);
process.stdout.write(await stream.stdoutText);
process.stderr.write(await stream.stderrText);
process.exit(await stream.exitCode);
