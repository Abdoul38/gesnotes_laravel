// Graphiques du tableau de bord — repris du script en ligne de l'ancien
// pages/accueil.php ; les données viennent du bloc JSON #donnees-accueil.
(function () {
  var source = document.getElementById('donnees-accueil');
  if (!source || typeof Chart === 'undefined') return;
  var d = JSON.parse(source.textContent);

  var COLORS = ['#1A3A5C', '#2C5F9E', '#1b5e20', '#e65100', '#6a1b9a', '#c62828', '#0277bd', '#2e7d32', '#f57f17', '#ad1457'];
  var COLORS_G = ['#1b5e20', '#2e7d32', '#388e3c', '#43a047', '#4caf50', '#66bb6a'];

  function graphique(id, config) {
    var el = document.getElementById(id);
    if (el) new Chart(el, config);
  }

  // Donut Genre
  graphique('chartGenre', {
    type: 'doughnut',
    data: { labels: ['Hommes', 'Femmes'], datasets: [{ data: d.genre, backgroundColor: ['#1A3A5C', '#e91e8c'], borderWidth: 2, borderColor: '#fff' }] },
    options: { responsive: true, cutout: '65%', plugins: { legend: { display: false } } }
  });

  // Barres Faculté
  graphique('chartFaculte', {
    type: 'bar',
    data: { labels: d.faculte.labels, datasets: [{ label: 'Inscrits', data: d.faculte.data, backgroundColor: COLORS, borderRadius: 5 }] },
    options: { responsive: true, plugins: { legend: { display: false } }, scales: { y: { beginAtZero: true } } }
  });

  // Barres horizontales Filière
  graphique('chartFiliere', {
    type: 'bar',
    data: { labels: d.filiere.labels, datasets: [{ label: 'Inscrits', data: d.filiere.data, backgroundColor: '#2C5F9E', borderRadius: 4 }] },
    options: { indexAxis: 'y', responsive: true, plugins: { legend: { display: false } }, scales: { x: { beginAtZero: true } } }
  });

  // Barres Niveau
  graphique('chartNiveau', {
    type: 'bar',
    data: { labels: d.niveau.labels, datasets: [{ label: 'Inscrits', data: d.niveau.data, backgroundColor: COLORS, borderRadius: 5 }] },
    options: { responsive: true, plugins: { legend: { display: false } }, scales: { y: { beginAtZero: true } } }
  });

  graphique('chartPedFac', {
    type: 'doughnut',
    data: { labels: d.pedFac.labels, datasets: [{ data: d.pedFac.data, backgroundColor: COLORS_G, borderWidth: 2, borderColor: '#fff' }] },
    options: { responsive: true, cutout: '55%', plugins: { legend: { position: 'bottom', labels: { font: { size: 10 } } } } }
  });

  graphique('chartPedDept', {
    type: 'doughnut',
    data: { labels: d.pedDept.labels, datasets: [{ data: d.pedDept.data, backgroundColor: COLORS, borderWidth: 2, borderColor: '#fff' }] },
    options: { responsive: true, cutout: '55%', plugins: { legend: { position: 'bottom', labels: { font: { size: 10 } } } } }
  });
})();
