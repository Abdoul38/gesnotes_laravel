// E-mails aux étudiants — repris de l'ancien notifications_email.php : zones
// selon les destinataires, mode test, recherche d'un étudiant (résultats
// construits avec textContent, jamais en HTML).
(function () {
  var $ = function (id) { return document.getElementById(id); };
  var dest = $('sel-dest');
  if (!dest) return;

  function zones() {
    var v = dest.value;
    $('zone-filiere').style.display = v === 'filiere' ? '' : 'none';
    $('zone-niveau').style.display = v === 'niveau' ? '' : 'none';
    $('zone-individuel').style.display = v === 'individuel' ? '' : 'none';
  }
  dest.addEventListener('change', zones);
  $('chk-test').addEventListener('change', function () { $('zone-test').style.display = this.checked ? '' : 'none'; });

  var champ = $('srch-et'), boite = $('et-results'), cache = $('et-id'), minuterie = null;
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
            ligne.style.cssText = 'padding:8px 12px;cursor:pointer;font-size:13px;border-bottom:1px solid #e5e7eb';
            ligne.textContent = e.prenoms + ' ' + e.nom + ' — ' + (e.numero || '—');
            ligne.addEventListener('click', function () {
              cache.value = e.id;
              champ.value = e.nom + ' ' + e.prenoms;
              boite.style.display = 'none';
            });
            boite.appendChild(ligne);
          });
          boite.style.display = liste.length ? '' : 'none';
        })
        .catch(function () {});
    }, 250);
  });
})();
