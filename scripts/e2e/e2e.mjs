// End-to-end test against a running stack (see start-stack.sh).
//   BASE=http://127.0.0.1:8088 SETUP_TOKEN=... DATA_DIR=/tmp/ls-run/data \
//   PW_PATH=/path/to/node_modules/playwright-core CHROME=/path/to/chrome node scripts/e2e/e2e.mjs
import { createRequire } from 'node:module';
import { createHash, createHmac, randomBytes } from 'node:crypto';
import { readFileSync, writeFileSync, mkdtempSync, rmSync, readdirSync, statSync } from 'node:fs';
import { tmpdir } from 'node:os';
import { join } from 'node:path';

const require = createRequire(import.meta.url);
const { chromium } = require(process.env.PW_PATH || 'playwright-core');
const BASE = process.env.BASE || 'http://127.0.0.1:8088';
const TOKEN = process.env.SETUP_TOKEN;
const DATA_DIR = process.env.DATA_DIR;
const ADMIN_PW = 'correct horse battery staple 42';
if (!TOKEN) throw new Error('SETUP_TOKEN required');

const tmp = mkdtempSync(join(tmpdir(), 'ls-e2e-'));
let passed = 0;
const ok = (cond, msg) => {
  if (!cond) throw new Error('FAIL: ' + msg);
  passed++;
  console.log('  ok -', msg);
};
const sha = (b) => createHash('sha256').update(b).digest('hex');

function base32(s) {
  const A = 'ABCDEFGHIJKLMNOPQRSTUVWXYZ234567';
  let bits = '';
  for (const c of s.replace(/=+$/, '')) bits += A.indexOf(c).toString(2).padStart(5, '0');
  const out = [];
  for (let i = 0; i + 8 <= bits.length; i += 8) out.push(parseInt(bits.slice(i, i + 8), 2));
  return Buffer.from(out);
}
function totp(secret, offset = 0) {
  const counter = Math.floor(Date.now() / 30000) + offset;
  const buf = Buffer.alloc(8);
  buf.writeBigUInt64BE(BigInt(counter));
  const h = createHmac('sha1', base32(secret)).update(buf).digest();
  const o = h[19] & 15;
  const n = ((h[o] & 0x7f) << 24) | (h[o + 1] << 16) | (h[o + 2] << 8) | h[o + 3];
  return String(n % 1000000).padStart(6, '0');
}

const browser = await chromium.launch({ executablePath: process.env.CHROME, args: ['--no-sandbox'] });
const externalRequests = [];
async function newPage(ctx) {
  const page = await ctx.newPage();
  page.on('request', (r) => {
    const u = new URL(r.url());
    if (!['127.0.0.1', 'localhost'].includes(u.hostname) && u.protocol.startsWith('http')) externalRequests.push(r.url());
  });
  page.on('pageerror', (e) => console.log('  [pageerror]', e.message));
  return page;
}

async function upload(ctx, filePath, opts = {}) {
  const page = await newPage(ctx);
  await page.goto(BASE + '/');
  await page.waitForSelector('#form:not([hidden])');
  await page.setInputFiles('#file', filePath);
  if (opts.maxFails) await page.selectOption('#max-fails', String(opts.maxFails));
  if (opts.failAction) await page.selectOption('#fail-action', opts.failAction);
  if (opts.noBurn) await page.uncheck('#burn');
  if (opts.words) {
    await page.click('summary');
    await page.selectOption('#words', String(opts.words));
  }
  await page.click('#go');
  await page.waitForSelector('#result:not([hidden])', { timeout: 120000 });
  const link = await page.textContent('#link');
  const code = await page.textContent('#code');
  await page.close();
  return { link, code };
}

async function openShare(ctx, link) {
  const page = await newPage(ctx);
  await page.goto(link);
  return page;
}

async function unlock(page, code) {
  await page.fill('#code', code);
  await page.click('#go');
  await page.waitForFunction(() => document.getElementById('working').hidden && !document.getElementById('go').disabled, null, { timeout: 60000 });
  await page.waitForTimeout(100);
}

