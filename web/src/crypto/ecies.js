import { hkdf } from './hkdf.js';
import { concat, fromUtf8, randomBytes, utf8 } from './util.js';

// sealed_name = eph_pub(65, uncompressed P-256) || iv(12) || AES-256-GCM(ct||tag)
// key = HKDF-SHA256(ikm = ECDH(eph, admin_pub), salt = eph_pub, info = "name-seal-v1")

const INFO = 'name-seal-v1';
const AAD = utf8('name-seal-v1');

export async function generateAdminKeyPair() {
  const kp = await crypto.subtle.generateKey({ name: 'ECDH', namedCurve: 'P-256' }, true, ['deriveBits']);
  const pub = new Uint8Array(await crypto.subtle.exportKey('raw', kp.publicKey));
  const priv = await crypto.subtle.exportKey('pkcs8', kp.privateKey);
  return { pub, pkcs8: new Uint8Array(priv) };
}

async function sealKey(secret, ephPub) {
  return crypto.subtle.importKey('raw', await hkdf(secret, ephPub, INFO, 32), 'AES-GCM', false, ['encrypt', 'decrypt']);
}

export async function sealName(adminPub, name, { ephemeral, iv = randomBytes(12) } = {}) {
  const peer = await crypto.subtle.importKey('raw', adminPub, { name: 'ECDH', namedCurve: 'P-256' }, false, []);
  const eph = ephemeral ?? (await crypto.subtle.generateKey({ name: 'ECDH', namedCurve: 'P-256' }, true, ['deriveBits']));
  const ephPub = new Uint8Array(await crypto.subtle.exportKey('raw', eph.publicKey));
  const secret = new Uint8Array(await crypto.subtle.deriveBits({ name: 'ECDH', public: peer }, eph.privateKey, 256));
  const key = await sealKey(secret, ephPub);
  secret.fill(0);
  const ct = await crypto.subtle.encrypt(
    { name: 'AES-GCM', iv, additionalData: AAD, tagLength: 128 },
    key,
    utf8(name),
  );
  return concat(ephPub, iv, new Uint8Array(ct));
}

export async function openName(adminPkcs8, sealed) {
  if (sealed.length < 65 + 12 + 16 || sealed[0] !== 4) throw new Error('bad sealed name');
  const ephPub = sealed.subarray(0, 65);
  const iv = sealed.subarray(65, 77);
  const ct = sealed.subarray(77);
  const priv = await crypto.subtle.importKey('pkcs8', adminPkcs8, { name: 'ECDH', namedCurve: 'P-256' }, false, ['deriveBits']);
  const peer = await crypto.subtle.importKey('raw', ephPub, { name: 'ECDH', namedCurve: 'P-256' }, false, []);
  const secret = new Uint8Array(await crypto.subtle.deriveBits({ name: 'ECDH', public: peer }, priv, 256));
  const key = await sealKey(secret, ephPub);
  secret.fill(0);
  const pt = await crypto.subtle.decrypt({ name: 'AES-GCM', iv, additionalData: AAD, tagLength: 128 }, key, ct);
  return fromUtf8(new Uint8Array(pt));
}

// ---- admin private-key wrapping (password-derived wrapKey) -------------------
// wrapKey = HKDF(Argon2id(password, wrap_salt), info = "admin-wrap-v1"); the
// server stores wrap_salt + iv||AES-GCM(wrapKey, pkcs8). Password never leaves
// the browser: the login secret is a *different* HKDF output (see admin module).

export async function wrapPrivateKey(wrapKeyBytes, pkcs8, iv = randomBytes(12)) {
  const key = await crypto.subtle.importKey('raw', wrapKeyBytes, 'AES-GCM', false, ['encrypt']);
  const ct = await crypto.subtle.encrypt({ name: 'AES-GCM', iv, additionalData: utf8('admin-wrap-v1'), tagLength: 128 }, key, pkcs8);
  return concat(iv, new Uint8Array(ct));
}

export async function unwrapPrivateKey(wrapKeyBytes, blob) {
  const key = await crypto.subtle.importKey('raw', wrapKeyBytes, 'AES-GCM', false, ['decrypt']);
  const pt = await crypto.subtle.decrypt(
    { name: 'AES-GCM', iv: blob.subarray(0, 12), additionalData: utf8('admin-wrap-v1'), tagLength: 128 },
    key,
    blob.subarray(12),
  );
  return new Uint8Array(pt);
}
