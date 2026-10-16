import '../crypto/argon2-browser.js';
import { api, ApiError } from '../common/api.js';
import { $, show, setText, nextFrame } from '../common/dom.js';
import { KDF_V1 } from '../crypto/kdf.js';
import { deriveAdminKeys } from '../crypto/admin.js';
import { generateAdminKeyPair, wrapPrivateKey } from '../crypto/ecies.js';
import { b64uEncode, randomBytes } from '../crypto/util.js';

const els = {
  checks: $('checks'), step1: $('step1'), step2: $('step2'), token: $('token'), pw: $('pw'), pw2: $('pw2'),
  next: $('next'), secret: $('secret'), uri: $('uri'), totp: $('totp'), finish: $('finish'), error: $('error'),
  working: $('working'), done: $('done'), blocked: $('blocked'),
};

const LABELS = { sodium: 'PHP sodium 扩展', pdo: '数据库驱动', data_dir: '数据目录可写', redis: 'Redis 连接', secret: '服务器密钥存储' };
let tokenValue = '';

function fail(msg) {
  setText(els.error, msg);
  show(els.error, true);
}

function describe(e) {
  if (e instanceof ApiError) {
    const map = {
      bad_setup_token: '安装令牌不正确。',
      rate_limited: '尝试过于频繁，请稍后再试。',
      bad_totp: '动态验证码不正确。',
      begin_required: '会话已过期，请重新开始。',
      not_found: '安装已完成。',
    };
    return map[e.code] || `服务器返回错误（${e.code || e.status}）。`;
  }
  return e.message || '发生错误。';
}

async function init() {
  let st;
  try {
    st = await api('/api/install/status');
  } catch (e) {
    show(els.blocked, true);
    return;
  }
  if (st.installed) {
    location.replace('/');
    return;
  }
  els.checks.replaceChildren();
  for (const [k, ok] of Object.entries(st.checks)) {
    const li = document.createElement('li');
    li.textContent = `${ok ? '✓' : '✗'} ${LABELS[k] || k}`;
    li.className = ok ? 'ok' : 'bad';
    els.checks.append(li);
  }
  if (!st.token_present) {
    setText($('token-hint'), '尚未生成安装令牌。请在服务器上运行：php bin/admin-setup-token.php');
  }
  show(els.step1, st.ready);
  if (!st.ready) fail('环境检查未通过，请先修复上面标记 ✗ 的项目。');
}

els.step1.addEventListener('submit', async (e) => {
  e.preventDefault();
  show(els.error, false);
  const pw = els.pw.value;
  if (pw.length < 12) return fail('管理员密码至少 12 个字符。');
  if (pw !== els.pw2.value) return fail('两次输入的密码不一致。');
  tokenValue = els.token.value.trim();
  els.next.disabled = true;
  try {
    const r = await api('/api/install/begin', { method: 'POST', json: { setup_token: tokenValue } });
    setText(els.secret, r.totp_secret);
    setText(els.uri, r.totp_uri);
    show(els.step1, false);
    show(els.step2, true);
    els.totp.focus();
  } catch (err) {
    fail(describe(err));
  } finally {
    els.next.disabled = false;
  }
});

els.step2.addEventListener('submit', async (e) => {
  e.preventDefault();
  show(els.error, false);
  els.finish.disabled = true;
  show(els.working, true);
  try {
    await nextFrame();
    const wrapSalt = randomBytes(16);
    const { loginSecret, wrapKey } = await deriveAdminKeys(els.pw.value, wrapSalt, KDF_V1);
    const { pub, pkcs8 } = await generateAdminKeyPair();
    const wrapped = await wrapPrivateKey(wrapKey, pkcs8);
    pkcs8.fill(0);
    wrapKey.fill(0);
    await api('/api/install/finish', {
      method: 'POST',
      json: {
        setup_token: tokenValue,
        admin_pub: b64uEncode(pub),
        wrap_salt: b64uEncode(wrapSalt),
        wrapped_priv: b64uEncode(wrapped),
        login_secret: b64uEncode(loginSecret),
        totp_code: els.totp.value.trim(),
      },
    });
    els.pw.value = els.pw2.value = '';
    show(els.step2, false);
    show(els.done, true);
  } catch (err) {
    fail(describe(err));
  } finally {
    els.finish.disabled = false;
    show(els.working, false);
  }
});

init();
