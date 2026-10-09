// ============================================================
// assets/sw.js — Service Worker EDUFILE
// ============================================================
// Stratégie volontairement prudente : on ne met en cache QUE les
// ressources statiques (CSS, JS, icônes, logo) — jamais les pages PHP
// dynamiques (notes, paiements, etc.), pour ne jamais risquer d'afficher
// des données périmées ou d'un autre étudiant. Le cache sert uniquement
// à accélérer le chargement et permettre un affichage minimal hors ligne.

// Changer de nom vide les anciens caches à l'activation (v1 : servait des
// scripts périmés, voire une réponse d'erreur, indéfiniment).
const CACHE_NOM = 'edufile-static-v2';

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

  // Réseau d'abord : on obtient toujours la version à jour des scripts et
  // styles (avant : cache d'abord, sans jamais revérifier — une correction ne
  // parvenait pas aux navigateurs, et une réponse d'erreur mise en cache
  // empêchait par exemple l'affichage des QR codes). Le cache ne sert que
  // hors ligne, et seules les réponses valides y entrent.
  event.respondWith(
    fetch(event.request).then((reponseReseau) => {
      if (reponseReseau.ok && reponseReseau.type === 'basic') {
        const clone = reponseReseau.clone();
        caches.open(CACHE_NOM).then((cache) => cache.put(event.request, clone));
      }
      return reponseReseau;
    }).catch(() =>
      caches.match(event.request).then((reponseEnCache) => reponseEnCache || new Response('', { status: 404 }))
    )
  );
});
