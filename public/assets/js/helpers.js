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

async function post(url, data) {
  const fd = new FormData();
  for (const [k, v] of Object.entries(data)) {
    if (v !== null && v !== undefined) fd.append(k, v);
  }
  const res = await fetch(url, { method: 'POST', body: fd });
  return res.json();
}

async function get(url) {
  const res = await fetch(url);
  return res.json();
}
