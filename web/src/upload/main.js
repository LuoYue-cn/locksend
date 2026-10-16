import '../crypto/argon2-browser.js';
import { api, ApiError, retry } from '../common/api.js';
import { $, show, setText, fmtBytes, nextFrame, copyText } from '../common/dom.js';
import { deriveShareKeys } from '../crypto/kdf.js';
import { CHUNK, encryptChunk, importDataKey, chunkCountFor } from '../crypto/chunk.js';
import { buildMeta, encryptMeta } from '../crypto/meta.js';
import { sealName } from '../crypto/ecies.js';
import { generatePassphrase, normalizeCode, customCodeLengthOk } from '../crypto/passphrase.js';
import { b64uEncode, b64uDecode, randomBytes, utf8 } from '../crypto/util.js';
import { loadZxcvbn } from './zxcvbn-loader.js';

const els = {
  form: $('form'), file: $('file'), fileInfo: $('file-info'), words: $('words'), maxFails: $('max-fails'),
  failAction: $('fail-action'), ttl: $('ttl'), burn: $('burn'), custom: $('custom'), customHint: $('custom-hint'),
  go: $('go'), error: $('error'), progress: $('progress'), bar: $('bar'), status: $('status'), cancel: $('cancel'),
  retry: $('retry'), result: $('result'), link: $('link'), code: $('code'), copyLink: $('copy-link'),
  copyCode: $('copy-code'), again: $('again'), expires: $('expires'),
};

let cfg = null;
let busy = false;
let abortRequested = false;
let session = null;

function fail(msg) {
  setText(els.error, msg);
  show(els.error, true);
}

async function init() {
  try {
    cfg = await api('/api/config');
  } catch {
    return fail('无法连接服务器，请稍后重试。');
  }
  if (!cfg.installed) {
    location.replace('/install');
    return;
  }
  els.ttl.replaceChildren();
  const opts = [1, 3, 7, 14, 30].filter((d) => d <= cfg.ttl_days.max);
  if (!opts.includes(cfg.ttl_days.max)) opts.push(cfg.ttl_days.max);
  for (const d of opts) {
    const o = document.createElement('option');
    o.value = String(d);
    o.textContent = `${d} 天`;
    if (d === cfg.ttl_days.default) o.selected = true;
    els.ttl.append(o);
  }
  setText($('file-limit'), `单个文件最大 ${limitText()}。`);
  show(els.form, true);
}

const tooLarge = (f) => cfg && f.size > cfg.max_file_bytes;
const limitText = () => `${Math.floor(cfg.max_file_bytes / 1e6)} MB`;

els.file.addEventListener('change', () => {
  const f = els.file.files[0];
  show(els.error, false);
  setText(els.fileInfo, f ? `${f.name}（${fmtBytes(f.size)}）` : '');
  if (f && tooLarge(f)) fail(`文件太大：单个文件不能超过 ${limitText()}。`);
});

els.custom.addEventListener('input', async () => {
  const v = els.custom.value;
  if (v === '') return setText(els.customHint, '');
  if (!customCodeLengthOk(v)) return setText(els.customHint, '至少 12 个字符。');
  const z = await loadZxcvbn();
  const score = z(normalizeCode(v)).score;
  setText(els.customHint, score >= 4 ? '强度合格。' : `强度不足（${score}/4），请使用更长、更随机的口令。`);
});

function setProgress(done, total, label) {
  els.bar.max = total;
  els.bar.value = done;
  setText(els.status, label);
}

els.form.addEventListener('submit', (e) => {
  e.preventDefault();
  if (busy) return;
  const file = els.file.files[0];
  if (!file) return fail('请先选择文件。');
  if (tooLarge(file)) return fail(`文件太大：单个文件不能超过 ${limitText()}。`);
  show(els.error, false);
  run(file).catch((err) => {
    busy = false;
    window.onbeforeunload = null;
    show(els.progress, false);
    show(els.form, true);
    if (err && err.message === 'aborted') return;
    fail(describe(err));
  });
});

function describe(err) {
  if (err instanceof ApiError) {
    if (err.status === 429) return '操作过于频繁，请稍后再试。';
    if (err.status === 507) return '服务器存储空间不足。';
    if (err.code === 'file_too_large') return `文件太大：单个文件不能超过 ${Math.floor(err.data.max_file_bytes / 1e6)} MB。`;
    if (err.status === 503) return '服务暂不可用。';
    return `服务器拒绝了请求（${err.code || err.status}）。`;
  }
  return err && err.message ? err.message : '未知错误。';
}

