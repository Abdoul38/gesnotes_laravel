// Inscription en ligne — choix du moyen de paiement (étape 3) et suivi
// automatique du paiement (étape 4), repris de l'ancienne page.
(function () {
  // ── Carte bancaire : informations du navigateur pour le 3-D Secure ──
  var formCarte = document.getElementById('form-carte');
  if (formCarte) {
    formCarte.elements.screenHeight.value = window.screen.height;
    formCarte.elements.screenWidth.value = window.screen.width;
    formCarte.elements.TZ.value = new Date().getTimezoneOffset();
  }

  // ── Étape 3 ─────────────────────────────────────────────────
  var cartes = document.querySelectorAll('.pay-card[data-moyen]');
  var zoneTel = document.getElementById('zone-telephone');
  var zoneCarte = document.getElementById('zone-carte');
  var choisir = function (carte) {
    cartes.forEach(function (c) { c.classList.toggle('selected', c === carte); });
    carte.querySelector('input').checked = true;
    var estCarte = carte.getAttribute('data-moyen') === 'visa_master';
    if (zoneTel) zoneTel.style.display = estCarte ? 'none' : '';
    if (zoneCarte) zoneCarte.style.display = estCarte ? '' : 'none';
    var tel = document.getElementById('telephone');
    if (tel) tel.required = !estCarte;
  };
  cartes.forEach(function (c) { c.addEventListener('click', function () { choisir(c); }); });
  if (cartes.length) choisir(document.querySelector('.pay-card.selected') || cartes[0]);

  // ── Étape 4 : vérification toutes les 6 secondes (20 fois au plus) ──
  var attente = document.getElementById('paiement-en-attente');
  var form = document.getElementById('formConfirmer');
  if (!attente || !form || attente.hasAttribute('data-deja-valide')) return;
  var url = attente.getAttribute('data-statut-url');
  var essais = 0;
  var afficher = function (id) { attente.style.display = 'none'; document.getElementById(id).style.display = 'block'; };
  var verifier = function () {
    fetch(url, { headers: { Accept: 'application/json' }, credentials: 'same-origin' })
      .then(function (r) { return r.json(); })
      .then(function (d) {
        if (d.ok && d.statut === 'valide') { form.submit(); return; }
        if (d.ok && d.statut === 'expire') { afficher('paiement-expire'); return; }
        if (d.ok && d.statut === 'echec') { afficher('paiement-echec'); return; }
        suite();
      })
      .catch(suite);
  };
  var suite = function () {
    essais++;
    if (essais < 20) { setTimeout(verifier, 6000); return; }
    var t = document.getElementById('texte-attente');
    if (t) t.textContent = 'La vérification automatique a expiré — utilisez le bouton ci-dessous.';
    var s = document.querySelector('#spinner-attente .spinner');
    if (s) s.style.display = 'none';
  };
  setTimeout(verifier, 6000);
})();
