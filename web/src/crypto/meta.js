import { CHUNK, chunkCountFor } from './chunk.js';
import { concat, fromUtf8, randomBytes, shareIdBytes, utf8 } from './util.js';

const AAD_LABEL = utf8('meta-v1');

export function buildMeta({ name, size, mime }) {
  return { name, size, mime, chunk_size: CHUNK, chunk_count: chunkCountFor(size), v: 1 };
}

export async function encryptMeta(kMeta, shareId, meta, iv = randomBytes(12)) {
  const key = await crypto.subtle.importKey('raw', kMeta, 'AES-GCM', false, ['encrypt']);
  const ct = await crypto.subtle.encrypt(
    { name: 'AES-GCM', iv, additionalData: concat(AAD_LABEL, shareIdBytes(shareId)), tagLength: 128 },
    key,
    utf8(JSON.stringify(meta)),
  );
  return concat(iv, new Uint8Array(ct));
}

export async function decryptMeta(kMeta, shareId, blob) {
  if (blob.length < 12 + 16) throw new Error('meta too short');
  const key = await crypto.subtle.importKey('raw', kMeta, 'AES-GCM', false, ['decrypt']);
  const pt = await crypto.subtle.decrypt(
    { name: 'AES-GCM', iv: blob.subarray(0, 12), additionalData: concat(AAD_LABEL, shareIdBytes(shareId)), tagLength: 128 },
    key,
    blob.subarray(12),
  );
  const meta = JSON.parse(fromUtf8(new Uint8Array(pt)));
  validateMeta(meta);
  return meta;
}

export function validateMeta(m) {
  const ok =
    m && m.v === 1 &&
    typeof m.name === 'string' &&
    typeof m.mime === 'string' &&
    Number.isSafeInteger(m.size) && m.size >= 0 &&
    m.chunk_size === CHUNK &&
    m.chunk_count === chunkCountFor(m.size);
  if (!ok) throw new Error('invalid metadata');
}
