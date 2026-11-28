// Check the built site's same-origin links without making network requests.
// Pages are hosted under a project base, not at the domain root.
import { readdir, readFile, stat } from 'node:fs/promises';
import path from 'node:path';
import { parse } from 'parse5';

const root = path.resolve(process.argv[2] || 'dist');
const origin = 'https://molik-labs.github.io';
const base = '/SHOPCLONE7_PAYOS/';
const htmlFiles = [];
async function walk(dir) {
  for (const item of await readdir(dir, { withFileTypes: true })) {
    const file = path.join(dir, item.name);
    if (item.isDirectory()) await walk(file);
    else if (item.name.endsWith('.html')) htmlFiles.push(file);
  }
}
await walk(root);
if (!htmlFiles.length) throw new Error(`No HTML files in ${root}; run the build first.`);
const cache = new Map();
async function data(file) {
  if (!cache.has(file)) {
    const document = parse(await readFile(file, 'utf8'));
    const refs = [], ids = new Set();
    function visit(node) {
      const attrs = Object.fromEntries((node.attrs || []).map(({ name, value }) => [name, value]));
      if (attrs.id) ids.add(attrs.id);
      if (attrs.name && node.tagName === 'a') ids.add(attrs.name);
      // Canonical for the special 404 page is metadata, not an ordinary route.
      if (!(node.tagName === 'link' && attrs.rel === 'canonical' && file === path.join(root, '404.html'))) {
        for (const key of ['href', 'src']) if (attrs[key]) refs.push(attrs[key]);
      }
      for (const child of node.childNodes || []) visit(child);
    }
    visit(document);
    cache.set(file, { refs, ids });
  }
  return cache.get(file);
}
const errors = [];
let count = 0;
for (const file of htmlFiles) {
  const relative = path.relative(root, file).split(path.sep).join('/');
  const page = base + (relative === 'index.html' ? '' : relative.replace(/index\.html$/, ''));
  const { refs } = await data(file);
  for (const ref of refs) {
    let url;
    try { url = new URL(ref, origin + page); } catch { errors.push(`${relative}: invalid URL ${ref}`); continue; }
    if (url.origin !== origin) continue;
    if (!url.pathname.startsWith(base)) { errors.push(`${relative}: missing project base in ${ref}`); continue; }
    let decoded;
    try { decoded = decodeURIComponent(url.pathname.slice(base.length)); } catch { errors.push(`${relative}: invalid path ${ref}`); continue; }
    let target = path.resolve(root, decoded);
    if (target !== root && !target.startsWith(root + path.sep)) { errors.push(`${relative}: path outside output ${ref}`); continue; }
    try {
      if ((await stat(target)).isDirectory()) target = path.join(target, 'index.html');
      if (!(await stat(target)).isFile()) throw new Error('not a file');
      if (url.hash && target.endsWith('.html')) {
        const anchor = decodeURIComponent(url.hash.slice(1));
        if (!(await data(target)).ids.has(anchor)) errors.push(`${relative}: missing anchor ${ref}`);
      }
    } catch { errors.push(`${relative}: missing target ${ref}`); }
    count++;
  }
}
if (errors.length) { console.error(errors.join('\n')); process.exitCode = 1; }
else console.log(`Checked ${count} internal links and assets across ${htmlFiles.length} HTML files.`);
