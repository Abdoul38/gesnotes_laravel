// Fenêtre de création / modification d'une salle — reprise du script en
// ligne de l'ancien pages/structure/salles.php. Appelée par data-onclick
// (le bouton Modifier passe la salle en JSON dans data-args).
window.ouvrirModal = function (s) {
  document.getElementById('modal-titre').textContent = s ? '✏️ Modifier la salle' : '➕ Nouvelle salle';
  document.getElementById('f-id').value = s ? s.id : '';
  document.getElementById('f-code').value = s ? s.code : '';
  document.getElementById('f-nom').value = s ? s.nom : '';
  document.getElementById('f-type').value = s ? s.type : 'salle_td';
  document.getElementById('f-capacite').value = s ? s.capacite : 0;
  document.getElementById('f-batiment').value = s ? (s.batiment || '') : '';
  document.getElementById('f-equipements').value = s ? (s.equipements || '') : '';
  document.getElementById('modal-salle').style.display = 'flex';
};
window.fermerModal = function () {
  document.getElementById('modal-salle').style.display = 'none';
};
