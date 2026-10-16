// Must be evaluated BEFORE argon2-browser's dist/argon2.js: that file reads
// self.Module at load time. Embedding the WASM here (esbuild "binary" loader)
// puts it under the same SRI hash as the page bundle.
import wasmBytes from '../../node_modules/argon2-browser/dist/argon2.wasm';

if (typeof globalThis.self === 'undefined') globalThis.self = globalThis;

export const ready = new Promise((resolve) => {
  globalThis.self.Module = {
    wasmBinary: wasmBytes,
    postRun: [() => resolve(globalThis.self.Module)],
  };
});