async function uploadChunks(s, key, file, count) {
  const sizeOf = (i) => Math.min(CHUNK, file.size - i * CHUNK);
  for (;;) {
    let have = new Set();
    try {
      const st = await retry(() => api(`/api/shares/${s.id}/upload-status`, { token: s.token }));
      for (const [a, b] of st.received) for (let i = a; i <= b; i++) have.add(i);
      for (let i = 0; i < count; i++) {
        if (abortRequested) throw new Error('aborted');
        if (have.has(i)) continue;
        const plain = new Uint8Array(await file.slice(i * CHUNK, i * CHUNK + sizeOf(i)).arrayBuffer());
        const ct = await encryptChunk(key, s.id, i, i === count - 1, plain);
        await retry(() => api(`/api/shares/${s.id}/chunks/${i}`, { method: 'PUT', body: ct, token: s.token, headers: { 'Content-Type': 'application/octet-stream' } }));
        have.add(i);
        setProgress(have.size, count, `已上传 ${have.size} / ${count} 块`);
      }
      return;
    } catch (e) {
      if (abortRequested || (e instanceof ApiError && e.status >= 400 && e.status < 500 && e.status !== 429)) throw e;
      setText(els.status, '连接中断。点击“继续”从断点恢复。');
      show(els.retry, true);
      await new Promise((r) => els.retry.addEventListener('click', r, { once: true }));
      show(els.retry, false);
    }
  }
}

async function run(file) {
  busy = true;
  abortRequested = false;
  if (utf8(file.name).length > 900) throw new Error('文件名过长。');
  const words = Number(els.words.value) === 6 ? 6 : 4;
  let code;
  if (els.custom.value.trim() !== '') {
    code = normalizeCode(els.custom.value);
    if (!customCodeLengthOk(code)) throw new Error('自定义取件码至少 12 个字符。');
    const z = await loadZxcvbn();
    if (z(code).score < 4) throw new Error('自定义取件码强度不足。');
  } else {
    code = generatePassphrase(words);
  }

  show(els.form, false);
  show(els.progress, true);
  setProgress(0, 1, '正在准备…');
  window.onbeforeunload = () => '上传尚未完成。';

  const reserved = await api('/api/shares/reserve', { method: 'POST' });
  session = { id: reserved.share_id, token: reserved.upload_token };
  const kLink = randomBytes(32);
  const salt = randomBytes(16);

  setText(els.status, '正在派生密钥（需要几秒钟）…');
  await nextFrame();
  const keys = await deriveShareKeys({ code, kLink, salt, shareId: session.id, params: cfg.kdf });

  const meta = buildMeta({ name: file.name, size: file.size, mime: file.type || 'application/octet-stream' });
  const encMeta = await encryptMeta(keys.kMeta, session.id, meta);
  const sealed = await sealName(b64uDecode(cfg.admin_pub), file.name);
  const count = chunkCountFor(file.size);

  await api(`/api/shares/${session.id}`, {
    method: 'POST',
    token: session.token,
    json: {
      kdf_v: cfg.kdf.v,
      salt: b64uEncode(salt),
      auth: b64uEncode(keys.auth),
      enc_meta: b64uEncode(encMeta),
      sealed_name: b64uEncode(sealed),
      chunk_count: count,
      max_fails: Number(els.maxFails.value),
      fail_action: els.failAction.value,
      ttl_days: Number(els.ttl.value),
      burn_after_complete: els.burn.checked,
      max_completions: 1,
    },
  });

  const key = await importDataKey(keys.kData, ['encrypt']);
  await uploadChunks(session, key, file, count);
  const fin = await retry(() => api(`/api/shares/${session.id}/finalize`, { method: 'POST', token: session.token }));

  busy = false;
  window.onbeforeunload = null;
  show(els.progress, false);
  setText(els.link, `${location.origin}/s/${session.id}#${b64uEncode(kLink)}`);
  setText(els.code, code);
  setText(els.expires, new Date(fin.expires_at * 1000).toLocaleString());
  show(els.result, true);
  kLink.fill(0);
  keys.kData.fill(0);
  keys.kMeta.fill(0);
  keys.auth.fill(0);
  session = null;
}

els.cancel.addEventListener('click', async () => {
  abortRequested = true;
  if (session) {
    try {
      await api(`/api/shares/${session.id}`, { method: 'DELETE', token: session.token });
    } catch {
      /* the server expires unfinished uploads on its own */
    }
  }
  location.reload();
});

async function copyWithFeedback(btn, text) {
  const ok = await copyText(text);
  const old = btn.textContent;
  btn.textContent = ok ? '已复制' : '复制失败，请手动选择';
  setTimeout(() => (btn.textContent = old), 1600);
}
els.copyLink.addEventListener('click', () => copyWithFeedback(els.copyLink, els.link.textContent));
els.copyCode.addEventListener('click', () => copyWithFeedback(els.copyCode, els.code.textContent));
els.again.addEventListener('click', () => location.reload());

init();
