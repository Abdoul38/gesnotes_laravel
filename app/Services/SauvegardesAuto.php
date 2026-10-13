<?php

namespace App\Services;

use App\Universites\RegistreUniversites;
use Carbon\CarbonInterface;
use Illuminate\Database\Connection;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use RuntimeException;
use Symfony\Component\Process\Process;
use Throwable;

/**
 * Sauvegardes automatiques de toutes les bases de production (universités
 * actives et base des espaces) :
 *  - complète : un fichier mysqldump compressé par base, cohérent
 *    (--single-transaction), avec la position du journal binaire au moment
 *    de la copie (--master-data=2) ;
 *  - différentielle : tous les journaux binaires écrits depuis la dernière
 *    complète → restauration = complète + dernière différentielle ;
 *  - incrémentielle : les journaux binaires écrits depuis la dernière
 *    sauvegarde (complète, différentielle ou incrémentielle) → restauration =
 *    complète + différentielle + incrémentielles suivantes, dans l'ordre.
 * Les journaux binaires (log_bin) sont communs au serveur : ils sont copiés
 * une fois et filtrés par base à la restauration (mysqlbinlog --database),
 * ce qui permet de restaurer UNE université, jusqu'à une date et une heure
 * précises (--stop-datetime).
 *
 * Chaque sauvegarde est un dossier <date>_<type>/ avec un manifest.json
 * (fichiers, empreintes SHA-256, positions, sauvegarde de départ), écrit en
 * dernier : un dossier sans manifeste réussi n'est jamais utilisé.
 * Les mots de passe passent par un fichier d'options temporaire
 * (--defaults-extra-file), jamais par la ligne de commande.
 */
class SauvegardesAuto
{
    public const TYPES = ['complete', 'differentielle', 'incrementielle'];

    private const NOM_BASE = '/^[A-Za-z0-9_]+$/';

    public function __construct(private readonly RegistreUniversites $registre) {}

    public function dossier(): string
    {
        $d = rtrim((string) config('gesnotes.sauvegardes_auto.dossier'), '/\\');
        if (! is_dir($d) && ! @mkdir($d, 0700, true) && ! is_dir($d)) {
            throw new RuntimeException("Dossier des sauvegardes impossible à créer : $d");
        }

        return $d;
    }

    /**
     * Bases à sauvegarder : nom de la base => libellé.
     *
     * @param  array<string>  $codes  codes d'université (toutes les actives et la base des espaces si vide)
     */
    public function bases(array $codes = []): array
    {
        $bases = [];
        foreach ($this->registre->actives() as $code => $u) {
            if ($u->base && (! $codes || in_array($code, $codes, true))) {
                $bases[$u->base] = $u->sigle;
            }
        }
        $espaces = config('gesnotes.espaces_base');
        if (! $codes && filled($espaces)) {
            $bases[$espaces] = 'Espaces (ministère, ANAB)';
        }
        foreach (array_keys($bases) as $b) {
            if (! preg_match(self::NOM_BASE, $b)) {
                throw new RuntimeException("Nom de base refusé : $b");
            }
        }

        return $bases;
    }

    public function journalBinaireActif(): bool
    {
        return (bool) $this->connexion()->scalar('SELECT @@log_bin');
    }

