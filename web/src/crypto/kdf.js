import { hkdf } from './hkdf.js';
import { concat, shareIdBytes, utf8, wipe } from './util.js';
import { normalizeCode } from './passphrase.js';

/** kdf_v=1 parameters; the server serves the same values from /api/config. */
export const KDF_V1 = Object.freeze({ v: 1, m: 65536, t: 3, p: 1, len: 32 });

let argon2idImpl = null;

/** Registered by argon2-browser.js (browser) or the test harness (Node). */
export function setArgon2id(fn) {
  argon2idImpl = fn;
}

export async function argon2id(pass, salt, params) {
  if (!argon2idImpl) throw new Error('argon2 not initialised');
  return argon2idImpl(pass, salt, params);
}

/**
 * Validates server-supplied KDF parameters. The server fixes them (kdf_v=1);
 * a client refuses anything weaker than the protocol minimum so a hostile or
 * buggy server cannot downgrade the work factor.
 */
export function checkKdfParams(p) {
  if (!p || p.v !== 1 || p.m < 65536 || p.t < 3 || p.p !== 1 || p.len !== 32) {
    throw new Error('unacceptable KDF parameters');
  }
  return { m: p.m, t: p.t, p: p.p, len: p.len };
}

/**
 * Derives the per-share secrets (PROTOCOL.md §3.2).
 * @returns {{auth:Uint8Array,kData:Uint8Array,kMeta:Uint8Array}}
 */
export async function deriveShareKeys({ code, kLink, salt, shareId, params }) {
  const p = checkKdfParams(params);
  if (kLink.length !== 32) throw new Error('bad K_link');
  if (salt.length !== 16) throw new Error('bad salt');
  const idBytes = shareIdBytes(shareId);
  const pass = utf8(normalizeCode(code));
  const M = await argon2id(pass, salt, p);
  const ikm = concat(M, kLink);
  const auth = await hkdf(ikm, idBytes, 'share-auth-v1', 32);
  const kFile = await hkdf(ikm, idBytes, 'file-master-v1', 32);
  const kData = await hkdf(kFile, new Uint8Array(0), 'data-v1', 32);
  const kMeta = await hkdf(kFile, new Uint8Array(0), 'meta-v1', 32);
  wipe(pass, M, ikm, kFile);
  return { auth, kData, kMeta };
}
