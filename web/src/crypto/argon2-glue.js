// Thin wrapper around the pinned argon2-browser WASM build (v1.18.0).
// `getModule` resolves to the initialised Emscripten module; the WASM bytes are
// supplied by the host (embedded in the bundle in the browser, read from disk
// in Node tests), so no network fetch is ever made.

const ARGON2ID = 2;

export function createArgon2id(getModule) {
  /**
   * @param {Uint8Array} pass
   * @param {Uint8Array} salt
   * @param {{m:number,t:number,p:number,len:number}} params  m in KiB
   * @returns {Promise<Uint8Array>}
   */
  return async function argon2id(pass, salt, params) {
    const M = await getModule();
    const alloc = (bytes) => M.allocate(new Uint8Array([...bytes, 0]), 'i8', M.ALLOC_NORMAL);
    const pwd = alloc(pass);
    const saltPtr = alloc(salt);
    const hash = M.allocate(new Array(params.len), 'i8', M.ALLOC_NORMAL);
    const encLen = M._argon2_encodedlen(params.t, params.m, params.p, salt.length, params.len, ARGON2ID);
    const encoded = M.allocate(new Array(encLen + 1), 'i8', M.ALLOC_NORMAL);
    try {
      const res = M._argon2_hash_ext(
        params.t, params.m, params.p,
        pwd, pass.length, saltPtr, salt.length,
        hash, params.len, encoded, encLen,
        ARGON2ID, 0, 0, 0, 0, 0x13,
      );
      if (res !== 0) {
        throw new Error('argon2 failed: ' + M.UTF8ToString(M._argon2_error_message(res)));
      }
      const out = new Uint8Array(params.len);
      for (let i = 0; i < params.len; i++) out[i] = M.HEAP8[hash + i] & 0xff;
      return out;
    } finally {
      M.HEAPU8.fill(0, pwd, pwd + pass.length);
      M._free(pwd);
      M._free(saltPtr);
      M._free(hash);
      M._free(encoded);
    }
  };
}
