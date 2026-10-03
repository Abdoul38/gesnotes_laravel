// Emploi du temps — fenêtre d'ajout / de modification d'une séance.
(function () {
  var form = document.getElementById('form-edt');
  if (!form) return;
  var suppr = document.getElementById('form-edt-suppr');
  var titre = document.getElementById('edt-titre');
  var champ = function (n) { return form.querySelector('[name="' + n + '"]'); };
  var ouvrir = function (s) {
    var valeurs = s || { id: '', jour: 'Lundi', heure_debut: '08:00', heure_fin: '10:00', matiere_id: '', enseignant_id: '', salle_id: '', type_seance: 'cours', couleur: '#1A3A5C' };
    Object.keys(valeurs).forEach(function (k) { var el = champ(k); if (el) el.value = valeurs[k] === null ? '' : valeurs[k]; });
    titre.textContent = s ? '✏️ Modifier la séance' : '➕ Ajouter une séance';
    suppr.style.display = s ? '' : 'none';
    suppr.querySelector('[name="id"]').value = s ? s.id : '';
    form.dispatchEvent(new Event('edt:charge'));
    window.openModal('modal-edt');
  };
  var bouton = document.querySelector('[data-edt-ajouter]');
  if (bouton) bouton.addEventListener('click', function () { ouvrir(null); });
  document.querySelectorAll('[data-edt-seance]').forEach(function (el) {
    var go = function () { ouvrir(JSON.parse(el.getAttribute('data-edt-seance'))); };
    el.addEventListener('click', go);
    el.addEventListener('keydown', function (e) { if (e.key === 'Enter') go(); });
  });
})();
