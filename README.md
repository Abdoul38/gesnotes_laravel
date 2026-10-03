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
- [ ] Étapes 8 à 13

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
- Points A1 à A14 de l'inventaire (`docs/laravel/INVENTAIRE.md` de l'ancien dépôt).

## À vérifier

- Enregistrement du service worker PWA dans un vrai Chrome (le navigateur intégré à l'outil de
  développement le refuse).
- `LICENCE_SECRET` vaut encore la valeur par défaut de l'ancienne application (anomalie A7).
