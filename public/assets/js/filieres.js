// Page Filières — reprise des scripts en ligne de l'ancien
// pages/structure/filieres.php. Données lues dans #donnees-filieres.
(function () {
  var source = document.getElementById('donnees-filieres');
  var donnees = source ? JSON.parse(source.textContent) : { departements: {}, ordres: {} };

  // Met à jour la liste des départements quand la faculté change.
  window.cascadeDep = function (facId, selectId, currentDep) {
    var sel = document.getElementById(selectId);
    if (!sel) return;
    sel.innerHTML = '';
    var vide = document.createElement('option');
    vide.value = '';
    vide.textContent = '— Aucun département —';
    sel.appendChild(vide);
    (donnees.departements[facId] || []).forEach(function (d) {
      var opt = document.createElement('option');
      opt.value = d.id;
      opt.textContent = d.libelle;
      if (parseInt(d.id, 10) === parseInt(currentDep, 10)) opt.selected = true;
      sel.appendChild(opt);
    });
  };

  // Propose l'ordre suivant dans l'UE choisie pour une nouvelle matière.
  window.updateOrdreMatiere = function (ueId) {
    var inp = document.getElementById('inp-ordre-mat');
    if (!inp) return;
    inp.value = (ueId && donnees.ordres[ueId] !== undefined) ? donnees.ordres[ueId] : 1;
  };
})();
