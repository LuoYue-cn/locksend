// Regenerates tests/vectors.json from fixed inputs. The PHP test-suite
// (server/tests/VectorsTest.php) recomputes every value with independent
// libraries (libsodium, hash_hkdf, OpenSSL), so both implementations must agree.
import { writeFileSync } from 'node:fs';
import { fileURLToPath } from 'node:url';
import { dirname, join } from 'node:path';
import { initArgon2, } from './helpers.js';
import { deriveShareKeys } from '../src/crypto/kdf.js';
import { encryptChunk, chunkIv, chunkAad, importDataKey } from '../src/crypto/chunk.js';
import { buildMeta, encryptMeta } from '../src/crypto/meta.js';
import { hex, b64uEncode } from '../src/crypto/util.js';
import { hkdf } from '../src/crypto/hkdf.js';

await initArgon2();
const here = dirname(fileURLToPath(import.meta.url));

const shareIdBytes = Uint8Array.from({ length: 16 }, (_, i) => i + 1);
const shareId = b64uEncode(shareIdBytes);
const kLink = Uint8Array.from({ length: 32 }, (_, i) => 0xa0 + i);
const salt = Uint8Array.from({ length: 16 }, (_, i) => 0x30 + i);
const code = 'River Amber_lunar-fox'; // normalises to river-amber-lunar-fox
const params = { v: 1, m: 65536, t: 3, p: 1, len: 32 };

const keys = await deriveShareKeys({ code, kLink, salt, shareId, params });
const key = await importDataKey(keys.kData);

const plain0 = new TextEncoder().encode('hello, locksend');
const c0 = await encryptChunk(key, shareId, 0, false, plain0);
const c1 = await encryptChunk(key, shareId, 1, true, new TextEncoder().encode('tail'));
const empty = await encryptChunk(key, shareId, 0, true, new Uint8Array(0));
const bigIndexIv = chunkIv(0x1_0000_0002);

const meta = buildMeta({ name: 'report.pdf', size: 15, mime: 'application/pdf' });
const metaIv = Uint8Array.from({ length: 12 }, (_, i) => 0x70 + i);
const encMeta = await encryptMeta(keys.kMeta, shareId, meta, metaIv);

const v = {
  share_id: shareId,
  share_id_hex: hex(shareIdBytes),
  k_link_hex: hex(kLink),
  salt_hex: hex(salt),
  code_input: code,
  code_normalized: 'river-amber-lunar-fox',
  kdf: params,
  auth_hex: hex(keys.auth),
  k_data_hex: hex(keys.kData),
  k_meta_hex: hex(keys.kMeta),
  chunk0: { plain_hex: hex(plain0), is_last: 0, iv_hex: hex(chunkIv(0)), aad_hex: hex(chunkAad(shareId, 0, false)), cipher_hex: hex(c0) },
  chunk1: { plain_hex: hex(new TextEncoder().encode('tail')), is_last: 1, iv_hex: hex(chunkIv(1)), aad_hex: hex(chunkAad(shareId, 1, true)), cipher_hex: hex(c1) },
  empty_file: { is_last: 1, iv_hex: hex(chunkIv(0)), aad_hex: hex(chunkAad(shareId, 0, true)), cipher_hex: hex(empty) },
  iv_index_2_pow_32_plus_2_hex: hex(bigIndexIv),
  meta_json: JSON.stringify(meta),
  meta_iv_hex: hex(metaIv),
  enc_meta_hex: hex(encMeta),
  hkdf_rfc5869_case1: {
    ikm_hex: '0b'.repeat(22),
    salt_hex: '000102030405060708090a0b0c',
    info_hex: 'f0f1f2f3f4f5f6f7f8f9',
    okm_hex: hex(await hkdf(Uint8Array.from(Array(22).fill(0x0b)), Uint8Array.from([0,1,2,3,4,5,6,7,8,9,10,11,12]), Uint8Array.from([0xf0,0xf1,0xf2,0xf3,0xf4,0xf5,0xf6,0xf7,0xf8,0xf9]), 42)),
  },
};
writeFileSync(join(here, 'vectors.json'), JSON.stringify(v, null, 2) + '\n');
console.log('wrote vectors.json');
