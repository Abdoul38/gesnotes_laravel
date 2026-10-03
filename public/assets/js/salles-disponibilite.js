// Disponibilité des salles en direct — planning des examens (ligne par
// ligne) et emploi du temps (fenêtres d'ajout et de modification), repris
// des scripts en ligne de planning.php et emploi_temps.php.
(function () {
  var verifier = function (url, params, select, avertissement) {
    if (!select) return;
    var q = Object.keys(params).map(function (k) { return k + '=' + encodeURIComponent(params[k]); }).join('&');
    fetch(url + '?' + q, { headers: { Accept: 'application/json' }, credentials: 'same-origin' })
      .then(function (r) { return r.json(); })
      .then(function (d) {
        var occupees = (d.occupees || []).map(String);
        var choisieOccupee = false;
        Array.prototype.forEach.call(select.options, function (o) {
          if (!o.value) return;
          var occupee = occupees.indexOf(o.value) !== -1;
          o.disabled = occupee && o.value !== select.value;
          o.textContent = o.textContent.replace(' 🔴 Occupée', '') + (occupee ? ' 🔴 Occupée' : '');
          if (occupee && o.value === select.value) choisieOccupee = true;
        });
        if (avertissement) avertissement.style.display = choisieOccupee ? 'block' : 'none';
      })
      .catch(function () {});
  };

  // Planning des examens
  var formPlanning = document.getElementById('form-planning');
  if (formPlanning) {
    var urlP = formPlanning.getAttribute('data-url-disponibilite');
    formPlanning.querySelectorAll('[data-ligne-planning]').forEach(function (ligne) {
      var champ = function (n) { return ligne.querySelector('[data-champ="' + n + '"]'); };
      var maj = function () {
        if (!champ('date').value || !champ('debut').value || !champ('fin').value) return;
        verifier(urlP, { date: champ('date').value, heure_debut: champ('debut').value, heure_fin: champ('fin').value, exclude_id: ligne.getAttribute('data-exclure') },
          champ('salle'), ligne.querySelector('[data-avertissement]'));
      };
      ['date', 'debut', 'fin', 'salle'].forEach(function (n) { champ(n).addEventListener('change', maj); });
    });
  }

  // Emploi du temps
  document.querySelectorAll('form[data-edt]').forEach(function (form) {
    var url = form.getAttribute('data-url-disponibilite');
    var maj = function () {
      var v = function (n) { var el = form.querySelector('[name="' + n + '"]'); return el ? el.value : ''; };
      if (!v('jour') || !v('heure_debut') || !v('heure_fin')) return;
      verifier(url, { jour: v('jour'), heure_debut: v('heure_debut'), heure_fin: v('heure_fin'), annee_id: form.getAttribute('data-annee'),
        sem_id: form.getAttribute('data-semestre'), exclude_id: v('id') || 0 }, form.querySelector('[name="salle_id"]'), form.querySelector('[data-avertissement]'));
    };
    ['jour', 'heure_debut', 'heure_fin', 'salle_id'].forEach(function (n) { var el = form.querySelector('[name="' + n + '"]'); if (el) el.addEventListener('change', maj); });
    form.addEventListener('edt:charge', maj);
  });
})();
