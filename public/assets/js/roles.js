// Rôles & permissions — « tout cocher » et mise en évidence des cases
// cochées (repris du script en ligne de roles.php).
(function () {
  var form = document.getElementById('form-roles');
  if (!form) return;
  var fond = form.getAttribute('data-fond');
  var couleur = form.getAttribute('data-couleur');
  var cases = form.querySelectorAll('input[name="permissions[]"]');
  var maj = function (chk) {
    var lbl = chk.closest('label');
    var txt = lbl.querySelector('.perm-text');
    lbl.style.borderColor = chk.checked ? couleur : 'var(--border)';
    lbl.style.background = chk.checked ? fond : '#fff';
    if (txt) txt.style.color = chk.checked ? couleur : 'var(--dark)';
  };
  var compteur = function () {
    var coches = form.querySelectorAll('input[name="permissions[]"]:checked').length;
    var pct = cases.length ? Math.round(coches / cases.length * 100) : 0;
    form.querySelector('[data-barre]').style.width = pct + '%';
    form.querySelector('[data-pourcentage]').textContent = pct + '%';
  };
  cases.forEach(function (chk) { chk.addEventListener('change', function () { maj(chk); compteur(); }); });
  document.querySelectorAll('[data-tout-cocher]').forEach(function (b) {
    b.addEventListener('click', function () {
      var etat = b.getAttribute('data-tout-cocher') === '1';
      cases.forEach(function (chk) { chk.checked = etat; maj(chk); });
      compteur();
    });
  });
})();
