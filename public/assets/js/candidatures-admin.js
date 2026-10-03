// Administration des candidatures — graphiques des onglets Statistiques et
// Analyses avancées (repris du script en ligne de portail_candidats_admin.php).
(function () {
  var bloc = document.getElementById('donnees-candidatures');
  if (!bloc || !window.Chart) return;
  var d = JSON.parse(bloc.textContent);
  var C = ['#5b21b6', '#7c3aed', '#a78bfa', '#16a34a', '#f59e0b', '#ef4444', '#0ea5e9', '#14b8a6', '#ec4899', '#64748b'];
  var $ = function (id) { return document.getElementById(id); };
  var col = function (liste, cle) { return (liste || []).map(function (x) { return x[cle]; }); };
  var anneau = function (id, libelles, valeurs) {
    if ($(id)) new Chart($(id), { type: 'doughnut', data: { labels: libelles, datasets: [{ data: valeurs, backgroundColor: C, borderWidth: 2, borderColor: '#fff' }] },
      options: { responsive: true, cutout: '55%', plugins: { legend: { position: 'bottom', labels: { boxWidth: 12, font: { size: 10 } } } } } });
  };
  var barres = function (id, libelles, series, options) {
    if ($(id)) new Chart($(id), { type: 'bar', data: { labels: libelles, datasets: series },
      options: Object.assign({ responsive: true, plugins: { legend: { position: 'top', labels: { boxWidth: 12, font: { size: 10 } } } }, scales: { y: { beginAtZero: true } } }, options || {}) });
  };
  var choix = function (liste) {
    return [
      { label: '1er choix', data: col(liste, 'ch1'), backgroundColor: '#5b21b6' },
      { label: '2e choix', data: col(liste, 'ch2'), backgroundColor: '#a78bfa' },
      { label: '3e choix', data: col(liste, 'ch3'), backgroundColor: '#ddd6fe' }
    ];
  };

  barres('c_fil', col(d.filieres, 'libelle'), choix(d.filieres), { indexAxis: 'y', scales: { x: { beginAtZero: true } } });
  anneau('c_bac', col(d.bac, 'libelle'), col(d.bac, 'nb'));
  if ($('c_gen')) anneau('c_gen', ['Hommes', 'Femmes'], [Number($('c_gen').getAttribute('data-hommes')), Number($('c_gen').getAttribute('data-femmes'))]);
  barres('c_fac', col(d.facultes, 'faculte'), choix(d.facultes));
  if ($('c_evo')) new Chart($('c_evo'), { type: 'line', data: { labels: col(d.evolution, 'jour'),
    datasets: [{ label: 'Dépôts', data: col(d.evolution, 'nb'), borderColor: '#5b21b6', backgroundColor: 'rgba(91,33,182,.1)', fill: true, tension: 0.3 }] },
    options: { responsive: true, plugins: { legend: { display: false } }, scales: { y: { beginAtZero: true } } } });

  barres('c_gb', d.genreBac.bacs, [
    { label: 'Hommes', data: d.genreBac.hommes, backgroundColor: '#1d4ed8' },
    { label: 'Femmes', data: d.genreBac.femmes, backgroundColor: '#db2777' }
  ]);
  var mentions = d.mentionsFilieres.series || {};
  barres('c_mpf', d.mentionsFilieres.filieres, Object.keys(mentions).map(function (m, i) {
    return { label: m, data: mentions[m], backgroundColor: C[i % C.length] };
  }), { scales: { x: { stacked: true }, y: { stacked: true, beginAtZero: true } } });
  barres('c_reg', col(d.region, 'libelle'), [{ label: 'Candidats', data: col(d.region, 'nb'), backgroundColor: '#0ea5e9' }], { indexAxis: 'y', plugins: { legend: { display: false } }, scales: { x: { beginAtZero: true } } });
})();
