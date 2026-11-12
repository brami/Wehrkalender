// Wehrkalender – kleine Hilfen ohne Framework (Kopieren, Passwort zeigen)
document.addEventListener('click', function (ev) {
  var copyBtn = ev.target.closest('[data-copy]');
  if (copyBtn) {
    var input = document.getElementById(copyBtn.getAttribute('data-copy'));
    if (!input) return;
    var text = input.value || input.textContent;
    var done = function () {
      var old = copyBtn.textContent;
      copyBtn.textContent = 'Kopiert ✓';
      setTimeout(function () { copyBtn.textContent = old; }, 1800);
    };
    if (navigator.clipboard && window.isSecureContext) {
      navigator.clipboard.writeText(text).then(done, function () { input.select(); document.execCommand('copy'); done(); });
    } else {
      input.select(); document.execCommand('copy'); done();
    }
    return;
  }
  var revealBtn = ev.target.closest('[data-reveal]');
  if (revealBtn) {
    var el = document.getElementById(revealBtn.getAttribute('data-reveal'));
    if (!el) return;
    var hidden = el.hasAttribute('hidden');
    if (hidden) { el.removeAttribute('hidden'); } else { el.setAttribute('hidden', ''); }
    var mask = document.getElementById(revealBtn.getAttribute('data-reveal') + '-mask');
    if (mask) { if (hidden) mask.setAttribute('hidden', ''); else mask.removeAttribute('hidden'); }
    revealBtn.textContent = hidden ? 'Passwort verbergen' : 'Passwort anzeigen';
  }
});
