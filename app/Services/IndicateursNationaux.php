<?php

namespace App\Services;

use App\Universites\RegistreUniversites;
use App\Universites\Universite;
use App\Universites\UniversiteCourante;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Indicateurs de toutes les universités pour le tableau de bord du
 * Ministère — portage de la collecte de pages/ministere.php.
 *
 * Chaque université est lue tour à tour (UniversiteCourante::pour) avec le
 * calcul déjà porté pour le rectorat (IndicateursRectorat : mêmes requêtes,
 * mis en cache 5 min par université) ; une université injoignable est
 * signalée « hors ligne » sans bloquer les autres.
 *
 * Différence voulue : lecture seule. L'ancienne page créait des tables,
 * ajoutait des colonnes et marquait « finalisées » des matières dans la
 * base de CHAQUE université à chaque consultation du ministère.
 */
class IndicateursNationaux
{
    public const SESSIONS = ['normale' => 'Session normale', 'rattrapage' => 'Rattrapage', 'reclamation' => 'Réclamation', 'reclamation_depot' => 'Réclamation dépôt'];

    public function __construct(
        private readonly RegistreUniversites $registre,
        private readonly UniversiteCourante $courante,
        private readonly IndicateursRectorat $indicateurs,
    ) {}

    /** @return array<string, Universite> */
    public function universites(): array
    {
        return $this->registre->actives();
    }

    public function universite(string $code): ?Universite
    {
        return $this->universites()[$code] ?? null;
    }

    /** Exécute $traitement dans la base de $u ; null si elle est injoignable. */
    public function dans(Universite $u, \Closure $traitement): mixed
    {
        // Compte MySQL dédié aux espaces nationaux (lecture seule, + écriture du
        // journal audit_log pour l'ANAB) s'il est configuré — voir BASCULE.md
        $lecture = config('gesnotes.espaces_db');
        $avant = null;
        if (filled($lecture['username'] ?? null)) {
            $avant = [config('database.connections.universite.username'), config('database.connections.universite.password')];
            config(['database.connections.universite.username' => $lecture['username'], 'database.connections.universite.password' => (string) ($lecture['password'] ?? '')]);
            DB::purge('universite');
        }
        try {
            return $this->courante->pour($u, $traitement);
        } catch (Throwable $e) {
            Log::warning("Espace national : université {$u->code} injoignable", ['erreur' => $e->getMessage()]);

            return null;
        } finally {
            if ($avant) {
                config(['database.connections.universite.username' => $avant[0], 'database.connections.universite.password' => $avant[1]]);
                DB::purge('universite');
            }
        }
    }

    /** Libellés de toutes les années connues (plus récente d'abord) et l'année active. */
    public function annees(): array
    {
        $libelles = [];
        $active = null;
        foreach ($this->universites() as $u) {
            foreach ($this->dans($u, fn () => DB::table('annees_academiques')->orderByDesc('libelle')->get(['libelle', 'active'])->all()) ?? [] as $a) {
                $libelles[$a->libelle] = $a->libelle;
                $active ??= $a->active ? $a->libelle : null;
            }
        }
        krsort($libelles);

        return [array_values($libelles), $active];
    }

    /**
     * Par université : ['uni' => Universite, 'erreur' => bool, + indicateurs].
     * L'année est désignée par son libellé (les identifiants diffèrent d'une base à l'autre).
     */
    public function parUniversite(string $annee): array
    {
        $resultat = [];
        foreach ($this->universites() as $code => $u) {
            $s = $this->dans($u, function () use ($annee) {
                $a = DB::table('annees_academiques')->where('libelle', $annee)->first() ?? DB::table('annees_academiques')->where('active', 1)->first();
                $id = (int) ($a->id ?? 0);

                return $this->indicateurs->pour($id) + [
                    'annee' => $a->libelle ?? '—',
                    'pv_attente' => (int) DB::table('pv_sessions as ps')->join('promotions as p', 'p.id', '=', 'ps.promotion_id')
                        ->where('p.annee_id', $id)->whereIn('ps.statut', ['brouillon', 'en_cours'])->count(),
                    'nb_facultes' => DB::table('facultes')->where('actif', 1)->count(),
                    'nb_filieres' => DB::table('filieres')->where('actif', 1)->count(),
                ];
            });
            $resultat[$code] = ['uni' => $u, 'erreur' => $s === null] + ($s ?? []);
        }

        return $resultat;
    }

    /** Totaux nationaux et tendance sur l'année précédente (ancienne page : même règle). */
    public function totaux(array $stats, string $annee): array
    {
        $ok = array_filter($stats, fn ($s) => ! $s['erreur']);
        $somme = fn (string $cle) => array_sum(array_column($ok, $cle));
        $passages = $somme('passages');
        $decisions = $passages + $somme('redoublants');
        $prevues = $somme('heures_prevues');
        // Admis + redoublants = inscrits (règle commune, App\Services\ClassementPassages) ; null : aucun inscrit
        $taux = $decisions > 0 ? round($passages / $decisions * 100, 1) : null;

        $prec = ['inscrits' => 0, 'passages' => 0, 'redoublants' => 0, 'trouve' => false];
        foreach ($ok as $s) {
            foreach ($s['evolution'] as $i => $e) {
                if ($e['annee'] === $annee && $i > 0) {
                    $p = $s['evolution'][$i - 1];
                    $prec = ['inscrits' => $prec['inscrits'] + $p['inscrits'], 'passages' => $prec['passages'] + $p['passages'],
                        'redoublants' => $prec['redoublants'] + $p['redoublants'], 'trouve' => true];
                }
            }
        }
        $decPrec = $prec['passages'] + $prec['redoublants'];

        return [
            'universites' => count($ok), 'inscrits' => $somme('inscrits'), 'hommes' => $somme('hommes'), 'femmes' => $somme('femmes'),
            'passages' => $passages, 'redoublants' => $somme('redoublants'), 'taux_passage' => $taux,
            'heures_prevues' => $prevues, 'heures_faites' => $somme('heures_faites'),
            'taux_programme' => $prevues > 0 ? round($somme('heures_faites') / $prevues * 100, 1) : 0,
            'pv_publies' => $somme('pv_publies'),
            'matieres' => array_map(fn ($k) => array_sum(array_map(fn ($s) => $s['matieres'][$k], $ok)), ['completes' => 'completes', 'en_cours' => 'en_cours', 'sans_toucher' => 'sans_toucher', 'sans_volume' => 'sans_volume']),
            'delta_inscrits' => $prec['trouve'] ? $somme('inscrits') - $prec['inscrits'] : null,
            'delta_taux' => $prec['trouve'] && $decPrec > 0 && $taux !== null ? round($taux - $prec['passages'] / $decPrec * 100, 1) : null,
        ];
    }

