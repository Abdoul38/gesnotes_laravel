// Pages d'import : affiche le nom et la taille du fichier choisi
// (repris du script en ligne de l'ancien etudiant_importer.php).
(function () {
  var champ = document.querySelector('[name="fichier_import"]');
  if (!champ) return;
  champ.addEventListener('change', function () {
    var info = document.getElementById('file-info');
    if (info && this.files[0]) {
      info.textContent = '✅ ' + this.files[0].name + ' (' + (this.files[0].size / 1024).toFixed(1) + ' Ko)';
    }
  });
})();
