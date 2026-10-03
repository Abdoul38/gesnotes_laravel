// Impression des cartes étudiantes — remplace les scripts en ligne de
// l'ancien carte_etudiant.php : QR codes de vérification, boutons, logo de secours.
(function () {
  document.querySelectorAll('[data-qr]').forEach(function (el) {
    try {
      var qr = qrcode(0, 'M');
      qr.addData(el.getAttribute('data-qr'));
      qr.make();
      el.innerHTML = qr.createSvgTag({ cellSize: parseInt(el.getAttribute('data-qr-cellule'), 10) || 1, margin: 0 });
    } catch (e) {}
  });

  var imprimer = document.getElementById('btn-imprimer');
  if (imprimer) imprimer.addEventListener('click', function () { window.print(); });

  var retour = document.getElementById('btn-retour');
  if (retour) retour.addEventListener('click', function (e) {
    if (history.length > 1) { e.preventDefault(); history.back(); }
  });

  document.querySelectorAll('img[data-onerror-src]').forEach(function (img) {
    img.addEventListener('error', function () {
      if (!img.dataset.fb) { img.dataset.fb = '1'; img.src = img.getAttribute('data-onerror-src'); }
    });
  });
})();
