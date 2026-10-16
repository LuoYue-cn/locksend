import '../crypto/argon2-browser.js';
import { api, ApiError } from '../common/api.js';
import { $, show, setText, fmtDate, nextFrame } from '../common/dom.js';
import { KDF_V1 } from '../crypto/kdf.js';
import { deriveAdminKeys } from '../crypto/admin.js';
import { unwrapPrivateKey, openName } from '../crypto/ecies.js';
import { b64uDecode } from '../crypto/util.js';

const els = {
  login: $('login'), pw: $('pw'), totp: $('totp'), go: $('go'), error: $('error'), working: $('working'),
  panel: $('panel'), rows: $('rows'), empty: $('empty'), logout: $('logout'), refresh: $('refresh'),
};

let priv = null; // PKCS#8 bytes, memory only; discarded on logout / page close

function fail(msg) {
  setText(els.error, msg);
  show(els.error, true);
}

async function loadList() {
  const { shares } = await api('/api/admin/shares');
  els.rows.replaceChildren();
  show(els.empty, shares.length === 0);
  for (const s of shares) {
    let name;
    try {
      name = await openName(priv, b64uDecode(s.sealed_name));
    } catch {
      name = '（无法解密）';
    }
    const tr = document.createElement('tr');
    const tdName = document.createElement('td');
    tdName.textContent = name;
    const tdId = document.createElement('td');
    tdId.textContent = s.id;
    tdId.className = 'mono';
    const tdCreated = document.createElement('td');
    tdCreated.textContent = fmtDate(s.created_at);
    const tdExp = document.createElement('td');
    tdExp.textContent = fmtDate(s.expires_at);
    const tdAct = document.createElement('td');
    const del = document.createElement('button');
    del.type = 'button';
    del.textContent = '删除';
    del.className = 'danger';
    del.addEventListener('click', async () => {
      if (!confirm(`永久删除“${name}”？此操作无法撤销。`)) return;
      del.disabled = true;
      try {
        await api(`/api/admin/shares/${s.id}`, { method: 'DELETE' });
        tr.remove();
        show(els.empty, els.rows.children.length === 0);
      } catch (e) {
        del.disabled = false;
        fail('删除失败。');
      }
    });
    tdAct.append(del);
    tr.append(tdName, tdId, tdCreated, tdExp, tdAct);
    els.rows.append(tr);
  }
}

async function enter(wrappedB64, wrapKey) {
  priv = await unwrapPrivateKey(wrapKey, b64uDecode(wrappedB64));
  show(els.login, false);
  show(els.panel, true);
  await loadList();
}

els.login.addEventListener('submit', async (e) => {
  e.preventDefault();
  show(els.error, false);
  els.go.disabled = true;
  show(els.working, true);
  try {
    await nextFrame();
    const { wrap_salt: salt } = await api('/api/admin/salt');
    const { loginSecret, wrapKey } = await deriveAdminKeys(els.pw.value, b64uDecode(salt), KDF_V1);
    let b64 = '';
    for (const b of loginSecret) b64 += String.fromCharCode(b);
    b64 = btoa(b64).replace(/\+/g, '-').replace(/\//g, '_').replace(/=+$/, '');
    const r = await api('/api/admin/login', { method: 'POST', json: { login_secret: b64, totp_code: els.totp.value.trim() } });
    els.pw.value = els.totp.value = '';
    await enter(r.wrapped_priv, wrapKey);
    wrapKey.fill(0);
  } catch (err) {
    if (err instanceof ApiError) {
      fail(err.status === 429 ? '尝试过于频繁，请稍后再试。' : err.status === 401 ? '密码或动态验证码不正确。' : `服务器返回错误（${err.code || err.status}）。`);
    } else {
      fail(err.message || '登录失败。');
    }
  } finally {
    els.go.disabled = false;
    show(els.working, false);
  }
});

els.logout.addEventListener('click', async () => {
  try {
    await api('/api/admin/logout', { method: 'POST' });
  } finally {
    priv = null;
    location.reload();
  }
});

els.refresh.addEventListener('click', () => loadList().catch(() => fail('刷新失败。')));

// A fresh page load always asks for the password again, because the unwrapped
// private key is never persisted anywhere.