    /**
     * Fait une sauvegarde ; retourne son manifeste. En cas d'échec, le
     * manifeste est marqué « echec » (jamais utilisé pour restaurer) et
     * l'exception est relancée.
     */
    public function sauvegarder(string $type, array $codes = [], bool $archive = false): array
    {
        if (! in_array($type, self::TYPES, true)) {
            throw new RuntimeException("Type de sauvegarde inconnu : $type");
        }

        return $this->verrouiller(function () use ($type, $codes, $archive) {
            // Serveur interrogé d'abord : MySQL arrêté → aucun dossier vide laissé derrière
            $serveur = (string) $this->connexion()->scalar('SELECT VERSION()');
            $id = $this->nouvelId($type);
            $dossier = $this->dossier().DIRECTORY_SEPARATOR.$id;
            mkdir($dossier, 0700, true);
            $manifeste = ['id' => $id, 'type' => $type, 'statut' => 'en_cours', 'debut' => now()->toIso8601String(), 'archive' => $archive,
                'serveur' => $serveur];
            try {
                $manifeste += $type === 'complete' ? $this->complete($dossier, $codes) : $this->journaux($dossier, $type);
                $manifeste['statut'] = 'ok';
            } catch (Throwable $e) {
                $manifeste['statut'] = 'echec';
                $manifeste['erreur'] = $e->getMessage();
                throw $e;
            } finally {
                $manifeste['fin'] = now()->toIso8601String();
                file_put_contents($dossier.DIRECTORY_SEPARATOR.'manifest.json', json_encode($manifeste, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
                $this->noterEtat($manifeste);
            }

            return $manifeste;
        });
    }

    /** Copie de chaque base, avec la position du journal binaire. */
    private function complete(string $dossier, array $codes): array
    {
        $bases = $this->bases($codes);
        if (! $bases) {
            throw new RuntimeException('Aucune base à sauvegarder.');
        }
        $binlog = $this->journalBinaireActif();
        $resultat = ['bases' => []];
        $this->avecOptions($this->identifiantsSauvegarde(), function (string $options) use ($bases, $binlog, $dossier, &$resultat) {
            foreach ($bases as $base => $libelle) {
                $fichier = $base.'.sql.gz';
                $chemin = $dossier.DIRECTORY_SEPARATOR.$fichier;
                $gz = gzopen($chemin, 'wb6');
                $debut = $fin = $erreurs = '';
                $p = new Process([$this->outil('mysqldump'), '--defaults-extra-file='.$options, '--single-transaction', '--quick',
                    '--routines', '--triggers', '--events', '--hex-blob', '--default-character-set=utf8mb4', ...($binlog ? ['--master-data=2'] : []), $base]);
                $p->setTimeout(null);
                $p->run(function ($canal, $donnees) use ($gz, &$debut, &$fin, &$erreurs) {
                    if ($canal === Process::ERR) {
                        $erreurs .= $donnees;

                        return;
                    }
                    gzwrite($gz, $donnees);
                    if (strlen($debut) < 16384) {
                        $debut .= substr($donnees, 0, 16384 - strlen($debut));
                    }
                    $fin = substr($fin.$donnees, -300);
                });
                gzclose($gz);
                if (! $p->isSuccessful() || ! str_contains($fin, '-- Dump completed')) {
                    throw new RuntimeException("mysqldump $base a échoué : ".Str::limit(trim($erreurs) ?: 'copie incomplète', 300));
                }
                $journal = null;
                if ($binlog) {
                    if (! preg_match("/MASTER_LOG_FILE='([^']+)',\\s*MASTER_LOG_POS=(\\d+)/", $debut, $m)) {
                        throw new RuntimeException("Position du journal binaire introuvable dans la copie de $base.");
                    }
                    $journal = ['fichier' => $m[1], 'position' => (int) $m[2]];
                }
                $resultat['bases'][$base] = ['libelle' => $libelle, 'fichier' => $fichier, 'octets' => filesize($chemin),
                    'sha256' => hash_file('sha256', $chemin), 'journal' => $journal];
            }
        });

        return $resultat;
    }

    /** Différentielle ou incrémentielle : copie des journaux binaires fermés. */
    private function journaux(string $dossier, string $type): array
    {
        if (! $this->journalBinaireActif()) {
            throw new RuntimeException('Journal binaire désactivé (log_bin) : sauvegardes différentielles et incrémentielles impossibles. Voir BASCULE.md.');
        }
        $catalogue = $this->catalogue();
        $complete = collect($catalogue)->where('type', 'complete')->last();
        if (! $complete) {
            throw new ChaineJournauxRompue('Aucune sauvegarde complète : en faire une d\'abord (--type=complete).');
        }
        $depart = collect($complete['bases'])->pluck('journal')->filter();
        if ($depart->count() !== count($complete['bases'])) {
            throw new ChaineJournauxRompue("La dernière complète ({$complete['id']}) a été faite sans journal binaire : en refaire une.");
        }
        $premier = $depart->map(fn ($j) => self::numero($j['fichier']))->min();
        $precedente = null;
        if ($type === 'incrementielle') {
            $precedente = collect($catalogue)->where('complete', $complete['id'])->last();
            if ($precedente) {
                $premier = self::numero($precedente['jusqua']) + 1;
            }
        }

        // Ferme le journal en cours : tout ce qui précède est copiable
        $db = $this->connexion();
        $db->statement('FLUSH BINARY LOGS');
        $fermes = array_slice(array_map(fn ($l) => $l->Log_name, $db->select('SHOW BINARY LOGS')), 0, -1);
        $aCopier = array_values(array_filter($fermes, fn ($n) => self::numero($n) >= $premier));
        if (! $aCopier || self::numero($aCopier[0]) !== $premier) {
            throw new ChaineJournauxRompue("Journal binaire n° $premier introuvable sur le serveur (supprimé avant d'être copié ?) : faire une complète.");
        }
        $source = $this->dossierJournaux();
        $journaux = [];
        foreach ($aCopier as $nom) {
            $chemin = $dossier.DIRECTORY_SEPARATOR.$nom.'.gz';
            self::compresser($source.DIRECTORY_SEPARATOR.$nom, $chemin);
            $journaux[] = ['nom' => $nom, 'fichier' => $nom.'.gz', 'octets' => filesize($chemin), 'sha256' => hash_file('sha256', $chemin)];
        }

        return ['complete' => $complete['id'], 'precedente' => $precedente['id'] ?? ($type === 'incrementielle' ? $complete['id'] : null),
            'du' => $aCopier[0], 'jusqua' => end($aCopier), 'bases' => array_map(fn ($b) => ['libelle' => $b['libelle']], $complete['bases']), 'journaux' => $journaux];
    }

    /** Sauvegardes réussies, de la plus ancienne à la plus récente (avec leur dossier). */
    public function catalogue(bool $avecEchecs = false): array
    {
        $liste = [];
        foreach (glob($this->dossier().DIRECTORY_SEPARATOR.'*'.DIRECTORY_SEPARATOR.'manifest.json') ?: [] as $f) {
            $m = json_decode((string) file_get_contents($f), true);
            if (is_array($m) && isset($m['id'], $m['type']) && ($avecEchecs || ($m['statut'] ?? '') === 'ok')) {
                $liste[] = $m + ['chemin' => dirname($f)];
            }
        }
        usort($liste, fn ($a, $b) => strcmp($a['id'], $b['id']));

        return $liste;
    }

    /**
     * Supprime les sauvegardes au-delà de la durée de conservation : on garde
     * les N dernières complètes, la première complète de chacun des M
     * derniers mois et les archives, avec leurs différentielles et
     * incrémentielles ; les échecs de plus de 7 jours.
     *
     * @return array<string> identifiants supprimés
     */
    public function purger(?CarbonInterface $maintenant = null): array
    {
        $maintenant ??= now();
        $catalogue = $this->catalogue(true);
        $completes = array_values(array_filter($catalogue, fn ($m) => $m['type'] === 'complete' && $m['statut'] === 'ok'));
        $garder = array_slice(array_reverse(array_column($completes, 'id')), 0, max(1, (int) config('gesnotes.sauvegardes_auto.garder_completes')));
        $limiteMois = $maintenant->copy()->startOfMonth()->subMonths(max(0, (int) config('gesnotes.sauvegardes_auto.garder_mois') - 1));
        $parMois = [];
        foreach ($completes as $c) {
            $parMois[substr($c['id'], 0, 7)] ??= $c;
            if (! empty($c['archive'])) {
                $garder[] = $c['id'];
            }
        }
        foreach ($parMois as $c) {
            if (Carbon::parse($c['debut'])->greaterThanOrEqualTo($limiteMois)) {
                $garder[] = $c['id'];
            }
        }
        $supprimes = [];
        foreach ($catalogue as $m) {
            $supprimer = $m['statut'] !== 'ok'
                ? Carbon::parse($m['debut'])->lessThan($maintenant->copy()->subDays(7))
                : ! in_array($m['type'] === 'complete' ? $m['id'] : ($m['complete'] ?? ''), $garder, true);
            if ($supprimer) {
                self::supprimerDossier($m['chemin']);
                $supprimes[] = $m['id'];
            }
        }
        // Dossiers sans manifeste (sauvegarde interrompue : arrêt du serveur, coupure…) de plus d'un jour
        foreach (glob($this->dossier().DIRECTORY_SEPARATOR.'*', GLOB_ONLYDIR) ?: [] as $d) {
            if (! is_file($d.DIRECTORY_SEPARATOR.'manifest.json') && filemtime($d) < $maintenant->copy()->subDay()->getTimestamp()) {
                self::supprimerDossier($d);
                $supprimes[] = basename($d);
            }
        }

        return $supprimes;
    }

    /**
     * Mode automatique : la sauvegarde qui aurait déjà dû être faite, ou
     * null. Rattrape ce qui a été manqué (ordinateur éteint ou en veille,
     * MySQL arrêté) au lieu d'attendre le créneau suivant :
     *  - complète : aucune depuis le dernier dimanche 2 h ;
     *  - différentielle : aucune (ni complète) depuis la dernière nuit à 2 h ;
     *  - incrémentielle : aucune sauvegarde depuis la dernière heure pleine
     *    de la journée de travail (7 h – 20 h ; la nuit : 20 h la veille).
     * Sans journal binaire : complètes seulement.
     */
    public function aFaire(CarbonInterface $maintenant, ?bool $binlog = null): ?string
    {
        $catalogue = collect($this->catalogue());
        $complete = $catalogue->where('type', 'complete')->last();
        $dimanche = $maintenant->copy()->setTime(2, 0);
        if ($dimanche->greaterThan($maintenant)) {
            $dimanche->subDay();
        }
        $nuit = $dimanche->copy();
        while ($dimanche->dayOfWeek !== 0) {
            $dimanche->subDay();
        }
        $avant = fn (?array $m, CarbonInterface $creneau) => ! $m || Carbon::parse($m['debut'])->lessThan($creneau);
        if ($avant($complete, $dimanche)) {
            return 'complete';
        }
        $binlog ??= $this->journalBinaireActif();
        if (! $binlog) {
            return null;
        }
        if (collect($complete['bases'] ?? [])->pluck('journal')->contains(null)) {
            return 'complete'; // complète faite sans journal binaire : pas de chaîne possible
        }
        $chaine = $catalogue->filter(fn ($m) => $m['id'] === $complete['id'] || ($m['complete'] ?? null) === $complete['id']);
        if ($avant($chaine->whereIn('type', ['complete', 'differentielle'])->last(), $nuit)) {
            return 'differentielle';
        }
        $heure = $maintenant->copy()->startOfHour();
        if ($heure->hour > 20) {
            $heure->setTime(20, 0);
        } elseif ($heure->hour < 7) {
            $heure->subDay()->setTime(20, 0);
        }

        return $avant($chaine->last(), $heure) ? 'incrementielle' : null;
    }

    /** Message d'erreur si le serveur MySQL ne répond pas, sinon null. */
    public function serveurInjoignable(): ?string
    {
        try {
            $this->connexion()->scalar('SELECT 1');

            return null;
        } catch (Throwable $e) {
            DB::purge('sauvegardes');

            return $e->getMessage();
        }
    }

    /** Note une panne du serveur MySQL ; vrai si elle commence (première tentative manquée). */
    public function noterPanne(string $erreur): bool
    {
        $etat = $this->etat();
        $nouvelle = ! isset($etat['panne']);
        $etat['panne'] = ['depuis' => $etat['panne']['depuis'] ?? now()->toIso8601String(), 'derniere' => now()->toIso8601String(),
            'tentatives' => ($etat['panne']['tentatives'] ?? 0) + 1, 'erreur' => Str::limit($erreur, 300)];
        $this->ecrireEtat($etat);

        return $nouvelle;
    }

    /** Fin de panne : la panne qui vient de se terminer (pour la signaler), ou null. */
    public function finPanne(): ?array
    {
        $etat = $this->etat();
        $panne = $etat['panne'] ?? null;
        if ($panne) {
            unset($etat['panne']);
            $this->ecrireEtat($etat);
        }

        return $panne;
    }

    /** Vrai si une alerte de ce sujet peut partir (au plus une toutes les $heures heures) ; la note envoyée. */
    public function alertePermise(string $sujet, int $heures = 3): bool
    {
        $etat = $this->etat();
        $derniere = $etat['alertes'][$sujet] ?? null;
        if ($derniere && Carbon::parse($derniere)->greaterThan(now()->subHours($heures))) {
            return false;
        }
        $etat['alertes'][$sujet] = now()->toIso8601String();
        $this->ecrireEtat($etat);

        return true;
    }

    private function ecrireEtat(array $etat): void
    {
        file_put_contents($this->dossier().DIRECTORY_SEPARATOR.'etat.json', json_encode($etat, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));
    }

    /**
     * Ce qu'il faut rejouer pour remettre une base dans l'état d'une date
     * (ou de la dernière sauvegarde) : complète, dernière différentielle,
     * incrémentielles suivantes, journaux binaires dans l'ordre.
     */
    public function plan(string $base, ?CarbonInterface $jusqua = null, bool $journauxServeur = false): array
    {
        $catalogue = collect($this->catalogue());
        $avant = fn ($m) => ! $jusqua || Carbon::parse($m['fin'])->lessThanOrEqualTo($jusqua);
        $complete = $catalogue->filter(fn ($m) => $m['type'] === 'complete' && isset($m['bases'][$base]) && $avant($m))->last();
        if (! $complete) {
            throw new RuntimeException("Aucune sauvegarde complète de $base".($jusqua ? ' antérieure au '.$jusqua->format('d/m/Y H:i') : '').'.');
        }
        $plan = ['complete' => $complete, 'differentielle' => null, 'incrementielles' => [], 'journaux' => [],
            'position' => $complete['bases'][$base]['journal'], 'avertissements' => []];
        if (! $plan['position']) {
            $plan['avertissements'][] = 'Complète faite sans journal binaire : restauration à l\'état du '.Carbon::parse($complete['fin'])->format('d/m/Y H:i').' seulement.';

            return $plan;
        }

        $chaine = $catalogue->filter(fn ($m) => ($m['complete'] ?? null) === $complete['id'])->values();
        $plan['differentielle'] = $chaine->filter(fn ($m) => $m['type'] === 'differentielle' && $avant($m))->last();
        $selection = array_filter([$plan['differentielle']]);
        foreach ($chaine->filter(fn ($m) => strcmp($m['id'], $plan['differentielle']['id'] ?? $complete['id']) > 0) as $m) {
            if ($m['type'] === 'incrementielle' && $avant($m)) {
                $selection[] = $plan['incrementielles'][] = $m;

                continue;
            }
            // Première sauvegarde après la date demandée : elle contient la fin de la période
            if ($jusqua) {
                $selection[] = $m;
                if ($m['type'] === 'incrementielle') {
                    $plan['incrementielles'][] = $m;
                } else {
                    $plan['differentielle'] = $m;
                }
            }
            break;
        }
        $journaux = [];
        foreach ($selection as $m) {
            foreach ($m['journaux'] as $j) {
                $journaux[$j['nom']] = ['nom' => $j['nom'], 'chemin' => $m['chemin'].DIRECTORY_SEPARATOR.$j['fichier'], 'sha256' => $j['sha256'], 'serveur' => false];
            }
        }
        $depart = self::numero($plan['position']['fichier']);
        $journaux = array_filter($journaux, fn ($j) => self::numero($j['nom']) >= $depart);
        uksort($journaux, fn ($a, $b) => self::numero($a) <=> self::numero($b));

        $dernier = $journaux ? self::numero(array_key_last($journaux)) : $depart - 1;
        if ($journauxServeur) {
            // Journaux encore sur le serveur, pas encore sauvegardés (serveur MySQL intact)
            $source = $this->dossierJournaux();
            foreach ($this->connexion()->select('SHOW BINARY LOGS') as $l) {
                if (self::numero($l->Log_name) > $dernier) {
                    $journaux[$l->Log_name] = ['nom' => $l->Log_name, 'chemin' => $source.DIRECTORY_SEPARATOR.$l->Log_name, 'sha256' => null, 'serveur' => true];
                }
            }
        } else {
            $derniere = $selection ? end($selection) : $complete;
            if (! $jusqua || Carbon::parse($derniere['fin'])->lessThan($jusqua)) {
                $plan['avertissements'][] = 'Les sauvegardes s\'arrêtent au '.Carbon::parse($derniere['fin'])->format('d/m/Y H:i')
                    .' : --journaux-serveur ajoute les modifications suivantes si le serveur MySQL est intact.';
            }
        }
        // Les journaux doivent se suivre sans trou depuis la position de la complète
        $attendu = $depart;
        foreach (array_keys($journaux) as $nom) {
            if (self::numero($nom) !== $attendu++) {
                throw new RuntimeException('Chaîne de journaux binaires incomplète : n° '.($attendu - 1).' manquant.');
            }
        }
        $plan['journaux'] = array_values($journaux);

        return $plan;
    }

    /**
     * Restaure une base d'après un plan, dans la base $cible (recréée). Les
     * écritures de la restauration ne vont pas dans le journal binaire.
     */
    public function restaurer(array $plan, string $base, string $cible, ?CarbonInterface $jusqua = null, ?callable $etape = null): void
    {
        if (! preg_match(self::NOM_BASE, $base) || ! preg_match(self::NOM_BASE, $cible)) {
            throw new RuntimeException('Nom de base refusé.');
        }
        $etape ??= fn () => null;
        $dump = $plan['complete']['chemin'].DIRECTORY_SEPARATOR.$plan['complete']['bases'][$base]['fichier'];
        $etape('Contrôle des empreintes');
        foreach ([[$dump, $plan['complete']['bases'][$base]['sha256']], ...array_map(fn ($j) => [$j['chemin'], $j['sha256']], $plan['journaux'])] as [$f, $empreinte]) {
            if (! is_file($f) || ($empreinte !== null && ! hash_equals($empreinte, hash_file('sha256', $f)))) {
                throw new RuntimeException('Fichier absent ou altéré : '.basename($f));
            }
        }

        $this->verrouiller(fn () => $this->avecOptions($this->identifiantsApplication(), function (string $options) use ($plan, $base, $cible, $jusqua, $etape, $dump) {
            $mysql = fn () => [$this->outil('mysql'), '--defaults-extra-file='.$options, '--default-character-set=utf8mb4',
                '--init-command=SET sql_log_bin=0', $cible];
            $db = $this->connexion(true);
            $db->statement("DROP DATABASE IF EXISTS `$cible`");
            $db->statement("CREATE DATABASE `$cible` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci");

            $etape('Chargement de la complète '.$plan['complete']['id']);
            $p = new Process($mysql());
            $p->setTimeout(null);
            $p->setInput((function () use ($dump) {
                $gz = gzopen($dump, 'rb');
                while (! gzeof($gz)) {
                    yield gzread($gz, 1 << 20);
                }
                gzclose($gz);
            })());
            $p->run();
            if (! $p->isSuccessful()) {
                throw new RuntimeException('Chargement de la complète échoué : '.Str::limit(trim($p->getErrorOutput()), 300));
            }

            if (! $plan['journaux']) {
                return;
            }
            $etape('Rejeu de '.count($plan['journaux']).' journal(aux) binaire(s)'.($jusqua ? ' jusqu\'au '.$jusqua->format('d/m/Y H:i:s') : ''));
            // mysqlbinlog lit --stop-datetime à l'heure locale du système (celle du serveur MySQL, sur la même machine)
            $decalage = (int) $db->scalar("SELECT TIMESTAMPDIFF(MINUTE, UTC_TIMESTAMP(), CONVERT_TZ(UTC_TIMESTAMP(), '+00:00', 'SYSTEM'))");
            $arret = $jusqua ? Carbon::instance($jusqua)->utc()->addMinutes($decalage)->format('Y-m-d H:i:s') : null;
            $temp = $this->dossier().DIRECTORY_SEPARATOR.'.tmp'.DIRECTORY_SEPARATOR.Str::random(12);
            mkdir($temp, 0700, true);
            try {
                $fichiers = [];
                foreach ($plan['journaux'] as $j) {
                    $fichiers[] = $f = $temp.DIRECTORY_SEPARATOR.$j['nom'];
                    $j['serveur'] ? copy($j['chemin'], $f) : self::decompresser($j['chemin'], $f);
                }
                $sql = $temp.DIRECTORY_SEPARATOR.'rejeu.sql';
                $p = new Process([$this->outil('mysqlbinlog'), '--database='.$base, ...($cible !== $base ? ['--rewrite-db='.$base.'->'.$cible] : []),
                    '--start-position='.$plan['position']['position'], ...($arret ? ['--stop-datetime='.$arret] : []),
                    '--result-file='.$sql, ...$fichiers]);
                $p->setTimeout(null);
                $p->run();
                if (! $p->isSuccessful()) {
                    throw new RuntimeException('Lecture des journaux binaires échouée : '.Str::limit(trim($p->getErrorOutput()), 300));
                }
                $p = new Process($mysql());
                $p->setTimeout(null);
                $entree = fopen($sql, 'rb');
                $p->setInput($entree);
                $p->run();
                fclose($entree);
                if (! $p->isSuccessful()) {
                    throw new RuntimeException('Rejeu des journaux binaires échoué : '.Str::limit(trim($p->getErrorOutput()), 300));
                }
            } finally {
                // Sans masquer l'erreur éventuelle de la restauration
                try {
                    self::supprimerDossier($temp);
                } catch (Throwable) {
                }
            }
        }));
    }

    /** Dernier état de chaque type (date, statut, message), pour l'affichage et la surveillance. */
    public function etat(): array
    {
        $f = $this->dossier().DIRECTORY_SEPARATOR.'etat.json';

        return is_file($f) ? (json_decode((string) file_get_contents($f), true) ?: []) : [];
    }

    private function noterEtat(array $m): void
    {
        $etat = $this->etat();
        $etat[$m['type']] = ['id' => $m['id'], 'date' => $m['fin'], 'statut' => $m['statut'], 'erreur' => $m['erreur'] ?? null];
        file_put_contents($this->dossier().DIRECTORY_SEPARATOR.'etat.json', json_encode($etat, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));
    }

    public function horsServeurConfiguree(): bool
    {
        $c = config('gesnotes.sauvegardes_auto.hors_serveur');

        return filled($c['rclone'] ?? null) && filled($c['destination'] ?? null);
    }

    /**
     * Copie hors du serveur (rclone copy) : ajoute ce qui manque à la
     * destination, sans jamais y effacer — une sauvegarde supprimée ou
     * abîmée sur le serveur reste intacte à l'extérieur. Le chiffrement est
     * assuré par la destination (remote « crypt » de rclone).
     */
    public function copierHorsServeur(): void
    {
        $this->verrouiller(function () {
            try {
                $this->rclone(['copy', $this->dossier(), $this->destinationHorsServeur(),
                    '--exclude', '/.tmp/**', '--exclude', '/.verrou', '--exclude', '/etat.json']);
                $this->noterEtatHorsServeur('ok');
            } catch (Throwable $e) {
                $this->noterEtatHorsServeur('echec', $e->getMessage());
                throw $e;
            }
        });
    }

    /** Rapatrie dans le dossier local les sauvegardes de la destination (serveur perdu ou dossier effacé). */
    public function recupererHorsServeur(): void
    {
        $this->verrouiller(fn () => $this->rclone(['copy', $this->destinationHorsServeur(), $this->dossier()]));
    }

    /**
     * Copie sur un support amovible (clé USB, disque externe), à débrancher
     * ensuite : chiffrée par le remote « crypt » de rclone (mêmes mots de
     * passe que la copie en ligne), sans rien effacer sur le support, puis
     * vérifiée fichier par fichier.
     *
     * @param  string  $support  lecteur (« E: ») ou dossier sur le support
     * @return array{dossier: string, octets: int}
     */
    public function copierSurSupport(string $support, bool $memeDisque = false): array
    {
        $cible = $this->dossierSupport($support, $memeDisque);
        $taille = 0;
        foreach (new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($this->dossier(), \FilesystemIterator::SKIP_DOTS)) as $f) {
            $taille += $f->getSize();
        }
        $libre = @disk_free_space($cible);
        if ($libre !== false && $libre < $taille) {
            throw new RuntimeException(sprintf('Place insuffisante sur %s : %.1f Mo libres, %.1f Mo nécessaires.', $support, $libre / 1048576, $taille / 1048576));
        }

        return $this->verrouiller(function () use ($cible, $taille) {
            $exclusions = ['--exclude', '/.tmp/**', '--exclude', '/.verrou', '--exclude', '/etat.json'];
            try {
                // Clés en FAT32 : dates à 2 s près (sinon tout serait recopié à chaque fois)
                $this->rclone(['copy', $this->dossier(), $this->destinationSupport($cible), '--modify-window', '2s', ...$exclusions]);
                // Support amovible : on relit tout (contenu déchiffré comparé aux originaux)
                $this->rclone(['cryptcheck', $this->dossier(), $this->destinationSupport($cible), '--one-way', ...$exclusions]);
                $this->noterEtatSupport('ok', $cible);
            } catch (Throwable $e) {
                $this->noterEtatSupport('echec', $cible, $e->getMessage());
                throw $e;
            }

            return ['dossier' => $cible, 'octets' => $taille];
        });
    }

    /** Rapatrie dans le dossier local les sauvegardes d'un support amovible. */
    public function recupererDepuisSupport(string $support): void
    {
        $cible = $this->dossierSupport($support, true);
        $this->verrouiller(fn () => $this->rclone(['copy', $this->destinationSupport($cible), $this->dossier()]));
    }

    /** Dossier des sauvegardes sur le support : <lecteur>\EDUFILE-sauvegardes. */
    private function dossierSupport(string $support, bool $memeDisque): string
    {
        $support = rtrim(trim($support), '/\\');
        if (preg_match('/^[A-Za-z]:?$/', $support)) {
            $support = strtoupper($support[0]).':';
        }
        if (! is_dir($support.DIRECTORY_SEPARATOR)) {
            throw new RuntimeException("Support introuvable : $support (clé USB branchée ? bonne lettre de lecteur ?)");
        }
        if (! $memeDisque) {
            $lecteur = strtoupper(substr(realpath($support.DIRECTORY_SEPARATOR) ?: $support, 0, 1));
            $local = strtoupper(substr(realpath($this->dossier()) ?: $this->dossier(), 0, 1));
            // Même lettre, ou autre partition du MÊME disque physique (ex. D: sur le SSD de C:) : aucune protection
            $disque = self::disquePhysique($lecteur);
            if ($lecteur === $local || ($disque !== null && $disque === self::disquePhysique($local))) {
                throw new RuntimeException("$support est sur le même disque physique que les sauvegardes : brancher une clé USB ou un disque externe.");
            }
        }

        return $support.DIRECTORY_SEPARATOR.'EDUFILE-sauvegardes';
    }

    /** Numéro du disque physique d'une lettre de lecteur (Windows), null si inconnu. */
    private static function disquePhysique(string $lettre): ?string
    {
        if (PHP_OS_FAMILY !== 'Windows' || ! preg_match('/^[A-Z]$/', $lettre)) {
            return null;
        }
        $p = new Process(['powershell', '-NoProfile', '-NonInteractive', '-Command', "(Get-Partition -DriveLetter $lettre -ErrorAction Stop).DiskNumber"]);
        $p->setTimeout(30);
        $p->run();
        $n = trim($p->getOutput());

        return $p->isSuccessful() && ctype_digit($n) ? $n : null;
    }

    /** Remote chiffré (config « usb » de rclone) pointé sur le dossier du support. */
    private function destinationSupport(string $dossier): string
    {
        $remote = (string) (config('gesnotes.sauvegardes_auto.hors_serveur.remote_support') ?: 'usb');

        return $remote.',remote="'.$dossier.'":';
    }

    private function noterEtatSupport(string $statut, string $dossier, ?string $erreur = null): void
    {
        $etat = $this->etat();
        $etat['support'] = ['date' => now()->toIso8601String(), 'statut' => $statut, 'dossier' => $dossier, 'erreur' => $erreur];
        file_put_contents($this->dossier().DIRECTORY_SEPARATOR.'etat.json', json_encode($etat, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));
    }

    private function destinationHorsServeur(): string
    {
        if (! $this->horsServeurConfiguree()) {
            throw new RuntimeException('Copie hors du serveur non configurée (SAUVEGARDES_HORS_SERVEUR_* dans .env).');
        }

        return (string) config('gesnotes.sauvegardes_auto.hors_serveur.destination');
    }

    private function rclone(array $arguments): void
    {
        $c = config('gesnotes.sauvegardes_auto.hors_serveur');
        if (! filled($c['rclone'] ?? null) || ! is_file($c['rclone'])) {
            throw new RuntimeException('rclone introuvable : '.$c['rclone']);
        }
        $p = new Process([$c['rclone'], ...$arguments, ...(filled($c['config'] ?? null) ? ['--config', $c['config']] : []),
            '--retries', '3', '--low-level-retries', '10', '--log-level', 'ERROR']);
        $p->setTimeout(3600);
        $p->run();
        if (! $p->isSuccessful()) {
            $erreurs = trim($p->getErrorOutput()) ?: trim($p->getOutput());
            throw new RuntimeException('rclone a échoué : '.Str::limit(implode(' | ', array_slice(explode("\n", $erreurs), -3)), 400));
        }
    }

    private function noterEtatHorsServeur(string $statut, ?string $erreur = null): void
    {
        $etat = $this->etat();
        $etat['hors_serveur'] = ['date' => now()->toIso8601String(), 'statut' => $statut, 'erreur' => $erreur];
        file_put_contents($this->dossier().DIRECTORY_SEPARATOR.'etat.json', json_encode($etat, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));
    }

    /** Connexion au serveur sans base : compte de sauvegarde, ou compte de l'application ($admin). */
    private function connexion(bool $admin = false): Connection
    {
        $nom = $admin ? 'sauvegardes_admin' : 'sauvegardes';
        [$utilisateur, $motDePasse] = $admin ? $this->identifiantsApplication() : $this->identifiantsSauvegarde();
        config(["database.connections.$nom" => array_merge(config('database.connections.universite'),
            ['database' => null, 'username' => $utilisateur, 'password' => $motDePasse])]);

        return DB::connection($nom);
    }

    private function identifiantsSauvegarde(): array
    {
        $u = config('gesnotes.sauvegardes_auto.utilisateur');

        return filled($u) ? [$u, (string) config('gesnotes.sauvegardes_auto.mot_de_passe')] : $this->identifiantsApplication();
    }

    private function identifiantsApplication(): array
    {
        return [(string) config('database.connections.universite.username'), (string) config('database.connections.universite.password')];
    }

    /** Fichier d'options MySQL temporaire (identifiants hors de la ligne de commande), supprimé après usage. */
    private function avecOptions(array $identifiants, callable $f): mixed
    {
        $dossier = $this->dossier().DIRECTORY_SEPARATOR.'.tmp';
        if (! is_dir($dossier)) {
            mkdir($dossier, 0700, true);
        }
        $chemin = $dossier.DIRECTORY_SEPARATOR.'client-'.Str::random(16).'.cnf';
        $valeur = fn ($v) => '"'.addcslashes((string) $v, '\\"').'"';
        $c = config('database.connections.universite');
        file_put_contents($chemin, "[client]\nuser=".$valeur($identifiants[0])."\npassword=".$valeur($identifiants[1])
            ."\nhost=".$valeur($c['host'])."\nport=".(int) $c['port']."\n");
        @chmod($chemin, 0600);
        try {
            return $f($chemin);
        } finally {
            @unlink($chemin);
        }
    }

    private function outil(string $nom): string
    {
        $dossier = config('gesnotes.sauvegardes_auto.outils') ?: rtrim((string) $this->connexion()->scalar('SELECT @@basedir'), '/\\').DIRECTORY_SEPARATOR.'bin';
        foreach ([$nom.'.exe', $nom] as $f) {
            if (is_file($chemin = $dossier.DIRECTORY_SEPARATOR.$f)) {
                return $chemin;
            }
        }
        throw new RuntimeException("Outil $nom introuvable dans $dossier (SAUVEGARDES_AUTO_OUTILS).");
    }

    /** Dossier des journaux binaires du serveur (même machine que l'application). */
    private function dossierJournaux(): string
    {
        $base = (string) $this->connexion()->scalar('SELECT @@log_bin_basename');

        return $base !== '' ? dirname($base) : rtrim((string) $this->connexion()->scalar('SELECT @@datadir'), '/\\');
    }

    private function verrouiller(callable $f): mixed
    {
        $h = fopen($this->dossier().DIRECTORY_SEPARATOR.'.verrou', 'c');
        $debut = time();
        while (! flock($h, LOCK_EX | LOCK_NB)) {
            if (time() - $debut > 600) {
                fclose($h);
                throw new RuntimeException('Une autre sauvegarde ou restauration est en cours.');
            }
            sleep(5);
        }
        try {
            return $f();
        } finally {
            flock($h, LOCK_UN);
            fclose($h);
        }
    }

    private function nouvelId(string $type): string
    {
        // Millisecondes : deux sauvegardes de la même seconde restent dans l'ordre (le catalogue est trié par identifiant)
        do {
            $id = now()->format('Y-m-d_His-v').'_'.$type;
            usleep(1000);
        } while (is_dir($this->dossier().DIRECTORY_SEPARATOR.$id));

        return $id;
    }

    public static function numero(string $journal): int
    {
        return (int) substr($journal, strrpos($journal, '.') + 1);
    }

    private static function compresser(string $source, string $cible): void
    {
        $in = @fopen($source, 'rb');
        if (! $in) {
            throw new RuntimeException('Journal binaire illisible : '.basename($source).' (le serveur MySQL est-il sur cette machine ?)');
        }
        $gz = gzopen($cible, 'wb6');
        while (! feof($in)) {
            gzwrite($gz, (string) fread($in, 1 << 20));
        }
        fclose($in);
        gzclose($gz);
    }

    private static function decompresser(string $source, string $cible): void
    {
        $gz = gzopen($source, 'rb');
        $out = fopen($cible, 'wb');
        while (! gzeof($gz)) {
            fwrite($out, (string) gzread($gz, 1 << 20));
        }
        gzclose($gz);
        fclose($out);
    }

    private static function supprimerDossier(string $dossier): void
    {
        if (! is_dir($dossier)) {
            return;
        }
        foreach (new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($dossier, \FilesystemIterator::SKIP_DOTS), \RecursiveIteratorIterator::CHILD_FIRST) as $f) {
            $f->isDir() ? rmdir($f->getPathname()) : unlink($f->getPathname());
        }
        rmdir($dossier);
    }
}
