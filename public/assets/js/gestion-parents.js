// Comptes parents — recherche de l'étudiant à lier (reprise de l'ancien
// gestion_parents.php). Résultats construits avec textContent : un nom ne
// peut pas injecter de HTML.
(function () {
  var champ = document.getElementById('rech-etudiant');
  if (!champ) return;
  var boite = document.getElementById('resultats-etudiant');
  var cache = document.getElementById('etudiant_id_choisi');
  var minuterie = null;

  champ.addEventListener('input', function () {
    cache.value = '';
    var q = champ.value.trim();
    clearTimeout(minuterie);
    if (q.length < 2) { boite.style.display = 'none'; return; }
    minuterie = setTimeout(function () {
      fetch(champ.getAttribute('data-url') + '?q=' + encodeURIComponent(q), { headers: { Accept: 'application/json' }, credentials: 'same-origin' })
        .then(function (r) { return r.ok ? r.json() : []; })
        .then(function (liste) {
          boite.textContent = '';
          liste.forEach(function (e) {
            var ligne = document.createElement('div');
            ligne.setAttribute('role', 'option');
            ligne.style.cssText = 'padding:8px;cursor:pointer;font-size:12px;border-bottom:1px solid #f0f0f0';
            ligne.textContent = e.nom + ' ' + e.prenoms + ' (' + (e.numero || '—') + ')';
            ligne.addEventListener('click', function () {
              cache.value = e.id;
              champ.value = ligne.textContent;
              boite.style.display = 'none';
            });
            boite.appendChild(ligne);
          });
          boite.style.display = liste.length ? '' : 'none';
        })
        .catch(function () {});
    }, 250);
  });

  document.getElementById('form-lier').addEventListener('submit', function (e) {
    if (!cache.value) { e.preventDefault(); alert('Choisissez un étudiant dans la liste.'); }
  });
})();
