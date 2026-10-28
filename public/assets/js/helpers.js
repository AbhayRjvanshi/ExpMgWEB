// ============================================================
// helpers.js — Shared utilities (loaded before all other JS)
// ============================================================
const API = '../api';

function $(sel)  { return document.querySelector(sel); }
function $$(sel) { return document.querySelectorAll(sel); }
function show(el) { el && el.classList.remove('hidden'); }
function hide(el) { el && el.classList.add('hidden'); }

function escapeHTML(str) {
  const d = document.createElement('div');
  d.textContent = str;
  return d.innerHTML;
}

function getCsrfToken() {
  const meta = document.querySelector('meta[name="csrf-token"]');
  if (meta && meta.content) {
    return meta.content;
  }

  const match = document.cookie.match(/(?:^|; )csrf_token=([^;]+)/);
  return match ? decodeURIComponent(match[1]) : '';
}

function isMutatingMethod(method) {
  return ['POST', 'PUT', 'PATCH', 'DELETE'].includes(String(method || '').toUpperCase());
}

const CSRF_TOKEN = getCsrfToken();

const EXPMG_COOLDOWN_KEY = 'expmg_cooldown_expires';
const EXPMG_ERRORS_KEY = 'expmg_request_errors';
const EXPMG_DEFAULT_RETRY_SECONDS = 5;

const expmgRequestErrors = new Map();
const expmgRetryActions = new Map();
const expmgInflightGets = new Map();
let expmgMissingCsrfWarningLogged = false;

let expmgCooldownTimerId = null;
let expmgCooldownUiTimerId = null;

function readCooldownExpiresAt() {
  const raw = sessionStorage.getItem(EXPMG_COOLDOWN_KEY);
  if (!raw) return 0;
  const parsed = Number(raw);
  if (!Number.isFinite(parsed) || parsed <= 0) {
    sessionStorage.removeItem(EXPMG_COOLDOWN_KEY);
    return 0;
  }
  return parsed;
}

function getRateLimitRemainingSeconds() {
  const expiresAt = readCooldownExpiresAt();
  if (!expiresAt) return 0;
  const remainingMs = expiresAt - Date.now();
  if (remainingMs <= 0) {
    sessionStorage.removeItem(EXPMG_COOLDOWN_KEY);
    return 0;
  }
  return Math.ceil(remainingMs / 1000);
}

function persistRequestErrors() {
  const entries = [];
  expmgRequestErrors.forEach((value, scope) => {
    entries.push([scope, value]);
  });
  try {
    sessionStorage.setItem(EXPMG_ERRORS_KEY, JSON.stringify(entries));
  } catch (_) {
    // Ignore storage errors to keep runtime resilient.
  }
}

function restoreRequestErrors() {
  const raw = sessionStorage.getItem(EXPMG_ERRORS_KEY);
  if (!raw) return;
  try {
    const parsed = JSON.parse(raw);
    if (!Array.isArray(parsed)) return;
    parsed.forEach((entry) => {
      if (!Array.isArray(entry) || entry.length !== 2) return;
      const scope = entry[0];
      const payload = entry[1];
      if (typeof scope === 'string' && payload && typeof payload === 'object') {
        expmgRequestErrors.set(scope, payload);
      }
    });
  } catch (_) {
    sessionStorage.removeItem(EXPMG_ERRORS_KEY);
  }
}

function clearCooldown() {
  sessionStorage.removeItem(EXPMG_COOLDOWN_KEY);
  if (expmgCooldownTimerId) {
    clearTimeout(expmgCooldownTimerId);
    expmgCooldownTimerId = null;
  }
  if (expmgCooldownUiTimerId) {
    clearInterval(expmgCooldownUiTimerId);
    expmgCooldownUiTimerId = null;
  }
}

