// ============================================================
// assets/sw.js — Service Worker EDUFILE
// ============================================================
// Stratégie volontairement prudente : on ne met en cache QUE les
// ressources statiques (CSS, JS, icônes, logo) — jamais les pages PHP
// dynamiques (notes, paiements, etc.), pour ne jamais risquer d'afficher
// des données périmées ou d'un autre étudiant. Le cache sert uniquement
// à accélérer le chargement et permettre un affichage minimal hors ligne.

const CACHE_NOM = 'edufile-static-v1';

const RESSOURCES_STATIQUES = [
  '/assets/css/app.css',
  '/assets/js/app.js',
  '/assets/icons/icon-192.png',
  '/assets/icons/icon-512.png',
  '/manifest.json',
];

// ── Installation : met en cache les ressources statiques connues ──
self.addEventListener('install', (event) => {
  event.waitUntil(
    caches.open(CACHE_NOM).then((cache) => {
      // addAll échoue entièrement si UNE seule ressource manque — on
      // ajoute donc une par une pour ne jamais bloquer l'installation
      // à cause d'un fichier absent (ex: app.js pas encore présent).
      return Promise.allSettled(
        RESSOURCES_STATIQUES.map((url) => cache.add(url).catch(() => null))
      );
    }).then(() => self.skipWaiting())
  );
});

// ── Activation : nettoie les anciennes versions du cache ──────────
self.addEventListener('activate', (event) => {
  event.waitUntil(
    caches.keys().then((noms) =>
      Promise.all(
        noms.filter((n) => n !== CACHE_NOM).map((n) => caches.delete(n))
      )
    ).then(() => self.clients.claim())
  );
});

// ── Requêtes : cache-first UNIQUEMENT pour le statique, réseau direct
// pour tout le reste (toutes les pages index.php?page=... dynamiques) ──
self.addEventListener('fetch', (event) => {
  const url = new URL(event.request.url);

  // Ne jamais intercepter les requêtes POST (formulaires) ni les pages
  // dynamiques — uniquement les fichiers statiques identifiables par
  // leur extension ou leur dossier /assets/.
  // /fichiers/ = photos, logos, pièces servis après contrôle d'accès :
  // jamais en cache (ils resteraient dans le navigateur après déconnexion).
  const estStatique = event.request.method === 'GET' && !url.pathname.startsWith('/fichiers/') && (
    url.pathname.startsWith('/assets/') ||
    /\.(css|js|png|jpg|jpeg|svg|woff2?|ico)$/i.test(url.pathname)
  );

  if (!estStatique) return; // laisse passer normalement, sans interception

  event.respondWith(
    caches.match(event.request).then((reponseEnCache) => {
      if (reponseEnCache) return reponseEnCache;
      return fetch(event.request).then((reponseReseau) => {
        // Met en cache la nouvelle ressource statique pour la prochaine fois
        const clone = reponseReseau.clone();
        caches.open(CACHE_NOM).then((cache) => cache.put(event.request, clone));
        return reponseReseau;
      }).catch(() => {
        // Hors ligne et pas en cache : on ne peut rien faire de mieux
        // pour une ressource statique manquante.
        return new Response('', { status: 404 });
      });
    })
  );
});
