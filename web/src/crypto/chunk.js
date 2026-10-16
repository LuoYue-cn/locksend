import { concat, shareIdBytes, u32be, utf8 } from './util.js';

export const CHUNK = 4194304;
export const TAG = 16;
export const CCHUNK = CHUNK + TAG;

export function chunkCountFor(size) {
  return size === 0 ? 1 : Math.ceil(size / CHUNK);
}

/** Ciphertext length of a whole file of `size` plaintext bytes. */
export function cipherSize(size) {
  return size + chunkCountFor(size) * TAG;
}

export function importDataKey(kData, usages = ['encrypt', 'decrypt']) {
  return crypto.subtle.importKey('raw', kData, 'AES-GCM', false, usages);
}

export function chunkIv(index) {
  const iv = new Uint8Array(12);
  const dv = new DataView(iv.buffer);
  dv.setUint32(4, Math.floor(index / 0x100000000), false);
  dv.setUint32(8, index >>> 0, false);
  return iv;
}

export function chunkAad(shareId, index, isLast) {
  return concat(utf8('v1'), shareIdBytes(shareId), u32be(index), Uint8Array.of(isLast ? 1 : 0));
}

export async function encryptChunk(key, shareId, index, isLast, plain) {
  const ct = await crypto.subtle.encrypt(
    { name: 'AES-GCM', iv: chunkIv(index), additionalData: chunkAad(shareId, index, isLast), tagLength: 128 },
    key,
    plain,
  );
  return new Uint8Array(ct);
}

export async function decryptChunk(key, shareId, index, isLast, cipher) {
  const pt = await crypto.subtle.decrypt(
    { name: 'AES-GCM', iv: chunkIv(index), additionalData: chunkAad(shareId, index, isLast), tagLength: 128 },
    key,
    cipher,
  );
  return new Uint8Array(pt);
}

/**
 * Expected ciphertext length of chunk `index` given metadata. Used by the
 * downloader to reject truncated / padded streams before decrypting.
 */
export function expectedCipherLen(index, chunkCount, size) {
  if (index < chunkCount - 1) return CCHUNK;
  const lastPlain = size === 0 ? 0 : size - CHUNK * (chunkCount - 1);
  return lastPlain + TAG;
}