function getCooldownBanner() {
  let banner = document.getElementById('expmgCooldownBanner');
  if (banner) return banner;

  banner = document.createElement('div');
  banner.id = 'expmgCooldownBanner';
  banner.className = 'expmg-cooldown-banner hidden';
  banner.innerHTML = `
    <div class="expmg-cooldown-banner__body">
      <strong>Too many requests.</strong>
      <span class="expmg-cooldown-banner__message">Please wait before retrying.</span>
    </div>
    <div class="expmg-cooldown-banner__actions">
      <span class="expmg-cooldown-banner__timer">0s</span>
      <button type="button" class="expmg-cooldown-banner__dismiss">Dismiss</button>
    </div>
  `;

  const dismissBtn = banner.querySelector('.expmg-cooldown-banner__dismiss');
  dismissBtn.addEventListener('click', () => {
    banner.classList.add('hidden');
  });

  const mount = () => {
    if (document.body && !document.getElementById(banner.id)) {
      document.body.appendChild(banner);
    }
  };

  if (document.body) {
    mount();
  } else {
    document.addEventListener('DOMContentLoaded', mount, { once: true });
  }

  return banner;
}

function updateCooldownBanner(retrySeconds, message) {
  const banner = getCooldownBanner();
  const seconds = Number.isFinite(retrySeconds) && retrySeconds > 0 ? Math.ceil(retrySeconds) : getRateLimitRemainingSeconds();
  const remaining = seconds > 0 ? seconds : 0;
  const text = typeof message === 'string' && message.trim() !== ''
    ? message.trim()
    : `Please wait ${remaining}s before retrying.`;

  banner.querySelector('.expmg-cooldown-banner__message').textContent = text;
  banner.querySelector('.expmg-cooldown-banner__timer').textContent = remaining > 0 ? `${remaining}s` : '0s';
  banner.classList.remove('hidden');

  if (expmgCooldownUiTimerId) {
    clearInterval(expmgCooldownUiTimerId);
  }

  expmgCooldownUiTimerId = setInterval(() => {
    const left = getRateLimitRemainingSeconds();
    const timerEl = banner.querySelector('.expmg-cooldown-banner__timer');
    if (timerEl) timerEl.textContent = `${left}s`;
    if (left <= 0) {
      banner.classList.add('hidden');
      clearInterval(expmgCooldownUiTimerId);
      expmgCooldownUiTimerId = null;
    }
  }, 1000);
}

function scheduleCooldownEnd(expiresAt) {
  if (expmgCooldownTimerId) clearTimeout(expmgCooldownTimerId);
  const delayMs = Math.max(0, expiresAt - Date.now());
  expmgCooldownTimerId = setTimeout(() => {
    clearCooldown();
    window.dispatchEvent(new CustomEvent('expmg:cooldown-end'));
  }, delayMs);
}

function startCooldown(retrySeconds) {
  // Do not reset an already-active cooldown window.
  if (getRateLimitRemainingSeconds() > 0) return;

  const safeSeconds = Number.isFinite(retrySeconds) && retrySeconds > 0
    ? Math.ceil(retrySeconds)
    : EXPMG_DEFAULT_RETRY_SECONDS;
  const expiresAt = Date.now() + safeSeconds * 1000;
  sessionStorage.setItem(EXPMG_COOLDOWN_KEY, String(expiresAt));
  scheduleCooldownEnd(expiresAt);
  updateCooldownBanner(safeSeconds);
  window.dispatchEvent(new CustomEvent('expmg:cooldown-start', {
    detail: { retry_after: safeSeconds, expires_at: expiresAt }
  }));
}

function parseRetryAfterSeconds(res, body) {
  const headerValue = res.headers.get('Retry-After');
  if (headerValue) {
    const asInt = parseInt(headerValue, 10);
    if (Number.isFinite(asInt) && asInt > 0) return asInt;
  }

  if (body && Number.isFinite(Number(body.retry_after)) && Number(body.retry_after) > 0) {
    return Math.ceil(Number(body.retry_after));
  }

  return EXPMG_DEFAULT_RETRY_SECONDS;
}

function setRequestError(result, opts) {
  const options = opts || {};
  const scope = typeof options.scope === 'string' ? options.scope : 'global';
  const payload = result && typeof result === 'object'
    ? result
    : { ok: false, _status: 0, error: 'Request failed.' };

  expmgRequestErrors.set(scope, payload);
  if (typeof options.retryAction === 'function') {
    expmgRetryActions.set(scope, options.retryAction);
  } else {
    expmgRetryActions.delete(scope);
  }
  persistRequestErrors();
}

function clearRequestError(scope) {
  const targetScope = typeof scope === 'string' ? scope : 'global';
  expmgRequestErrors.delete(targetScope);
  expmgRetryActions.delete(targetScope);
  persistRequestErrors();
}

