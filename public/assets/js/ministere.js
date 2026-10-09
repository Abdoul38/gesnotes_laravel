// Hauteur donnée par le cadre .graphe (sinon Chart.js agrandit le graphique sans fin dans une grille)
if (window.Chart) { Chart.defaults.maintainAspectRatio = false; }
// Tableau de bord national du Ministère — graphiques et filtre du détail
// (repris du script en ligne de l'ancien ministere.php).
(function () {
  var $ = function (id) { return document.getElementById(id); };
  var bloc = $('donnees-ministere');
  if (bloc && window.Chart) {
    var d = JSON.parse(bloc.textContent);
    var legende = { legend: { position: 'bottom', labels: { boxWidth: 12, font: { size: 10 } } } };
    var COULEURS = ['#1A3A5C', '#166534', '#1D4ED8', '#7C3AED', '#C8860A', '#DC2626'];
    if ($('cInscrits')) new Chart($('cInscrits'), {
      type: 'bar', data: { labels: d.sigles, datasets: [{ label: 'Inscrits', data: d.inscrits, backgroundColor: COULEURS }] },
      options: { responsive: true, plugins: { legend: { display: false } }, scales: { y: { beginAtZero: true } } }
    });
    if ($('cPassage')) new Chart($('cPassage'), {
      type: 'bar',
      data: { labels: d.sigles, datasets: [{ label: 'Taux de passage', data: d.taux, backgroundColor: d.taux.map(function (t) { return t >= 50 ? '#166534' : '#dc2626'; }) }] },
      options: { responsive: true, plugins: { legend: { display: false } }, scales: { y: { beginAtZero: true, max: 100 } } }
    });
    if ($('cGenre')) new Chart($('cGenre'), {
      type: 'bar',
      data: { labels: d.sigles, datasets: [{ label: 'Hommes', data: d.hommes, backgroundColor: '#1D4ED8' }, { label: 'Femmes', data: d.femmes, backgroundColor: '#DB2777' }] },
      options: { responsive: true, plugins: legende, scales: { x: { stacked: true }, y: { stacked: true, beginAtZero: true } } }
    });
    if ($('cCompare')) new Chart($('cCompare'), {
      type: 'bar',
      data: { labels: d.sigles, datasets: [{ label: 'Inscrits', data: d.inscrits, backgroundColor: '#1A3A5C' }, { label: 'Passages', data: d.passages, backgroundColor: '#166534' }] },
      options: { responsive: true, plugins: legende, scales: { y: { beginAtZero: true } } }
    });
    document.querySelectorAll('canvas[data-evolution]').forEach(function (c) {
      var e = d.evolution[c.getAttribute('data-evolution')];
      if (!e) return;
      var taux = c.getAttribute('data-serie') === 'taux';
      new Chart(c, {
        type: 'line',
        data: { labels: e.annees, datasets: [{ label: taux ? 'Taux de passage (%)' : 'Inscrits', data: taux ? e.taux : e.inscrits,
          borderColor: taux ? '#166534' : '#1A3A5C', backgroundColor: taux ? 'rgba(22,101,52,.1)' : 'rgba(26,58,92,.1)', fill: true, tension: 0.3 }] },
        options: { responsive: true, plugins: legende, scales: { y: taux ? { beginAtZero: true, max: 100 } : { beginAtZero: true } } }
      });
    });
  }

  // Recherche dans le détail (sans accents ni casse) : ouvre les universités concernées
  var champ = $('filtre-detail');
  if (champ) {
    var normaliser = function (s) { return s.normalize('NFD').replace(/[̀-ͯ]/g, '').toLowerCase(); };
    champ.addEventListener('input', function () {
      var q = normaliser(champ.value.trim());
      document.querySelectorAll('[data-recherche]').forEach(function (el) {
        var trouve = !q || normaliser(el.getAttribute('data-recherche')).indexOf(q) !== -1;
        el.style.display = trouve ? '' : 'none';
        if (q && trouve) { el.open = true; var parent = el.parentElement.closest('details'); if (parent) parent.open = true; }
      });
    });
  }
})();
