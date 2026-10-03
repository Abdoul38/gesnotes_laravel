// Champ de recherche qui masque les options d'une liste déroulante
// (data-filtre-options="id-du-select") — repris de l'ancien liste_valides.php.
document.querySelectorAll('[data-filtre-options]').forEach(function (champ) {
  var liste = document.getElementById(champ.getAttribute('data-filtre-options'));
  if (!liste) return;
  champ.addEventListener('input', function () {
    var q = champ.value.toLowerCase();
    Array.prototype.forEach.call(liste.options, function (o) {
      var texte = (o.parentNode.label || '') + ' ' + o.text;
      o.style.display = texte.toLowerCase().indexOf(q) !== -1 ? '' : 'none';
    });
  });
});
