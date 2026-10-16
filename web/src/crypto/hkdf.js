import { utf8 } from './util.js';

/** HKDF-SHA256 (RFC 5869) via WebCrypto. Returns raw bytes. */
export async function hkdf(ikm, salt, info, length = 32) {
  const base = await crypto.subtle.importKey('raw', ikm, 'HKDF', false, ['deriveBits']);
  const bits = await crypto.subtle.deriveBits(
    { name: 'HKDF', hash: 'SHA-256', salt, info: typeof info === 'string' ? utf8(info) : info },
    base,
    length * 8,
  );
  return new Uint8Array(bits);
}
