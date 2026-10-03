// Inscription scolarité : fenêtre de modification d'une inscription
// (repris du script en ligne de l'ancien inscription_scolarite.php).
window.ouvrirModifInscription = function (ins) {
  document.getElementById('modif-ins-id').value = ins.id;
  document.getElementById('modif-ins-filiere').value = ins.filiere_id || '';
  document.getElementById('modif-ins-niveau').value = ins.niveau_id || '';
  document.getElementById('modif-ins-annee').value = ins.annee_id || '';
  document.getElementById('modif-ins-montant').value = ins.montant_paye || 0;
  document.getElementById('modif-ins-mode').value = ins.mode_paiement || 'especes';
  document.getElementById('modif-ins-souslabel').textContent = (ins.annee_lib || '') + ' — ' + (ins.niveau_code || '');
  document.getElementById('modal-modif-inscription').style.display = 'flex';
};
