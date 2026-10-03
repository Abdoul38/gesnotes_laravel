// Tableau de bord statistiques — graphiques (repris de stats.php, qui
// chargeait Chart.js depuis un CDN bloqué et visait des éléments absents).
(function () {
  var bloc = document.getElementById('donnees-stats');
  if (!bloc || !window.Chart) return;
  var d = JSON.parse(bloc.textContent);
  var $ = function (id) { return document.getElementById(id); };
  var total = d.decisions.reduce(function (a, b) { return a + b; }, 0) || 1;
  if ($('chartDecisions')) new Chart($('chartDecisions'), {
    type: 'doughnut',
    data: { labels: ['Admis', 'Ajournés', 'Défaillants'], datasets: [{ data: d.decisions, backgroundColor: ['#1b5e20', '#e65100', '#b71c1c'], borderWidth: 2, borderColor: '#fff' }] },
    options: { responsive: true, plugins: { legend: { position: 'bottom' },
      tooltip: { callbacks: { label: function (c) { return c.label + ' : ' + c.raw + ' (' + Math.round(c.raw / total * 100) + '%)'; } } } } }
  });
  if ($('chartMentions')) new Chart($('chartMentions'), {
    type: 'bar',
    data: { labels: d.mentions.libelles, datasets: [{ label: "Nombre d'étudiants", data: d.mentions.valeurs, backgroundColor: ['#1565C0', '#2E7D32', '#F57F17', '#C62828'], borderRadius: 6 }] },
    options: { responsive: true, plugins: { legend: { display: false } }, scales: { y: { beginAtZero: true, ticks: { stepSize: 1 } } } }
  });
  if ($('chartEvolInscrits')) new Chart($('chartEvolInscrits'), {
    type: 'line',
    data: { labels: d.annees, datasets: [{ label: 'Inscrits', data: d.evInscrits, borderColor: '#e65100', backgroundColor: 'rgba(230,81,0,.1)', fill: true, tension: 0.3 }] },
    options: { responsive: true, scales: { y: { beginAtZero: true } } }
  });
  if ($('chartEvolTaux')) new Chart($('chartEvolTaux'), {
    type: 'line',
    data: { labels: d.annees, datasets: [{ label: 'Taux de passage (%)', data: d.evTaux, borderColor: '#1A3A5C', backgroundColor: 'rgba(26,58,92,.1)', fill: true, tension: 0.3 }] },
    options: { responsive: true, scales: { y: { beginAtZero: true, max: 100 } } }
  });
})();
