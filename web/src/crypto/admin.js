import { argon2id, checkKdfParams } from './kdf.js';
import { hkdf } from './hkdf.js';
import { utf8, wipe } from './util.js';

// Admin password handling (PROTOCOL.md §7):
//   M          = Argon2id(password, wrap_salt, kdf_v1)
//   login      = HKDF(M, "admin-login-v1")   -> sent to the server (verified as HMAC(pepper, login))
//   wrapKey    = HKDF(M, "admin-wrap-v1")    -> never leaves the browser
// The two outputs are independent, so the login secret reveals nothing about wrapKey.

export async function deriveAdminKeys(password, wrapSalt, params) {
  const p = checkKdfParams(params);
  const pass = utf8(password.normalize('NFKC'));
  const M = await argon2id(pass, wrapSalt, p);
  const none = new Uint8Array(0);
  const loginSecret = await hkdf(M, none, 'admin-login-v1', 32);
  const wrapKey = await hkdf(M, none, 'admin-wrap-v1', 32);
  wipe(pass, M);
  return { loginSecret, wrapKey };
}
