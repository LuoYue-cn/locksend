export class ApiError extends Error {
  constructor(status, code, data) {
    super(code || `http_${status}`);
    this.status = status;
    this.code = code;
    this.data = data || {};
  }
}

/**
 * Same-origin JSON/binary fetch. Never sends referrers or cached copies.
 * `token` is an opaque bearer token; admin calls rely on the HttpOnly cookie.
 */
export async function api(path, { method = 'GET', json, body, token, headers = {}, raw = false, signal } = {}) {
  const h = { ...headers };
  let payload = body;
  if (json !== undefined) {
    h['Content-Type'] = 'application/json';
    payload = JSON.stringify(json);
  }
  if (token) h.Authorization = `Bearer ${token}`;
  if (method !== 'GET') h['X-LockSend'] = '1';
  const res = await fetch(path, {
    method,
    headers: h,
    body: payload,
    credentials: 'same-origin',
    cache: 'no-store',
    referrerPolicy: 'no-referrer',
    signal,
  });
  if (!res.ok) {
    let data = {};
    try {
      data = await res.json();
    } catch {
      /* non-JSON error */
    }
    throw new ApiError(res.status, data.error, data);
  }
  if (raw) return res;
  if (res.status === 204) return null;
  return res.json();
}

const sleep = (ms) => new Promise((r) => setTimeout(r, ms));

/** Retries network failures, 429 and 5xx with exponential backoff; 4xx fail immediately. */
export async function retry(fn, tries = 5, base = 600) {
  for (let i = 0; ; i++) {
    try {
      return await fn();
    } catch (e) {
      const transient = !(e instanceof ApiError) || e.status === 429 || e.status >= 500;
      if (!transient || i >= tries - 1) throw e;
      await sleep(base * 2 ** i);
    }
  }
}
