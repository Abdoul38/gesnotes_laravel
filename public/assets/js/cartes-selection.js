// Sélection des cartes étudiantes — repris du script en ligne de l'ancien
// carte_etudiant.php (filtre de la liste, génération en masse).
(function () {
  var champ = document.getElementById('srch-et');
  if (champ) champ.addEventListener('input', function () {
    var q = this.value.toLowerCase().trim();
    Array.prototype.forEach.call(document.getElementById('sel-et').options, function (o) {
      var mat = (o.dataset.matricule || '').toLowerCase();
      var nom = (o.dataset.nom || '').toLowerCase();
      o.style.display = (!q || mat.indexOf(q) !== -1 || nom.indexOf(q) !== -1) ? '' : 'none';
    });
  });

  window.genererMasse = function () {
    var bouton = document.querySelector('[data-onclick="genererMasse"]');
    var url = bouton.getAttribute('data-url') + '?masse=1&annee_id=' + encodeURIComponent(document.getElementById('sel-annee').value);
    var fac = document.getElementById('sel-fac').value;
    if (fac) url += '&faculte=' + encodeURIComponent(fac);
    window.location.href = url;
  };
})();
