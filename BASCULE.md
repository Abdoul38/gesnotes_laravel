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
   - `ESPACES_BASE` (ex. `gesnotes_espaces`) : base propre aux comptes et au journal du Ministère et de l'ANAB, pour
     qu'une restauration de la base UDH ne les ramène plus en arrière. `php artisan gesnotes:separer-espaces` constate,
     `--appliquer` crée la base et y copie comptes et journal depuis la base `ESPACES_UNIVERSITE_COMPTES` (`udh`), sans
     rien y retirer (relançable). Sans `ESPACES_BASE`, ancien fonctionnement : comptes dans la base UDH ;
   - `ESPACES_DB_USERNAME` / `ESPACES_DB_PASSWORD` : compte MySQL des espaces Ministère et ANAB, qui ne peut que lire
     les bases des universités (et écrire le journal `audit_log` des consultations de l'ANAB).
     `php artisan gesnotes:compte-lecture-espaces` liste les droits ; `--appliquer` (avec un compte MySQL autorisé à
     créer des comptes, root en général) crée le compte `gesnotes_lecture`, lui donne `SELECT` sur chaque base
     d'université active et `INSERT` sur leur seul `audit_log`, vérifie qu'il ne peut pas écrire ailleurs, puis écrit
     ces deux variables dans le `.env` (mot de passe aléatoire, jamais affiché). À relancer si une université s'ajoute.
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
   `php artisan gesnotes:copier-fichiers` fait tout ce tableau : constat, puis `--appliquer` copie (par défaut dans
   `storage/app/private/fichiers`, ou `--destination=…`), range les anciennes sauvegardes par université et met les
   trois variables à jour dans le `.env`. Copie seulement (l'ancienne application garde ses fichiers) ; un fichier
   déjà copié n'est pas recopié : la relancer **le jour J**, juste avant d'arrêter l'ancienne application.
5. **Bases et licences** :
   - `php artisan gesnotes:completer-schema` liste les tables du schéma absentes d'une base (l'ancienne application en
     créait certaines seulement à la première utilisation d'une page, ex. `mobile_reset_codes`) ; après une sauvegarde,
     `--appliquer` les crée (uniquement des `CREATE TABLE IF NOT EXISTS`, aucune donnée touchée) — nécessaire aussi pour
     les tables propres à la version Laravel (`paiements_annules`, sans laquelle l'annulation d'un paiement échoue ; `pv_publications`, sans laquelle
     la publication d'un PV échoue ; `convocations_emises`, sans laquelle l'impression des convocations échoue) ;
   - `php artisan gesnotes:reparer-passages` constate, puis `--appliquer` répare (après une sauvegarde), les décisions
     de passage : ajoute « licence_validee » à la colonne `passages_niveau.decision` des bases qui ne l'ont pas (MySQL
     l'y enregistrait VIDE sans erreur), remet ces décisions vides en « licence_validee » et supprime les doublons
     (même étudiant et même année, filières différentes : seule la dernière décision est gardée) ;
   - `php artisan gesnotes:licence <code> [--expiration=AAAA-MM-JJ] [--max=N] --installer` émet et installe la licence
     d'une université (une licence ne vaut que pour l'université dont elle porte le code) ;
   - une université pas encore ouverte : `UNI_<CODE>_ACTIF=false` dans le `.env`.
6. **Vérification** : `php artisan gesnotes:verifier-bascule`. Elle contrôle la configuration, les dossiers, puis
   pour chaque université active : tables du schéma de référence, licence, administrateur actif, messagerie. Elle ne
   modifie rien et n'affiche aucun secret. Corriger toutes les erreurs (✘) ; lire les alertes (!).
7. **Répétition** : `php artisan gesnotes:repetition-bascule` copie chaque base (et celle des espaces) sur le serveur
   MySQL sous le nom `<base>_repetition`, branche l'application sur ces copies le temps de la commande, y déroule
   completer-schema, reparer-passages, separer-espaces (appliqués aux copies), copier-fichiers (constat) et
   verifier-bascule, contrôle les chiffres de l'année active (admis + redoublants = inscrits), puis supprime les
   copies (`--garder` pour les conserver et dérouler à la main les essais du §3 avec `UNI_<CODE>_DB=<base>_repetition`).
   Les bases d'origine sont seulement lues.

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

## 6. Sauvegardes automatiques

Trois types, pour toutes les bases de production (universités actives et base des espaces), dans
`SAUVEGARDES_AUTO_DOSSIER` (défaut `storage/app/private/sauvegardes-auto`), un dossier par sauvegarde avec un
`manifest.json` (fichiers, empreintes SHA-256, position du journal binaire) :

| Type | Contenu | Quand (planification) | Restauration |
|---|---|---|---|
| complète | une copie `mysqldump` par base | dimanche 2 h | complète seule |
| différentielle | journaux binaires depuis la dernière complète | lundi à samedi 2 h | complète + dernière différentielle |
| incrémentielle | journaux binaires depuis la dernière sauvegarde | chaque heure, 7 h – 20 h | complète + différentielle + incrémentielles suivantes |

La planification (`gesnotes:sauvegarder --auto`, toutes les 5 minutes) fait la sauvegarde **due** : un créneau
manqué (ordinateur éteint ou en veille, MySQL arrêté) est rattrapé dès que possible, sans attendre le suivant ; une
chaîne de journaux rompue repart d'une complète. MySQL injoignable : la panne est notée (`--liste`) et signalée par
e-mail à son retour (pendant la panne, l'e-mail est impossible : ses réglages sont en base).

Conservation : les 5 dernières complètes (`SAUVEGARDES_AUTO_GARDER`) avec leurs différentielles et
incrémentielles, la première complète de chacun des 12 derniers mois (`SAUVEGARDES_AUTO_GARDER_MOIS`), les archives
sans limite ; suppression après chaque complète.

### Mise en place (une fois)

1. **Journal binaire** — dans `C:\xampp00\mysql\bin\my.ini`, section `[mysqld]` (`server-id=1` y est déjà), puis
   redémarrer MySQL depuis XAMPP ; vérifier avec `SELECT @@log_bin;` (1) :
   ```
   log_bin=mysql-bin
   binlog_format=ROW
   expire_logs_days=21
   ```
   Sans journal binaire, seules les complètes fonctionnent (les autres échouent avec un message clair).
2. **Compte MySQL de sauvegarde** (mot de passe fort, jamais versionné) :
   ```sql
   CREATE USER 'sauvegarde'@'localhost' IDENTIFIED BY '…';
   GRANT SELECT, SHOW VIEW, TRIGGER, LOCK TABLES, EVENT, RELOAD, REPLICATION CLIENT ON *.* TO 'sauvegarde'@'localhost';
   ```
3. **`.env`** : `SAUVEGARDES_AUTO=true`, `SAUVEGARDES_AUTO_DB_USERNAME=sauvegarde`, `SAUVEGARDES_AUTO_DB_PASSWORD=…`,
   `SAUVEGARDES_AUTO_ALERTE=<adresse>` (e-mail en cas d'échec, par le serveur SMTP de l'université par défaut), puis
   `php artisan config:cache`.
4. **Premier essai** : `php artisan gesnotes:sauvegarder --type=complete`, puis `--type=incrementielle`.
5. **Planificateur de tâches Windows** (invite de commandes administrateur) : lance le planificateur Laravel chaque
   minute, qui déclenche les sauvegardes dues :
   ```
   schtasks /create /tn "EDUFILE planificateur" /sc minute /mo 1 /ru SYSTEM /tr "C:\xampp00\php\php.exe C:\xampp00\gesnotes-laravel\artisan schedule:run"
   ```
   Sur un portable, autoriser la tâche sur batterie et au réveil (PowerShell administrateur) :
   ```
   Set-ScheduledTask -TaskName "EDUFILE planificateur" -Settings (New-ScheduledTaskSettingsSet -AllowStartIfOnBatteries -DontStopIfGoingOnBatteries -StartWhenAvailable -WakeToRun)
   ```
   Et faire démarrer MySQL avec Windows : panneau XAMPP en administrateur, case « Svc » de MySQL.
   Contrôle : `php artisan schedule:list` ; `php artisan gesnotes:restaurer --liste` signale une sauvegarde en retard.
6. **Copie hors du serveur (Google Drive, chiffrée)** — faite automatiquement après chaque sauvegarde avec
   [rclone](https://rclone.org) (`C:\xampp00\rclone\rclone.exe`) ; rien n'est jamais effacé sur Drive.
   1. Autoriser rclone sur le compte Google (le navigateur s'ouvre ; droit limité aux fichiers créés par rclone) :
      ```
      C:\xampp00\rclone\rclone.exe config create gdrive drive scope=drive.file --config C:\xampp00\rclone\config\rclone.conf
      ```
   2. Remote chiffré par-dessus : `C:\xampp00\rclone\rclone.exe config --config C:\xampp00\rclone\config\rclone.conf`, puis `n` (nouveau), nom `edufile`, type `crypt`,
      remote `gdrive:EDUFILE-sauvegardes`, chiffrement des noms `standard`, des dossiers `true`, mot de passe et
      « salt » saisis (`y`). **Noter les deux mots de passe hors du serveur** : sans eux, les copies sont
      illisibles, même pour nous.
   3. Ranger la configuration dans un dossier réservé à SYSTEM et aux administrateurs (rclone réécrit le
      fichier à chaque renouvellement de l'accès : c'est le DOSSIER qui doit être protégé), en administrateur :
      ```
      mkdir C:\xampp00\rclone\config
      move C:\xampp00\rclone\rclone.conf C:\xampp00\rclone\config\rclone.conf
      icacls C:\xampp00\rclone\config /inheritance:r /grant:r *S-1-5-18:(OI)(CI)F *S-1-5-32-544:(OI)(CI)F
      icacls C:\xampp00\rclone\config\rclone.conf /reset
      ```
      Les commandes rclone et `gesnotes:copier-*` lancées à la main se font alors en invite administrateur.
   4. `.env` : `SAUVEGARDES_HORS_SERVEUR_RCLONE=C:\xampp00\rclone\rclone.exe`, `SAUVEGARDES_HORS_SERVEUR_CONFIG=C:\xampp00\rclone\config\rclone.conf`,
      `SAUVEGARDES_HORS_SERVEUR_DESTINATION=edufile:` ; essai : `php artisan gesnotes:copier-hors-serveur`.
7. **Copie mensuelle sur clé USB** (débranchée ensuite : hors de portée d'un piratage du serveur ou du compte
   Google), chiffrée avec les mêmes mots de passe que Drive. Une fois, en administrateur, créer le remote « usb »
   en copiant les réglages de chiffrement de « edufile » (aucun mot de passe à retaper) : lancer
   `C:\xampp00\rclone\rclone.exe config --config C:\xampp00\rclone\config\rclone.conf`, puis `c` (Copy remote),
   remote `edufile`, nouveau nom `usb`, puis `q`. (Le dossier de la clé est fixé à chaque copie.)
   Puis chaque mois (un rappel par e-mail arrive le lundi quand la dernière copie a plus de 28 jours) : brancher
   la clé, puis en administrateur `php artisan gesnotes:copier-usb E:` (lettre de la clé). La copie va dans
   `E:\EDUFILE-sauvegardes`, n'efface rien sur la clé, puis tout est relu et comparé aux originaux. Éjecter la clé
   et la ranger hors du bâtiment du serveur. Utiliser au moins deux clés en alternance.

### Utilisation

- État : `php artisan gesnotes:restaurer --liste` (sauvegardes, statuts, erreurs, dernière copie hors du serveur) ;
  `etat.json` dans le dossier.
- Fin d'année, après les délibérations : `php artisan gesnotes:sauvegarder --type=complete --archive` (jamais supprimée).
- Avant une opération risquée (`--appliquer`, mise à jour) : `php artisan gesnotes:sauvegarder --type=incrementielle`.

### Restaurer

Toujours d'abord le plan (rien n'est modifié), puis `--appliquer` :

```
php artisan gesnotes:restaurer --universite=udh
php artisan gesnotes:restaurer --universite=udh --appliquer
```

- Par défaut la base est restaurée **à côté** (`<base>_restauration`) : on vérifie, on récupère ce qu'il faut.
- `--jusqua="2026-10-12 14:30"` : état à cette heure (ex. juste avant une erreur de saisie).
- `--journaux-serveur` : ajoute les modifications pas encore sauvegardées, si le serveur MySQL est intact.
- `--espaces` : base des espaces (ministère, ANAB) au lieu d'une université.
- `--remplacer` : restaure dans la base de production elle-même — mettre d'abord le site en maintenance
  (`php artisan down`), puis `php artisan up` ; une nouvelle complète est faite aussitôt.

**Serveur perdu** : réinstaller l'application, MySQL et rclone, remettre `rclone.conf` (ou le recréer avec le même
compte Google et les deux mots de passe notés), puis `php artisan gesnotes:copier-hors-serveur --recuperer` (ou
`gesnotes:copier-usb E: --recuperer` depuis une clé) avant `gesnotes:restaurer`.

Les empreintes de tous les fichiers sont contrôlées avant la restauration ; une chaîne de journaux incomplète est
refusée plutôt que de produire une base incomplète. Faire un essai de restauration à côté chaque mois.
