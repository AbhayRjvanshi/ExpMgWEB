

// M2/L-4: CSP-safe delegated modal close (replaces inline onclick).
document.addEventListener('click', function (e) {
  var btn = e.target.closest('[data-close-modal]');
  if (!btn) return;
  var modal = document.getElementById(btn.dataset.closeModal);
  if (modal) modal.classList.add('hidden');
});
