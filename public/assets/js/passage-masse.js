// Passage en masse : sélection des étudiants et contrôle avant envoi
// (repris du script de l'ancien passage_niveau.php).
(function () {
  var form = document.getElementById('frmPassage');
  if (!form) return;
  var cases = function () { return form.querySelectorAll('.chk-et'); };
  var tout = document.getElementById('chkAll');

  function compter() {
    var n = form.querySelectorAll('.chk-et:checked').length;
    document.getElementById('nbSelectionnes').textContent = n + ' sélectionné(s)';
    tout.checked = n === cases().length;
  }

  tout.addEventListener('change', function () {
    cases().forEach(function (c) { c.checked = tout.checked; });
    compter();
  });
  form.querySelectorAll('[data-selection]').forEach(function (b) {
    b.addEventListener('click', function () {
      var type = b.getAttribute('data-selection');
      cases().forEach(function (c) { c.checked = c.closest('tr').dataset.type === type; });
      compter();
    });
  });
  form.addEventListener('change', function (e) { if (e.target.classList.contains('chk-et')) compter(); });

  form.addEventListener('submit', function (e) {
    var n = form.querySelectorAll('.chk-et:checked').length;
    if (!n) { alert('Sélectionnez au moins un étudiant.'); e.preventDefault(); return; }
    if (!form.querySelector('[name=nouvel_annee_id]').value) { alert('Sélectionnez la nouvelle année académique.'); e.preventDefault(); return; }
    if (!confirm('Enregistrer les décisions de passage pour ' + n + ' étudiant(s) ? (Aucune inscription ne sera créée)')) e.preventDefault();
  });
  compter();
})();
