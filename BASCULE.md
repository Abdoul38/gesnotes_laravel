# Bascule vers la version Laravel (étape 13)

Procédure pour remplacer l'ancienne application (`htdocs/gesnotes`) par celle-ci, université par université
ou toutes ensemble. Les **bases de données ne changent pas** : la version Laravel lit et écrit les mêmes tables
(aucune migration de schéma). Ce qui change : le code servi, l'emplacement des fichiers déposés, la configuration.

> Rien dans ce document n'est fait automatiquement. Chaque étape est à exécuter par la personne qui administre
> le serveur, pendant une fenêtre de maintenance annoncée.

## 1. Avant le jour J

1. **Serveur** : PHP 8.2 ou plus avec `pdo_mysql`, `mbstring`, `zip`, `openssl`, `fileinfo` ; Composer.
2. **Code** : déposer le projet hors du dossier web, puis `composer install --no-dev --optimize-autoloader`.
   Le dossier servi par Apache (ou nginx) doit être **`public/`** et lui seul.
3. **`.env` de production** (à partir de `.env.example`) :
   - `APP_ENV=production`, `APP_DEBUG=false`, `APP_URL=https://…`, puis `php artisan key:generate` (une seule fois) ;
   - `UNIVERSITES_DOMAINE`, `DB_HOST`, `DB_USERNAME`, `DB_PASSWORD`, et `UNI_<CODE>_DB` si un nom de base diffère ;
     `UNI_<CODE>_ACTIF=false` pour une université pas encore ouverte ;
   - `SESSION_SECURE_COOKIE=true` (site en https) ;
   - `LICENCE_SECRET` : la clé qui signe les licences. Garder celle de l'ancienne application permet de garder les
     licences déjà émises ; en changer (recommandé, voir « À vérifier » du README) impose de réémettre une licence
     par université ;
   - `KOMIPAY_*` (paiement en ligne) : identifiants **neufs** si les anciens ont circulé dans le dépôt (voir README) ;
   - `ESPACES_UNIVERSITE_COMPTES` (base des comptes Ministère / ANAB, `udh` par défaut).
   - `ESPACES_DB_USERNAME` / `ESPACES_DB_PASSWORD` : compte MySQL des espaces Ministère et ANAB, qui ne peut que lire
     les bases des universités (et écrire le journal `audit_log` des consultations de l'ANAB). À créer une fois, par
     exemple (une ligne `GRANT INSERT` par base d'université) :
     ```sql
     CREATE USER 'cursus_lecture'@'localhost' IDENTIFIED BY '<mot de passe long>';
     GRANT SELECT ON `gesnotes%`.* TO 'cursus_lecture'@'localhost';
     GRANT INSERT ON `gesnotes`.`audit_log` TO 'cursus_lecture'@'localhost';
     GRANT INSERT ON `gesnotes_uam`.`audit_log` TO 'cursus_lecture'@'localhost';
     ```
   - `VERIFICATION_SECRET` : longue valeur aléatoire, fixée **avant** d'imprimer le moindre document et **jamais changée
     ensuite** (elle signe les QR codes ; la changer ramène les documents déjà imprimés à la vérification réduite).
4. **Dossiers de fichiers**, hors du dossier web, accessibles en écriture par le serveur :
   | Variable | Contenu à copier depuis l'ancienne application |
   |---|---|
   | `DOSSIER_UPLOADS` | tout `uploads/` (logos, photos, photos_enseignants, documents…), plus `assets/img/cachet.png` et `assets/img/signature.png` (ou `.jpg`) s'ils s'y trouvent : à poser directement dans ce dossier |
   | `DOSSIER_PIECES_CANDIDATS` | `candidature/uploads/pieces/` |
   | `DOSSIER_IMAGES_PORTAIL` | `candidature/assets/img/` (carrousel du portail candidats, un dossier par sigle) |
   | `DOSSIER_SAUVEGARDES` | rien d'obligatoire ; les anciennes sauvegardes (`pages/backups/`) vont dans `<dossier>/<code>/` si on veut pouvoir les retélécharger |
   Tant que `ANCIENNE_APP_CHEMIN` est renseignée et ces variables vides, les fichiers sont lus dans l'ancienne
   application : pratique pour essayer, **à ne pas garder** puisque l'ancienne application sera retirée.
5. **Bases et licences** :
   - `php artisan gesnotes:completer-schema` liste les tables du schéma absentes d'une base (l'ancienne application en
     créait certaines seulement à la première utilisation d'une page, ex. `mobile_reset_codes`) ; après une sauvegarde,
     `--appliquer` les crée (uniquement des `CREATE TABLE IF NOT EXISTS`, aucune donnée touchée) — nécessaire aussi pour
     les tables propres à la version Laravel (`paiements_annules`, sans laquelle l'annulation d'un paiement échoue ; `pv_publications`, sans laquelle
     la publication d'un PV échoue) ;
   - `php artisan gesnotes:licence <code> [--expiration=AAAA-MM-JJ] [--max=N] --installer` émet et installe la licence
     d'une université (une licence ne vaut que pour l'université dont elle porte le code) ;
   - une université pas encore ouverte : `UNI_<CODE>_ACTIF=false` dans le `.env`.
6. **Vérification** : `php artisan gesnotes:verifier-bascule`. Elle contrôle la configuration, les dossiers, puis
   pour chaque université active : tables du schéma de référence, licence, administrateur actif, messagerie. Elle ne
   modifie rien et n'affiche aucun secret. Corriger toutes les erreurs (✘) ; lire les alertes (!).
7. **Répétition** : faire une fois toute la procédure sur une copie des bases (nom de base en `UNI_<CODE>_DB`) et
   dérouler les essais du §3.

## 2. Le jour J

1. Annoncer la coupure ; passer l'ancienne application en maintenance (ou couper son hôte virtuel).
2. **Sauvegarder chaque base** (mysqldump) et le dossier `uploads/` de l'ancienne application, hors du serveur.
3. Recopier les fichiers déposés depuis le §1.4 (ils ont pu changer depuis la répétition).
4. `php artisan config:cache`, `php artisan route:cache`, `php artisan view:cache`.
5. `php artisan gesnotes:verifier-bascule` : aucune erreur.
6. Pointer l'hôte virtuel (`*.cursus-niger.net`, domaine nu compris) vers `public/` de la version Laravel.
7. Dérouler les essais du §3, puis rouvrir.

Les anciennes adresses restent valables : `index.php?page=…` (favoris, liens des anciens e-mails), QR codes déjà
imprimés (`index.php?page=verifier_diplome&num=…`), portail candidats (`candidature/index.php?page=…`), application
mobile (`api_mobile.php`, `portail2/index.php?page=…`), `uploads/logos/…` : toutes sont redirigées vers la page
correspondante (`App\Http\Controllers\AnciennesAdressesController`).

## 3. Essais après la bascule (par université)

- Domaine nu : page de présentation, liens vers chaque université, l'ANAB et le Ministère.
- Connexion administrateur, tableau de bord, menu complet ; « mot de passe oublié » (e-mail reçu, lien valable 1 h).
- Un compte par rôle : secrétaire, agent de faculté (ne voit que sa faculté), enseignant (portail enseignant),
  étudiant (portail étudiant : relevé d'un PV publié), recteur, parent (Espace Parents).
- Un PV : détail, recalcul, impression, export Excel ; un relevé de notes et son QR code (page de vérification).
- Un QR code imprimé **avant** la bascule : il doit ouvrir la vérification.
- Portail candidats : création de compte, dépôt, quitus ; inscription en ligne jusqu'au choix du moyen de paiement
  (paiement réel seulement si KomiPay est en mode `live` et les identifiants valides).
- Application mobile : connexion, notes, mot de passe oublié.
- `ministere.<domaine>` et `anab.<domaine>` : connexion, tableau national, liste des éligibles.
- Paramètres → Sauvegarde : créer puis télécharger une sauvegarde.

## 4. Retour arrière

Les bases étant les mêmes, re-pointer l'hôte virtuel vers l'ancienne application suffit, avec ces effets :
- les écritures faites entre-temps restent (notes, inscriptions…) : elles sont dans les mêmes tables ;
- l'application mobile et les sessions : chacun devra se reconnecter (les jetons de l'API sont désormais enregistrés
  par leur empreinte, que l'ancienne API ne sait pas lire) ;
- les fichiers déposés après la bascule sont dans les nouveaux dossiers : les recopier dans `uploads/`.
Les mots de passe changés entre-temps restent valables (même algorithme de hachage).

## 5. Après la bascule (quand tout est stable)

1. Retirer l'ancienne application du serveur, en particulier : `outils/` (dont le fichier d'identifiants de
   démonstration), `candidature/test_*.php`, `pages/backups/`, `config/` (mots de passe).
2. Vider `ANCIENNE_APP_CHEMIN` du `.env`, relancer `php artisan config:cache` et `gesnotes:verifier-bascule`.
3. Traiter les points de « Décisions à prendre » du README, notamment : identifiants KomiPay et mot de passe SMTP
   à changer, colonne `email_logs.reponse_brute` à vider, utilisateur MySQL en lecture seule pour le Ministère et l'ANAB.