    /** Filières de même intitulé présentes dans au moins deux universités. */
    public function comparaisonFilieres(array $stats): array
    {
        $parFiliere = [];
        foreach ($stats as $code => $s) {
            foreach ($s['erreur'] ? [] : $s['facultes'] as $f) {
                foreach ($f['filieres'] as $l) {
                    $c = &$parFiliere[$l['filiere']][$code];
                    $c ??= ['inscrits' => 0, 'passages' => 0, 'redoublants' => 0, 'heures_prevues' => 0, 'heures_faites' => 0];
                    foreach (array_keys($c) as $k) {
                        $c[$k] += $l[$k];
                    }
                    unset($c);
                }
            }
        }
        $parFiliere = array_filter($parFiliere, fn ($u) => count($u) >= 2);
        ksort($parFiliere);

        return $parFiliere;
    }

    /** PV finalisés ou publiés d'une année, toutes universités (filtres facultatifs). */
    public function pv(string $annee, string $universite = '', string $statut = '', string $session = ''): array
    {
        $liste = [];
        foreach ($this->universites() as $code => $u) {
            if ($universite !== '' && $universite !== $code) {
                continue;
            }
            $lignes = $this->dans($u, fn () => DB::table('pv_sessions as ps')
                ->join('promotions as p', 'p.id', '=', 'ps.promotion_id')->join('filieres as f', 'f.id', '=', 'p.filiere_id')
                ->join('niveaux as n', 'n.id', '=', 'p.niveau_id')->join('semestres as s', 's.id', '=', 'ps.semestre_id')
                ->join('annees_academiques as a', 'a.id', '=', 'p.annee_id')
                ->where('a.libelle', $annee)->whereIn('ps.statut', in_array($statut, ['finalise', 'publie'], true) ? [$statut] : ['finalise', 'publie'])
                ->when(isset(self::SESSIONS[$session]), fn ($q) => $q->where('ps.type_session', $session))
                ->orderBy('f.libelle')->orderBy('n.code')->orderBy('s.code')
                ->get(['ps.id', 'ps.type_session', 'ps.statut', 'ps.verrouille', 'f.libelle as filiere', 'n.code as niveau', 's.libelle as sem_libelle',
                    DB::raw('(SELECT COUNT(*) FROM resultats_semestre rs WHERE rs.pv_id = ps.id) AS nb_total'),
                    DB::raw('(SELECT COUNT(*) FROM resultats_semestre rs WHERE rs.pv_id = ps.id AND rs.valide = 1) AS nb_admis')])
                ->all()) ?? [];
            foreach ($lignes as $l) {
                $l->uni = $u;
                $l->taux = $l->nb_total > 0 ? round($l->nb_admis / $l->nb_total * 100, 1) : 0;
                $liste[] = $l;
            }
        }

        return $liste;
    }

    /**
     * Détail d'un PV finalisé ou publié (l'ancienne page ouvrait aussi les
     * brouillons, par simple numéro), avec ses résultats et ses matières.
     */
    public function detailPv(Universite $u, int $id): ?array
    {
        return $this->dans($u, function () use ($id) {
            $pv = app(Pv::class)->trouver($id);
            if (! $pv || ! in_array($pv->statut, ['finalise', 'publie'], true)) {
                return null;
            }
            $resultats = DB::table('resultats_semestre as rs')->join('etudiants as e', 'e.id', '=', 'rs.etudiant_id')->where('rs.pv_id', $id)
                ->orderByRaw('rs.moyenne_generale IS NULL')->orderByDesc('rs.moyenne_generale')->orderBy('e.nom')->orderBy('e.prenoms')
                ->get(['e.numero', 'e.nom', 'e.prenoms', 'rs.credits_obtenus', 'rs.moyenne_generale', 'rs.decision', 'rs.mention', 'rs.rang', 'rs.valide']);
            $matieres = DB::table('matieres as m')->join('unites_enseignement as ue', 'ue.id', '=', 'm.ue_id')
                ->where('ue.promotion_id', $pv->promotion_id)->where('ue.semestre_id', $pv->semestre_id)->where('m.actif', 1)
                ->orderBy('m.ordre')->orderBy('m.libelle')
                ->get(['m.id', 'm.code', 'm.libelle', 'm.credits', 'm.finalisee',
                    DB::raw('(SELECT COUNT(*) FROM notes no WHERE no.pv_id = '.(int) $id.' AND no.matiere_id = m.id AND (no.note_examen IS NOT NULL OR no.note_cc IS NOT NULL)) AS nb_notes')]);

            return ['pv' => $pv, 'resultats' => $resultats, 'matieres' => $matieres];
        });
    }
}
