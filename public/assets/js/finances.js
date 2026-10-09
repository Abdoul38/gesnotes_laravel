// Hauteur donnée par le cadre .graphe (sinon Chart.js agrandit le graphique sans fin dans une grille)
if (window.Chart) { Chart.defaults.maintainAspectRatio = false; }
// Finances — repris du script en ligne de l'ancien finances.php :
// graphiques de l'aperçu, fenêtres des tarifs et des dépenses.
(function () {
  var $ = function (id) { return document.getElementById(id); };
  var fcfa = function (v) { return Number(v).toLocaleString('fr-FR') + ' F'; };

  // ── Aperçu : graphiques ─────────────────────────────────────
  var bloc = $('donnees-finances');
  if (bloc && window.Chart) {
    var d = JSON.parse(bloc.textContent);
    new Chart($('chartEvolution'), {
      type: 'line',
      data: { labels: d.labels, datasets: [
        { label: 'Recettes', data: d.recettes, borderColor: '#16a34a', backgroundColor: 'rgba(22,163,74,0.1)', fill: true, tension: 0.3 },
        { label: 'Dépenses', data: d.depenses, borderColor: '#dc2626', backgroundColor: 'rgba(220,38,38,0.1)', fill: true, tension: 0.3 }
      ] },
      options: { responsive: true, plugins: { legend: { position: 'bottom' } }, scales: { y: { beginAtZero: true, ticks: { callback: fcfa } } } }
    });
    if ($('chartCategories')) new Chart($('chartCategories'), {
      type: 'doughnut',
      data: { labels: d.categories.labels, datasets: [{ data: d.categories.valeurs,
        backgroundColor: ['#dc2626', '#ea580c', '#d97706', '#65a30d', '#0891b2', '#2563eb', '#7c3aed', '#db2777'] }] },
      options: { responsive: true, plugins: { legend: { position: 'bottom', labels: { boxWidth: 12, font: { size: 10 } } } } }
    });
    if ($('chartFilieres')) new Chart($('chartFilieres'), {
      type: 'bar',
      data: { labels: d.filieres.labels, datasets: [{ label: 'Recettes', data: d.filieres.valeurs, backgroundColor: '#2563eb' }] },
      options: { responsive: true, indexAxis: 'y', plugins: { legend: { display: false } }, scales: { x: { beginAtZero: true, ticks: { callback: fcfa } } } }
    });
  }

  // ── Grille tarifaire ────────────────────────────────────────
  var nouveauTarif = document.querySelector('[data-tarif-nouveau]');
  if (nouveauTarif) nouveauTarif.addEventListener('click', function () {
    $('modal-titre-tarif').textContent = '➕ Nouveau tarif';
    $('tarif-montant').value = '';
    window.openModal('modal-tarif');
  });
  document.querySelectorAll('[data-tarif]').forEach(function (btn) {
    btn.addEventListener('click', function () {
      var t = JSON.parse(btn.getAttribute('data-tarif'));
      $('modal-titre-tarif').textContent = '✏️ Modifier le tarif';
      $('tarif-filiere').value = t.filiere_id;
      $('tarif-niveau').value = t.niveau_id;
      $('tarif-type').value = t.type_paiement;
      $('tarif-montant').value = t.montant;
      window.openModal('modal-tarif');
    });
  });

  // ── Dépenses ────────────────────────────────────────────────
  var form = $('form-depense');
  var nouvelle = document.querySelector('[data-depense-nouvelle]');
  if (form && nouvelle) nouvelle.addEventListener('click', function () {
    form.reset();
    $('dep-action').value = 'ajouter';
    $('dep-id').value = '';
    $('modal-titre-depense').textContent = '➕ Nouvelle dépense';
    $('dep-date').value = form.getAttribute('data-aujourdhui');
    $('dep-annee').value = form.getAttribute('data-annee');
    window.openModal('modal-depense');
  });
  document.querySelectorAll('[data-depense]').forEach(function (btn) {
    btn.addEventListener('click', function () {
      var d = JSON.parse(btn.getAttribute('data-depense'));
      $('dep-action').value = 'modifier';
      $('dep-id').value = d.id;
      $('modal-titre-depense').textContent = '✏️ Modifier la dépense';
      $('dep-categorie').value = d.categorie_id;
      $('dep-annee').value = d.annee_id || form.getAttribute('data-annee');
      $('dep-libelle').value = d.libelle;
      $('dep-montant').value = d.montant;
      $('dep-date').value = d.date_depense;
      $('dep-mode').value = d.mode_paiement;
      $('dep-beneficiaire').value = d.beneficiaire || '';
      $('dep-reference').value = d.reference || '';
      $('dep-notes').value = d.notes || '';
      window.openModal('modal-depense');
    });
  });
})();
