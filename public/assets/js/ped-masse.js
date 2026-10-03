// Inscription pédagogique en masse (repris de l'ancien inscription_ped_masse.php,
// dont les scripts en ligne étaient bloqués par la CSP).
(function () {
  var form = document.getElementById('frmPedMasse');
  if (!form) return;

  // Les cases cochées partent en un seul champ JSON (évite la limite max_input_vars).
  // Écouteur en phase de capture : il passe avant la confirmation de layout.js.
  form.addEventListener('submit', function () {
    var data = {};
    form.querySelectorAll('tr[data-et-id]').forEach(function (tr) {
      var ids = [];
      tr.querySelectorAll('.chk-sem:checked').forEach(function (c) { ids.push(parseInt(c.value, 10)); });
      if (ids.length) data[tr.dataset.etId] = ids;
    });
    document.getElementById('etudiants_json').value = JSON.stringify(data);
  }, true);

  document.getElementById('btn-suggestions').addEventListener('click', function () {
    form.querySelectorAll('.chk-sem').forEach(function (c) { c.checked = false; });
    form.querySelectorAll('tr[data-et-id]').forEach(function (tr) {
      JSON.parse(tr.dataset.sugg || '[]').forEach(function (id) {
        var c = tr.querySelector('input.chk-sem[value="' + id + '"]');
        if (c) c.checked = true;
      });
    });
  });
})();
