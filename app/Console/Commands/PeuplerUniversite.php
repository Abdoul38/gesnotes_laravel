<?php

namespace App\Console\Commands;

use App\Services\Inscriptions;
use App\Services\Matricules;
use App\Services\Pv;
use App\Services\Resultats;
use App\Services\SaisieNotes;
use App\Universites\RegistreUniversites;
use App\Universites\UniversiteCourante;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;

/**
 * Ajoute des étudiants d'essai et leur parcours (inscriptions, notes de
 * session normale et de rattrapage, résultats calculés par le moteur,
 * passages de niveau, paiements) dans la base d'une université EXISTANTE,
 * en réutilisant sa structure : filières, maquettes, années, PV déjà là.
 *
 * Rien n'est supprimé ni modifié dans les données existantes (sauf les
 * rangs des PV existants où des étudiants sont ajoutés, recalculés par le
 * moteur). Tout ce qui est créé est noté dans un manifeste
 * (storage/app/private/essais/peuplement-<code>.json) : --annuler retire
 * exactement ces lignes. Faire une sauvegarde de la base avant.
 *
 * Écrire dans une base dont le nom ne contient pas « _test » exige
 * --base-reelle-autorisee (accord explicite de l'université).
 */
class PeuplerUniversite extends Command
{
    protected $signature = 'gesnotes:peupler
        {universite : code de l\'université}
        {--etudiants=6 : étudiants par parcours et par promotion d\'entrée}
        {--annuler : retire tout ce qu\'un peuplement précédent a créé}
        {--base-reelle-autorisee : autorise l\'écriture dans une base dont le nom ne contient pas _test}';

    protected $description = 'Ajoute (ou retire avec --annuler) des étudiants d\'essai et leur parcours dans une base d\'université existante';

    /** Parcours : étapes [code filière, code niveau, semestres]. */
    private const PARCOURS = [
        'Droit' => [['DROIT', 'L1', ['S1', 'S2']], ['DROIT', 'L2', ['S3', 'S4']], ['DR-PRIV', 'L3', ['S5', 'S6']]],
        'Informatique de Gestion' => [['IG', 'DUT1', ['S1', 'S2']], ['IG', 'DUT2', ['S3', 'S4']], ['IG', 'LP', ['S5', 'S6']]],
    ];

    /** Profils : [moyenne visée en session normale, gain au rattrapage, absent à un examen] */
    private const PROFILS = [
        'excellent' => [15.5, 0, false], 'bon' => [13, 0, false], 'moyen' => [11, 0, false], 'juste' => [9.5, 2.5, false],
        'rattrapage' => [8, 3.5, false], 'faible' => [6.5, 1, false], 'absent' => [10, 0, true], 'moyen2' => [10.5, 1.5, false],
    ];

    private const NOMS = ['ABDOU', 'MOUSSA', 'ISSOUFOU', 'HAMANI', 'SALEY', 'GARBA', 'IBRAHIM', 'MAHAMANE', 'OUMAROU', 'BOUBACAR', 'ALI', 'SOUMANA', 'HASSANE', 'ADAMOU', 'IDRISSA', 'YACOUBA'];

    private const PRENOMS = ['F' => ['Aïchatou', 'Mariama', 'Fati', 'Hadiza', 'Zeinabou', 'Rahama', 'Safiatou', 'Balkissa'],
        'M' => ['Abdoulaye', 'Issaka', 'Moustapha', 'Souleymane', 'Ousmane', 'Hamidou', 'Yahaya', 'Amadou']];

    private array $manifeste = ['etudiants' => [], 'pv_crees' => [], 'pv_touches' => [], 'promotions' => [], 'utilisateurs' => [], 'parents' => [], 'mesr' => [], 'anab' => []];

    private array $etat = []; // etudiant => [parcours, étape, profil]

    private int $auteur;

    public function handle(RegistreUniversites $registre, UniversiteCourante $courante): int
    {
        $u = $registre->trouver((string) $this->argument('universite'));
        if (! $u || ! $u->estUniversite() || ! $u->base) {
            $this->error('Université inconnue.');

            return self::FAILURE;
        }
        if (! str_contains($u->base, '_test') && ! $this->option('base-reelle-autorisee')) {
            $this->error("« {$u->base} » n'est pas une base de test : ajouter --base-reelle-autorisee (après une sauvegarde).");

            return self::FAILURE;
        }
        $courante->definir($u);
        $fichier = storage_path("app/private/essais/peuplement-{$u->code}.json");
        // Le nom de la base vient du .env (UNI_<CODE>_DB) : le montrer avant d'écrire quoi que ce soit
        if (! $this->confirm("Base « {$u->base} » ({$u->sigle}) : ".($this->option('annuler') ? 'retirer le peuplement' : 'ajouter des étudiants d\'essai').' ?', false)) {
            return self::FAILURE;
        }

        if ($this->option('annuler')) {
            return $this->annuler($fichier);
        }
        if (is_file($fichier)) {
            $this->error('Un peuplement existe déjà pour cette université : lancer d\'abord --annuler.');

            return self::FAILURE;
        }
        @mkdir(dirname($fichier), 0750, true);
        mt_srand(crc32($u->code.'peupler'));

        $annees = $this->annees();
        if (count($annees) < 3) {
            $this->error('Il faut l\'année active et les deux précédentes.');

            return self::FAILURE;
        }
        $parcours = $this->parcours();
        if (! $parcours) {
            $this->error('Aucun parcours trouvé (filières DROIT / DR-PRIV / IG et niveaux attendus).');

            return self::FAILURE;
        }
        $this->comptes($u);

        try {
            foreach (array_values($annees) as $i => $annee) {
                $this->line("  {$annee->libelle}…");
                foreach ($parcours as $nom => $etapes) {
                    $this->nouveauxEtudiants($nom, $etapes[0], $annee, (int) $this->option('etudiants'));
                }
                $this->annee($annee, $parcours, $i === count($annees) - 1, $annees[$i + 1] ?? null);
            }
            $this->finitions($u);
        } finally {
            file_put_contents($fichier, json_encode($this->manifeste, JSON_PRETTY_PRINT));
        }

        $this->table(['Ajouté', 'Nombre'], [
            ['étudiants', count($this->manifeste['etudiants'])], ['PV créés', count($this->manifeste['pv_crees'])],
            ['PV existants complétés', count(array_diff($this->manifeste['pv_touches'], $this->manifeste['pv_crees']))],
            ['promotions créées (maquette copiée)', count($this->manifeste['promotions'])],
            ['notes', DB::table('notes')->whereIn('etudiant_id', $this->manifeste['etudiants'])->count()],
            ['résultats', DB::table('resultats_semestre')->whereIn('etudiant_id', $this->manifeste['etudiants'])->count()],
            ['comptes d\'essai', count($this->manifeste['utilisateurs']) + count($this->manifeste['parents']) + count($this->manifeste['mesr']) + count($this->manifeste['anab'])],
        ]);
        $this->info('Manifeste : '.$fichier);
        $this->info('Identifiants des comptes d\'essai : storage/app/private/essais/identifiants.txt');

        return self::SUCCESS;
    }

    /** L'année active et les deux précédentes (par libellé). */
    private function annees(): array
    {
        $active = DB::table('annees_academiques')->where('active', 1)->value('libelle');

        return DB::table('annees_academiques')->where('libelle', '<=', $active)->orderByDesc('libelle')->limit(3)->get()->reverse()->values()->all();
    }

    /** Parcours dont toutes les filières et tous les niveaux existent : [nom => [[filiere_id, niveau_id, [sem_code => sem_id]], …]] */
    private function parcours(): array
    {
        $filieres = DB::table('filieres')->pluck('id', 'code');
        $niveaux = DB::table('niveaux')->pluck('id', 'code');
        $semestres = DB::table('semestres')->pluck('id', 'code');
        $resultat = [];
        foreach (self::PARCOURS as $nom => $etapes) {
            $ok = true;
            $liste = [];
            foreach ($etapes as [$fil, $niv, $sems]) {
                $ok = $ok && isset($filieres[$fil], $niveaux[$niv]) && ! array_diff($sems, $semestres->keys()->all());
                $liste[] = [$filieres[$fil] ?? 0, $niveaux[$niv] ?? 0, array_combine($sems, array_map(fn ($s) => $semestres[$s] ?? 0, $sems))];
            }
            if ($ok) {
                $resultat[$nom] = $liste;
            }
        }

        return $resultat;
    }

    private function motDePasse(): string
    {
        $f = storage_path('app/private/essais/identifiants.txt');
        if (is_file($f) && preg_match('/^Mot de passe : (\S+)$/m', (string) file_get_contents($f), $m)) {
            return $m[1];
        }
        $mdp = 'Essai'.bin2hex(random_bytes(4)).'9';
        file_put_contents($f, "Comptes d'essai créés par php artisan gesnotes:peupler (bases d'essai uniquement).\n"
            ."Mot de passe : $mdp\n\nPersonnel : essai.admin@essai.local, essai.secretaire@essai.local, essai.enseignant@essai.local (si un enseignant existe)\n"
            ."Étudiant : essai.etudiant@essai.local — Parent : téléphone 99000001\nMinistère : essai_mesr — ANAB : essai_anab (base UDH)\n");

        return $mdp;
    }

    private function comptes($u): void
    {
        $mdp = Hash::make($this->motDePasse());
        $creer = function (string $email, string $role, array $extra = []) use ($mdp) {
            if (DB::table('utilisateurs')->where('email', $email)->exists()) {
                return (int) DB::table('utilisateurs')->where('email', $email)->value('id');
            }
            $id = DB::table('utilisateurs')->insertGetId($extra + ['email' => $email, 'nom' => 'ESSAI', 'prenoms' => ucfirst($role), 'mot_de_passe' => $mdp, 'role' => $role, 'actif' => 1]);
            $this->manifeste['utilisateurs'][] = $id;

            return $id;
        };
        $this->auteur = $creer('essai.admin@essai.local', 'admin');
        $creer('essai.secretaire@essai.local', 'secretaire');
        if ($ens = DB::table('enseignants')->where('actif', 1)->value('id')) {
            $creer('essai.enseignant@essai.local', 'enseignant', ['enseignant_id' => $ens]);
        }
        if ($u->code === config('gesnotes.espaces_comptes')) {
            foreach (['mesr' => 'mesr_comptes', 'anab' => 'anab_comptes'] as $cle => $table) {
                if (! DB::table($table)->where('login', "essai_$cle")->exists()) {
                    $this->manifeste[$cle][] = DB::table($table)->insertGetId(['login' => "essai_$cle", 'mot_de_passe' => $mdp, 'nom_complet' => 'Compte d\'essai', 'role' => 'admin', 'actif' => 1]);
                }
            }
        }
    }

    private function nouveauxEtudiants(string $parcours, array $etape, object $annee, int $nb): void
    {
        $profils = array_keys(self::PROFILS);
        for ($i = 0; $i < $nb; $i++) {
            $sexe = $i % 2 === 0 ? 'F' : 'M';
            $id = DB::table('etudiants')->insertGetId([
                'nom' => self::NOMS[mt_rand(0, 15)], 'prenoms' => self::PRENOMS[$sexe][mt_rand(0, 7)], 'sexe' => $sexe,
                'date_naissance' => (2004 + (int) substr($annee->libelle, 2, 2) - 24).'-0'.mt_rand(1, 9).'-1'.mt_rand(0, 9),
                'lieu_naissance' => ['Tahoua', 'Niamey', 'Maradi', 'Zinder', 'Dosso', 'Agadez'][mt_rand(0, 5)], 'nationalite' => 'Nigérienne',
                'filiere_id' => $etape[0], 'statut_etudiant' => 'actif', 'bac_annee' => (int) substr($annee->libelle, 0, 4),
                'telephone' => '96'.str_pad((string) mt_rand(0, 999999), 6, '0', STR_PAD_LEFT),
            ]);
            app(Matricules::class)->attribuer($id);
            $this->manifeste['etudiants'][] = $id;
            $this->etat[$id] = [$parcours, 0, $profils[$i % count($profils)]];
        }
    }

    private function annee(object $annee, array $parcours, bool $enCours, ?object $suivante): void
    {
        $aPasser = [];
        foreach ($parcours as $nom => $etapes) {
            foreach ($etapes as $k => [$fil, $niv, $sems]) {
                $etudiants = array_keys(array_filter($this->etat, fn ($e) => $e[0] === $nom && $e[1] === $k));
                if (! $etudiants) {
                    continue;
                }
                $promotionsAvant = DB::table('promotions')->pluck('id')->all();
                $promo = app(Inscriptions::class)->promotion($fil, $niv, $annee->id);
                if (! in_array($promo, $promotionsAvant, true)) {
                    $this->manifeste['promotions'][] = $promo;
                }
                foreach ($etudiants as $et) {
                    DB::table('etudiants')->where('id', $et)->update(['filiere_id' => $fil]);
                    $ins = DB::table('inscriptions')->insertGetId(['etudiant_id' => $et, 'promotion_id' => $promo, 'niveau_id' => $niv, 'annee_id' => $annee->id,
                        'date_inscription' => substr($annee->libelle, 0, 4).'-10-15', 'statut' => 'inscrit', 'montant_paye' => Inscriptions::FRAIS, 'mode_paiement' => 'especes', 'saisie_par' => $this->auteur]);
                    $paye = $this->etat[$et][2] === 'faible' ? 25000 : 50000;
                    DB::table('paiements')->insert(['etudiant_id' => $et, 'inscription_id' => $ins, 'type_paiement' => 'scolarite', 'montant_du' => 50000, 'montant_paye' => $paye,
                        'date_paiement' => substr($annee->libelle, 0, 4).'-10-15', 'mode_paiement' => 'especes', 'statut' => $paye < 50000 ? 'partiel' : 'complet', 'saisi_par' => $this->auteur, 'annee_id' => $annee->id]);
                    foreach ($sems as $semId) {
                        DB::table('inscriptions_pedagogiques')->insertOrIgnore(['etudiant_id' => $et, 'inscription_id' => $ins, 'semestre_id' => $semId, 'annee_id' => $annee->id,
                            'statut' => 'actif', 'date_inscription' => substr($annee->libelle, 0, 4).'-10-20', 'saisie_par' => $this->auteur]);
                    }
                }
                $j = 0;
                foreach ($sems as $code => $semId) {
                    $this->semestre($promo, $semId, $code, $etudiants, $enCours ? ($j === 0 ? 'publie' : 'saisie') : 'complet', $annee->libelle);
                    $j++;
                }
                if (! $enCours && $suivante) {
                    foreach ($etudiants as $et) {
                        $valides = DB::table('resultats_semestre as rs')->join('pv_sessions as ps', 'ps.id', '=', 'rs.pv_id')
                            ->where('rs.etudiant_id', $et)->where('rs.valide', 1)->where('ps.promotion_id', $promo)->distinct()->count('ps.semestre_id');
                        $derniere = $k === count($etapes) - 1;
                        $decision = $valides === count($sems) ? ($derniere ? 'licence_validee' : 'passage') : 'redoublement';
                        $suivantNiv = $decision === 'passage' ? $etapes[$k + 1][1] : $niv;
                        app(Inscriptions::class)->enregistrerPassage($et, $fil, $niv, $suivantNiv, $annee->id, $suivante->id, $decision);
                        if ($decision === 'passage') {
                            $aPasser[$et] = $k + 1;
                        } elseif ($decision === 'licence_validee') {
                            $aPasser[$et] = 99;
                            DB::table('etudiants')->where('id', $et)->update(['statut_etudiant' => 'diplome']);
                        }
                    }
                }
            }
        }
        foreach ($aPasser as $et => $etape) {
            $this->etat[$et][1] = $etape;
        }
    }

    /** PV normal (réutilisé s'il existe) puis, années terminées, rattrapage. */
    private function semestre(int $promo, int $semId, string $code, array $etudiants, string $etat, string $annee): void
    {
        $libelle = "PV $code — ".DB::table('promotions as p')->join('filieres as f', 'f.id', '=', 'p.filiere_id')->join('niveaux as n', 'n.id', '=', 'p.niveau_id')
            ->where('p.id', $promo)->selectRaw("CONCAT(n.code, ' ', f.libelle) AS l")->value('l')." — $annee";
        $normale = $this->pv($promo, $semId, 'normale', null, $libelle, $etat === 'saisie' ? 'brouillon' : 'publie');
        $pv = app(Pv::class)->trouver($normale);
        $matieres = app(SaisieNotes::class)->matieres($pv);
        $saisies = [];
        foreach ($etudiants as $i => $et) {
            if ($etat === 'saisie' && $i % 3 === 2) {
                continue;
            }
            [$cible, , $absent] = self::PROFILS[$this->etat[$et][2]];
            foreach ($matieres as $j => $m) {
                $saisies[$et][$m->id] = ($absent && $j === 0) ? [null, null] : $this->note($cible, $m);
            }
        }
        app(SaisieNotes::class)->enregistrer($pv, $saisies, $this->auteur);
        if ($etat === 'saisie') {
            return;
        }
        $this->calculer($normale, array_keys($saisies));
        if ($etat !== 'complet') {
            return;
        }
        $nonValides = DB::table('resultats_semestre')->where('pv_id', $normale)->whereIn('etudiant_id', $etudiants)->where('valide', 0)->pluck('etudiant_id')->all();
        if (! $nonValides) {
            return;
        }
        $ratt = $this->pv($promo, $semId, 'rattrapage', $normale, str_replace("PV $code", "PV $code Rattrapage", $libelle), 'publie');
        $pvR = app(Pv::class)->trouver($ratt);
        $saisiesR = [];
        foreach ($nonValides as $et) {
            [$cible, $gain] = self::PROFILS[$this->etat[$et][2]];
            foreach ($matieres as $m) {
                $saisiesR[$et][$m->id] = $this->note($cible + $gain, $m);
            }
        }
        app(SaisieNotes::class)->enregistrer($pvR, $saisiesR, $this->auteur);
        $this->calculer($ratt, $nonValides);
    }

    /** PV existant (réutilisé tel quel) ou créé avec le statut voulu. */
    private function pv(int $promo, int $semId, string $type, ?int $normale, string $libelle, string $statut): int
    {
        $id = DB::table('pv_sessions')->where(['promotion_id' => $promo, 'semestre_id' => $semId, 'type_session' => $type])->value('id');
        if (! $id) {
            $id = DB::table('pv_sessions')->insertGetId(['promotion_id' => $promo, 'semestre_id' => $semId, 'type_session' => $type, 'pv_normale_id' => $normale,
                'libelle' => mb_substr($libelle, 0, 200), 'statut' => $statut, 'verrouille' => 0, 'created_by' => $this->auteur]);
            $this->manifeste['pv_crees'][] = $id;
        }
        $this->manifeste['pv_touches'][] = $id;

        return (int) $id;
    }

    private function note(float $cible, object $m): array
    {
        $sur20 = max(0, min(20, $cible + mt_rand(-30, 30) / 10));
        $max = (float) $m->note_max_examen;
        $cc = (float) $m->note_max_cc;
        $td = $cc > 0 ? round(min($cc, $sur20 / 20 * $cc + mt_rand(-5, 5) / 10), 2) : null;

        return [round(min($max, max(0, $sur20 / 20 * $max)), 2), $td];
    }

    private function calculer(int $pvId, array $etudiants): void
    {
        foreach ($etudiants as $et) {
            app(Resultats::class)->recalculer($pvId, (int) $et);
        }
        app(Resultats::class)->recalculerRangs($pvId);
    }

    /** Compte étudiant et parent pour le meilleur étudiant ajouté, affectation de l'enseignant. */
    private function finitions($u): void
    {
        $et = DB::table('etudiants')->whereIn('id', $this->manifeste['etudiants'])->orderBy('id')->first();
        $mdp = Hash::make($this->motDePasse());
        if (! DB::table('utilisateurs')->where('email', 'essai.etudiant@essai.local')->exists()) {
            $this->manifeste['utilisateurs'][] = DB::table('utilisateurs')->insertGetId(['email' => 'essai.etudiant@essai.local', 'nom' => $et->nom, 'prenoms' => $et->prenoms,
                'mot_de_passe' => $mdp, 'role' => 'etudiant', 'etudiant_id' => $et->id, 'actif' => 1]);
        }
        if (! DB::table('parents')->where('telephone', '99000001')->exists()) {
            $p = DB::table('parents')->insertGetId(['nom' => $et->nom, 'prenoms' => 'Parent essai', 'telephone' => '99000001', 'mot_de_passe_hash' => $mdp, 'actif' => 1]);
            DB::table('parents_etudiants')->insert(['parent_id' => $p, 'etudiant_id' => $et->id, 'lien' => 'parent']);
            $this->manifeste['parents'][] = $p;
        }
    }

    private function annuler(string $fichier): int
    {
        if (! is_file($fichier)) {
            $this->error('Aucun peuplement enregistré pour cette université.');

            return self::FAILURE;
        }
        $m = json_decode((string) file_get_contents($fichier), true);
        $et = $m['etudiants'] ?? [];
        DB::transaction(function () use ($m, $et) {
            foreach (['notes', 'resultats_semestre', 'resultats_ue', 'inscriptions_pedagogiques', 'inscriptions', 'paiements', 'passages_niveau', 'parents_etudiants'] as $t) {
                DB::table($t)->whereIn('etudiant_id', $et)->delete();
            }
            DB::table('utilisateurs')->whereIn('id', $m['utilisateurs'] ?? [])->delete();
            DB::table('parents_etudiants')->whereIn('parent_id', $m['parents'] ?? [])->delete();
            DB::table('parents')->whereIn('id', $m['parents'] ?? [])->delete();
            DB::table('mesr_comptes')->whereIn('id', $m['mesr'] ?? [])->delete();
            DB::table('anab_comptes')->whereIn('id', $m['anab'] ?? [])->delete();
            // PV créés : seulement s'ils ne contiennent plus rien d'autre
            foreach ($m['pv_crees'] ?? [] as $pv) {
                if (! DB::table('notes')->where('pv_id', $pv)->exists() && ! DB::table('resultats_semestre')->where('pv_id', $pv)->exists()) {
                    DB::table('pv_sessions')->where('id', $pv)->delete();
                }
            }
            foreach ($m['promotions'] ?? [] as $p) {
                if (! DB::table('inscriptions')->where('promotion_id', $p)->exists() && ! DB::table('pv_sessions')->where('promotion_id', $p)->exists()) {
                    DB::table('matieres')->whereIn('ue_id', DB::table('unites_enseignement')->where('promotion_id', $p)->pluck('id'))->delete();
                    DB::table('unites_enseignement')->where('promotion_id', $p)->delete();
                    DB::table('promotions')->where('id', $p)->delete();
                }
            }
            DB::table('etudiants')->whereIn('id', $et)->delete();
        });
        foreach (array_unique(array_diff($m['pv_touches'] ?? [], $m['pv_crees'] ?? [])) as $pv) {
            app(Resultats::class)->recalculerRangs((int) $pv);
        }
        rename($fichier, $fichier.'.annule-'.date('Ymd-His'));
        $this->info(count($et).' étudiant(s) d\'essai et leurs données retirés.');

        return self::SUCCESS;
    }
}
