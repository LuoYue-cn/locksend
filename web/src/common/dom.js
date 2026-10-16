export const $ = (id) => document.getElementById(id);

export function show(el, on = true) {
  el.hidden = !on;
}

/** All dynamic text goes through textContent: file names are attacker-controlled. */
export function setText(el, text) {
  el.textContent = text;
}

export function fmtBytes(n) {
  if (n < 1024) return `${n} B`;
  const units = ['KiB', 'MiB', 'GiB', 'TiB', 'PiB'];
  let v = n / 1024;
  let i = 0;
  while (v >= 1024 && i < units.length - 1) {
    v /= 1024;
    i++;
  }
  return `${v.toFixed(v >= 100 ? 0 : 1)} ${units[i]}`;
}

export function fmtDate(sec) {
  return new Date(sec * 1000).toLocaleString();
}

export const nextFrame = () => new Promise((r) => requestAnimationFrame(() => setTimeout(r, 0)));

export async function copyText(text) {
  try {
    await navigator.clipboard.writeText(text);
    return true;
  } catch {
    return false;
  }
}

/** Removes path separators and control characters from a name used as a download file name. */
export function safeFileName(name) {
  const cleaned = name.replace(/[\u0000-\u001f\u007f/\\:*?"<>|]/g, '_').replace(/^\.+/, '_').trim();
  return cleaned === '' ? 'download' : cleaned.slice(0, 200);
}
