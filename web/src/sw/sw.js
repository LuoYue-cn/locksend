// Streaming-download helper. The page decrypts chunks and hands the plaintext to
// this worker over a MessageChannel; the worker exposes it as a download response
// at /dl/<token>. Nothing is cached and no network request is made from here.

const jobs = new Map();

self.addEventListener('install', () => self.skipWaiting());
self.addEventListener('activate', (e) => e.waitUntil(self.clients.claim()));

self.addEventListener('message', (e) => {
  const d = e.data;
  if (!d || d.type !== 'start' || !/^[A-Za-z0-9_-]{16,64}$/.test(d.key) || !e.ports[0]) return;
  const port = e.ports[0];
  const stream = new ReadableStream({
    start(controller) {
      port.onmessage = (m) => {
        if (m.data.type === 'chunk') controller.enqueue(new Uint8Array(m.data.buf));
        else if (m.data.type === 'end') {
          controller.close();
          jobs.delete(d.key);
        } else if (m.data.type === 'abort') {
          controller.error(new Error('aborted'));
          jobs.delete(d.key);
        }
      };
    },
    pull() {
      port.postMessage({ type: 'pull' });
    },
    cancel() {
      port.postMessage({ type: 'cancelled' });
      jobs.delete(d.key);
    },
  }, { highWaterMark: 0 }); // pull() runs only when the browser really reads, so the page can detect a hijacked/blocked download
  jobs.set(d.key, { stream, name: String(d.name), size: Number(d.size) });
  port.postMessage({ type: 'ready' });
});

function disposition(name) {
  const ascii = name.replace(/[^\x20-\x7e]|["\\;]/g, '_');
  return `attachment; filename="${ascii}"; filename*=UTF-8''${encodeURIComponent(name).replace(/['()*]/g, (c) => '%' + c.charCodeAt(0).toString(16).toUpperCase())}`;
}

self.addEventListener('fetch', (e) => {
  const url = new URL(e.request.url);
  const m = url.origin === self.location.origin && /^\/dl\/([A-Za-z0-9_-]{16,64})$/.exec(url.pathname);
  if (!m) return;
  const job = jobs.get(m[1]);
  if (!job) {
    e.respondWith(new Response('Not found', { status: 404 }));
    return;
  }
  const headers = new Headers({
    'Content-Type': 'application/octet-stream',
    'Content-Disposition': disposition(job.name),
    'X-Content-Type-Options': 'nosniff',
    'Cache-Control': 'no-store',
    'Content-Security-Policy': "default-src 'none'; sandbox",
  });
  if (Number.isSafeInteger(job.size) && job.size >= 0) headers.set('Content-Length', String(job.size));
  e.respondWith(new Response(job.stream, { headers }));
});
