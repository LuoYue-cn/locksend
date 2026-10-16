import { createRequire } from 'node:module';
import { readFileSync } from 'node:fs';
import { fileURLToPath } from 'node:url';
import { dirname, join } from 'node:path';
import { createArgon2id } from '../src/crypto/argon2-glue.js';
import { setArgon2id } from '../src/crypto/kdf.js';

const here = dirname(fileURLToPath(import.meta.url));
const dist = join(here, '..', 'node_modules', 'argon2-browser', 'dist');

let ready;
export function initArgon2() {
  if (!ready) {
    const require = createRequire(import.meta.url);
    ready = new Promise((resolve) => {
      globalThis.self = { Module: { wasmBinary: readFileSync(join(dist, 'argon2.wasm')), postRun: [() => resolve(globalThis.self.Module)] } };
      require(join(dist, 'argon2.js'));
    });
    setArgon2id(createArgon2id(() => ready));
  }
  return ready;
}

export const loadVectors = () => JSON.parse(readFileSync(join(here, 'vectors.json'), 'utf8'));
