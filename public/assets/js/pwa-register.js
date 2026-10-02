// ============================================================
// assets/js/pwa-register.js — Enregistrement du Service Worker
// ============================================================
if ('serviceWorker' in navigator) {
  window.addEventListener('load', function () {
    navigator.serviceWorker.register('/sw.js').then(function (registration) {
      console.log('EDUFILE PWA : Service Worker enregistré (scope: ' + registration.scope + ')');
    }).catch(function (erreur) {
      console.warn('EDUFILE PWA : échec de l\'enregistrement du Service Worker', erreur);
    });
  });
}

// ── Suggestion d'installation (bouton personnalisé) ────────────────
// Le navigateur ne propose pas toujours son invite d'installation
// automatique — on capte l'événement pour pouvoir proposer notre
// propre bouton "Installer l'application" si on en ajoute un plus tard.
let evenementInstallationDiffere = null;
window.addEventListener('beforeinstallprompt', function (e) {
  e.preventDefault();
  evenementInstallationDiffere = e;
  document.dispatchEvent(new CustomEvent('edufile-pwa-installable'));
});

window.installerEdufilePWA = function () {
  if (!evenementInstallationDiffere) return;
  evenementInstallationDiffere.prompt();
  evenementInstallationDiffere.userChoice.then(function () {
    evenementInstallationDiffere = null;
  });
};
