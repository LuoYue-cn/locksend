import { b64uEncode, randomBytes } from '../crypto/util.js';
import { safeFileName } from '../common/dom.js';

/**
 * Output sinks for decrypted data, tried in this order by download/main.js:
 *  1. FileSystemSink     – File System Access API (Chromium): writes straight to a file the user picked.
 *                          Not a browser download, so download managers cannot intercept it.
 *  2. ServiceWorkerSink  – streams through /dl/<key> as a normal download, constant memory.
 *  3. BlobSink           – keeps the file in memory (files are limited to 500 MB), then saves it.
 * A sink that cannot deliver throws SinkUnavailable so the caller can restart with the next one.
 */
export class SinkUnavailable extends Error {}

export class FileSystemSink {
  static supported() {
    return typeof window.showSaveFilePicker === 'function' && window.isSecureContext;
  }

  constructor(name) {
    this.name = safeFileName(name);
  }

  /** Must be the first await in the click handler (needs the user's activation). Rejects with AbortError if the user cancels. */
  async open() {
    const handle = await window.showSaveFilePicker({ suggestedName: this.name });
    this.writable = await handle.createWritable();
  }

  async write(bytes) {
    await this.writable.write(bytes);
  }

  async close() {
    await this.writable.close();
  }

  abort() {
    try {
      this.writable.abort();
    } catch {
      /* already closed */
    }
  }
}

export class ServiceWorkerSink {
  static async supported() {
    if (!('serviceWorker' in navigator) || !window.isSecureContext) return false;
    try {
      await navigator.serviceWorker.register('/sw.js', { scope: '/' });
      await navigator.serviceWorker.ready;
      if (!navigator.serviceWorker.controller) {
        await new Promise((resolve) => {
          navigator.serviceWorker.addEventListener('controllerchange', resolve, { once: true });
          setTimeout(resolve, 1500);
        });
      }
      return !!navigator.serviceWorker.controller;
    } catch {
      return false;
    }
  }

  constructor(name, size) {
    this.name = safeFileName(name);
    this.size = size;
    this.key = b64uEncode(randomBytes(18));
    this.waiters = [];
    this.credit = 0;
    this.cancelled = false;
    this.firstPull = new Promise((resolve) => {
      this.onFirstPull = resolve;
    });
  }

  /**
   * Hands the stream to the worker and starts the download. Resolves only once the browser has
   * actually begun reading it; otherwise (download manager took over, worker restarted, download
   * blocked) throws SinkUnavailable after a short timeout.
   */
  async open() {
    const ch = new MessageChannel();
    this.port = ch.port1;
    const ready = new Promise((resolve) => {
      this.port.onmessage = (m) => {
        if (m.data.type === 'ready') resolve();
        else if (m.data.type === 'pull') {
          this.onFirstPull();
          const w = this.waiters.shift();
          if (w) w();
          else this.credit++;
        } else if (m.data.type === 'cancelled') {
          this.cancelled = true;
          this.onFirstPull();
          for (const w of this.waiters.splice(0)) w();
        }
      };
    });
    const controller = navigator.serviceWorker.controller;
    if (!controller) throw new SinkUnavailable('no service worker');
    controller.postMessage({ type: 'start', key: this.key, name: this.name, size: this.size }, [ch.port2]);
    await this.within(ready, 4000);
    const frame = document.createElement('iframe');
    frame.hidden = true;
    frame.src = `/dl/${this.key}`;
    document.body.append(frame);
    this.frame = frame;
    await this.within(this.firstPull, 8000);
    if (this.cancelled) throw new SinkUnavailable('download cancelled');
  }

  within(promise, ms) {
    let timer;
    const timeout = new Promise((_, reject) => {
      timer = setTimeout(() => reject(new SinkUnavailable('download did not start')), ms);
    });
    return Promise.race([promise, timeout]).finally(() => clearTimeout(timer));
  }

  async write(bytes) {
    if (this.credit > 0) this.credit--;
    else await new Promise((r) => this.waiters.push(r));
    if (this.cancelled) throw new SinkUnavailable('download cancelled');
    const buf = bytes.buffer.slice(bytes.byteOffset, bytes.byteOffset + bytes.byteLength);
    this.port.postMessage({ type: 'chunk', buf }, [buf]);
  }

  async close() {
    this.port.postMessage({ type: 'end' });
    setTimeout(() => this.frame && this.frame.remove(), 60000);
  }

  abort() {
    try {
      this.port.postMessage({ type: 'abort' });
    } catch {
      /* port already closed */
    }
    if (this.frame) this.frame.remove();
  }
}

export class BlobSink {
  constructor(name) {
    this.name = safeFileName(name);
    this.parts = [];
  }

  async open() {}

  async write(bytes) {
    this.parts.push(bytes.slice());
  }

  async close() {
    const url = URL.createObjectURL(new Blob(this.parts, { type: 'application/octet-stream' }));
    const a = document.createElement('a');
    a.href = url;
    a.download = this.name;
    document.body.append(a);
    a.click();
    a.remove();
    setTimeout(() => URL.revokeObjectURL(url), 60000);
    this.parts = [];
  }

  abort() {
    this.parts = [];
  }
}
