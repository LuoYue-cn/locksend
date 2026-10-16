import { WORDS } from './wordlist-eff.js';

/** trim → NFKC → lowercase → whitespace/underscore to '-'  (PROTOCOL.md §6) */
export function normalizeCode(code) {
  return code
    .trim()
    .normalize('NFKC')
    .toLowerCase()
    .replace(/[\s_]+/g, '-');
}

/** Unbiased integer in [0, n) by rejection sampling over 32-bit values. */
export function randomIndex(n, rng = (a) => crypto.getRandomValues(a)) {
  if (!Number.isInteger(n) || n <= 0 || n > 0x100000000) throw new RangeError('bad range');
  const limit = Math.floor(0x100000000 / n) * n;
  const buf = new Uint32Array(1);
  for (;;) {
    rng(buf);
    if (buf[0] < limit) return buf[0] % n;
  }
}

export function generatePassphrase(words = 4, rng) {
  if (words !== 4 && words !== 6) throw new RangeError('words must be 4 or 6');
  const out = [];
  for (let i = 0; i < words; i++) out.push(WORDS[randomIndex(WORDS.length, rng)]);
  return out.join('-');
}

/** Minimum bar for a user-chosen code: >= 12 chars. Strength (zxcvbn >= 4) is checked by the caller. */
export function customCodeLengthOk(code) {
  return normalizeCode(code).length >= 12;
}