function getRequestErrorMessage(res, fallback) {
  const defaultMsg = fallback || 'Something went wrong. Please try again.';
  if (!res || typeof res !== 'object') return defaultMsg;

  const remaining = getRateLimitRemainingSeconds();
  if ((res._status === 429 || res.status === 429) && remaining > 0) {
    return `Too many requests. Please wait ${remaining}s before retrying.`;
  }

  if (typeof res.error === 'string' && res.error.trim() !== '') {
    return res.error;
  }

  return defaultMsg;
}

window.ExpMgStatus = {
  getRateLimitRemainingSeconds,
  setRequestError,
  clearRequestError,
  getRequestErrorMessage,
  showCooldown(retrySeconds, message) {
    startCooldown(retrySeconds);
    if (typeof message === 'string' && message.trim() !== '') {
      updateCooldownBanner(retrySeconds, message);
    }
  },
  getRequestError(scope) {
    const targetScope = typeof scope === 'string' ? scope : 'global';
    return expmgRequestErrors.get(targetScope) || null;
  },
  getRetryAction(scope) {
    const targetScope = typeof scope === 'string' ? scope : 'global';
    return expmgRetryActions.get(targetScope) || null;
  }
};

async function parseResponseBody(res) {
  const text = await res.text();
  if (!text) return {};
  try {
    return JSON.parse(text);
  } catch (_) {
    return { ok: false, error: 'Invalid server response.' };
  }
}

function normalizeErrorResult(body, status, retryAfter, limitedBy) {
  const base = body && typeof body === 'object' ? body : {};
  if (!Object.prototype.hasOwnProperty.call(base, 'ok')) {
    base.ok = false;
  }
  base._status = status;
  if (retryAfter > 0) base._retry_after = retryAfter;
  if (limitedBy) base.limited_by = limitedBy;
  if (typeof base.error !== 'string' || base.error.trim() === '') {
    if (status === 429 && retryAfter > 0) {
      base.error = `Too many requests. Please wait ${retryAfter}s before retrying.`;
    } else {
      base.error = 'Request failed.';
    }
  }
  return base;
}

// ── Per-intent idempotency keys ──────────────────────────────────────────
// A double-click (or Enter pressed twice) produces two identical requests.
// Generating a fresh key per call would defeat server-side dedup, so the key
// is cached per method + URL + canonical payload for 3 seconds and reused.
const EXPMG_IDEMPOTENCY_TTL_MS = 3000;
const expmgIdempotencyCache = new Map();

function canonicalIdempotencyPayload(data) {
  const entries = [];
  for (const [k, v] of Object.entries(data || {})) {
    if (v === null || v === undefined) continue;
    if (k === '_idempotencyKey') continue;
    entries.push([k, String(v)]);
  }
  entries.sort((a, b) => (a[0] < b[0] ? -1 : a[0] > b[0] ? 1 : 0));
  return entries.map(([k, v]) => `${encodeURIComponent(k)}=${encodeURIComponent(v)}`).join('&');
}

function getIdempotencyKey(method, url, data) {
  if (data && data._idempotencyKey) return String(data._idempotencyKey);

  const now = Date.now();
  for (const [k, entry] of expmgIdempotencyCache) {
    if (entry.expires <= now) expmgIdempotencyCache.delete(k);
  }

  const cacheKey = `${String(method).toUpperCase()}:${url}:${canonicalIdempotencyPayload(data)}`;
  const hit = expmgIdempotencyCache.get(cacheKey);
  if (hit && hit.expires > now) return hit.key;

  const key = `${now.toString(36)}-${Math.random().toString(36).slice(2, 10)}`;
  expmgIdempotencyCache.set(cacheKey, { key, expires: now + EXPMG_IDEMPOTENCY_TTL_MS });
  return key;
}

