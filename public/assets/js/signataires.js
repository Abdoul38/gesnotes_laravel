// Signataires — fenêtre de modification unique, remplie selon la ligne choisie.
(function () {
  var modal = document.getElementById('modal-signataire');
  if (!modal) return;
  var form = modal.querySelector('form');
  document.querySelectorAll('[data-signataire]').forEach(function (bouton) {
    bouton.addEventListener('click', function () {
      var s = JSON.parse(bouton.getAttribute('data-signataire'));
      ['id', 'faculte_id', 'titre', 'nom', 'qualite'].forEach(function (k) {
        form.querySelector('[name="' + k + '"]').value = s[k] === null ? '' : s[k];
      });
      modal.querySelector('[data-titre-nom]').textContent = s.nom;
      window.openModal('modal-signataire');
    });
  });
})();
