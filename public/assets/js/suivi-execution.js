// Suivi d'exécution — filtres par statut et recherche (repris de suivi_execution.php).
(function () {
  var lignes = document.querySelectorAll('.exec-row');
  var champ = document.getElementById('srch-exec');
  var compteur = document.getElementById('nb-exec-affiche');
  if (!lignes.length) return;
  var categorie = '';
  var appliquer = function () {
    var q = champ ? champ.value.trim().toLowerCase() : '';
    var n = 0;
    lignes.forEach(function (l) {
      var ok = (!categorie || l.getAttribute('data-cat') === categorie) && (!q || l.getAttribute('data-txt').indexOf(q) !== -1);
      l.style.display = ok ? '' : 'none';
      if (ok) n++;
    });
    if (compteur) compteur.textContent = n + ' matière(s)';
  };
  document.querySelectorAll('[data-filtre-exec]').forEach(function (b) {
    b.addEventListener('click', function () {
      categorie = b.getAttribute('data-filtre-exec');
      if (!categorie && champ) champ.value = '';
      document.querySelectorAll('[data-filtre-exec]').forEach(function (x) { x.style.borderColor = x === b && categorie ? '#1A3A5C' : 'transparent'; });
      appliquer();
    });
  });
  if (champ) champ.addEventListener('input', appliquer);
})();