async function requestJson(method, url, data) {
  const remaining = getRateLimitRemainingSeconds();
  if (remaining > 0) {
    return normalizeErrorResult({
      ok: false,
      error: `Too many requests. Please wait ${remaining}s before retrying.`
    }, 429, remaining, 'client_cooldown');
  }

  const init = { method, headers: {}, credentials: 'include' };
  if (isMutatingMethod(method)) {
    const csrfToken = CSRF_TOKEN || getCsrfToken();
    if (csrfToken) {
      init.headers['X-CSRF-Token'] = csrfToken;
    } else if (!expmgMissingCsrfWarningLogged && typeof console !== 'undefined' && console.warn) {
      expmgMissingCsrfWarningLogged = true;
      console.warn('CSRF token missing for mutating request:', method, url);
    }
    init.headers['Idempotency-Key'] = getIdempotencyKey(method, url, data);
  }

  if (method === 'POST') {
    const fd = new FormData();
    for (const [k, v] of Object.entries(data || {})) {
      if (k === '_idempotencyKey') continue;
      if (v !== null && v !== undefined) fd.append(k, v);
    }
    init.body = fd;
  }

  try {
    const res = await fetch(url, init);
    const body = await parseResponseBody(res);

    if (res.status === 429) {
      const retryAfter = parseRetryAfterSeconds(res, body);
      startCooldown(retryAfter);
      return normalizeErrorResult(body, 429, retryAfter, body.limited_by || body.limitedBy || 'server');
    }

    if (!res.ok) {
      return normalizeErrorResult(body, res.status, 0, null);
    }

    if (!body || typeof body !== 'object') {
      return { ok: true, _status: res.status };
    }

    if (!Object.prototype.hasOwnProperty.call(body, 'ok')) {
      body.ok = true;
    }

    return body;
  } catch (_) {
    return normalizeErrorResult({ ok: false, error: 'Connection issue. Retry.' }, 0, 0, null);
  }
}

async function post(url, data) {
  return requestJson('POST', url, data || {});
}

async function get(url) {
  const key = `GET:${url}`;
  if (expmgInflightGets.has(key)) {
    return expmgInflightGets.get(key);
  }

  const promise = requestJson('GET', url)
    .finally(() => {
      expmgInflightGets.delete(key);
    });

  expmgInflightGets.set(key, promise);
  return promise;
}

restoreRequestErrors();
function restoreCooldownBanner() {
  const remaining = getRateLimitRemainingSeconds();
  if (remaining <= 0) return;

  const expiresAt = readCooldownExpiresAt();
  getCooldownBanner();
  if (expiresAt > Date.now()) {
    scheduleCooldownEnd(expiresAt);
  }
  updateCooldownBanner(remaining);
}

restoreCooldownBanner();

window.addEventListener('pageshow', restoreCooldownBanner);
document.addEventListener('DOMContentLoaded', restoreCooldownBanner, { once: true });
setTimeout(restoreCooldownBanner, 0);

// Global cooldown event listeners: auto-show banner on any 429 (works on all pages)
window.addEventListener('expmg:cooldown-start', (evt) => {
  const detail = evt.detail || {};
  const retryAfter = detail.retry_after || EXPMG_DEFAULT_RETRY_SECONDS;
  updateCooldownBanner(retryAfter);
});

window.addEventListener('expmg:cooldown-end', () => {
  const banner = document.getElementById('expmgCooldownBanner');
  if (banner) banner.classList.add('hidden');
});

