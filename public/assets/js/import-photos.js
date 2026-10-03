// Import des photos : résumé de la sélection (repris de l'ancien import_photos.php,
// le nom du fichier est maintenant inséré comme texte et non comme HTML).
(function () {
  var champ = document.getElementById('zip-input');
  if (!champ) return;
  champ.addEventListener('change', function () {
    var info = document.getElementById('zip-info');
    var n = this.files.length;
    info.textContent = '';
    if (!n) return;
    var gras = document.createElement('strong');
    var taille;
    if (n === 1) {
      gras.textContent = this.files[0].name;
      taille = this.files[0].size;
    } else {
      gras.textContent = n + ' fichiers sélectionnés';
      taille = Array.prototype.reduce.call(this.files, function (s, f) { return s + f.size; }, 0);
    }
    info.append('✅ ', gras, ' (' + (taille / 1024 / 1024).toFixed(1) + ' Mo' + (n > 1 ? ' au total)' : ')'));
  });
})();
