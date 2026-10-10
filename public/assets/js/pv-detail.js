// Détail d'un PV. Remplace l'ancien assets/js/pv_detail.js :
//  - résultats provisoires pendant la saisie, calculés par le MOTEUR sur le
//    serveur (simulation sans enregistrement) et non plus par une copie
//    JavaScript des règles qui donnait d'autres résultats ;
//  - recalcul progressif étudiant par étudiant ;
//  - filtres du tableau ; fermeture du menu « Vider notes ».
(function () {
  'use strict';
  var jeton = (document.querySelector('meta[name="csrf-token"]') || {}).content || '';
  function poster(url, donnees) {
    var fd = donnees instanceof FormData ? donnees : new FormData();
    if (!(donnees instanceof FormData)) Object.keys(donnees).forEach(function (k) { fd.append(k, donnees[k]); });
    return fetch(url, { method: 'POST', body: fd, headers: { 'X-CSRF-TOKEN': jeton, 'Accept': 'application/json' } })
      .then(function (r) { return r.json(); });
  }
  var couleurs = { 'AV': 'var(--green-700)', 'AC': 'var(--blue-600)', 'NV': 'var(--amber-600)', 'Déf.': 'var(--red-600)', 'Elim': 'var(--red-600)' };
  var f2 = function (v) { return v === null || v === undefined ? '—' : Number(v).toFixed(2); };
  function cellule(tr, classe, texte, couleur) {
    var td = tr.querySelector('.' + classe);
    if (!td) return;
    td.textContent = texte;
    if (couleur) td.style.color = couleur;
  }

  // ── Résultats provisoires pendant la saisie ──────────────────
  var form = document.getElementById('form-notes');
  if (form) {
    var minuteurs = {};
    form.addEventListener('input', function (e) {
      var input = e.target;
      if (!input.classList.contains('note-input')) return;
      var tr = input.closest('tr');
      clearTimeout(minuteurs[tr.dataset.etudiantId]);
      minuteurs[tr.dataset.etudiantId] = setTimeout(function () { simuler(tr); }, 400);
    });

    var numeros = {};
    function simuler(tr) {
      var et = tr.dataset.etudiantId, fd = new FormData();
      // Seule la réponse à la DERNIÈRE saisie de la ligne est appliquée
      var numero = numeros[et] = (numeros[et] || 0) + 1;
      fd.append('etudiant_id', et);
      tr.querySelectorAll('input.note-input').forEach(function (i) {
        fd.append(i.name.replace('notes[' + et + ']', 'notes'), i.value);
      });
      poster(form.dataset.simulation, fd).then(function (d) {
        if (numero !== numeros[et]) return;
        if (!d.ok) {
          document.getElementById('save-status-bar').textContent = '⚠️ ' + ((d.erreurs || [])[0] || 'Calcul provisoire impossible');
          return;
        }
        tr.classList.add('provisoire');
        Object.keys(d.matieres).forEach(function (mat) {
          var m = d.matieres[mat];
          cellule(tr, 'mat-finale-' + mat, f2(m.finale));
          cellule(tr, 'mat-cred-' + mat, String(m.credits), m.credits > 0 ? 'var(--green-700)' : 'var(--text-muted)');
          cellule(tr, 'mat-obs-' + mat, m.obs || '—', couleurs[m.obs] || 'var(--text-muted)');
        });
        Object.keys(d.ues).forEach(function (ue) {
          var u = d.ues[ue];
          // Déf. : une note manque dans l'UE ; Elim : note ou moyenne d'UE éliminatoire
          var obs = u.defaillant ? (u.vide ? 'Déf.' : 'Elim') : (!u.valide ? 'NV' : null);
          cellule(tr, 'ue-total-' + ue, f2(u.total));
          cellule(tr, 'ue-moy-' + ue, f2(u.moyenne), u.valide ? 'var(--green-700)' : 'var(--amber-600)');
          cellule(tr, 'ue-cred-' + ue, u.credits + '/' + u.max, u.credits >= u.max ? 'var(--green-700)' : 'var(--red-600)');
          cellule(tr, 'ue-val-' + ue, u.valide ? 'OUI' : 'NON');
          if (obs) cellule(tr, 'ue-obs-' + ue, obs, couleurs[obs]);
        });
        if (d.semestre) {
          var s = d.semestre, max = (tr.querySelector('.sem-cred').textContent.split('/')[1] || '');
          cellule(tr, 'sem-cred', s.credits + '/' + max);
          cellule(tr, 'sem-moy', f2(s.moyenne), s.valide ? 'var(--green-700)' : 'var(--amber-600)');
          cellule(tr, 'sem-decision', s.decision, s.valide ? 'var(--green-700)' : 'var(--amber-600)');
          cellule(tr, 'sem-mention', s.mention);
          tr.dataset.moy = s.moyenne; tr.dataset.credits = s.credits; tr.dataset.decision = s.decision; tr.dataset.mention = s.mention;
        }
        document.getElementById('save-status-bar').textContent = '✏️ Résultats provisoires (en italique) — cliquez sur « Tout enregistrer » pour les valider';
      });
    }
  }

  // ── Recalcul progressif ──────────────────────────────────────
  var bouton = document.getElementById('btn-recalc-async');
  if (bouton) {
    var annule = false, enCours = false;
    var barre = document.getElementById('recalc-bar'), etiquette = document.getElementById('recalc-label'), zone = document.getElementById('recalc-progress');
    function etat(actif) {
      enCours = actif; bouton.disabled = actif;
      bouton.textContent = actif ? '⏳ En cours...' : '⚡ Recalcul rapide';
      zone.style.display = actif ? 'flex' : 'none';
    }
    document.getElementById('recalc-annuler').addEventListener('click', function () { annule = true; etat(false); });
    bouton.addEventListener('click', async function () {
      if (enCours) return;
      annule = false; etat(true);
      var depart = await poster(bouton.dataset.url, { mode: 'start' });
      if (!depart.ok) { alert('Erreur : ' + (depart.error || 'inconnue')); etat(false); return; }
      var erreurs = [], fait = 0;
      for (var i = 0; i < depart.ids.length && !annule; i++) {
        var r = await poster(bouton.dataset.url, { etudiant_id: depart.ids[i] });
        if (!r.ok) erreurs.push('Étudiant #' + depart.ids[i] + ' : ' + r.error);
        fait++;
        barre.style.width = Math.round(fait / depart.total * 100) + '%';
        etiquette.textContent = fait + ' / ' + depart.total;
      }
      if (annule) return;
      etiquette.textContent = 'Rangs...';
      await poster(bouton.dataset.url, { mode: 'rangs' });
      if (erreurs.length) alert('Recalcul terminé avec ' + erreurs.length + ' erreur(s) :\n' + erreurs.slice(0, 5).join('\n'));
      location.reload();
    });
  }

  // ── Filtres du tableau ───────────────────────────────────────
  var lignes = function () { return document.querySelectorAll('tr.table-row-searchable'); };
  var champ = function (id) { return document.getElementById(id); };
  function filtrer() {
    var min = parseFloat(champ('filter-moy-min').value), max = parseFloat(champ('filter-moy-max').value);
    var cred = parseInt(champ('filter-credits').value, 10);
    var dec = champ('filter-decision').value.toLowerCase(), men = champ('filter-mention').value.toLowerCase();
    var visibles = 0;
    lignes().forEach(function (tr) {
      var moy = parseFloat(tr.dataset.moy) || 0, c = parseInt(tr.dataset.credits, 10) || 0;
      var ok = !(!isNaN(min) && moy < min) && !(!isNaN(max) && moy > max) && !(!isNaN(cred) && c < cred)
        && (!dec || (tr.dataset.decision || '').toLowerCase().indexOf(dec) !== -1)
        && (!men || (tr.dataset.mention || '').toLowerCase().indexOf(men) !== -1);
      tr.style.display = ok ? '' : 'none';
      if (ok) visibles++;
    });
    champ('filter-count').textContent = visibles < lignes().length ? visibles + ' / ' + lignes().length + ' étudiant(s)' : '';
  }
  ['filter-moy-min', 'filter-moy-max', 'filter-credits', 'filter-decision', 'filter-mention'].forEach(function (id) {
    var el = champ(id);
    if (el) { el.addEventListener('input', filtrer); el.addEventListener('change', filtrer); }
  });
  var reset = champ('filter-reset');
  if (reset) reset.addEventListener('click', function () {
    ['filter-moy-min', 'filter-moy-max', 'filter-credits'].forEach(function (id) { champ(id).value = ''; });
    ['filter-decision', 'filter-mention'].forEach(function (id) { champ(id).selectedIndex = 0; });
    filtrer();
  });

  // Menu « Vider notes » : se ferme au clic ailleurs
  document.addEventListener('click', function (e) {
    var dd = document.getElementById('dropdown-vider');
    if (dd && !dd.parentElement.contains(e.target)) dd.style.display = 'none';
  });
})();
