// Build-time only (Node + esbuild). Produces content-hashed bundles with SRI hashes
// injected into the HTML, plus a build manifest of SHA-256 digests for publication.
import { build } from 'esbuild';
import { createHash } from 'node:crypto';
import { mkdirSync, readFileSync, writeFileSync, rmSync, copyFileSync, readdirSync } from 'node:fs';
import { dirname, join } from 'node:path';
import { fileURLToPath } from 'node:url';

const root = dirname(fileURLToPath(import.meta.url));
const out = join(root, '..', 'server', 'public');
const assets = join(out, 'assets');
const templates = join(root, '..', 'server', 'templates');

rmSync(assets, { recursive: true, force: true });
mkdirSync(assets, { recursive: true });
mkdirSync(templates, { recursive: true });

const sha = (buf, algo = 'sha256', enc = 'hex') => createHash(algo).update(buf).digest(enc);
const sri = (buf) => 'sha384-' + sha(buf, 'sha384', 'base64');
const manifest = {};

function emit(name, ext, buf) {
  const h = sha(buf).slice(0, 12);
  const file = `${name}.${h}.${ext}`;
  writeFileSync(join(assets, file), buf);
  manifest[`assets/${file}`] = sha(buf);
  return { src: `/assets/${file}`, integrity: sri(buf) };
}

// zxcvbn (strength check for custom codes) is loaded lazily, so it is a separate hashed file.
const zx = emit('zxcvbn', 'js', readFileSync(join(root, 'node_modules/zxcvbn/dist/zxcvbn.js')));

// argon2-browser's Emscripten glue has a Node-only branch that require()s fs/path.
// It never runs in a browser; resolve those to empty modules so nothing Node-specific ships.
const stubNodeBuiltins = {
  name: 'stub-node-builtins',
  setup(b) {
    b.onResolve({ filter: /^(fs|path)$/ }, (a) => ({ path: a.path, namespace: 'stub' }));
    b.onLoad({ filter: /.*/, namespace: 'stub' }, () => ({ contents: 'module.exports = {};', loader: 'js' }));
  },
};

const common = {
  plugins: [stubNodeBuiltins],
  bundle: true,
  minify: true,
  write: false,
  target: ['es2022'],
  format: 'iife',
  legalComments: 'none',
  loader: { '.wasm': 'binary' },
  logLevel: 'warning',
};

async function bundle(entry, define = {}) {
  const r = await build({ ...common, entryPoints: [join(root, 'src', entry)], define });
  return Buffer.from(r.outputFiles[0].contents);
}

const cssBuild = await build({
  entryPoints: [join(root, 'src/css/app.css')], bundle: true, minify: true, write: false, logLevel: 'warning',
});
const css = emit('app', 'css', Buffer.from(cssBuild.outputFiles[0].contents));

const pages = {
  upload: { entry: 'upload/main.js', html: 'index.html', define: { __ZXCVBN__: JSON.stringify(zx) }, dest: out },
  download: { entry: 'download/main.js', html: 's.html', define: {}, dest: out },
  admin: { entry: 'admin/main.js', html: 'admin.html', define: {}, dest: out },
  install: { entry: 'install/main.js', html: 'install.html', define: {}, dest: templates },
};

for (const [name, p] of Object.entries(pages)) {
  const js = emit(name, 'js', await bundle(p.entry, p.define));
  let html = readFileSync(join(root, 'src/html', p.html), 'utf8');
  html = html
    .replace('{{CSS}}', `<link rel="stylesheet" href="${css.src}" integrity="${css.integrity}" crossorigin="anonymous">`)
    .replace('{{JS}}', `<script src="${js.src}" integrity="${js.integrity}" crossorigin="anonymous" defer></script>`);
  writeFileSync(join(p.dest, p.html), html);
  manifest[p.html] = sha(html);
}

// Static informational page (no script).
{
  let html = readFileSync(join(root, 'src/html/security.html'), 'utf8');
  html = html.replace('{{CSS}}', `<link rel="stylesheet" href="${css.src}" integrity="${css.integrity}" crossorigin="anonymous">`);
  writeFileSync(join(out, 'security.html'), html);
  manifest['security.html'] = sha(html);
}

// Service worker must live at a fixed path in the site root.
{
  const sw = await bundle('sw/sw.js');
  writeFileSync(join(out, 'sw.js'), sw);
  manifest['sw.js'] = sha(sw);
}

// Argon2 WASM digest recorded for audit (it is embedded in every page bundle).
manifest['argon2.wasm (embedded)'] = sha(readFileSync(join(root, 'node_modules/argon2-browser/dist/argon2.wasm')));

const sorted = Object.fromEntries(Object.entries(manifest).sort(([a], [b]) => a.localeCompare(b)));
writeFileSync(join(out, 'build-manifest.json'), JSON.stringify({ algorithm: 'sha256', files: sorted }, null, 2) + '\n');
console.log(`built ${Object.keys(sorted).length} files`);
for (const f of readdirSync(assets)) console.log('  assets/' + f);
