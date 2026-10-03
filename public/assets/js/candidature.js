// Portail candidats — repris des scripts en ligne de l'ancien portail :
// carrousel de l'accueil, détection matricule / n° de dossier, aperçu des
// pièces (nom affiché en texte, jamais en HTML).
(function () {
  // ── Carrousel ───────────────────────────────────────────────
  var diapos = document.querySelectorAll('.slide');
  var points = document.querySelectorAll('.slider-dots .dot');
  if (diapos.length) {
    var courant = 0, minuterie = null;
    var aller = function (n) {
      diapos[courant].classList.remove('active');
      if (points[courant]) points[courant].classList.remove('active');
      courant = (n + diapos.length) % diapos.length;
      diapos[courant].classList.add('active');
      if (points[courant]) points[courant].classList.add('active');
      relancer();
    };
    var relancer = function () { clearInterval(minuterie); minuterie = setInterval(function () { aller(courant + 1); }, 5500); };
    document.addEventListener('click', function (e) {
      var b = e.target.closest('[data-diapo]');
      if (b) aller(courant + parseInt(b.getAttribute('data-diapo'), 10));
      var p = e.target.closest('[data-diapo-aller]');
      if (p) aller(parseInt(p.getAttribute('data-diapo-aller'), 10));
    });
    var slider = document.getElementById('slider'), x0 = 0;
    if (slider) {
      slider.addEventListener('touchstart', function (e) { x0 = e.touches[0].clientX; }, { passive: true });
      slider.addEventListener('touchend', function (e) {
        var dx = e.changedTouches[0].clientX - x0;
        if (Math.abs(dx) > 50) aller(courant + (dx < 0 ? 1 : -1));
      }, { passive: true });
    }
    relancer();
  }
  var nav = document.getElementById('mainNav');
  if (nav) window.addEventListener('scroll', function () { nav.classList.toggle('scrolled', window.scrollY > 60); }, { passive: true });

  // ── Identifiant d'inscription : matricule ou n° de dossier ────
  var champ = document.getElementById('ins-inp-unified');
  var aide = document.getElementById('ins-detect-text');
  if (champ && aide) {
    var sigle = (champ.getAttribute('data-sigle') || '').toUpperCase();
    var defaut = aide.textContent;
    champ.addEventListener('input', function () {
      var v = champ.value.trim().toUpperCase();
      if (v && (v.charAt(0) === 'D' || (sigle && v.indexOf(sigle) === 0))) { aide.textContent = '📄 Nouveau bachelier détecté — N° dossier ou dépôt'; aide.style.color = '#c8860a'; }
      else if (v) { aide.textContent = '🎓 Ancien étudiant détecté — Numéro de matricule'; aide.style.color = '#1A3A5C'; }
      else { aide.textContent = defaut; aide.style.color = '#6b7280'; }
    });
  }

  // ── Dépôt : aperçu du fichier choisi, glisser-déposer ─────────
  document.querySelectorAll('[data-zone-depot]').forEach(function (zone) {
    var input = zone.querySelector('input[type=file]');
    input.addEventListener('change', function () {
      var f = input.files[0];
      if (!f) return;
      if (f.size > 3 * 1024 * 1024) { alert('Fichier trop volumineux (max 3 Mo)'); input.value = ''; return; }
      zone.classList.add('success');
      zone.querySelector('.upload-icon').textContent = '✅';
      var texte = zone.querySelector('.upload-text');
      texte.textContent = '';
      var nom = document.createElement('strong');
      nom.textContent = f.name;
      var taille = document.createElement('small');
      taille.textContent = (f.size / 1024 / 1024).toFixed(2) + ' Mo';
      texte.appendChild(nom);
      texte.appendChild(taille);
    });
    zone.addEventListener('dragover', function (e) { e.preventDefault(); zone.classList.add('drag'); });
    zone.addEventListener('dragleave', function () { zone.classList.remove('drag'); });
    zone.addEventListener('drop', function (e) {
      e.preventDefault();
      zone.classList.remove('drag');
      if (e.dataTransfer.files[0]) {
        var dt = new DataTransfer();
        dt.items.add(e.dataTransfer.files[0]);
        input.files = dt.files;
        input.dispatchEvent(new Event('change'));
      }
    });
  });
})();
