import test from 'node:test';
import assert from 'node:assert/strict';
import { initArgon2, loadVectors } from './helpers.js';
import { deriveShareKeys, checkKdfParams } from '../src/crypto/kdf.js';
import { hkdf } from '../src/crypto/hkdf.js';
import { encryptChunk, decryptChunk, importDataKey, chunkIv, chunkAad, chunkCountFor, cipherSize, expectedCipherLen, CHUNK, CCHUNK } from '../src/crypto/chunk.js';
import { buildMeta, encryptMeta, decryptMeta } from '../src/crypto/meta.js';
import { generateAdminKeyPair, sealName, openName, wrapPrivateKey, unwrapPrivateKey } from '../src/crypto/ecies.js';
import { normalizeCode, randomIndex, generatePassphrase } from '../src/crypto/passphrase.js';
import { WORDS } from '../src/crypto/wordlist-eff.js';
import { hex, unhex, b64uEncode, b64uDecode } from '../src/crypto/util.js';

await initArgon2();
const V = loadVectors();

test('HKDF matches RFC 5869 case 1', async () => {
  const c = V.hkdf_rfc5869_case1;
  const okm = await hkdf(unhex(c.ikm_hex), unhex(c.salt_hex), unhex(c.info_hex), 42);
  assert.equal(hex(okm), '3cb25f25faacd57a90434f64d0362f2a2d2d0a90cf1a5a4c5db02d56ecc4c5bf34007208d5b887185865');
  assert.equal(hex(okm), c.okm_hex);
});

test('key derivation reproduces fixed vector', async () => {
  const k = await deriveShareKeys({ code: V.code_input, kLink: unhex(V.k_link_hex), salt: unhex(V.salt_hex), shareId: V.share_id, params: V.kdf });
  assert.equal(hex(k.auth), V.auth_hex);
  assert.equal(hex(k.kData), V.k_data_hex);
  assert.equal(hex(k.kMeta), V.k_meta_hex);
});

test('a different code or K_link changes every key', async () => {
  const base = { kLink: unhex(V.k_link_hex), salt: unhex(V.salt_hex), shareId: V.share_id, params: V.kdf };
  const a = await deriveShareKeys({ ...base, code: 'river-amber-lunar-fox' });
  const b = await deriveShareKeys({ ...base, code: 'river-amber-lunar-fax' });
  const c = await deriveShareKeys({ ...base, code: 'river-amber-lunar-fox', kLink: new Uint8Array(32) });
  assert.notEqual(hex(a.auth), hex(b.auth));
  assert.notEqual(hex(a.auth), hex(c.auth));
  assert.notEqual(hex(a.kData), hex(c.kData));
});

test('client refuses weakened KDF parameters', () => {
  assert.throws(() => checkKdfParams({ v: 1, m: 1024, t: 3, p: 1, len: 32 }));
  assert.throws(() => checkKdfParams({ v: 1, m: 65536, t: 1, p: 1, len: 32 }));
  assert.throws(() => checkKdfParams({ v: 2, m: 65536, t: 3, p: 1, len: 32 }));
  assert.doesNotThrow(() => checkKdfParams({ v: 1, m: 65536, t: 3, p: 1, len: 32 }));
});

test('chunk IV and AAD layout', () => {
  assert.equal(hex(chunkIv(0x100000002)), V.iv_index_2_pow_32_plus_2_hex);
  assert.equal(hex(chunkAad(V.share_id, 0, false)), V.chunk0.aad_hex);
  assert.equal(hex(chunkAad(V.share_id, 1, true)), V.chunk1.aad_hex);
});

test('chunk ciphertext vectors', async () => {
  const key = await importDataKey(unhex(V.k_data_hex));
  assert.equal(hex(await encryptChunk(key, V.share_id, 0, false, unhex(V.chunk0.plain_hex))), V.chunk0.cipher_hex);
  assert.equal(hex(await encryptChunk(key, V.share_id, 1, true, unhex(V.chunk1.plain_hex))), V.chunk1.cipher_hex);
  const empty = await encryptChunk(key, V.share_id, 0, true, new Uint8Array(0));
  assert.equal(empty.length, 16);
  assert.equal(hex(empty), V.empty_file.cipher_hex);
  assert.equal((await decryptChunk(key, V.share_id, 0, true, empty)).length, 0);
});

test('chunk auth: wrong index, wrong is_last, wrong share, bit flip all fail', async () => {
  const key = await importDataKey(unhex(V.k_data_hex));
  const ct = unhex(V.chunk0.cipher_hex);
  await assert.rejects(decryptChunk(key, V.share_id, 1, false, ct));
  await assert.rejects(decryptChunk(key, V.share_id, 0, true, ct));
  await assert.rejects(decryptChunk(key, b64uEncode(new Uint8Array(16)), 0, false, ct));
  const bad = ct.slice();
  bad[3] ^= 1;
  await assert.rejects(decryptChunk(key, V.share_id, 0, false, bad));
  await assert.doesNotReject(decryptChunk(key, V.share_id, 0, false, ct));
});

