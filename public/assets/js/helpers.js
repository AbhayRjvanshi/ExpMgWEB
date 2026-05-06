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
  }

  if (method === 'POST') {
    const fd = new FormData();
    for (const [k, v] of Object.entries(data || {})) {
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