try {
  // ---- install ------------------------------------------------------------
  console.log('install flow');
  const ctx = await browser.newContext({ acceptDownloads: true });
  let page = await newPage(ctx);
  let res = await page.goto(BASE + '/install');
  ok(res.status() === 200, '/install is served before installation');
  await page.waitForSelector('#step1:not([hidden])');
  ok((await page.locator('#checks li.ok').count()) === 5, 'all environment checks pass');

  await page.fill('#token', 'wrong-token-wrong-token-xx');
  await page.fill('#pw', ADMIN_PW);
  await page.fill('#pw2', ADMIN_PW);
  await page.click('#next');
  await page.waitForSelector('#error:not([hidden])');
  ok((await page.textContent('#error')).includes('令牌'), 'wrong setup token is rejected');

  await page.fill('#token', TOKEN);
  await page.click('#next');
  await page.waitForSelector('#step2:not([hidden])');
  const secret = (await page.textContent('#secret')).trim();
  await page.fill('#totp', '000000');
  await page.click('#finish');
  await page.waitForSelector('#error:not([hidden])', { timeout: 60000 });
  ok((await page.textContent('#error')).includes('验证码'), 'wrong TOTP code is rejected');

  // Avoid the very start/end of a window so server and test clocks agree on the code.
  while (Date.now() % 30000 < 1500 || Date.now() % 30000 > 27000) await new Promise((r) => setTimeout(r, 300));
  const installWindow = Math.floor(Date.now() / 30000);
  await page.fill('#totp', totp(secret));
  await page.click('#finish');
  await page.waitForSelector('#done:not([hidden])', { timeout: 60000 });
  ok(true, 'installation completes');

  res = await (await ctx.newPage()).goto(BASE + '/install');
  ok(res.status() === 404, '/install returns 404 after installation');
  const begin = await ctx.request.post(BASE + '/api/install/begin', { data: { setup_token: TOKEN }, headers: { 'X-LockSend': '1' } });
  ok(begin.status() === 404, 'install API is sealed after installation');
  const cfg = await (await ctx.request.get(BASE + '/api/config')).json();
  ok(cfg.installed === true && typeof cfg.admin_pub === 'string', 'config reports installed with admin public key');

  // ---- upload + download --------------------------------------------------
  console.log('upload / download');
  const big = randomBytes(9 * 1024 * 1024 + 123); // 3 chunks, last one partial
  const bigPath = join(tmp, '报告 final (1).bin');
  writeFileSync(bigPath, big);
  const t0 = Date.now();
  const up = await upload(ctx, bigPath, { noBurn: true });
  console.log(`  (upload of ${big.length} bytes took ${Date.now() - t0} ms)`);
  ok(/^http:\/\/127\.0\.0\.1:8088\/s\/[A-Za-z0-9_-]{22}#[A-Za-z0-9_-]{43}$/.test(up.link), 'share link has 22-char id and 32-byte fragment key');
  ok(/^[a-z]+(-[a-z]+){3}$/.test(up.code), 'pick-up code is 4 words');

  const NO_PICKER = () => { delete window.showSaveFilePicker; };
  const dl = await browser.newContext({ acceptDownloads: true });
  await dl.addInitScript(NO_PICKER);
  page = await openShare(dl, up.link);
  await page.waitForSelector('#gate:not([hidden])');
  const bodyBefore = await page.textContent('body');
  ok(!bodyBefore.includes('报告') && !bodyBefore.includes('9437'), 'no file information shown before verification');

  await unlock(page, up.code.replace('-', 'x-').replace('xx', 'x')); // corrupt first word
  ok((await page.textContent('#error')).includes('还可尝试 4 次'), 'wrong code shows attempts remaining');
  await unlock(page, up.code);
  await page.waitForSelector('#info:not([hidden])');
  ok((await page.textContent('#name')) === '报告 final (1).bin', 'file name decrypted in browser after verification');

  let streamed = false;
  page.on('request', (r) => { if (new URL(r.url()).pathname.startsWith('/dl/')) streamed = true; });
  const [download] = await Promise.all([page.waitForEvent('download', { timeout: 120000 }), page.click('#start')]);
  const saved = join(tmp, 'saved.bin');
  await download.saveAs(saved);
  ok(sha(readFileSync(saved)) === sha(big), 'downloaded bytes match original (SHA-256)');
  ok(download.suggestedFilename().includes('final'), 'suggested file name preserved');
  ok(streamed, 'download was streamed through the service worker (/dl/…), not buffered as a Blob');
  await page.waitForSelector('#done:not([hidden])', { timeout: 30000 });
  ok(true, 'download completes and is confirmed to the server');

  // burn_after_complete was disabled for this share: it must still be available.
  const again = await openShare(dl, up.link);
  await again.waitForSelector('#gate:not([hidden])');
  ok(true, 'share with auto-burn disabled survives a completed download');
  await again.close();

  // ---- burn after complete ------------------------------------------------
  console.log('burn after complete');
  const small = randomBytes(1000);
  const smallPath = join(tmp, 'note.txt');
  writeFileSync(smallPath, small);
  const b = await upload(ctx, smallPath);
  page = await openShare(dl, b.link);
  await page.waitForSelector('#gate:not([hidden])');
  await unlock(page, b.code);
  await page.waitForSelector('#info:not([hidden])');
  const [d2] = await Promise.all([page.waitForEvent('download', { timeout: 60000 }), page.click('#start')]);
  await d2.saveAs(join(tmp, 'note.out'));
  ok(sha(readFileSync(join(tmp, 'note.out'))) === sha(small), 'small file round-trips');
  await page.waitForSelector('#done:not([hidden])');
  ok((await page.textContent('#done-msg')).includes('自动销毁'), 'share reports auto-destruction');
  const gone = await openShare(dl, b.link);
  await gone.waitForSelector('#gone:not([hidden])');
  ok(true, 'burned share is gone');
  if (DATA_DIR) {
    const id = b.link.split('/s/')[1].split('#')[0];
    let exists = true;
    try { statSync(join(DATA_DIR, 'blobs', id)); } catch { exists = false; }
    ok(!exists, 'ciphertext blobs deleted from disk after burn');
  }

  // ---- empty file ---------------------------------------------------------
  console.log('empty file');
  const emptyPath = join(tmp, 'empty.dat');
  writeFileSync(emptyPath, '');
  const e = await upload(ctx, emptyPath);
  page = await openShare(dl, e.link);
  await page.waitForSelector('#gate:not([hidden])');
  await unlock(page, e.code);
  await page.waitForSelector('#info:not([hidden])');
  const [d3] = await Promise.all([page.waitForEvent('download', { timeout: 60000 }), page.click('#start')]);
  await d3.saveAs(join(tmp, 'empty.out'));
  ok(readFileSync(join(tmp, 'empty.out')).length === 0, 'empty file round-trips');

  // ---- resume after the connection drops mid-upload -----------------------
  console.log('resumable upload');
  const resumeBytes = randomBytes(3 * 4 * 1024 * 1024 + 4567); // 4 chunks
  const resumePath = join(tmp, 'resume.bin');
  writeFileSync(resumePath, resumeBytes);
  const up2 = await newPage(ctx);
  const okPuts = [];
  const statusCalls = [];
  let putSeen = 0;
  let blocked = false;
  let abortedPuts = 0;
  up2.on('response', (r) => {
    const m = /\/chunks\/(\d+)$/.exec(r.url());
    if (m && r.request().method() === 'PUT' && r.status() === 204) okPuts.push(Number(m[1]));
  });
  up2.on('request', (r) => { if (r.url().endsWith('/upload-status')) statusCalls.push(Date.now()); });
  await up2.route('**/api/shares/*/chunks/*', async (route) => {
    if (route.request().method() !== 'PUT') return route.continue();
    putSeen++;
    if (putSeen > 2 && blocked) { abortedPuts++; return route.abort('connectionreset'); }
    return route.continue();
  });
  await up2.goto(BASE + '/');
  await up2.waitForSelector('#form:not([hidden])');
  await up2.setInputFiles('#file', resumePath);
  blocked = true; // takes effect from the third chunk request onwards
  await up2.click('#go');
  await up2.waitForSelector('#retry:not([hidden])', { timeout: 90000 });
  ok(JSON.stringify(okPuts) === '[0,1]' && abortedPuts >= 1, `connection loss after 2 of 4 chunks surfaces a resume button (stored ${JSON.stringify(okPuts)}, ${abortedPuts} aborted requests)`);
  ok((await up2.textContent('#status')).includes('继续'), 'status text tells the user how to resume');
  const statusBefore = statusCalls.length;
  blocked = false;
  await up2.click('#retry');
  await up2.waitForSelector('#result:not([hidden])', { timeout: 120000 });
  ok(statusCalls.length > statusBefore, 'resume asks the server which chunks it already has');
  ok(JSON.stringify([...okPuts].sort()) === '[0,1,2,3]', `chunks 0 and 1 were not re-sent; 2 and 3 were uploaded once each (stored ${JSON.stringify(okPuts)})`);
  const resumed = { link: await up2.textContent('#link'), code: await up2.textContent('#code') };
  await up2.close();
  page = await openShare(dl, resumed.link);
  await page.waitForSelector('#gate:not([hidden])');
  await unlock(page, resumed.code);
  await page.waitForSelector('#info:not([hidden])');
  const [d4] = await Promise.all([page.waitForEvent('download', { timeout: 120000 }), page.click('#start')]);
  await d4.saveAs(join(tmp, 'resume.out'));
  ok(sha(readFileSync(join(tmp, 'resume.out'))) === sha(resumeBytes), 'file uploaded in two sessions decrypts to the identical bytes');

  // ---- other ways of saving ------------------------------------------------
  console.log('download paths');
  const pathBytes = randomBytes(6 * 1024 * 1024 + 321);
  const pathFile = join(tmp, 'paths.bin');
  writeFileSync(pathFile, pathBytes);
  // Simulates what a download manager / blocker does to the streamed download: the service worker answers
  // /dl/<key> with a 404 or a network error and never reads the stream. page.route() cannot do this because
  // it does not see requests that a service worker handles, so the worker script itself is replaced.
  const breakSw = async (c, mode) => {
    await c.route('**/sw.js', async (route) => {
      const real = await (await route.fetch()).text();
      const respond = mode === '404' ? "new Response('Not found', { status: 404 })" : 'Response.error()';
      const patch = `self.addEventListener('fetch', (e) => { if (new URL(e.request.url).pathname.startsWith('/dl/')) e.respondWith(${respond}); });\n`;
      await route.fulfill({ contentType: 'application/javascript', body: patch + real });
    });
  };

  const pathUp = async () => upload(ctx, pathFile, { noBurn: true });
  const unlockShare = async (pg, link, code) => {
    await pg.goto(link);
    await pg.waitForSelector('#gate:not([hidden])');
    await unlock(pg, code);
    await pg.waitForSelector('#info:not([hidden])');
  };

  // (a) the browser's save-file dialog (File System Access API), mocked with an in-page collector
  if (!process.env.SKIP_PICKER_CASE) {
    const shared = await pathUp();
    const c = await browser.newContext({ acceptDownloads: true });
    await c.addInitScript(() => {
      window.__saved = [];
      window.showSaveFilePicker = async (opts) => ({
        createWritable: async () => ({
          write: async (b) => { window.__saved.push(b.slice()); },
          close: async () => { window.__closed = opts.suggestedName; },
          abort: async () => { window.__aborted = true; },
        }),
      });
    });
    const pg = await newPage(c);
    let swUsed = false;
    pg.on('request', (r) => { if (new URL(r.url()).pathname.startsWith('/dl/')) swUsed = true; });
    await unlockShare(pg, shared.link, shared.code);
    await pg.click('#start');
    await pg.waitForSelector('#done:not([hidden])', { timeout: 60000 });
    const got = Buffer.concat((await pg.evaluate(() => window.__saved.map((b) => Array.from(b)))).map((a) => Buffer.from(a)));
    ok(sha(got) === sha(pathBytes), 'save-file dialog path writes the identical bytes');
    ok(!swUsed && (await pg.evaluate(() => window.__closed)) === 'paths.bin', 'dialog path is used first, with the original file name, and needs no /dl/ request');
    await c.close();
  }

  // (b) a download manager / extension hijacks /dl/<key> and gets a 404 (reported bug): the page must fall back
  {
    const shared = await pathUp();
    const c = await browser.newContext({ acceptDownloads: true });
    await c.addInitScript(NO_PICKER);
    const pg = await newPage(c);
    await breakSw(c, '404');
    let hijacked = 0;
    pg.on('response', (r) => { if (new URL(r.url()).pathname.startsWith('/dl/') && r.status() === 404) hijacked++; });
    await unlockShare(pg, shared.link, shared.code);
    const [d] = await Promise.all([pg.waitForEvent('download', { timeout: 60000 }), pg.click('#start')]);
    const out = join(tmp, 'hijacked.out');
    await d.saveAs(out);
    ok(hijacked >= 1, 'test setup: the streamed download really did get a 404 from /dl/');
    ok(sha(readFileSync(out)) === sha(pathBytes), 'hijacked /dl/ (404) falls back to a normal save with identical bytes');
    await pg.waitForSelector('#done:not([hidden])', { timeout: 30000 });
    ok(!(await pg.isVisible('#error')), 'no "download cancelled" error is shown after the fallback');
    await c.close();
  }

  // (c) the browser cancels the streamed download right away
  {
    const shared = await pathUp();
    const c = await browser.newContext({ acceptDownloads: true });
    await c.addInitScript(NO_PICKER);
    const pg = await newPage(c);
    await breakSw(c, 'error');
    await unlockShare(pg, shared.link, shared.code);
    const [d] = await Promise.all([pg.waitForEvent('download', { timeout: 60000 }), pg.click('#start')]);
    const out = join(tmp, 'cancelled.out');
    await d.saveAs(out);
    ok(sha(readFileSync(out)) === sha(pathBytes), 'cancelled streamed download falls back with identical bytes');
    await c.close();
  }

  // (d) the user closes the save dialog: no silent second download
  {
    const shared = await pathUp();
    const c = await browser.newContext({ acceptDownloads: true });
    // a real user needs a moment before pressing Cancel in the dialog
    await c.addInitScript(() => { window.showSaveFilePicker = () => new Promise((_, reject) => setTimeout(() => reject(new DOMException('closed', 'AbortError')), 1500)); });
    const pg = await newPage(c);
    let anyDownload = false;
    pg.on('download', () => { anyDownload = true; });
    await unlockShare(pg, shared.link, shared.code);
    await pg.click('#start');
    await pg.waitForSelector('#error:not([hidden])');
    await pg.waitForTimeout(1500);
    ok((await pg.textContent('#error')).includes('已取消保存') && !anyDownload, 'closing the save dialog stops cleanly and can be retried');
    ok(await pg.isEnabled('#start'), 'download button is usable again');
    await c.close();
  }

  // (e) a broken save dialog that rejects at once as "cancelled" (seen in some desktop WebView builds) -> next method
  {
    const shared = await pathUp();
    const c = await browser.newContext({ acceptDownloads: true });
    const pg = await newPage(c);
    await unlockShare(pg, shared.link, shared.code);
    // make the rejection instant (the earlier "closed dialog" case waited like a real user)
    await pg.evaluate(() => { window.showSaveFilePicker = () => Promise.reject(new DOMException('closed', 'AbortError')); });
    const [d] = await Promise.all([pg.waitForEvent('download', { timeout: 60000 }), pg.click('#start')]);
    const out = join(tmp, 'brokenpicker.out');
    await d.saveAs(out);
    ok(sha(readFileSync(out)) === sha(pathBytes), 'an instantly-rejecting save dialog falls back to another method (identical bytes)');
    ok(!(await pg.isVisible('#error')), 'no "已取消保存" error for a dialog that never appeared');
    await c.close();
  }

  // (f) phone browser: no dialog, no service worker; decrypt, then a separate "保存文件" tap saves the file
  {
    const shared = await pathUp();
    const phone = await browser.newContext({
      acceptDownloads: true,
      userAgent: 'Mozilla/5.0 (Linux; Android 14; PJZ110) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/130.0.0.0 Mobile Safari/537.36',
      viewport: { width: 412, height: 915 },
      isMobile: true,
      hasTouch: true,
    });
    // what the reported phone browser did: a picker that claims "cancelled" immediately
    await phone.addInitScript(() => { window.showSaveFilePicker = () => Promise.reject(new DOMException('closed', 'AbortError')); });
    const pg = await newPage(phone);
    let swUsed = false;
    pg.on('request', (r) => { if (new URL(r.url()).pathname.startsWith('/dl/')) swUsed = true; });
    await unlockShare(pg, shared.link, shared.code);
    await pg.click('#start');
    await pg.waitForSelector('#save:not([hidden])', { timeout: 60000 });
    ok(!(await pg.isVisible('#error')), 'phone: no "已取消保存" error; a 保存文件 button appears after decryption');
    const [d] = await Promise.all([pg.waitForEvent('download', { timeout: 30000 }), pg.click('#save-btn')]);
    const out = join(tmp, 'phone.out');
    await d.saveAs(out);
    ok(sha(readFileSync(out)) === sha(pathBytes) && d.suggestedFilename() === 'paths.bin', 'phone: tapping 保存文件 saves the identical file with its name');
    await pg.waitForSelector('#done:not([hidden])', { timeout: 30000 });
    ok(!swUsed, 'phone: no service-worker stream is used');
    await phone.close();
  }

  // ---- wrong code destroys ------------------------------------------------
  console.log('wrong code destroys share');
  const w = await upload(ctx, smallPath, { maxFails: 3 });
  page = await openShare(dl, w.link);
  await page.waitForSelector('#gate:not([hidden])');
  for (let i = 0; i < 3; i++) await unlock(page, 'wrong-wrong-wrong-wrong');
  await page.waitForSelector('#gone:not([hidden])');
  ok((await page.textContent('#gone-msg')).includes('销毁'), 'share destroyed after max wrong codes');
  const after = await openShare(dl, w.link);
  await after.waitForSelector('#gone:not([hidden])');
  ok(true, 'correct code no longer works afterwards');

  // ---- missing / wrong link key -------------------------------------------
  console.log('link key is required');
  const k = await upload(ctx, smallPath);
  const noKey = await openShare(dl, k.link.split('#')[0]);
  await noKey.waitForSelector('#gone:not([hidden])');
  ok((await noKey.textContent('#gone-msg')).includes('不完整'), 'link without # key cannot proceed');
  const badKeyLink = k.link.split('#')[0] + '#' + Buffer.alloc(32, 7).toString('base64url');
  const bk = await openShare(dl, badKeyLink);
  await bk.waitForSelector('#gate:not([hidden])');
  await unlock(bk, k.code);
  ok((await bk.textContent('#error')).includes('取件码错误'), 'correct code with wrong link key is rejected');

  // ---- admin --------------------------------------------------------------
  console.log('admin');
  const admin = await newPage(ctx);
  await admin.goto(BASE + '/admin');
  await admin.fill('#pw', 'wrong password wrong password');
  // The install step spent the code of its own 30 s window (codes are single-use), so wait for a
  // later window and for a few seconds of margin from its edges before using the current code.
  for (;;) {
    const w = Math.floor(Date.now() / 30000);
    const intoWindow = Date.now() % 30000;
    if (w > installWindow && intoWindow > 2500 && intoWindow < 24000) break;
    await new Promise((r) => setTimeout(r, 500));
  }
  await admin.fill('#totp', totp(secret, 0));
  await admin.click('#go');
  await admin.waitForSelector('#error:not([hidden])', { timeout: 60000 });
  ok(true, 'wrong admin password is rejected');
  await admin.waitForFunction(() => !document.getElementById('go').disabled);
  await admin.fill('#pw', ADMIN_PW);
  await admin.fill('#totp', totp(secret, 0));
  const loginResp = admin.waitForResponse((r) => r.url().endsWith('/api/admin/login'), { timeout: 60000 });
  await admin.click('#go');
  const lr = await loginResp;
  const lrBody = await lr.text();
  console.log(`  [admin login] status=${lr.status()} body=${lrBody.slice(0, 80)} t=${new Date().toISOString()}`);
  await admin.waitForSelector('#panel:not([hidden])', { timeout: 60000 });
  await admin.waitForSelector('#rows tr');
  const names = await admin.locator('#rows tr td:first-child').allTextContents();
  ok(names.includes('报告 final (1).bin') && names.includes('note.txt'), 'admin sees decrypted file names');
  const headers = await admin.locator('thead th').allTextContents();
  ok(!headers.join(' ').match(/大小|IP|下载/), 'admin table has no size / IP / download columns');
  admin.once('dialog', (d) => d.accept());
  await admin.locator('#rows tr', { hasText: '报告 final (1).bin' }).locator('button').click();
  await admin.waitForFunction(() => ![...document.querySelectorAll('#rows tr td:first-child')].some((td) => td.textContent.includes('final')));
  ok(true, 'admin deletes a share');
  const rm = await openShare(dl, up.link);
  await rm.waitForSelector('#gone:not([hidden])');
  ok(true, 'deleted share is gone for visitors');

  // ---- privacy of stored data ---------------------------------------------
  if (DATA_DIR) {
    console.log('stored data');
    const walk = (d) => readdirSync(d, { withFileTypes: true }).flatMap((x) => (x.isDirectory() ? walk(join(d, x.name)) : [join(d, x.name)]));
    const files = walk(DATA_DIR).filter((f) => !f.endsWith('.sqlite-wal') || true);
    let leaked = null;
    for (const f of files) {
      const buf = readFileSync(f);
      for (const needle of ['note.txt', 'final (1)', up.code, b.code, 'text/plain', 'application/octet-stream', up.link.split('#')[1]]) {
        if (buf.includes(Buffer.from(needle))) leaked = `${f} contains "${needle}"`;
      }
    }
    ok(leaked === null, 'no plaintext name / mime / code / link key appears anywhere under the data dir' + (leaked ? ` (${leaked})` : ''));
  }

  // ---- file size limit (500 MB) -------------------------------------------
  console.log('file size limit');
  {
    const lim = await newPage(ctx);
    await lim.goto(BASE + '/');
    await lim.waitForSelector('#form:not([hidden])');
    ok((await lim.textContent('#file-limit')).includes('500 MB'), 'upload page states the 500 MB limit');
    const overPath = join(tmp, 'over.bin');
    const { truncateSync, openSync, closeSync } = await import('node:fs');
    closeSync(openSync(overPath, 'w'));
    truncateSync(overPath, 500 * 1000 * 1000 + 1); // sparse: no real disk use
    let reserveCalls = 0;
    lim.on('request', (r) => { if (r.url().endsWith('/api/shares/reserve')) reserveCalls++; });
    await lim.setInputFiles('#file', overPath);
    await lim.waitForSelector('#error:not([hidden])');
    ok((await lim.textContent('#error')).includes('不能超过 500 MB'), 'selecting a 500 MB + 1 byte file shows the limit immediately');
    await lim.click('#go');
    await lim.waitForTimeout(500);
    ok(reserveCalls === 0 && (await lim.isHidden('#progress')), 'an oversized file is refused before anything is sent to the server');
    await lim.close();
    rmSync(overPath, { force: true });
  }

  // ---- largest allowed size (opt-in: BIG=1) ---------------------------------
  if (process.env.BIG) {
    const { execSync } = await import('node:child_process');
    const bytes = Number(process.env.BIG_BYTES || 490 * 1000 * 1000);
    console.log(`large file (${(bytes / 1e6).toFixed(0)} MB)`);
    const bigFile = join(tmp, 'large.bin');
    execSync(`head -c ${bytes} /dev/urandom > '${bigFile}'`);
    const wantHash = execSync(`sha256sum '${bigFile}'`).toString().split(' ')[0];
    const MiB = 1024 * 1024;
    const rss = (pattern) => execSync(`ps -eo rss=,args= | grep -E '${pattern}' | grep -v grep || true`).toString()
      .split('\n').filter(Boolean).reduce((a, l) => a + Number(l.trim().split(/\s+/)[0]), 0) * 1024;
    const bigCtx = await browser.newContext({ acceptDownloads: true });
    await bigCtx.addInitScript(NO_PICKER);
    const baseFpm = rss('php-fpm: pool');
    let peakFpm = 0;
    const sampler = setInterval(() => { peakFpm = Math.max(peakFpm, rss('php-fpm: pool')); }, 300);

    const t1 = Date.now();
    const p = await newPage(bigCtx);
    await p.goto(BASE + '/');
    await p.waitForSelector('#form:not([hidden])');
    await p.setInputFiles('#file', bigFile);
    ok(await p.isHidden('#error'), 'a file just under the limit is accepted by the page');
    await p.click('#go');
    await p.waitForSelector('#result:not([hidden])', { timeout: 15 * 60 * 1000 });
    const bigUp = { link: await p.textContent('#link'), code: await p.textContent('#code') };
    await p.close();
    console.log(`  (upload ${((Date.now() - t1) / 1000).toFixed(0)} s)`);
    if (DATA_DIR) {
      const id = bigUp.link.split('/s/')[1].split('#')[0];
      let stored = 0;
      for (const f of readdirSync(join(DATA_DIR, 'blobs', id))) stored += statSync(join(DATA_DIR, 'blobs', id, f)).size;
      const chunks = Math.ceil(bytes / (4 * MiB));
      ok(stored === bytes + chunks * 16, `server stores exactly size + 16 bytes per chunk (${chunks} chunks)`);
    }
    const t2 = Date.now();
    const bp = await openShare(bigCtx, bigUp.link);
    await bp.waitForSelector('#gate:not([hidden])');
    await unlock(bp, bigUp.code);
    await bp.waitForSelector('#info:not([hidden])');
    let viaSw = false;
    bp.on('request', (r) => { if (new URL(r.url()).pathname.startsWith('/dl/')) viaSw = true; });
    const [bigDl] = await Promise.all([bp.waitForEvent('download', { timeout: 15 * 60 * 1000 }), bp.click('#start')]);
    const bigOut = join(tmp, 'large.out');
    await bigDl.saveAs(bigOut);
    await bp.waitForSelector('#done:not([hidden])', { timeout: 60000 });
    clearInterval(sampler);
    console.log(`  (download + decrypt ${((Date.now() - t2) / 1000).toFixed(0)} s)`);
    ok(viaSw, 'large download is streamed through the service worker');
    ok(execSync(`sha256sum '${bigOut}'`).toString().split(' ')[0] === wantHash, 'large file round-trips with identical SHA-256');
    if (baseFpm > 0) ok((peakFpm - baseFpm) / MiB < 200, `PHP-FPM memory stays bounded (+${Math.round((peakFpm - baseFpm) / MiB)} MiB)`);
    if (DATA_DIR) {
      let gone = false;
      try { statSync(join(DATA_DIR, 'blobs', bigUp.link.split('/s/')[1].split('#')[0])); } catch { gone = true; }
      ok(gone, 'large ciphertext deleted after the confirmed download');
    }
    rmSync(bigFile, { force: true });
    rmSync(bigOut, { force: true });
    await bigCtx.close();
  }

  ok(externalRequests.length === 0, 'no requests to third-party hosts' + (externalRequests.length ? `: ${externalRequests[0]}` : ''));
  console.log(`\nALL PASSED (${passed} checks)`);
} catch (err) {
  console.error('\n' + (err.stack || err));
  process.exitCode = 1;
} finally {
  await browser.close();
  rmSync(tmp, { recursive: true, force: true });
}
