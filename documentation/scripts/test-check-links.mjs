import { mkdtemp, mkdir, rm, writeFile } from 'node:fs/promises';
import os from 'node:os';
import path from 'node:path';
import { spawnSync } from 'node:child_process';
import assert from 'node:assert/strict';

const dir = await mkdtemp(path.join(os.tmpdir(), 'molik-doc-links-'));
const checker = path.resolve('scripts/check-links.mjs');
const run = () => spawnSync(process.execPath, [checker, dir], { encoding: 'utf8' });
try {
  await mkdir(path.join(dir, 'guide'));
  await writeFile(path.join(dir, 'guide', 'index.html'), '<h1 id="nội-dung">Guide</h1>');
  await writeFile(path.join(dir, 'index.html'), '<a href="./guide/#nội-dung">Guide</a>');
  assert.equal(run().status, 0, 'valid relative link and Unicode anchor should pass');
  await writeFile(path.join(dir, 'index.html'), '<a href="./missing/">Broken</a>');
  assert.notEqual(run().status, 0, 'missing page should fail');
  await writeFile(path.join(dir, 'index.html'), '<a href="./guide/#missing">Broken</a>');
  assert.notEqual(run().status, 0, 'missing anchor should fail');
  await writeFile(path.join(dir, 'index.html'), '<a href="/guide/">Broken</a>');
  assert.notEqual(run().status, 0, 'root link without Pages base should fail');
  console.log('Link checker fixtures passed (valid, missing page, missing anchor, missing base).');
} finally {
  await rm(dir, { recursive: true, force: true });
}
