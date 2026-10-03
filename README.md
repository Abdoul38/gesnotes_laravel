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
- [ ] Étapes 3 à 13

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

## Décisions à prendre (université)

- **Copie de maquette** : comme l'original, elle ne recopie ni le nombre de matières à choisir
  d'une UE (`nb_options_a_choisir`) ni le type « optionnelle » des matières. Une maquette à options
  redevient entièrement obligatoire après copie. Recopier ces deux champs ?
- Points A1 à A14 de l'inventaire (`docs/laravel/INVENTAIRE.md` de l'ancien dépôt).

## À vérifier

- Enregistrement du service worker PWA dans un vrai Chrome (le navigateur intégré à l'outil de
  développement le refuse).
- `LICENCE_SECRET` vaut encore la valeur par défaut de l'ancienne application (anomalie A7).
