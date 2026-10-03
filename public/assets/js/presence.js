// Présence — matières de la promotion choisie (libellés insérés en texte, jamais en HTML).
(function () {
  var promo = document.getElementById('promotion_id');
  var sel = document.getElementById('sel-matiere');
  if (!promo || !sel) return;
  var option = function (valeur, texte) { var o = document.createElement('option'); o.value = valeur; o.textContent = texte; return o; };
  var remplir = function (liste, vide) { sel.textContent = ''; sel.appendChild(option('', vide)); liste.forEach(function (m) { sel.appendChild(option(m.id, m.libelle)); }); };
  promo.addEventListener('change', function () {
    if (!promo.value) { remplir([], "-- Choisir une promotion d'abord --"); return; }
    remplir([], 'Chargement...');
    fetch(promo.getAttribute('data-url-matieres') + '?promotion_id=' + encodeURIComponent(promo.value), { headers: { Accept: 'application/json' }, credentials: 'same-origin' })
      .then(function (r) { return r.json(); })
      .then(function (d) { remplir(d, d.length ? '-- Choisir --' : 'Aucune matière dans votre périmètre'); })
      .catch(function () { remplir([], 'Erreur de chargement'); });
  });
})();
