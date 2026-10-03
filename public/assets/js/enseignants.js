// Enseignants — repris des scripts en ligne de l'ancien enseignants.php :
// cascade faculté → département, fiche lecture/modification, fenêtre des
// matières affectées, filtre des matières du formulaire d'affectation.
(function () {
  var donnees = document.getElementById('donnees-enseignants');
  var DEPS_PAR_FAC = donnees ? JSON.parse(donnees.textContent) : {};

  window.cascadeDep = function (facId, selectId, depActuel) {
    var sel = document.getElementById(selectId);
    if (!sel) return;
    sel.innerHTML = '<option value="">— Aucun département —</option>';
    (DEPS_PAR_FAC[facId] || []).forEach(function (d) {
      var opt = document.createElement('option');
      opt.value = d.id;
      opt.textContent = d.libelle;
      if (parseInt(d.id, 10) === parseInt(depActuel, 10)) opt.selected = true;
      sel.appendChild(opt);
    });
  };

  // Fiche : lecture par défaut, formulaire au clic sur « Modifier »
  function basculerFiche() {
    document.querySelectorAll('#form-dossier [data-view], #form-dossier [data-edit]').forEach(function (el) {
      el.style.display = (el.style.display === 'none') ? '' : 'none';
    });
  }
  ['btn-modifier-dossier', 'btn-annuler-dossier'].forEach(function (id) {
    var b = document.getElementById(id);
    if (b) b.addEventListener('click', basculerFiche);
  });

  window.togglePopup = function (id) {
    var popup = document.getElementById('popup-' + id);
    var voile = document.getElementById('overlay-' + id);
    var visible = popup.style.display !== 'none';
    popup.style.display = visible ? 'none' : 'block';
    voile.style.display = visible ? 'none' : 'block';
  };

  // Formulaire d'affectation : les options d'origine sont gardées à part
  // pour pouvoir refiltrer à chaque changement.
  var selMatiere = document.getElementById('sel-matiere');
  var toutes = selMatiere ? Array.prototype.slice.call(selMatiere.querySelectorAll('option[data-fn]')).map(function (o) { return o.cloneNode(true); }) : [];

  window.filtrerMatieres = function () {
    if (!selMatiere) return;
    var annee = document.getElementById('sel-annee').value;
    var fn = document.getElementById('sel-filiere').value;
    var sem = document.getElementById('sel-semestre').value;
    selMatiere.innerHTML = '<option value="">— Sélectionner une matière —</option>';
    toutes.forEach(function (opt) {
      if ((!annee || opt.dataset.annee === annee) && (!fn || opt.dataset.fn === fn) && (!sem || opt.dataset.sem === sem)) {
        var o = opt.cloneNode(true);
        o.style.display = '';
        selMatiere.appendChild(o);
      }
    });
  };
  window.filtrerMatieres();
})();
