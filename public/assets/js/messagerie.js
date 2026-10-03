// Messagerie — recherche du destinataire (repris de l'ancien messagerie.php).
// Les résultats sont construits avec textContent : un nom d'étudiant ne peut
// jamais injecter de HTML dans la page.
(function () {
  var champ = document.getElementById('srch-msg');
  if (!champ) return;
  var boite = document.getElementById('msg-results');
  var cache = document.getElementById('dest-id');
  var minuterie = null;

  function choisir(e) {
    cache.value = e.id;
    champ.value = e.prenoms + ' ' + e.nom;
    boite.style.display = 'none';
  }

  function afficher(liste) {
    boite.textContent = '';
    liste.forEach(function (e) {
      var ligne = document.createElement('div');
      ligne.className = 'msg-result-item';
      ligne.setAttribute('role', 'option');
      ligne.style.cssText = 'padding:10px 14px;cursor:pointer;font-size:13px;border-bottom:1px solid #e5e7eb';
      ligne.textContent = e.prenoms + ' ' + e.nom + ' ';
      var matricule = document.createElement('span');
      matricule.style.cssText = 'color:#5b21b6;font-family:monospace';
      matricule.textContent = e.numero || '';
      ligne.appendChild(matricule);
      ligne.addEventListener('click', function () { choisir(e); });
      boite.appendChild(ligne);
    });
    boite.style.display = liste.length ? '' : 'none';
  }

  champ.addEventListener('input', function () {
    cache.value = '';
    var q = champ.value.trim();
    clearTimeout(minuterie);
    if (q.length < 2) { boite.style.display = 'none'; return; }
    minuterie = setTimeout(function () {
      fetch(champ.getAttribute('data-url') + '?q=' + encodeURIComponent(q), { headers: { Accept: 'application/json' }, credentials: 'same-origin' })
        .then(function (r) { return r.ok ? r.json() : []; })
        .then(afficher)
        .catch(function () {});
    }, 250);
  });

  document.getElementById('form-message').addEventListener('submit', function (e) {
    if (!cache.value) { e.preventDefault(); alert('Choisissez un destinataire dans la liste.'); }
  });
})();
