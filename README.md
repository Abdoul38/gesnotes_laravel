# EDUFILE / GesNotes — version Laravel

Conversion progressive de l'application GesNotes (`C:\xampp00\htdocs\gesnotes`) vers Laravel 12.
L'ancienne application reste en service pendant toute la migration : **les deux utilisent les
mêmes bases de données**, sans modification de leur structure.

L'inventaire complet de ce qui doit être converti (pages, règles de calcul, tables, anomalies)
se trouve dans l'ancien dépôt : `docs/laravel/INVENTAIRE.md`.

## Installation locale

```bash
composer install
cp .env.example .env        # puis renseigner DB_*, LICENCE_SECRET, UNI_<CODE>_DB
php artisan key:generate
php artisan serve --host=127.0.0.1 --port=8010
```

Ouvrir `http://udh.localhost:8010` (le sous-domaine choisit l'université).
Le port 8000 est souvent déjà pris par `udh_site`.

## Architecture

| Élément | Où | Rôle |
|---|---|---|
| Registre des universités | `config/universites.php` | une base MySQL par université ; aucun mot de passe dans le code |
| Choix de l'université | `App\Http\Middleware\ResoudreUniversite`, `App\Universites\*` | sous-domaine → base (mêmes règles que l'ancien `config/loader.php`) |
| Connexion à la base | connexion `universite` (`config/database.php`) | `strict => null` : le mode SQL du serveur n'est pas modifié, comme avant |
| Authentification | `App\Models\Utilisateur`, `Auth\ConnexionController` | table `utilisateurs` existante, mots de passe existants acceptés |
| Rôles et permissions | `App\Autorisations\Roles`, `Utilisateur::autorise()`, middleware `permission:` | portage de `hasRole()` / `hasPermission()` ; tables `role_permissions` |
| Menu | `config/menu.php` (généré depuis l'ancien `menu_items()`), `App\Navigation\Menu` | page migrée → route Laravel du même nom ; sinon lien vers l'ancienne application |
| Session | `App\Http\Middleware\SessionActive` | 30 min d'inactivité, session liée à une université, cantonnement étudiant / recteur |
| Licence | `App\Services\Licence`, middleware `licence` | même algorithme : les licences déjà émises restent valides |
| Paramètres | `App\Services\Parametres` | `valeur()` = ancien `param()`, `brut()` = ancien `getParam()` |
| Journaux | `App\Services\Journal` | écrit dans `journal_audit` et `audit_log`, comme avant |
| Fichiers déposés | `FichierController`, route `/fichiers/...` | servis par l'application, plus directement par le serveur web |
| Sécurité | `App\Http\Middleware\EntetesSecurite` | CSP stricte sans script en ligne (`script-src 'self'`) |

Sessions, cache et files d'attente sont en **fichiers / synchrone** : Laravel ne crée aucune table
dans les bases des universités.

## Tests

Les tests n'utilisent que deux bases de test, créées depuis le schéma de référence
`database/schema/universite-schema.sql` (union des schémas de toutes les bases au 02/10/2026) :

```bash
php artisan gesnotes:preparer-bases-test
php artisan test
```

La commande refuse de recréer une base dont le nom ne contient pas `_test`.

Données de démonstration (base de test uniquement) :

```bash
php artisan gesnotes:preparer-bases-test gesnotes_test_udh
UNI_UDH_DB=gesnotes_test_udh php artisan db:seed --class=DemoSeeder
UNI_UDH_DB=gesnotes_test_udh php artisan serve --port=8011
```

## Avancement

Plan en 13 étapes (section 11 de l'inventaire).

- [x] **Étape 1 — socle** : multi-université, connexion / déconnexion, rôles, permissions, menu,
      session, licence, paramètres, journaux, mise en page, tableau de bord (`accueil`).
  - Tableau de bord vérifié : mêmes chiffres que l'ancien sur les 45 combinaisons
    année × faculté de la base locale.
  - Reste dans l'étape 1 : page de présentation complète (`landing.php`, version provisoire en place),
    mot de passe oublié, espace parents (liens vers l'ancienne application en attendant).
- [x] **Étape 2 — structure académique** : années (`annees`), niveaux (`niveaux`), facultés
      (`facultes`), salles (`salles`), promotions (`promotions`), filières / départements / UE /
      matières (`filieres`, 3 onglets, copie de maquette, volume horaire en masse).
  - Reportés à leur module : emploi du temps, planning des examens, présence, suivi d'exécution
    (rangés dans `pages/structure/` mais liés aux examens et aux notes).
  - Non repris : action `creer_promo` de `promotions.php`, appelée par aucun formulaire.
- [x] **Étape 3 — étudiants et enseignants** : liste (`etudiants`), fiche (`etudiant_form`), dossier
      (`dossier_etudiant`), API de recherche, cartes (`carte_etudiant`, 3 modèles), import des photos
      (`import_photos`), import sans inscription (`etudiant_importer`), import avec inscription
      (`import_etudiants`), génération des comptes (`generer_comptes_etudiants`), enseignants et
      affectations (`enseignants`).
  - Service unique des matricules (`App\Services\Matricules`, format 00001), lecteur de tableurs
    commun (`App\Support\Tableur`).
  - Reporté à l'étape des portails : `portail_enseignant` (espace de l'enseignant lui-même).
- [x] **Étape 4 — inscriptions** : inscription scolarité (`inscription_scolarite`), pédagogique en
      masse (`inscription_ped_masse`), choix des options (`choix_options`), transfert et passage
      individuel (`transfert_niveau`), passage en masse (`passage_niveau`), listes des inscrits
      (`liste_inscrits`, PDF, Excel).
  - Service commun `App\Services\Inscriptions` (promotion + maquette, modes de paiement, règles de
    suggestion des semestres et de décision de passage).
  - `inscription_pedagogique` portée au début de l'étape 5 (avec le moteur de notes) ; reportée à l'étape 5 :
    `liste_valides` (règles de validation par année).
  - Reportés à l'étape 10 (portail candidats) : `inscription_en_ligne`, `demandes_inscription`.
    Non repris : `inscription.php`, absente du routeur (code mort).
- [x] **Étape 5 — notes et PV** : moteur de résultats (`App\Services\Resultats`, comparé à l'ancien code :
      1 234 opérations, 0 différence — `outils/comparaison-resultats/`), inscription pédagogique
      (`inscription_pedagogique`), liste des PV (`pv`), création (`pv_creer`), détail et saisie
      (`pv_detail`), accès rapide (`notes`), import des notes (`notes_importer`), export Excel
      (`pv_exporter`), PV imprimable (`pv_imprimer`), délibération (`deliberation`), relevé de notes
      (`releve_notes`), relevés individuels (`releves`), vérification (`verif_resultats`), liste des
      admis (`liste_valides`), réclamations (`reclamations`, `reclamation_traitement`,
      `reclamations_export`).
  - Une seule définition des étudiants d'un PV (`App\Services\Pv`) et une seule source des résultats :
    ceux enregistrés par le moteur. Toutes les pages qui « recalculaient en direct » avec leurs
    propres règles (détail, export, impression, délibération, relevés, liste des admis) affichent
    désormais ces résultats ; la simulation pendant la saisie passe par le moteur lui-même.
  - Reporté à l'étape 8 (communication) : `notifier_pv` et l'envoi automatique à la publication
    (l'événement `App\Events\PvPublie` est déjà émis).
  - Le relevé côté étudiant (PV publié seulement) est prêt ; il s'ouvrira avec le portail étudiant.
  - Non repris : `notes_pv/resultats.php`, sans appelant (doublon de `api/resultats.php`, qui
    recalculait sur un simple GET).
- [x] **Étape 6 — documents** : vérification publique (`verifier_diplome`), attestation de réussite par PV
      (`attestation`), attestations de validation / licence / Master (`attestations`), diplômes (`diplome`),
      liste des éligibles (`diplomes`), registre (`registre_diplomes`), certificat de scolarité
      (`certificat_scolarite`), convocations (`convocations`).
  - Services `App\Services\Parcours` (semestres validés par cycle, moyenne et mention d'un document : une
    seule règle pour la liste et les documents) et `App\Services\RegistreDocuments` (numéros sous verrou).
  - Les QR codes des cartes, relevés et documents pointent maintenant vers `/verification`.
- [x] **Étape 7 — finances** : paiements (`paiements`, export CSV), reçus (`recu_paiement`, `recu_inscription`),
      finances (`finances` : aperçu, grille tarifaire, dépenses), tarifs d'inscription en ligne (`tarifs_niveaux`),
      paiements en ligne (`paiements_en_ligne`, lecture seule).
  - Service `App\Services\Paiements` (types, modes, statut calculé, périmètre).
  - Reporté à l'étape 8 (envoi d'e-mails) : `relances`. Reporté à l'étape 10 (portail candidats, où vivent les
    paiements KomiPay) : `komipay_litiges` et le client KomiPay — l'ancienne page de litiges ne trouvait jamais rien
    (elle cherche un mode « komipay » et une colonne `reference_komipay` absents de la table `paiements`).
  - Non repris : `depenses.php` et `grille_tarifaire.php` (absents de la liste des pages autorisées du routeur :
    inaccessibles, remplacés par `finances`) ; `pages/finances/komipay*.php` (fichiers de configuration, pas des pages).
  - Exports XLSX : fichier temporaire non préexistant (l'export échouait au hasard sous Windows,
    « Renaming temporary file failed »).
- [x] **Étape 8 — communication** : configuration e-mail et SMS (`parametres_email`, `parametres_sms`),
      notification d'un PV (`notifier_pv`) et notification automatique à la publication, relances des impayés
      (`relances`), annonces (`annonces`), messagerie (`messagerie`), e-mails aux étudiants (`notifications_email`),
      vue d'ensemble (`dashboard_notifications`).
  - Services `App\Services\Communication\{Courriel, Sms, Notifications}` ; écouteur `App\Listeners\NotifierPublicationPv`.
  - `Perimetre::limiterEtudiants()` : étudiants de la faculté (ou du département pour un chef de département).
- [x] **Étape 9 — portails** : portail étudiant (`portail_etudiant`), espace parents (`login_parent`,
      `portail_parent`) et comptes parents (`gestion_parents`), portail enseignant (`portail_enseignant`),
      tableau de bord du rectorat (`rectorat`).
  - Garde d'authentification `parent` (modèle `ParentEleve`, table `parents`), middleware `parent`.
  - Service `App\Services\SaisieNotes` : enregistrement des notes commun à l'import de la scolarité et au portail
    enseignant (bornes, rattrapage, lecture des fichiers) — une seule règle.
  - Service `App\Services\IndicateursRectorat` : indicateurs d'une année, en cache 5 min par université
    (réutilisable pour le tableau de bord du ministère, étape 12).
- [x] **Étape 10 — portail candidats** (sous `/candidature`) : accueil, comptes, dépôt du dossier en 4 étapes, mes
      dossiers, profil, quitus ; inscription académique en ligne avec paiement KomiPay (MyNita, Amana ; Mobile Money et
      carte si activés), reçu ; côté GesNotes : administration des candidatures (`portail_candidats_admin`,
      `dossier_candidat`, `portail_export`), litiges KomiPay (`komipay_litiges`), demandes d'inscription
      (`demandes_inscription`).
  - Garde `candidat` (modèle `Candidat`, table `candidats`), middleware `candidat` ; services `Candidatures`,
    `InscriptionEnLigne`, `Paiement\KomiPay` (identifiants dans `.env` : `KOMIPAY_*`, voir `.env.example`).
  - Non repris : `candidature/inscription.php` et `pages/inscriptions/inscription_en_ligne.php` (copies plus anciennes
    de la page d'inscription, la seconde ne pouvait pas s'exécuter), `candidature/pages/accueil_portail*.php`,
    `database.php`, `komipay_litiges.php` (hors routeur), `candidature/test_*.php` (scripts de
    diagnostic KomiPay, en ligne de commande seulement), `ajax_check_inscription.php`
    (renvoyait nom et accès au reçu de n'importe quel matricule), l'administration à mot de passe partagé du portail
    (`candidature/pages/admin.php`, `admin_export.php`, remplacée par `portail_candidats_admin`) et `portail_admin`
    (ancien portail, tables `portail_dossiers` / `portail_paiements`, hors menu — voir « Décisions »).
- [x] **Étape 11 — API mobile** : `api_mobile.php?action=…` (même adresse, mêmes 17 actions, même format
      `{success, message, data}`), aussi sous `/api/mobile`. Routes `routes/api_mobile.php`, sans session (jeton Bearer).
  - Version reproduite : `pages/api/api_mobile.php` (réinitialisation par code SMS/e-mail), celle qu'appelle
    l'application `MOBILE/` du dépôt ; l'ancien fichier racine n'a pas été modifié.
  - Non repris : `api/notes.php`, `api/promotions.php`, `api/resultats.php`, `api/stats.php`, `api/test_session.php`
    (aucune page ne les appelle) ; `ajax_recalcul_queue` est devenu `pv_detail.file` (étape 5).
- [x] **Pages hors étapes** (portées avant les étapes 12 et 13) :
  - Rapports et suivi : statistiques (`stats`), historique académique (`historique`), rapports (`rapports`,
    `rapports_export`), suivi d'exécution (`suivi_execution`).
  - Examens et cours : présence (`presence`, avec `api_matieres_promotion`), planning des examens (`planning`),
    emploi du temps (`emploi_temps`).
  - Administration : utilisateurs (`utilisateurs`), rôles (`roles`), signataires (`signataires`), paramètres
    (`parametres`), licence (`parametres_licence`, adresse `/licence`, ouverte même licence invalide), sauvegarde
    (`sauvegarde`), journaux (`logs`).
  - Non repris : `utilisateur_form` (nom autorisé par l'ancien routeur, sans fichier).
- [x] **Étape 12 — Ministère et ANAB** : espaces servis sur leur sous-domaine (`ministere.<domaine>`, `anab.<domaine>`),
      routes `routes/espaces.php` (`/ministere`, `/anab`). Ministère : tableau de bord national (vue nationale, évolution,
      comparaison et filières équivalentes, détail université → faculté → filière, PV, journal), export Excel, rapport et
      PV imprimables. ANAB : étudiants éligibles (S1–S4 validés, licence et DUT) et dossier. Pour les deux : connexion,
      mot de passe, gestion des comptes (administrateurs).
  - Gardes `mesr` et `anab` (modèles `Espaces\CompteMesr`, `Espaces\CompteAnab`, tables existantes `mesr_comptes`,
    `anab_comptes`, dans la base de `ESPACES_UNIVERSITE_COMPTES`, UDH par défaut), middleware `espace` / `espace:admin`.
  - Service `IndicateursNationaux` : lit chaque université tour à tour (`UniversiteCourante::pour`) avec le calcul du
    rectorat (étape 9), en cache 5 min par université ; une base injoignable est « hors ligne » sans bloquer les autres.
  - Non repris : la table `mesr_cache` (cache de l'application à la place).
- [ ] Étape 13 (bascule)

## Comportements volontairement modifiés

| Avant | Maintenant | Pourquoi |
|---|---|---|
| Rôle et faculté gardés en session jusqu'à la déconnexion | relus à chaque requête | un changement de rôle s'applique tout de suite |
| Compte désactivé : reste connecté | déconnecté à la requête suivante | sécurité |
| `?uni=xxx` change d'université partout | seulement en local (`UNIVERSITES_PARAMETRE_UNI`) | une session d'une université pouvait lire la base d'une autre |
| Même session valable sur toutes les universités | session liée à l'université de connexion | même raison |
| Déconnexion par lien (GET) | bouton (POST + jeton CSRF) | un lien piégé ne peut plus déconnecter |
| Logos servis directement par Apache | route `/fichiers/logos/...` (nom de fichier contrôlé) | préparation du contrôle d'accès des fichiers |
| Service worker sous `/assets/sw.js` (portée `/assets/` seulement) | `/sw.js`, jamais de cache pour `/fichiers/` | il ne contrôlait pas les pages ; les photos ne doivent pas rester en cache |
| Chart.js chargé depuis un CDN | copie locale (même version 4.4.0) | CSP stricte |
| Message « N tentative(s) restante(s) » | supprimé | n'aidait qu'un attaquant |
| Messages flash insérés en HTML (`Toast.show`) | texte seul (corrigé aussi dans l'ancienne application) | un message contenant une saisie pouvait injecter du HTML |
| **Étape 2** | | |
| Actions des pages structure : n'importe quel identifiant accepté | l'élément visé doit appartenir à la faculté de l'utilisateur (`App\Autorisations\Perimetre`) | un compte d'une faculté pouvait modifier les autres |
| Suppression d'une année : seul le bouton masqué s'il reste des promotions | refusée par le serveur | la cascade effaçait promotions, inscriptions pédagogiques, emploi du temps |
| Suppression d'un niveau : contrôle des promotions seulement | aussi inscriptions et tarifs | tarifs effacés et inscriptions vidées en cascade |
| Suppression d'une faculté : contrôle des agents seulement | aussi filières et départements | départements effacés en cascade, filières orphelines |
| Suppression d'une filière : aucun contrôle | refusée s'il reste promotions ou étudiants | promotions, inscriptions, PV, notes, tarifs effacés en cascade |
| Suppression d'une UE : matières actives seulement | aussi notes et résultats (matières désactivées comprises) | les notes étaient effacées en cascade |
| Département créé avec un code existant d'une autre faculté : celui-ci était écrasé | refusé avec message | le code est unique dans toute l'université |
| Matière ré-ajoutée avec le code d'une matière supprimée : restait invisible | réactivée | le message « ajoutée » était trompeur |
| Volume horaire en masse : toute l'université | limité à la faculté si le compte en a une | périmètre |
| Promotions : générer / copier sur toutes les facultés | limité à la faculté de l'utilisateur | périmètre |
| Logo de faculté : nom de fichier tiré du code saisi, logo actuel tiré d'un champ caché | nom nettoyé, logo relu en base, image vérifiée | écriture hors du dossier, chemin arbitraire |
| Couleur de faculté libre | `#RRGGBB` uniquement | insérée dans des styles CSS |
| Suppression d'une salle : bouton admin, serveur ouvert | admin aussi côté serveur | |
| Bouton « Modifier » des salles (onclick en ligne bloqué par la CSP) | fonctionne | |
| Copie de maquette : matières lues par GROUP_CONCAT (1 024 caractères) | requête normale | une UE riche en matières était copiée en partie |
| Champ texte « code couleur » des facultés | retiré | ignoré par le serveur |
| **Étape 3** | | |
| Matricules calculés à 9 endroits, « UDH2026… » depuis la fiche | un seul service, 00001…, sous verrou | doublons lors d'inscriptions simultanées, formats mélangés |
| Suppression d'un étudiant : effacement en cascade | refusée s'il a un historique (décision de l'université) | inscriptions, notes, paiements effacés |
| Bouton « Supprimer matricule 0 » | retiré (décision de l'université) | effaçait aussi les étudiants pas encore inscrits |
| Recherche d'étudiants (API, espace parents) | corrigée (aussi dans l'ancienne application) | paramètre répété refusé par PDO : la recherche échouait |
| Photos, pièces, cachet, signature, photos d'enseignants accessibles par simple URL (`uploads/`, `assets/img/`) | routes réservées au personnel, `Cache-Control: private, no-store` | données personnelles accessibles par URL |
| Documents du dossier : extension seule vérifiée | signature du fichier vérifiée (PDF, Word, image) | un fichier quelconque pouvait être déposé |
| Cartes : numéro = nombre de cartes + 1 | plus grand numéro + 1, sous verrou | après une suppression, numéro déjà pris : impression en erreur |
| Cartes : nationalité toujours « Nigérienne » | nationalité de l'étudiant | |
| Import des photos : extension seule, ZIP lu sans limite | image vérifiée, extension du type réel, taille contrôlée avant décompression | fichier piégé, « bombe ZIP » |
| Import des photos : correspondance partielle « premier venu » | ignorée si plusieurs étudiants correspondent | photo posée sur la fiche d'un autre |
| Import Portail : colonnes à positions fixes | repérées par leurs en-têtes | l'export actuel décalait adresse et parents dans les champs du bac ; en .xlsx les 3 premiers candidats étaient perdus |
| Import CSV : ligne d'en-têtes | sautée (sans inscription) / repérée par sa colonne « Nom » (avec inscription) | importée comme un étudiant « NOM », ou toutes les lignes rejetées |
| Import : sexe absent → « M » ; année du bac ou mode « MyNITA » invalides → ligne en échec | sexe vide ; valeurs invalides laissées vides ; MyNITA → Mobile Money | |
| Import : dates numériques d'Excel, 31/02 | lues ; date impossible refusée | 31/02 devenait le 3 mars |
| Onglets « Ajouter manuellement » et « Format CSV » de l'import | retirés | n'ouvraient aucun panneau |
| Comptes étudiants : étudiant sans matricule | signalé en erreur | compte créé avec un mot de passe vide |
| Comptes étudiants : `@udh.ne` pour toutes les universités | `@<code>.ne` (inchangé pour l'UDH) | |
| Enseignants : affectation / retrait / reconduction par un agent de faculté | limités à sa faculté | il touchait les matières et enseignants des autres facultés |
| Suppression d'un enseignant : affectations effacées en cascade | refusée s'il a affectations, séances ou compte | même règle que pour les étudiants |
| **Étape 4** | | |
| Inscription : matricule `MAX + 1` sans verrou, enregistrements séparés | service des matricules, inscription + paiement en une transaction | doublons, inscriptions à moitié enregistrées |
| Modes de paiement « MyNITA », « Amana » proposés | enregistrés comme Mobile Money | absents de la base : l'inscription échouait |
| Modification d'une inscription | refusée si doublon niveau × année ; limitée à la faculté ; maquette copiée si promotion créée ; filière de l'étudiant changée seulement pour la dernière inscription | doublons, périmètre, promotions sans UE |
| Inscription pédagogique en masse | fonctionne (scripts déplacés dans un fichier) | la CSP bloquait ses scripts : rien n'était enregistré |
| Suggestion des semestres : deux versions | une seule (celle de la page individuelle) | la version « masse » suggérait S1 + S2 à un licencié |
| Choix des options : tout décocher | retire les choix | rien n'était envoyé, les anciens choix restaient |
| Choix des options : UE, étudiants, promotion | vérifiés (semestre, inscription pédagogique, faculté) | n'importe quel identifiant accepté |
| Décision de passage : 4 règles différentes | une seule (`Inscriptions::decisionPassage`) | licence validée enregistrée « redoublement », LP « validée » dès S1 + S2, IUT ignoré en individuel |
| Passage en masse : n'importe quel identifiant d'étudiant | seulement les inscrits de la promotion choisie | |
| Spécialisation L3 / LP choisie | doit faire partie des filières proposées | |
| Transfert, passage : aucun contrôle de faculté | limités à la faculté de l'agent | |
| Listes PDF et Excel : pas de filtre de faculté | même périmètre que la page | un agent obtenait les listes de toute l'université |
| Excel : matricule « 00012 » écrit comme le nombre 12 | texte, zéros conservés | |
| Excel : noms de feuilles coupés à 31 caractères, parfois identiques ; nom « Université Djibo Hamani » écrit en dur | noms uniques ; nom de l'université tiré des paramètres | fichier refusé par Excel ; mauvais nom pour les autres universités |

| **Étape 5** | | |
| Pages qui recalculaient les résultats à l'affichage (détail du PV en PHP puis en JS, export avec note éliminatoire 5/20 en dur, impression, délibération, relevés, liste des admis) | résultats enregistrés par le moteur partout ; simulation par le moteur pendant la saisie | l'écran, l'Excel et le PV imprimé pouvaient donner des décisions différentes |
| Délibération et relevés : options non choisies comptées comme manquantes | ignorées (comme le moteur) | un étudiant sans l'option était déclaré défaillant |
| Relevé individuel (aperçu) : compensation ignorée | observation du moteur (AC affiché) | une matière compensée apparaissait NV |
| Quatre définitions des étudiants d'un PV | une seule (`App\Services\Pv`) | le recalcul rapide créait des résultats pour des étudiants qui ne composaient pas |
| PV imprimé, session normale : promotion exacte seulement | mêmes étudiants que le détail | ceux qui composaient le semestre en avance étaient omis |
| Verrou du PV appliqué à l'affichage seulement | refusé aussi par le serveur (saisie, import, actions, correction) | un envoi direct modifiait un PV verrouillé |
| Saisie : n'importe quel étudiant / matière, notes non bornées | étudiants et matières du PV, notes 0 … maximum, matières acquises protégées | |
| Recalcul progressif : sans jeton CSRF, ouvert à tout enseignant | jeton et droits de modification exigés | |
| Suppression d'un PV : effacement en cascade | seulement un brouillon non verrouillé | notes et résultats effacés |
| Statut / titre d'un PV : n'importe quel identifiant | PV du périmètre de l'agent | |
| Avancement de saisie : 5 (liste) ou 2 (accès rapide) matières supposées | nombre réel de matières | |
| Import par matière : résultats non recalculés ; enseignant pouvait importer le PV complet | recalcul ; enseignant limité à sa matière | |
| Import : notes au-dessus du maximum acceptées ; absent au rattrapage = 0 (import complet) ou vide (par matière) | refusées ; absent = note vide, matière défaillante, pour les deux | |
| Inscription pédagogique : toujours l'année active | année de l'inscription scolarité retenue | un étudiant inscrit une année précédente était rattaché à la mauvaise année |
| Création de PV : PV de 1ère session / PV source quelconque | même promotion, même semestre, bon type | |
| Délibération, liste des admis, relevés : chef de département voyait toute la faculté | son département | |
| Relevé de notes : enseignant voyait n'importe quel étudiant ; étudiant voyait un PV non publié | enseignant : PV où il a une matière ; étudiant : PV publié | résultats lus avant la délibération |
| Numéro de relevé : nombre de relevés + 1 | plus grand numéro + 1, sous verrou | doublons |
| Vérification des résultats : correction par lien (GET) | bouton (POST + CSRF), journalisée, refusée sur PV verrouillé | une image piégée pouvait lancer la correction |
| Réclamations : une réclamation traitée pouvait l'être à nouveau par envoi direct | refusé | |
| Réclamation fondée, examen seul corrigé : CC effacé, examen seul retenu | CC existant conservé ; notes bornées | note finale fausse |
| Réclamations : enseignant voyait toutes celles de l'université | seulement ses matières | |
| Export des réclamations : toute l'université ; matières homonymes fusionnées | faculté / département de l'agent ; une feuille par matière | |
| « Tahoua », « République du Niger », nom de l'université écrits en dur (dates, en-têtes) | paramètres de l'université | |

| **Étape 6** | | |
| Vérification publique : aucune limite | 30 vérifications / 10 min par adresse IP, numéro contrôlé, page exclue des moteurs de recherche | les numéros se suivent : un robot pouvait relever nom, mention et moyenne de tous les étudiants |
| Attestation de réussite individuelle : « Admis » imprimé pour n'importe quel étudiant (identifiant dans l'adresse) | refusée si l'étudiant n'est pas admis dans le PV | fausses attestations |
| Diplôme : aucun contrôle de rôle ; Master « manuel » créé ou réécrit par un simple lien GET, puis déclaré « authentique » par la vérification publique | rôles des documents officiels ; formulaire POST (CSRF) contrôlé ; refusé si des résultats de Master existent ; un diplôme enregistré n'est jamais réécrit | faux diplômes vérifiables |
| Certificat par inscription : enregistré sous l'étudiant n° 0 | enregistré sous l'étudiant | tous ces certificats partageaient un numéro ; la vérification affichait le nom du dernier imprimé |
| Certificat pour une inscription annulée | refusé | |
| Attestations, diplômes, certificats, registre, éligibles : aucun contrôle de faculté | étudiants de la faculté de l'agent (filière ou inscription) | |
| Attestations et diplômes : revérification « en direct » avec une règle de défaillance propre ; liste et documents ne choisissaient pas le même semestre | résultats enregistrés ; un seul choix (session normale, puis année la plus récente) | moyenne de la liste différente de celle du document |
| Diplôme de Licence complété par des semestres de Master ; diplôme de DUT pour tout étudiant ayant 4 semestres | cycles séparés ; DUT seulement après DUT1 / DUT2 | |
| Mentions des documents : 16 / 14 / 12 en dur | seuils de l'université (`mention_*`, mêmes valeurs par défaut) | |
| Numéros d'attestation, de diplôme, de certificat : nombre + 1, sans verrou | plus grand numéro + 1, sous verrou | doublons, impression en erreur |
| Convocations : en rattrapage, tous les inscrits ; individuelle pour n'importe quel identifiant | étudiants du PV (sans les admis de 1ère session) | |
| Registre : tout chargé en une page | pagination (50) | |
| Avertissement « aucun visa configuré » imprimé sur le diplôme | affiché à l'écran seulement | |
| Certificat : numéro d'enregistrement non visible | imprimé en pied de page | vérification possible sans scanner le QR |

| **Étape 7** | | |
| Paiements : ajout, modification, suppression de n'importe quel paiement / étudiant | étudiants de la faculté de l'agent | |
| Paiements, dépenses, tarifs : type, mode, montant (négatif accepté), date, longueurs non contrôlés | contrôlés ; montants plafonnés à 100 millions | erreurs SQL, montants négatifs |
| Modification d'un paiement, d'un tarif, d'une dépense (JavaScript en ligne bloqué par la CSP) | fonctionne | |
| Export CSV : une cellule commençant par `=`, `+`, `-`, `@` exécutée par Excel | préfixée d'une apostrophe | injection de formules |
| Suppression d'un paiement : journal sans montant | montant, statut et étudiant journalisés | traçabilité |
| Recettes d'un agent de faculté : jointure sur les inscriptions | sous-requête | un paiement comptait autant de fois que l'étudiant avait d'inscriptions dans la faculté |
| Dépenses et catégories (communes à l'université) modifiables par un agent de faculté | comptes sans restriction de faculté | |
| Tarifs de la grille : filière d'une autre faculté acceptée | filières de la faculté de l'agent | |
| Tarifs d'inscription en ligne (toute l'université) modifiables par un agent de faculté ; montant invalide ignoré en silence | comptes sans restriction de faculté ; tout ou rien, journalisé | |
| Reçus et paiements en ligne : aucun contrôle de faculté | faculté de l'agent | |
| Reçu d'inscription « officiellement inscrit » pour une inscription annulée ou inexistante ; numéro identique d'une année sur l'autre | inscription en cours exigée ; numéro par inscription | faux reçus |
| Graphiques des finances chargés depuis un CDN (bloqué par la CSP) | Chart.js local | |

| **Étape 8** | | |
| Serveur SMTP et passerelle SMS modifiables par sc_entite, doyen, chef de département | administrateur seulement | détourner le serveur SMTP donnait les liens de réinitialisation de mot de passe de tous les comptes ; la passerelle SMS recevait la clé API |
| Journal des e-mails : échange SMTP complet, authentification comprise | message court, jamais l'échange | le mot de passe SMTP (base64) était enregistré en base |
| Client SMTP fait main : en-têtes non protégés, pas de « dot-stuffing » | Symfony Mailer | injection d'en-têtes et de commandes SMTP |
| Clé API SMS renvoyée en clair dans la page | jamais affichée (vide = conservée) | |
| Passerelle SMS : toute adresse | https obligatoire | |
| Publication d'un PV : notification pendant la requête, renvoyée à chaque republication | après la réponse, une seule fois par PV | délai d'attente dépassé, envois en double |
| « Notifier un PV » : PV non publié accepté, tout rôle avec la permission | PV publié, gestionnaires de PV de son périmètre | |
| Relances : n'importe quel paiement (même soldé, autre faculté) ; le bouton d'une ligne relançait le dernier étudiant (formulaires imbriqués) ; seul l'e-mail du compte, jamais de SMS | impayés de son périmètre ; bonne ligne ; contacts de la fiche ; groupe : pas deux relances en 24 h | |
| Annonces : création, publication, suppression avec la permission de lecture ; toute annonce modifiable par tous ; boutons bloqués par la CSP | permission d'écriture ; ses annonces pour un compte de faculté ; fonctionne | |
| Messagerie : doyen / chef de département lisaient et supprimaient les messages de toute l'université ; noms insérés en HTML dans la recherche | périmètre ; texte seul | injection HTML par un nom de candidat |
| E-mails aux étudiants : `mail()` de PHP (configuration SMTP ignorée) ; « par niveau » en erreur SQL ; « tous » = toute l'université ; nom et contacts de l'UDH en dur | serveur configuré ; réparé ; périmètre ; paramètres de l'université | |
| Vue d'ensemble : parents et étudiants de toute l'université ; liste « jamais notifiés » toujours vide (erreur SQL de collation masquée) | périmètre ; réparée | |
| **Étape 9** | | |
| Portail étudiant : résultats des PV « finalisés » (non publiés) visibles | PV publiés seulement | résultats vus avant délibération |
| Portail étudiant : e-mail et mot de passe modifiables sans le mot de passe actuel | mot de passe actuel exigé ; 8 caractères, lettres et chiffres | session laissée ouverte = compte perdu |
| Espace parents : résultats des PV en brouillon visibles | PV publiés seulement | |
| Comptes parents : un agent de faculté liait n'importe quel étudiant à un compte créé avec son propre numéro, et modifiait ou réinitialisait n'importe quel compte | étudiants et comptes de son périmètre ; délier/désactiver en POST | lecture des notes, paiements et présences de toute l'université |
| Portail enseignant : n'importe quel PV et matière affichés en changeant l'adresse (notes et liste des étudiants), modèle Excel sans contrôle | affectations visibles seulement | fuite de notes d'autres facultés |
| Portail enseignant : import sans contrôle du verrou du PV ; PV d'une autre matière accepté ; n'importe quel identifiant d'étudiant enregistré | PV non verrouillé, de la matière, étudiants du PV | |
| Portail enseignant : notes sans maximum (import : seules les négatives refusées) ; résultats non recalculés | 0 à la note maximale, sinon rien n'est enregistré ; résultats recalculés | |
| Portail enseignant, rattrapage : meilleure note d'examen gardée ; un absent effaçait aussi la note de TD de l'autre enseignant | meilleure note finale (même règle que l'import) ; seule la composante de l'enseignant | |
| Matière finalisée automatiquement dès la première note saisie | quand toutes les notes de la matière sont complètes | l'enseignant de cours verrouillait la matière avant la saisie du TD |
| Volume horaire prévu modifiable par un chef de département ou un doyen sur toute matière | son département / sa faculté | |
| Rectorat : PV en brouillon consultable en changeant `pv_id` | PV finalisés ou publiés | |
| Rectorat : le navigateur pouvait écrire ce qu'il voulait dans le journal des consultations ; exports PDF par html2pdf (CDN, bloqué par la CSP) | exports servis et journalisés par le serveur ; pages imprimables | journal falsifiable |
| Rectorat : message du changement de mot de passe repris de l'adresse ; 8 caractères suffisaient | message en session ; lettres et chiffres exigés | texte arbitraire affiché sur la page |
| Rectorat : cache des indicateurs dans une table relue avec `unserialize()` ; historique de tous les comptes affiché comme « mes consultations » | cache de l'application ; le recteur voit les siennes, l'administrateur toutes | |
| **Étape 10** | | |
| Portail candidats : session partagée avec GesNotes, jamais renouvelée, sans expiration ; connexion sans limite d'essais ; mot de passe de 6 caractères | session propre (garde « candidat »), renouvelée, 30 min ; 5 essais puis 5 min ; 8 caractères, lettres et chiffres | fixation de session, devinette de mots de passe |
| Dossier soumis ou accepté encore modifiable (choix, pièces) puis re-soumis ; nouveau brouillon à chaque visite de la page | seuls brouillon et incomplet modifiables ; création en POST, un seul dossier en cours | changement de filière après acceptation |
| Pièces : extension seule vérifiée, dossier public (quiconque avait le nom les ouvrait) | contenu vérifié, dossier privé, servies au candidat et à l'administration | pièces d'identité exposées |
| Notes internes de l'administration envoyées au navigateur du candidat (détail du dossier en JavaScript) | page rendue par le serveur, sans les notes | |
| Quitus : QR code produit par api.qrserver.com (nom du candidat envoyé à un tiers) ; quitus d'un brouillon ; numéro changeant à chaque affichage | QR produit dans la page ; dossier soumis ; numéro stable | fuite de données personnelles |
| Profil : nouveau mot de passe sans le mot de passe actuel, confirmation différente ignorée | mot de passe actuel exigé, erreur affichée | |
| Administration du portail : un mot de passe unique partagé (`candidature/pages/admin.php`) | comptes du personnel (admin, secrétaire, sc_centrale) | pas de traçabilité, mot de passe partagé |
| Statut d'un dossier : n'importe quelle valeur acceptée ; « Créer l'étudiant » : doublon à chaque clic, matricule « 0 » (2ᵉ fiche en échec), statut « nouveau » et année « 2024-2025 » invalides | statut contrôlé ; une fiche par dossier, matricule vide, données valides | la création échouait |
| Dépôt par l'administration : mot de passe provisoire enregistré en clair dans la note du dossier | affiché une seule fois | |
| Inscription en ligne : saisir le matricule d'un étudiant déjà inscrit ouvrait son reçu ; « déjà inscrit ? » renvoyait le nom de n'importe quel matricule | reçu réservé à la session qui a payé, au candidat propriétaire et à la scolarité ; 30 identifications / 10 min par adresse IP | les matricules se suivent |
| Paiement encaissé pour un étudiant sans filière, inscription jamais créée ; second paiement possible pendant qu'un premier attend | identification refusée avant paiement ; pas de second paiement en attente | argent encaissé sans inscription |
| Matricule « MAX + 1 » sans verrou, inscription créée deux fois en cas de double confirmation ; mode « mynita » / « visa_master » hors liste de la base | sous verrou, une seule fois ; mode valide | doublons, échecs d'enregistrement |
| Vérification KomiPay jusqu'à 5 minutes dans la requête ; réponse complète de KomiPay écrite dans le journal ; identifiants KomiPay en clair dans le dépôt | 25 secondes ; code et message seulement ; identifiants dans `.env` | |
| Reçu : « Université Djibo Hamani de Tahoua », contacts et logos de l'UDH écrits en dur | université courante | portail multi-universités |
| Litiges KomiPay : cherchaient dans `paiements` une colonne absente (page toujours vide) | portent sur les inscriptions payées en ligne ; confirmation = inscription finalisée ; journalisés | |
| Demandes d'inscription : aucune permission, chaque acceptation créait une fiche, inscription sans date (en échec) | gestion des inscriptions ; une fois ; inscription complète | |
| Session du personnel expirée sur une page publique (portail candidats, parents, vérification) : renvoi à la connexion du personnel ; étudiant connecté renvoyé de l'inscription en ligne vers son portail | session du personnel fermée sans gêner la page ; pages publiques ouvertes à tous | |
| **Étape 11** | | |
| API mobile : jetons enregistrés en clair ; jeton accepté dans l'adresse (`?token=`) | empreinte SHA-256 (anciens jetons acceptés jusqu'à expiration) ; en-tête seulement | une copie de la base donnait des sessions ; jetons dans les journaux |
| Résultats et réclamations : PV « finalisés » (non publiés) visibles, résultats de PV en brouillon aussi | PV publiés seulement | même règle que le portail étudiant |
| Photo et cachet servis depuis le dossier public | photo par adresse signée (1 h) ; cachet plus fourni | faux documents |
| « Marquer lu » : tout message portant le même numéro de destinataire ; reçu en erreur (colonne inexistante) ; annonces par niveau jamais affichées | messages adressés à l'étudiant ; réparé ; affichées | |
| Version racine en Latin-1 : réponses JSON vides dès qu'un message contenait un accent | UTF-8 | |
| Mot de passe de 6 caractères ; licence non vérifiée | 8 caractères, lettres et chiffres ; licence vérifiée (réponse JSON) | |
| **Pages hors étapes — rapports, examens et cours** | | |
| Rapports : export, comparatif inter-années, rapport financier et chiffres globaux portant sur toute l'université ; historique de n'importe quel étudiant | limités à la faculté (ou au département) de l'utilisateur | fuite de données entre facultés |
| Export CSV des rapports : cellules recopiées telles quelles ; en-tête « UDH » | protégé contre les formules (=, +, -, @), journalisé ; université courante | injection de formules dans Excel |
| Statistiques : graphiques jamais affichés (CDN bloqué par la CSP, éléments inexistants) | Chart.js servi localement | |
| Présence : tout compte autorisé créait des séances et modifiait les présences de toute l'université ; étudiants hors promotion enregistrables | périmètre de chacun (faculté, département, matières affectées) ; inscrits de la promotion seulement | |
| Planning des examens : salle déjà occupée seulement signalée ; aucune façon de retirer une matière ; logos UDH/FADEG en dur | refusée à l'enregistrement ; date vidée = ligne retirée ; logos de l'université et de la faculté | doubles réservations |
| Emploi du temps : tout compte en lecture ajoutait, modifiait ou supprimait des séances de n'importe quelle promotion ; couleur insérée telle quelle dans les styles | personnel de scolarité, dans sa faculté ; couleur #rrggbb, matière/salle/enseignant contrôlés, salle occupée refusée | |
| Suivi d'exécution : un chef de département corrigeait les heures de toute matière | celles de son département | |
| **Pages hors étapes — administration** | | |
| Utilisateurs : rôle, faculté, département et liaisons non vérifiés ; un agent « sc_entite », un doyen ou un chef de département pouvait être créé sans faculté (il voyait alors toutes les facultés) ; mot de passe quelconque ; dernier administrateur supprimable ou rétrogradable ; création en échec (`prenoms` absent) | vérifiés ; faculté obligatoire pour ces rôles, département pour le chef ; 8 caractères, lettres et chiffres ; dernier administrateur et propre rôle protégés ; actions journalisées | élévation de périmètre |
| Rôles : n'importe quel nom de rôle et de permission enregistré ; message affiché en HTML brut | rôle et permissions vérifiés contre leurs listes ; enregistrement atomique, journalisé | |
| Signataires : un agent ne voyait que sa faculté mais pouvait ajouter, modifier, activer ou supprimer les signataires de toutes | limité à sa faculté | signature de documents d'une autre faculté |
| Paramètres « Général » : toute clé envoyée était enregistrée (licence, SMTP, KomiPay…) ; page ouverte à tout détenteur de `inscription_ped` (agents de faculté compris) | clés de l'institution seulement ; administrateur et secrétariat (voir « Décisions ») | réécriture de la configuration |
| Paramètres : valeurs par défaut de l'UDH (nom, ville, contacts) écrites dans la base de toute nouvelle université ; images contrôlées par l'extension (logo, armoirie) | nom et ville de l'université courante, contacts vides ; contenu vérifié, 2 Mo | |
| Licence : clé d'un autre établissement acceptée (puis application bloquée) ; page dans l'application bloquée par… la licence | refusée à l'activation ; `/licence` reste ouverte à l'administrateur | |
| Sauvegarde : dossier commun à toutes les universités, téléchargement par nom de fichier (la sauvegarde d'une autre université était récupérable) ; tables entières chargées en mémoire ; erreur SQL affichée | un dossier par université hors du dossier web (`DOSSIER_SAUVEGARDES`), téléchargement par numéro ; écriture au fil de l'eau ; erreur dans le journal du serveur | fuite d'une base entière |
| **Étape 12** | | |
| Ministère : chaque consultation créait des tables, ajoutait des colonnes et marquait « finalisées » les matières notées dans la base de CHAQUE université | lecture seule | écriture dans les données des universités depuis un compte extérieur |
| Ministère : détail d'un PV ouvert par numéro, brouillons compris ; journal des exports PDF écrit par le navigateur, sans jeton | PV finalisés ou publiés seulement ; exports produits et journalisés par le serveur | |
| Taux de passage national : licences validées comptées deux fois ; répartition H/F : tous les étudiants « actifs » de la base, toutes années | comptées une fois ; inscrits de l'année affichée | chiffres faux |
| ANAB : résultats des PV en brouillon pris en compte ; filière insérée dans la requête SQL ; aucune trace des dossiers consultés | PV finalisés ou publiés ; paramètres liés ; chaque dossier consulté inscrit au journal d'activité de l'université concernée | données personnelles consultées par un organisme extérieur |
| Connexion Ministère / ANAB : jeton CSRF écrit dans l'attribut `action` du formulaire (jamais envoyé : « Session expirée ») ; drapeau de session sans expiration (24 h pour l'ANAB), compte désactivé resté connecté ; déconnexion par lien | connexion réparée, 5 essais puis 5 min ; 30 min d'inactivité ; compte relu à chaque requête ; déconnexion en POST | |
| Comptes Ministère / ANAB : rôle quelconque envoyé à la base ; mot de passe de réinitialisation saisi en clair, 8 caractères quelconques ; propre compte et dernier administrateur désactivables | rôle contrôlé ; champ masqué, lettres et chiffres ; protégés | |

## Décisions à prendre (université)

- **Copie de maquette** : comme l'original, elle ne recopie ni le nombre de matières à choisir
  d'une UE (`nb_options_a_choisir`) ni le type « optionnelle » des matières. Une maquette à options
  redevient entièrement obligatoire après copie. Recopier ces deux champs ?
- **Mot de passe initial des comptes étudiants = matricule** (repris tel quel). Les matricules se
  suivent (00001, 00002…) et figurent sur les cartes : quiconque connaît un matricule peut ouvrir
  le compte d'un étudiant qui n'a pas encore changé son mot de passe, voire le changer avant lui.
  Recommandé : mot de passe provisoire aléatoire, imprimé une seule fois à la génération, et
  changement obligatoire à la première connexion (demande une colonne dans `utilisateurs`).
- **Réclamation fondée sur un PV verrouillé** : comme avant, la note est corrigée et les résultats
  recalculés même si le PV source est verrouillé (c'est la procédure prévue après délibération).
  Faut-il plutôt exiger le déverrouillage, comme pour les autres modifications ?
- **QR codes infalsifiables** : la vérification publique se fait avec le seul numéro, qui se suit
  (R26-000001, R26-000002…). La limite par adresse IP freine un robot mais ne l'empêche pas avec
  plusieurs adresses. Recommandé : ajouter au QR une clé secrète (signature du numéro) et n'afficher
  le nom complet, la mention et la moyenne qu'avec cette clé (sinon : document authentique, type,
  initiales). Les documents déjà imprimés n'ont pas de clé : ils afficheraient la version réduite.
- **Numéro « ANM/DepD/aa/0001 » des attestations de réussite et « N° 0001/aaaa » des convocations** :
  simple compteur de la page imprimée (repris tel quel), il recommence à 1 à chaque impression et
  n'est enregistré nulle part. Le remplacer par un numéro enregistré et vérifiable ?
- **Identifiants KomiPay en clair dans l'ancien dépôt** : `candidature/includes/komipay.php` (suivi par git, dépôt
  GitHub) contient l'identifiant, le mot de passe, la clé API et la passe-clé KomiPay. Si le dépôt est ou a été
  public, ou partagé, les considérer comme compromis : en générer de nouveaux chez KomiPay, et les retirer du dépôt
  et de son historique. Dans la version Laravel ils iront dans `.env` (jamais versionné).
- **Suppression d'un paiement** : effacement définitif (repris tel quel, désormais journalisé avec le montant).
  Préférer une annulation (statut) qui garde la trace comptable ?
- **Mot de passe SMTP dans l'ancien journal des e-mails** : chaque envoi réussi de l'ancienne application a
  enregistré dans `email_logs.reponse_brute` l'échange SMTP, authentification comprise (identifiant et mot de passe
  en base64, lisibles par quiconque accède à la base ou à ses sauvegardes). À faire par l'université : changer le
  mot de passe SMTP, puis vider cette colonne (`UPDATE email_logs SET reponse_brute = NULL WHERE reponse_brute LIKE '%AUTH LOGIN%'`).
  Je ne l'ai pas exécuté : ce sont vos données réelles.
- **Inscription en ligne par matricule, sans compte** : comme avant, connaître un matricule (ils se suivent) suffit à
  voir le nom et la filière d'un étudiant et à ouvrir son inscription. La limite par adresse IP freine sans empêcher.
  Recommandé : demander aussi la date de naissance (ou un code reçu par SMS) avant d'afficher quoi que ce soit.
- **Paiement par carte** : les données de carte passent par notre serveur avant d'aller à KomiPay (c'est le
  fonctionnement de leur API) ; le canal est désactivé par défaut (`KOMIPAY_MOYENS`). L'activer impose les obligations
  PCI-DSS correspondantes ; préférer une page de paiement hébergée par KomiPay si elle existe.
- **Ancien `portail_admin`** (tables `portail_dossiers`, `portail_paiements` d'un portail antérieur) : non repris, hors
  menu. Vérifier si ces tables contiennent encore des dossiers à traiter ; sinon les archiver.
- **Délai de paiement de 15 minutes** compté depuis l'identification (repris tel quel) et non depuis le paiement :
  un étudiant lent à choisir son moyen de paiement voit son paiement expirer plus tôt. Le compter depuis le paiement ?
- **Délai de réclamation (72 h) compté depuis la dernière modification du PV** (repris tel quel : pas de date de
  publication en base) : toute modification après publication (verrouillage, correction) rouvre le délai. Ajouter une
  colonne `publie_le` renseignée à la publication ?
- **Application mobile** : la version publiée de l'application (ancienne réinitialisation par date de naissance)
  recevra « mettez à jour l'application » pour le mot de passe oublié. Publier la version du dépôt (`MOBILE/`) avant la
  bascule de l'API, et y remplacer l'adresse du serveur.
- **Relevé et attestation depuis le portail étudiant** : comme avant, l'étudiant consulte ses résultats publiés mais
  ne peut pas imprimer lui-même son relevé de notes ni son attestation (documents délivrés par la scolarité, numérotés
  au registre). Les ouvrir aux étudiants (version « non officielle » sans numéro, ou numérotée) ?
- **Matières déjà notées non finalisées** : l'ancien portail enseignant marquait finalisée, une fois par session,
  toute matière ayant au moins une note (rattrapage de données). Ce n'est pas repris : sur les données migrées,
  les matières déjà finalisées le restent ; les autres attendent des notes complètes ou une finalisation manuelle.
- **Paramètres réservés à l'administrateur et au secrétariat** : l'ancienne page était ouverte à tout compte ayant
  `inscription_ped` (agents de faculté, doyens…), alors que ses réglages (règles de calcul des PV, logo, nom de
  l'institution, visas des diplômes) valent pour toute l'université. Si d'autres rôles doivent y accéder, préciser
  lesquels et pour quels onglets.
- **Anciennes sauvegardes** : celles faites par l'ancienne application sont dans `pages/backups/` (dossier commun à
  toutes les universités). Elles restent visibles dans l'historique mais marquées « Fichier absent » : les déplacer
  dans le dossier de leur université (`DOSSIER_SAUVEGARDES/<code>/`) pour les retélécharger, ou les archiver hors
  du serveur.
- **Signature de l'autorité** (cartes étudiantes) : comme avant, l'image est rangée avec les logos, servis sans
  connexion (pour la page de connexion). La servir aux seuls utilisateurs connectés, comme le cachet ?
- **Utilisateur MySQL en lecture seule pour le Ministère et l'ANAB** : ces espaces lisent toutes les bases avec le même
  compte MySQL que les universités. Le code est désormais en lecture seule, mais un compte MySQL limité à `SELECT`
  (plus l'écriture des journaux) protégerait les données même en cas de faille. À prévoir lors de la bascule ?
- **Mentions de l'ANAB** : seuils fixes 16 / 14 / 12 et éligibilité à 12/20 (repris tels quels, point A4) alors que
  les PV utilisent les seuils de chaque université. Unifier ?
- **Comptes Ministère / ANAB dans la base UDH** (repris tels quels) : une restauration de la base UDH les ramènerait
  à leur état d'alors. Les déplacer dans une base propre aux espaces nationaux ?
- Points A1 à A14 de l'inventaire (`docs/laravel/INVENTAIRE.md` de l'ancien dépôt).

## À vérifier

- Enregistrement du service worker PWA dans un vrai Chrome (le navigateur intégré à l'outil de
  développement le refuse).
- `LICENCE_SECRET` vaut encore la valeur par défaut de l'ancienne application (anomalie A7).
