// PV imprimable : le tableau est réduit (zoom) pour tenir dans la largeur du
// papier choisi au moment d'imprimer, et dans la fenêtre à l'écran. Avec
// beaucoup de matières, il débordait de la page.
(function () {
  var zone = document.querySelector('.pv-zone');
  if (!zone) return;
  var cadre = zone.parentElement;
  var echelle = document.getElementById('pv-echelle');
  var pxParMm = 96 / 25.4;

  function ajuster(largeurDisponible) {
    zone.style.zoom = 1;
    if (!(largeurDisponible > 0)) { // fenêtre masquée ou pas encore mise en page
      if (echelle) echelle.textContent = '100 %';
      return;
    }
    var naturelle = zone.scrollWidth;
    var z = naturelle > largeurDisponible ? largeurDisponible / naturelle : 1;
    zone.style.zoom = z;
    // Bordures et tailles de police minimales ne se réduisent pas en proportion : on remesure et on corrige
    for (var i = 0; i < 4 && z < 1; i++) {
      var reelle = zone.getBoundingClientRect().width;
      if (reelle <= largeurDisponible) break;
      z = z * largeurDisponible / reelle * 0.99;
      zone.style.zoom = z;
    }
    if (echelle) echelle.textContent = Math.round(z * 100) + ' %';
  }
  function pourEcran() { document.body.classList.remove('mesure-impression'); ajuster(cadre.clientWidth); }
  function pourPapier() {
    // Mesure avec les tailles de l'impression (texte plus petit) : réduction au plus juste
    document.body.classList.add('mesure-impression');
    ajuster(parseFloat(zone.getAttribute('data-largeur-papier-mm')) * pxParMm);
  }

  window.addEventListener('beforeprint', pourPapier);
  window.addEventListener('afterprint', pourEcran);
  window.addEventListener('resize', pourEcran);
  pourEcran();
})();
