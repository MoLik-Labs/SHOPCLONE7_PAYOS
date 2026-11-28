// Starlight builds first; index again after VitePress has added the reference.
// Use the same Pagefind API as Starlight so its existing search UI stays intact.
import * as pagefind from 'pagefind';
import { readdir, readFile } from 'node:fs/promises';
import path from 'node:path';
const output = path.resolve('dist');
function check(result) {
  if (result.errors?.length) throw new Error(result.errors.map(String).join('\n'));
  return result;
}
// Fail the build if a VitePress upgrade changes the article markup: otherwise
// Pagefind would silently index its navigation rather than the actual content.
const referenceDir = path.join(output, 'reference');
const articles = (await readdir(referenceDir)).filter((name) => name.endsWith('.html') && name !== '404.html');
if (articles.length === 0) throw new Error('VitePress reference has no HTML pages.');
for (const name of articles) {
  const html = await readFile(path.join(referenceDir, name), 'utf8');
  if (!/class="vp-doc[^\"]*" data-pagefind-body/.test(html)) throw new Error(`Missing Pagefind article marker in reference/${name}`);
}
try {
  const { index } = check(await pagefind.createIndex());
  const { page_count } = check(await index.addDirectory({ path: output }));
  check(await index.writeFiles({ outputPath: path.join(output, 'pagefind') }));
  console.log(`Shared Pagefind index: ${page_count} HTML pages, including ${articles.length} reference articles.`);
} finally { await pagefind.close(); }
