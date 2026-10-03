// Tableau de bord du rectorat — repris du script en ligne de l'ancien
// rectorat.php : graphiques (accueil, évolution) et filtre des facultés.
(function () {
  var $ = function (id) { return document.getElementById(id); };

  var bloc = $('donnees-rectorat');
  if (bloc && window.Chart) {
    var d = JSON.parse(bloc.textContent);
    var legende = { legend: { position: 'bottom', labels: { boxWidth: 12, font: { size: 10 } } } };
    if ($('cInscrits')) new Chart($('cInscrits'), {
      type: 'bar', data: { labels: d.facultes, datasets: [{ label: 'Inscrits', data: d.inscrits, backgroundColor: '#1A3A5C' }] },
      options: { responsive: true, plugins: { legend: { display: false } }, scales: { y: { beginAtZero: true } } }
    });
    if ($('cPassage')) new Chart($('cPassage'), {
      type: 'bar',
      data: { labels: d.facultes, datasets: [{ label: 'Taux de passage', data: d.taux,
        backgroundColor: d.taux.map(function (t) { return t >= 50 ? '#166534' : '#dc2626'; }) }] },
      options: { responsive: true, plugins: { legend: { display: false } }, scales: { y: { beginAtZero: true, max: 100 } } }
    });
    if ($('cGenre')) new Chart($('cGenre'), {
      type: 'bar',
      data: { labels: d.facultes, datasets: [
        { label: 'Hommes', data: d.hommes, backgroundColor: '#1D4ED8' },
        { label: 'Femmes', data: d.femmes, backgroundColor: '#DB2777' }
      ] },
      options: { responsive: true, plugins: legende, scales: { x: { stacked: true }, y: { stacked: true, beginAtZero: true } } }
    });
    if ($('evChartIns')) new Chart($('evChartIns'), {
      type: 'line', data: { labels: d.annees, datasets: [{ label: 'Inscrits', data: d.evInscrits, borderColor: '#1A3A5C', backgroundColor: 'rgba(26,58,92,.1)', fill: true, tension: 0.3 }] },
      options: { responsive: true, plugins: legende, scales: { y: { beginAtZero: true } } }
    });
    if ($('evChartTaux')) new Chart($('evChartTaux'), {
      type: 'line', data: { labels: d.annees, datasets: [{ label: 'Taux de passage (%)', data: d.evTaux, borderColor: '#166534', backgroundColor: 'rgba(22,101,52,.1)', fill: true, tension: 0.3 }] },
      options: { responsive: true, plugins: legende, scales: { y: { beginAtZero: true, max: 100 } } }
    });
  }

  // Filtre des facultés / filières (sans accents ni casse)
  var champ = $('filtre-detail');
  if (champ) {
    var normaliser = function (s) { return s.normalize('NFD').replace(/[̀-ͯ]/g, '').toLowerCase(); };
    champ.addEventListener('input', function () {
      var q = normaliser(champ.value.trim());
      document.querySelectorAll('[data-recherche]').forEach(function (el) {
        el.style.display = !q || normaliser(el.getAttribute('data-recherche')).indexOf(q) !== -1 ? '' : 'none';
      });
    });
  }
})();