// ============================================================
// showConfirmModal — reusable destructive-action confirmation.
// Backed by the global .modal-overlay styles (z-index 5000).
// Returns a Promise<boolean>: true = confirmed, false = dismissed
// (Cancel click, overlay click, or Escape). Drop-in replacement for
// native confirm() in delete flows across the app.
// ============================================================
function showConfirmModal(opts) {
  const options = Object.assign(
    { title: 'Are you sure?', message: '', confirmLabel: 'Confirm', cancelLabel: 'Cancel', danger: false },
    opts || {}
  );
  return new Promise((resolve) => {
    let overlay = document.getElementById('expmgConfirmModal');
    if (overlay) overlay.remove();

    overlay = document.createElement('div');
    overlay.id = 'expmgConfirmModal';
    overlay.className = 'modal-overlay';
    overlay.innerHTML =
      '<div style="width:100%;max-width:380px;padding:1.5rem;position:relative;background:var(--bg-surface);color:var(--text-primary);border:1px solid var(--border-soft);border-radius:16px;box-shadow:var(--shadow-popover);">' +
        '<h3 style="font-size:1.05rem;font-weight:700;margin-bottom:0.5rem;color:var(--text-primary);">' + escapeHTML(options.title) + '</h3>' +
        '<p style="font-size:0.88rem;color:var(--text-secondary);margin-bottom:1.25rem;">' + escapeHTML(options.message) + '</p>' +
        '<div style="display:flex;gap:0.5rem;">' +
          '<button type="button" data-expmg-confirm="0" style="flex:1;padding:0.55rem 1rem;border:none;border-radius:10px;background:var(--bg-subtle);color:var(--text-primary);font-weight:600;font-size:0.88rem;cursor:pointer;">' + escapeHTML(options.cancelLabel) + '</button>' +
          '<button type="button" data-expmg-confirm="1" style="flex:1;padding:0.55rem 1rem;border:none;border-radius:10px;' + (options.danger ? 'background:var(--danger-600);' : 'background:var(--accent-primary);') + 'color:#ffffff;font-weight:600;font-size:0.88rem;cursor:pointer;">' + escapeHTML(options.confirmLabel) + '</button>' +
        '</div>' +
      '</div>';
    document.body.appendChild(overlay);

    const done = (result) => {
      overlay.remove();
      document.removeEventListener('keydown', onKey);
      resolve(result);
    };
    const onKey = (ev) => {
      if (ev.key !== 'Escape') return;
      // Tier 6 dialog: consume the Escape so lower-tier modal handlers
      // (View all, edit forms) never see this keystroke.
      ev.preventDefault();
      ev.stopImmediatePropagation();
      done(false);
    };
    overlay.addEventListener('click', (ev) => { if (ev.target === overlay) done(false); });
    overlay.querySelector('[data-expmg-confirm="0"]').addEventListener('click', () => done(false));
    overlay.querySelector('[data-expmg-confirm="1"]').addEventListener('click', () => done(true));
    document.addEventListener('keydown', onKey);
    const confirmBtn = overlay.querySelector('[data-expmg-confirm="1"]');
    if (confirmBtn) confirmBtn.focus();
  });
}

// ============================================================
// ExpMgTheme — palette & light/dark mode engine.
// An inline bootstrap script at the top of <body> (index, login,
// signup, splash) applies persisted preferences before first paint;
// these helpers mutate and persist them at runtime.
// localStorage keys: expmg_palette (default 'green'),
// expmg_theme_mode ('light' | 'dark', default 'light').
// Dispatches 'expmg:theme-change' on document so charts and other
// theme-aware components can re-render immediately.
// ============================================================
const ExpMgTheme = {
  PALETTES: ['green', 'indigo', 'sand', 'cobalt'],
  getPalette() {
    const p = document.body.getAttribute('data-palette');
    return this.PALETTES.indexOf(p) !== -1 ? p : 'green';
  },
  isDark() {
    return document.body.classList.contains('theme-dark');
  },
  setPalette(key, persist) {
    if (this.PALETTES.indexOf(key) === -1) return;
    if (key === 'green') document.body.removeAttribute('data-palette');
    else document.body.setAttribute('data-palette', key);
    try { if (persist !== false) localStorage.setItem('expmg_palette', key); } catch (_) {}
    this._emit();
  },
  setMode(mode, persist) {
    const dark = mode === 'dark';
    document.body.classList.toggle('theme-dark', dark);
    try { if (persist !== false) localStorage.setItem('expmg_theme_mode', dark ? 'dark' : 'light'); } catch (_) {}
    this._emit();
  },
  toggleMode() {
    this.setMode(this.isDark() ? 'light' : 'dark');
  },
  cssVar(name) {
    return getComputedStyle(document.body).getPropertyValue(name).trim();
  },
  _emit() {
    try {
      document.dispatchEvent(new CustomEvent('expmg:theme-change', {
        detail: { palette: this.getPalette(), mode: this.isDark() ? 'dark' : 'light' }
      }));
    } catch (_) {}
  }
};
window.ExpMgTheme = ExpMgTheme;

// ============================================================
// formatDateDMY — converts a MySQL DATE ("YYYY-MM-DD") or DATETIME
// ("YYYY-MM-DD HH:MM:SS") into "DD-MM-YYYY" without timezone
// rollover bugs (pure string manipulation, no Date() parsing).
// ============================================================
function formatDateDMY(dateStr) {
  if (!dateStr || typeof dateStr !== 'string') return 'Date unrecorded';
  const cleanDate = dateStr.trim().split(/[ T]/)[0];
  if (cleanDate === '1970-01-01') return 'Date unrecorded';
  const parts = cleanDate.split('-');
  if (parts.length === 3 && parts[0].length === 4) {
    const [yyyy, mm, dd] = parts;
    return `${dd.padStart(2, '0')}-${mm.padStart(2, '0')}-${yyyy}`;
  }
  return cleanDate;
}

