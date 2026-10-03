// Annonces — repris du script en ligne de l'ancien annonces.php (bloqué par
// la CSP) : fenêtre de création / modification, champs filière / niveau.
(function () {
  var form = document.getElementById('form-annonce');
  if (!form) return;
  var $ = function (id) { return document.getElementById(id); };

  function basculerCible() {
    var v = $('f-cible').value;
    $('zone-filiere').style.display = v === 'filiere' ? '' : 'none';
    $('zone-niveau').style.display = v === 'niveau' ? '' : 'none';
  }
  $('f-cible').addEventListener('change', basculerCible);

  var nouvelle = document.querySelector('[data-annonce-nouvelle]');
  if (nouvelle) nouvelle.addEventListener('click', function () {
    form.reset();
    $('f-action').value = 'creer';
    $('f-id').value = '';
    $('modal-titre-annonce').textContent = '➕ Nouvelle annonce';
    $('f-debut').value = form.getAttribute('data-aujourdhui');
    basculerCible();
    window.openModal('modal-annonce');
  });

  document.querySelectorAll('[data-annonce]').forEach(function (btn) {
    btn.addEventListener('click', function () {
      var a = JSON.parse(btn.getAttribute('data-annonce'));
      $('f-action').value = 'modifier';
      $('f-id').value = a.id;
      $('modal-titre-annonce').textContent = "✏️ Modifier l'annonce";
      $('f-titre').value = a.titre;
      $('f-type').value = a.type;
      $('f-cible').value = a.cible;
      $('f-debut').value = a.date_debut || '';
      $('f-fin').value = a.date_fin || '';
      $('f-contenu').value = a.contenu;
      $('f-publiee').checked = a.publiee;
      $('f-epinglee').checked = a.epinglee;
      $('f-filiere').value = a.filiere_id || '';
      $('f-niveau').value = a.niveau_id || '';
      basculerCible();
      window.openModal('modal-annonce');
    });
  });
})();
