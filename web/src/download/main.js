import '../crypto/argon2-browser.js';
import { api, ApiError, retry } from '../common/api.js';
import { $, show, setText, fmtBytes, nextFrame } from '../common/dom.js';
import { deriveShareKeys } from '../crypto/kdf.js';
import { decryptChunk, importDataKey, expectedCipherLen } from '../crypto/chunk.js';
import { decryptMeta } from '../crypto/meta.js';
import { b64uDecode } from '../crypto/util.js';
import { normalizeCode } from '../crypto/passphrase.js';
import { FileSystemSink, ServiceWorkerSink, BlobSink, SinkUnavailable } from './sink.js';

const els = {
  gate: $('gate'), code: $('code'), go: $('go'), error: $('error'), working: $('working'),
  info: $('info'), name: $('name'), size: $('size'), start: $('start'), progress: $('progress'), bar: $('bar'),
  status: $('status'), done: $('done'), doneMsg: $('done-msg'), gone: $('gone'), goneMsg: $('gone-msg'),
  warn: $('warn'), save: $('save'), saveBtn: $('save-btn'),
};

// Phones and in-app/WebView browsers: the save-file dialog is often present but broken (it rejects at once
// as if the user cancelled), and streamed downloads through a service worker are frequently ignored.
// There the file is decrypted in memory and saved by a direct tap on a "save" button instead.
const MOBILE = (navigator.userAgentData && navigator.userAgentData.mobile)
  || /Android|iPhone|iPad|iPod|Mobile|HarmonyOS/i.test(navigator.userAgent);

const m = /^\/s\/([A-Za-z0-9_-]{22})\/?$/.exec(location.pathname);
const shareId = m ? m[1] : null;
let kLink = null;
try {
  const frag = location.hash.slice(1);
  kLink = frag ? b64uDecode(frag) : null;
  if (kLink && kLink.length !== 32) kLink = null;
} catch {
  kLink = null;
}

let state = null;

function fail(msg) {
  setText(els.error, msg);
  show(els.error, true);
}

function terminal(msg) {
  show(els.gate, false);
  show(els.info, false);
  show(els.progress, false);
  setText(els.goneMsg, msg);
  show(els.gone, true);
  window.onbeforeunload = null;
}

async function init() {
  if (!shareId) return terminal('链接无效。');
  if (!kLink) return terminal('链接不完整：缺少 # 之后的密钥部分。请使用完整的分享链接。');
  try {
    state = { challenge: await api(`/api/shares/${shareId}/challenge`) };
  } catch (e) {
    if (e instanceof ApiError && e.status === 410) return terminal('此分享不存在，或已被销毁、已过期。');
    return terminal('无法连接服务器，请稍后重试。');
  }
  show(els.gate, true);
  els.code.focus();
}

els.gate.addEventListener('submit', async (e) => {
  e.preventDefault();
  if (els.go.disabled) return;
  show(els.error, false);
  const code = normalizeCode(els.code.value);
  if (code === '') return fail('请输入取件码。');
  els.go.disabled = true;
  show(els.working, true);
  await nextFrame();
  try {
    const keys = await deriveShareKeys({ code, kLink, salt: b64uDecode(state.challenge.salt), shareId, params: state.challenge.kdf });
    const res = await api(`/api/shares/${shareId}/unlock`, { method: 'POST', json: { auth: btoaUrl(keys.auth) } });
    keys.auth.fill(0);
    const meta = await decryptMeta(keys.kMeta, shareId, b64uDecode(res.enc_meta));
    if (meta.chunk_count !== res.chunk_count) throw new Error('元数据与服务器记录不一致。');
    state = { token: res.download_token, meta, key: await importDataKey(keys.kData, ['decrypt']) };
    keys.kData.fill(0);
    keys.kMeta.fill(0);
    els.code.value = '';
    show(els.gate, false);
    setText(els.name, meta.name);
    setText(els.size, fmtBytes(meta.size));
    show(els.info, true);
    els.start.focus();
  } catch (err) {
    if (err instanceof ApiError) {
      if (err.status === 401) {
        const left = err.data.attempts_left;
        fail(`取件码错误，还可尝试 ${left} 次。次数用尽后此分享将被永久销毁。`);
      } else if (err.status === 410) {
        terminal(err.code === 'destroyed' ? '取件码错误次数过多，此分享已被销毁。' : '此分享不存在，或已被销毁、已过期。');
      } else if (err.status === 423) {
        terminal(`取件码错误次数过多，此分享已被冻结，约 ${Math.ceil((err.data.retry_after || 86400) / 3600)} 小时后可再试。`);
      } else if (err.status === 429) {
        fail('尝试过于频繁，请稍后再试。');
      } else {
        fail(`服务器返回错误（${err.code || err.status}）。`);
      }
    } else {
      fail(err.message || '解锁失败。');
    }
  } finally {
    els.go.disabled = false;
    show(els.working, false);
  }
});

