// zxcvbn is ~800 KB, so it is a separate, content-hashed file loaded only when the
// user opens the advanced "custom code" option. Its SRI hash is injected at build time.
/* global __ZXCVBN__ */
let pending = null;

export function loadZxcvbn() {
  if (window.zxcvbn) return Promise.resolve(window.zxcvbn);
  if (!pending) {
    pending = new Promise((resolve, reject) => {
      const s = document.createElement('script');
      s.src = __ZXCVBN__.src;
      s.integrity = __ZXCVBN__.integrity;
      s.crossOrigin = 'anonymous';
      s.onload = () => resolve(window.zxcvbn);
      s.onerror = () => reject(new Error('zxcvbn 加载失败'));
      document.head.append(s);
    });
  }
  return pending;
}
