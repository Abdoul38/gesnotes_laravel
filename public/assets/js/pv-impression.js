// PV imprimable : le tableau doit tenir dans la largeur du papier choisi (au
// moment d'imprimer) et dans la fenêtre (à l'écran). Avec beaucoup de
// matières, il débordait de la page, puis devenait illisible une fois réduit.
//  1. le tableau prend toute la largeur disponible, les noms de matières
//     passant sur plusieurs lignes si besoin ;
//  2. à l'impression, s'il faudrait trop le réduire, il est découpé en bandes
//     superposées (groupes d'UE entiers ; n°, matricule et nom répétés dans
//     chaque bande, résultat du semestre dans la dernière) ;
//  3. ce qui dépasse encore est réduit (zoom) juste ce qu'il faut.
// Chaque cellule porte data-g : « f » (identité), l'indice de son UE, ou « r » (résultat).
(function () {
  var zone = document.querySelector('.pv-zone');
  if (!zone) return;
  var cadre = zone.parentElement;
  var echelle = document.getElementById('pv-echelle');
  var modele = zone.querySelector('table.pv').cloneNode(true);
  var pxParMm = 96 / 25.4;
  var ECHELLE_LISIBLE = 0.85; // en dessous, on préfère découper en bandes

  function afficher(z, bandes) {
    if (echelle) echelle.textContent = Math.round(z * 100) + ' %' + (bandes > 1 ? ' · ' + bandes + ' bandes' : '');
  }

  // Indices des UE, dans l'ordre
  var groupes = [];
  modele.querySelectorAll('thead tr:first-child th[data-g]').forEach(function (th) {
    var g = th.getAttribute('data-g');
    if (g !== 'f' && g !== 'r') groupes.push(g);
  });

  // Remplace le contenu de la zone par une table par bande (liste d'indices d'UE)
  function construire(bandes) {
    zone.innerHTML = '';
    bandes.forEach(function (bande, i) {
      var t = modele.cloneNode(true);
      var dernier = i === bandes.length - 1;
      t.querySelectorAll('[data-g]').forEach(function (c) {
        var g = c.getAttribute('data-g');
        var garder = g === 'f' || (g === 'r' ? dernier : bande.indexOf(g) !== -1);
        if (!garder) c.parentNode.removeChild(c);
      });
      zone.appendChild(t);
    });
  }

  // Largeur la plus étroite possible (noms de matières repliés sur plusieurs lignes)
  function largeurMinimale() {
    zone.style.zoom = 1;
    zone.style.width = 'min-content';
    return zone.scrollWidth;
  }

  // Découpe les UE en n bandes consécutives de largeurs proches
  function decouper(n, largeurs) {
    var total = largeurs.reduce(function (a, b) { return a + b; }, 0);
    var bandes = [[]], cumul = 0;
    groupes.forEach(function (g, i) {
      var cible = total * bandes.length / n;
      var courante = bandes[bandes.length - 1];
      // Nouvelle bande si on dépasse la cible (plus près de la cible sans cette UE) et qu'il reste des UE pour les bandes suivantes
      if (courante.length && bandes.length < n && cumul + largeurs[i] / 2 > cible && groupes.length - i >= n - bandes.length) {
        courante = [];
        bandes.push(courante);
      }
      courante.push(g);
      cumul += largeurs[i];
    });
    return bandes;
  }

  function zoomer(largeurDisponible, minimale) {
    if (minimale <= largeurDisponible) {
      zone.style.width = largeurDisponible + 'px';
      return 1;
    }
    var z = largeurDisponible / minimale;
    zone.style.zoom = z;
    // Bordures et tailles de police minimales ne se réduisent pas en proportion : on remesure et on corrige
    for (var i = 0; i < 4; i++) {
      var reelle = zone.getBoundingClientRect().width;
      if (reelle <= largeurDisponible) break;
      z = z * largeurDisponible / reelle * 0.99;
      zone.style.zoom = z;
    }
    return z;
  }

  function ajuster(largeurDisponible, enBandes) {
    construire([groupes]);
    zone.style.zoom = 1;
    if (!(largeurDisponible > 0)) { // fenêtre masquée ou pas encore mise en page
      zone.style.width = '';
      afficher(1, 1);
      return;
    }
    var minimale = largeurMinimale();
    var nb = 1;
    if (enBandes && groupes.length > 1 && largeurDisponible / minimale < ECHELLE_LISIBLE) {
      // Largeur de chaque UE dans la table complète
      var largeurs = groupes.map(function (g) {
        return zone.querySelector('thead tr:first-child th[data-g="' + g + '"]').getBoundingClientRect().width;
      });
      for (nb = 2; nb <= groupes.length; nb++) {
        construire(decouper(nb, largeurs));
        minimale = largeurMinimale();
        if (largeurDisponible / minimale >= ECHELLE_LISIBLE) break;
      }
      if (nb > groupes.length) nb = groupes.length;
    }
    afficher(zoomer(largeurDisponible, minimale), nb);
  }

  function pourEcran() { document.body.classList.remove('mesure-impression'); ajuster(cadre.clientWidth, false); }
  function pourPapier() {
    // Mesure avec les tailles de l'impression (texte plus petit) : réduction au plus juste
    document.body.classList.add('mesure-impression');
    ajuster(parseFloat(zone.getAttribute('data-largeur-papier-mm')) * pxParMm, true);
  }

  window.addEventListener('beforeprint', pourPapier);
  window.addEventListener('afterprint', pourEcran);
  window.addEventListener('resize', pourEcran);
  pourEcran();
})();