function btoaUrl(bytes) {
  let s = '';
  for (const b of bytes) s += String.fromCharCode(b);
  return btoa(s).replace(/\+/g, '-').replace(/\//g, '_').replace(/=+$/, '');
}

async function fetchChunk(i) {
  const { meta, token } = state;
  const res = await retry(() => api(`/api/shares/${shareId}/chunks/${i}`, { token, raw: true }));
  const buf = new Uint8Array(await res.arrayBuffer());
  if (buf.length !== expectedCipherLen(i, meta.chunk_count, meta.size)) throw new Error(`第 ${i + 1} 块长度异常，已中止。`);
  return buf;
}

/** Downloads, decrypts and writes every chunk into `sink`. Throws SinkUnavailable if the sink cannot deliver. */
async function pump(sink) {
  const { meta, key } = state;
  let next = fetchChunk(0);
  let written = 0;
  for (let i = 0; i < meta.chunk_count; i++) {
    const cipher = await next;
    if (i + 1 < meta.chunk_count) next = fetchChunk(i + 1);
    const plain = await decryptChunk(key, shareId, i, i === meta.chunk_count - 1, cipher);
    await sink.write(plain);
    written += plain.length;
    els.bar.max = meta.size || 1;
    els.bar.value = written;
    setText(els.status, `${fmtBytes(written)} / ${fmtBytes(meta.size)}`);
  }
  if (written !== meta.size) throw new Error('解密后的大小与元数据不一致。');
}

function downloadFailed(err) {
  window.onbeforeunload = null;
  show(els.progress, false);
  els.start.disabled = false;
  if (err instanceof ApiError && (err.status === 410 || err.status === 401)) return terminal('此分享已不可用。');
  return fail(err instanceof ApiError ? `下载失败（${err.code || err.status}）。` : err && err.name === 'OperationError' ? '数据校验失败：文件可能已被损坏或篡改，已中止。' : (err && err.message) || '下载失败。');
}

/** Tells the server the download is complete (it may burn the share) and shows the final message. */
async function finish(message) {
  let burned = false;
  try {
    burned = (await retry(() => api(`/api/shares/${shareId}/complete`, { method: 'POST', token: state.token }))).burned;
  } catch {
    /* the file is already saved; the share simply stays until it expires */
  }
  show(els.progress, false);
  show(els.info, false);
  show(els.save, false);
  setText(els.doneMsg, burned ? `${message}此分享已按设置自动销毁。` : message);
  show(els.done, true);
}

/** Mobile path: decrypt fully into memory, then save from a fresh tap so the browser treats it as user-initiated. */
async function mobileDownload() {
  const sink = new BlobSink(state.meta.name);
  try {
    await pump(sink);
  } catch (err) {
    sink.abort();
    return downloadFailed(err);
  }
  window.onbeforeunload = null;
  show(els.progress, false);
  show(els.info, false);
  show(els.save, true);
  els.saveBtn.disabled = false;
  els.saveBtn.focus();
  els.saveBtn.onclick = async () => {
    els.saveBtn.disabled = true;
    await sink.close();
    await finish('已交给浏览器保存，请在浏览器的下载列表中查看。');
  };
}

els.start.addEventListener('click', async () => {
  els.start.disabled = true;
  show(els.error, false);
  show(els.warn, false);
  const { meta } = state;
  show(els.progress, true);
  window.onbeforeunload = () => '下载尚未完成。';

  if (MOBILE) return mobileDownload();

  // Preferred order: save-file dialog (cannot be intercepted by download managers), streamed download
  // through the service worker, then in-memory (files are limited to 500 MB). The picker has to be
  // the first await so the click's user activation is still valid.
  const attempts = [];
  if (meta.size > 0 && FileSystemSink.supported()) attempts.push(() => new FileSystemSink(meta.name));
  attempts.push(async () => (meta.size > 0 && (await ServiceWorkerSink.supported()) ? new ServiceWorkerSink(meta.name, meta.size) : null));
  attempts.push(() => new BlobSink(meta.name));

  let finished = false;
  let lastError = null;
  for (const make of attempts) {
    let sink = null;
    const startedAt = Date.now();
    try {
      sink = await make();
      if (!sink) continue;
      await sink.open();
      await pump(sink);
      await sink.close();
      finished = true;
      break;
    } catch (err) {
      if (sink) sink.abort();
      // A real "cancel" in the save dialog takes the user at least a moment. An AbortError that comes back
      // almost instantly means the dialog never appeared (broken implementation): use the next method instead.
      if (err && err.name === 'AbortError' && sink instanceof FileSystemSink && Date.now() - startedAt > 600) {
        show(els.progress, false);
        els.start.disabled = false;
        window.onbeforeunload = null;
        return fail('已取消保存。可再次点击“下载并解密”。');
      }
      lastError = err;
      if (err instanceof SinkUnavailable || (sink instanceof FileSystemSink && !(err instanceof ApiError) && err.name !== 'OperationError')) {
        continue; // try the next way of saving
      }
      break;
    }
  }

  if (!finished) return downloadFailed(lastError);
  window.onbeforeunload = null;
  await finish('文件已保存。');
});

init();