test('size arithmetic', () => {
  assert.equal(chunkCountFor(0), 1);
  assert.equal(chunkCountFor(1), 1);
  assert.equal(chunkCountFor(CHUNK), 1);
  assert.equal(chunkCountFor(CHUNK + 1), 2);
  assert.equal(cipherSize(0), 16);
  assert.equal(cipherSize(CHUNK), CCHUNK);
  assert.equal(cipherSize(CHUNK + 1), CCHUNK + 17);
  assert.equal(expectedCipherLen(0, 2, CHUNK + 1), CCHUNK);
  assert.equal(expectedCipherLen(1, 2, CHUNK + 1), 17);
  assert.equal(expectedCipherLen(0, 1, 0), 16);
});

test('metadata vector and round trip', async () => {
  const meta = buildMeta({ name: 'report.pdf', size: 15, mime: 'application/pdf' });
  assert.equal(JSON.stringify(meta), V.meta_json);
  const blob = await encryptMeta(unhex(V.k_meta_hex), V.share_id, meta, unhex(V.meta_iv_hex));
  assert.equal(hex(blob), V.enc_meta_hex);
  assert.deepEqual(await decryptMeta(unhex(V.k_meta_hex), V.share_id, blob), meta);
  await assert.rejects(decryptMeta(unhex(V.k_meta_hex), b64uEncode(new Uint8Array(16).fill(9)), blob));
});

test('metadata with inconsistent chunk_count is rejected', async () => {
  const bad = { ...buildMeta({ name: 'a', size: 5, mime: '' }), chunk_count: 3 };
  const blob = await encryptMeta(unhex(V.k_meta_hex), V.share_id, bad);
  await assert.rejects(decryptMeta(unhex(V.k_meta_hex), V.share_id, blob), /invalid metadata/);
});

test('ECIES name sealing round trip, layout and tamper detection', async () => {
  const { pub, pkcs8 } = await generateAdminKeyPair();
  assert.equal(pub.length, 65);
  const sealed = await sealName(pub, '报告 final.pdf');
  assert.equal(sealed[0], 4);
  assert.equal(sealed.length, 65 + 12 + new TextEncoder().encode('报告 final.pdf').length + 16);
  assert.equal(await openName(pkcs8, sealed), '报告 final.pdf');
  const bad = sealed.slice();
  bad[bad.length - 1] ^= 1;
  await assert.rejects(openName(pkcs8, bad));
  const other = await generateAdminKeyPair();
  await assert.rejects(openName(other.pkcs8, sealed));
  const again = await sealName(pub, '报告 final.pdf');
  assert.notEqual(hex(again.subarray(0, 65)), hex(sealed.subarray(0, 65)), 'ephemeral key must be fresh');
});

test('private key wrap / unwrap', async () => {
  const { pkcs8 } = await generateAdminKeyPair();
  const wk = crypto.getRandomValues(new Uint8Array(32));
  const blob = await wrapPrivateKey(wk, pkcs8);
  assert.deepEqual(await unwrapPrivateKey(wk, blob), pkcs8);
  await assert.rejects(unwrapPrivateKey(crypto.getRandomValues(new Uint8Array(32)), blob));
});

test('wordlist is the EFF large list', () => {
  assert.equal(WORDS.length, 7776);
  assert.equal(new Set(WORDS).size, 7776);
  assert.equal(WORDS[0], 'abacus');
  assert.equal(WORDS[7775], 'zoom');
});

test('code normalisation', () => {
  assert.equal(normalizeCode('  River Amber_lunar-fox '), 'river-amber-lunar-fox');
  assert.equal(normalizeCode('ＲＩＶＥＲ amber'), 'river-amber');
});

test('rejection sampling is unbiased at the boundary and rejects values >= limit', () => {
  const n = 7776;
  const limit = Math.floor(2 ** 32 / n) * n;
  const feed = [limit, limit + 5, 2 ** 32 - 1, 7777];
  const rng = (a) => { a[0] = feed.shift(); return a; };
  assert.equal(randomIndex(n, rng), 7777 % n);
  assert.equal(feed.length, 0, 'three out-of-range draws must have been discarded');
  assert.throws(() => randomIndex(0));
});

test('passphrase shape and uniformity (chi-square sanity)', () => {
  assert.match(generatePassphrase(4), /^[a-z]+(-[a-z]+){3}$/);
  assert.equal(generatePassphrase(6).split('-').length, 6);
  assert.throws(() => generatePassphrase(5));
  const buckets = new Array(16).fill(0);
  const N = 32000;
  for (let i = 0; i < N; i++) buckets[randomIndex(16)]++;
  const exp = N / 16;
  const chi = buckets.reduce((s, o) => s + (o - exp) ** 2 / exp, 0);
  assert.ok(chi < 50, `chi-square ${chi}`);
});

test('base64url helpers', () => {
  const b = crypto.getRandomValues(new Uint8Array(33));
  assert.deepEqual(b64uDecode(b64uEncode(b)), b);
  assert.throws(() => b64uDecode('a+b'));
  assert.equal(b64uEncode(new Uint8Array(16)).length, 22);
});
