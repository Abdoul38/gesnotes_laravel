// Pages imprimables autonomes (sans layout.js) : impression, fermeture,
// envoi automatique des filtres, images de secours (repris des scripts en
// ligne des anciennes pages PDF / imprimables).
document.addEventListener('click', function (e) {
  if (e.target.closest('[data-print]')) {
    document.body.style.zoom = '100%';
    setTimeout(function () { window.print(); }, 150);
  }
  if (e.target.closest('[data-window-close]')) window.close();
  if (e.target.closest('[data-history-back]')) { e.preventDefault(); history.back(); }
});
// QR codes de vérification (relevés…) : nécessite qrcode.js chargé avant
if (window.qrcode) {
  document.querySelectorAll('[data-qr]').forEach(function (el) {
    try {
      var qr = qrcode(0, 'M');
      qr.addData(el.getAttribute('data-qr'));
      qr.make();
      el.innerHTML = qr.createSvgTag({ cellSize: parseInt(el.getAttribute('data-qr-cellule'), 10) || 1, margin: 0 });
    } catch (err) {}
  });
}
document.addEventListener('change', function (e) {
  var el = e.target.closest('[data-autosubmit]');
  if (el && el.form) el.form.submit();
});
document.addEventListener('error', function (e) {
  var el = e.target;
  if (!el.hasAttribute) return;
  if (el.hasAttribute('data-onerror-hide')) el.style.display = 'none';
  if (el.hasAttribute('data-onerror-invisible')) el.style.visibility = 'hidden';
}, true);