// ============================================================
// viewAllBtnHtml — shared "View all ↓" trigger button markup.
// Rendered only when a collection exceeds the 5-item inline cap;
// the count shown is the TOTAL number of entries (e.g. "View all (12)").
// ============================================================
function viewAllBtnHtml(count) {
  const label = (typeof count === 'number' && count > 0) ? 'View all (' + count + ')' : 'View all';
  return '<button type="button" class="btn-view-all">' +
    '<span>' + escapeHTML(label) + '</span>' +
    '<svg width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><polyline points="6 9 12 15 18 9"></polyline></svg>' +
  '</button>';
}

// ============================================================
// openViewAllModal — shared centered, scrollable pop-up modal for
// the "View all ↓" flows. Sits on the global .modal-overlay styles
// (z-index 5000, blurred backdrop). Locks body scroll while open;
// dismisses via the ✕ button, backdrop click, or Escape. Returns a
// handle: { close(), body, setBody(html), isClosed() } so callers
// can live-refresh the body after mutations and bind row events.
// ============================================================
function openViewAllModal(opts) {
  const options = Object.assign({ title: '', subtitle: '', badge: null, bodyHtml: '' }, opts || {});

  let overlay = document.getElementById('expmgViewAllModal');
  if (overlay) overlay.remove();

  overlay = document.createElement('div');
  overlay.id = 'expmgViewAllModal';
  overlay.className = 'modal-overlay';
  overlay.innerHTML =
    '<div class="view-all-modal-card" role="dialog" aria-modal="true">' +
      '<div class="view-all-modal-header">' +
        '<div class="view-all-modal-heading">' +
          '<h3 class="view-all-modal-title">' + escapeHTML(options.title || '') +
            (options.badge
              ? ' <span class="exp-badge ' + (options.badge.className || 'badge-personal') + '">' + escapeHTML(options.badge.label || '') + '</span>'
              : '') +
          '</h3>' +
          (options.subtitle
            ? '<div class="view-all-modal-subtitle">' + escapeHTML(options.subtitle) + '</div>'
            : '') +
        '</div>' +
        '<button type="button" class="view-all-modal-close" aria-label="Close">&#10005;</button>' +
      '</div>' +
      '<div class="view-all-modal-body"></div>' +
    '</div>';
  document.body.appendChild(overlay);

  const bodyEl = overlay.querySelector('.view-all-modal-body');
  bodyEl.innerHTML = options.bodyHtml || '';

  let closed = false;
  const prevOverflow = document.body.style.overflow;
  document.body.style.overflow = 'hidden';

  const close = () => {
    if (closed) return;
    closed = true;
    document.body.style.overflow = prevOverflow || '';
    overlay.remove();
    document.removeEventListener('keydown', onKey);
    if (typeof options.onClose === 'function') options.onClose();
  };
  const onKey = (ev) => {
    if (ev.key !== 'Escape') return;
    // Escape peels one layer at a time: when a higher-layered modal is
    // open above this overlay (e.g. the group paid-by confirm popup at
    // z-index 5010), let Escape target that one instead of closing this.
    const myZ = parseInt(getComputedStyle(overlay).zIndex, 10) || 5000;
    const stackedAbove = Array.from(document.querySelectorAll('.modal-overlay')).some(el => {
      if (el === overlay || el.classList.contains('hidden')) return false;
      return (parseInt(getComputedStyle(el).zIndex, 10) || 5000) > myZ;
    });
    if (stackedAbove) return;
    // No higher-tier modal is open — this overlay is the top layer. Consume
    // the Escape so lower-tier / page-load handlers never see it.
    ev.preventDefault();
    ev.stopImmediatePropagation();
    close();
  };
  overlay.addEventListener('click', (ev) => { if (ev.target === overlay) close(); });
  overlay.querySelector('.view-all-modal-close').addEventListener('click', close);
  document.addEventListener('keydown', onKey);

  return {
    close,
    overlay,
    body: bodyEl,
    setBody(html) { bodyEl.innerHTML = html; },
    isClosed() { return closed; }
  };
}
