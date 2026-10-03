// Relances — repris du script en ligne de l'ancien relances.php : case
// « tout sélectionner » et confirmation de la relance groupée.
(function () {
  var form = document.getElementById('form-relances');
  if (!form) return;
  var tout = document.getElementById('chk-tout');
  if (tout) tout.addEventListener('change', function () {
    form.querySelectorAll('.chk-relance').forEach(function (c) { if (!c.disabled) c.checked = tout.checked; });
  });
  form.addEventListener('submit', function (e) {
    var bouton = e.submitter;
    if (!bouton || bouton.name !== 'mode') return;   // relance d'une seule ligne : pas de confirmation
    var n = form.querySelectorAll('.chk-relance:checked').length;
    if (n === 0) { e.preventDefault(); alert('Sélectionnez au moins un étudiant.'); return; }
    if (!confirm('Envoyer une relance à ' + n + ' étudiant(s) ?')) e.preventDefault();
  });
})();
