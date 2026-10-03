// Liste des PV : statut modifiable sur place, titre modifiable en double-clic
// (repris de l'ancien pv.php ; le jeton CSRF part désormais dans l'en-tête).
(function () {
  var jeton = (document.querySelector('meta[name="csrf-token"]') || {}).content || '';

  document.querySelectorAll('.statut-select').forEach(function (sel) {
    sel.addEventListener('change', function () {
      var fd = new FormData();
      fd.append('pv_id', sel.dataset.id);
      fd.append('statut', sel.value);
      fetch(sel.dataset.url, { method: 'POST', body: fd, headers: { 'X-CSRF-TOKEN': jeton, 'Accept': 'application/json' } })
        .then(function (r) { return r.json(); })
        .then(function (d) {
          sel.style.background = d.ok ? '#dcfce7' : '#fee2e2';
          setTimeout(function () { sel.style.background = ''; }, 1200);
        });
    });
  });

  function annuler(input) {
    input.style.display = 'none';
    input.previousElementSibling.style.display = 'inline';
  }
  document.querySelectorAll('.pv-titre-display').forEach(function (span) {
    span.addEventListener('dblclick', function () {
      var input = span.nextElementSibling;
      span.style.display = 'none';
      input.style.display = 'inline-block';
      input.focus();
      input.select();
    });
  });
  document.querySelectorAll('.pv-titre-input').forEach(function (input) {
    input.addEventListener('keydown', function (e) {
      if (e.key === 'Enter') { e.preventDefault(); input.closest('form').submit(); }
      if (e.key === 'Escape') annuler(input);
    });
    input.addEventListener('blur', function () { annuler(input); });
  });
})();
