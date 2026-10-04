<?php

namespace App\Console\Commands;

use App\Services\Inscriptions;
use App\Services\Licence;
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
 * Remplit une base de TEST avec un jeu de démonstration réaliste : deux
 * facultés, deux licences (L1–L3), trois années académiques, trois
 * promotions d'étudiants aux profils variés (bons, moyens, rattrapage,
 * redoublants, défaillants), PV de session normale et de rattrapage
 * calculés par le moteur de résultats, passages de niveau, paiements,
 * comptes de chaque rôle.
 *
 * Passe par les services de l'application (matricules, saisie des notes,
 * moteur de résultats, passages) : les résultats sont ceux que
 * l'application produirait. Refuse toute base dont le nom ne contient pas
 * « _test » ; la base est vidée avant d'être remplie.
 */
class GenererDemo extends Command
{
    protected $signature = 'gesnotes:demo
        {universite=udh : université dont la base (UNI_<CODE>_DB) est remplie}
        {--permissions-depuis= : base d\'où copier le catalogue des permissions et des rôles (lecture seule)}
        {--etudiants=8 : étudiants par filière et par promotion d\'entrée}';

    protected $description = 'Remplit une base de test (nom en _test) avec des données de démonstration';

    /** Mot de passe de tous les comptes de démonstration (base de test uniquement). */
    public const MOT_DE_PASSE = 'Demo2026!';

    private const ANNEES = ['2023-2024', '2024-2025', '2025-2026'];

    private const NOMS = ['ABDOU', 'MOUSSA', 'ISSOUFOU', 'HAMANI', 'SALEY', 'GARBA', 'IBRAHIM', 'MAHAMANE', 'OUMAROU', 'BOUBACAR', 'ALI', 'SOUMANA', 'HASSANE', 'ADAMOU', 'IDRISSA', 'YACOUBA'];

    private const PRENOMS_F = ['Aïchatou', 'Mariama', 'Fati', 'Hadiza', 'Zeinabou', 'Rahama', 'Safiatou', 'Balkissa'];

    private const PRENOMS_M = ['Abdoulaye', 'Issaka', 'Moustapha', 'Souleymane', 'Ousmane', 'Hamidou', 'Yahaya', 'Amadou'];

    /** Profils : [moyenne visée en session normale, gain au rattrapage, absent à un examen ?] */
    private const PROFILS = [
        'excellent' => [15.5, 0, false], 'bon' => [13, 0, false], 'moyen' => [11, 0, false], 'juste' => [9.5, 2.5, false],
        'rattrapage' => [8, 3.5, false], 'faible' => [6.5, 1, false], 'absent' => [10, 0, true], 'moyen2' => [10.5, 1.5, false],
    ];

    private int $admin;

    private array $semestres = [];

    private array $niveaux = [];

    private array $annees = [];

    public function handle(RegistreUniversites $registre, UniversiteCourante $courante): int
    {
        $u = $registre->trouver((string) $this->argument('universite'));
        if (! $u || ! $u->estUniversite() || ! $u->base || ! str_contains($u->base, '_test')) {
            $this->error('Refusé : la base de cette université doit avoir un nom contenant « _test » (UNI_<CODE>_DB).');

            return self::FAILURE;
        }
        mt_srand(crc32($u->code));
        $courante->definir($u);
        $this->info("Base {$u->base} ({$u->sigle}) : remise à zéro puis remplissage…");
        $this->vider();
        $this->referentiel($u);
        $this->comptes($u);

        $filieres = $this->structure();
        $parCohorte = [];
        foreach (self::ANNEES as $i => $annee) {
            // Nouvelle promotion d'entrée chaque année
            foreach ($filieres as $fil) {
                $parCohorte[$annee][$fil->id] = $this->nouveauxEtudiants($fil, $annee, (int) $this->option('etudiants'));
            }
            $this->anneeAcademique($annee, $filieres, $i === count(self::ANNEES) - 1);
        }
        $this->finitions();

        $this->table(['Élément', 'Nombre'], collect(['etudiants', 'inscriptions', 'inscriptions_pedagogiques', 'pv_sessions', 'notes', 'resultats_semestre', 'passages_niveau', 'paiements', 'utilisateurs'])
            ->map(fn ($t) => [$t, DB::table($t)->count()])->all());
        $this->info('Comptes : admin@demo.test, secretaire@demo.test, scolarite.fs@demo.test (agent FS), doyen.fs@demo.test, enseignant@demo.test, recteur@demo.test,');
        $this->info('etudiant@demo.test (portail étudiant) ; parent : téléphone 90000001 — mot de passe commun : constante GenererDemo::MOT_DE_PASSE.');

        return self::SUCCESS;
    }

    private function vider(): void
    {
        DB::statement('SET FOREIGN_KEY_CHECKS=0');
        foreach (DB::select('SELECT table_name AS t FROM information_schema.tables WHERE table_schema = DATABASE() AND table_type = "BASE TABLE"') as $t) {
            DB::table($t->t)->truncate();
        }
        DB::statement('SET FOREIGN_KEY_CHECKS=1');
    }

    private function referentiel($u): void
    {
        $source = (string) $this->option('permissions-depuis');
        if ($source !== '' && preg_match('/^[A-Za-z0-9_]+$/', $source)) {
            DB::statement("INSERT INTO permissions SELECT * FROM `$source`.permissions");
            DB::statement("INSERT INTO role_permissions SELECT * FROM `$source`.role_permissions");
        }
        DB::table('parametres')->insert([
            ['cle' => 'institution_nom', 'valeur' => $u->nom, 'label' => "Nom de l'institution", 'groupe' => 'institution'],
            ['cle' => 'institution_ville', 'valeur' => $u->ville, 'label' => 'Ville', 'groupe' => 'institution'],
            ['cle' => 'institution_republique', 'valeur' => 'République du Niger', 'label' => 'République', 'groupe' => 'institution'],
            ['cle' => 'licence_cle', 'valeur' => app(Licence::class)->generer(strtoupper($u->code)), 'label' => 'Clé de licence', 'groupe' => 'licence'],
        ]);
        foreach (['S1', 'S2', 'S3', 'S4', 'S5', 'S6'] as $s) {
            $this->semestres[$s] = DB::table('semestres')->insertGetId(['code' => $s, 'libelle' => 'Semestre '.substr($s, 1)]);
        }
        foreach (['L1' => 'Licence 1', 'L2' => 'Licence 2', 'L3' => 'Licence 3'] as $c => $l) {
            $this->niveaux[$c] = DB::table('niveaux')->insertGetId(['code' => $c, 'libelle' => $l]);
        }
        foreach (self::ANNEES as $i => $a) {
            [$debut, $fin] = explode('-', $a);
            $this->annees[$a] = DB::table('annees_academiques')->insertGetId(['libelle' => $a, 'date_debut' => "$debut-10-01", 'date_fin' => "$fin-07-31",
                'active' => $i === count(self::ANNEES) - 1 ? 1 : 0]);
        }
    }

    private function comptes($u): void
    {
        $fs = DB::table('facultes')->insertGetId(['code' => 'FS', 'libelle' => 'Faculté des Sciences', 'couleur' => '#1A3A5C', 'ville' => $u->ville, 'actif' => 1]);
        $fd = DB::table('facultes')->insertGetId(['code' => 'FDSE', 'libelle' => 'Faculté de Droit et Sciences Économiques', 'couleur' => '#7c2d12', 'ville' => $u->ville, 'actif' => 1]);
        $ens = DB::table('enseignants')->insertGetId(['matricule' => 'ENS001', 'nom' => 'KADRI', 'prenoms' => 'Halima', 'grade' => 'Maître-Assistant', 'faculte_id' => $fs, 'actif' => 1]);
        $mdp = Hash::make(self::MOT_DE_PASSE);
        $compte = fn ($email, $nom, $role, $extra = []) => DB::table('utilisateurs')->insertGetId($extra + ['email' => $email, 'nom' => $nom, 'prenoms' => 'Démo',
            'mot_de_passe' => $mdp, 'role' => $role, 'actif' => 1]);
        $this->admin = $compte('admin@demo.test', 'ADMIN', 'admin');
        $compte('secretaire@demo.test', 'SECRETAIRE', 'secretaire');
        $compte('scolarite.fs@demo.test', 'AGENT FS', 'sc_entite', ['faculte_id' => $fs]);
        $compte('doyen.fs@demo.test', 'DOYEN FS', 'doyen', ['faculte_id' => $fs]);
        $compte('enseignant@demo.test', 'KADRI', 'enseignant', ['enseignant_id' => $ens]);
        $compte('recteur@demo.test', 'RECTEUR', 'recteur');
        DB::table('signataires')->insert([['titre' => 'Le Doyen', 'nom' => 'Pr. MAHAMADOU Ali', 'actif' => 1, 'faculte_id' => $fs],
            ['titre' => 'Le Doyen', 'nom' => 'Pr. SIDIBÉ Awa', 'actif' => 1, 'faculte_id' => $fd]]);
    }

    /** Deux filières de licence avec leur faculté. */
    private function structure(): array
    {
        $fac = DB::table('facultes')->pluck('id', 'code');

        return [
            (object) ['id' => DB::table('filieres')->insertGetId(['code' => 'MI', 'libelle' => 'Mathématiques-Informatique', 'departement' => 'Mathématiques',
                'faculte' => 'Faculté des Sciences', 'faculte_id' => $fac['FS'], 'actif' => 1, 'domaine' => 'Sciences et Technologies', 'mention_academique' => 'Mathématiques']), 'code' => 'MI'],
            (object) ['id' => DB::table('filieres')->insertGetId(['code' => 'DP', 'libelle' => 'Droit Privé', 'departement' => 'Droit',
                'faculte' => 'Faculté de Droit et Sciences Économiques', 'faculte_id' => $fac['FDSE'], 'actif' => 1, 'domaine' => 'Droit et Gestion', 'mention_academique' => 'Droit']), 'code' => 'DP'],
        ];
    }

    private function nouveauxEtudiants(object $fil, string $annee, int $nb): array
    {
        $profils = array_keys(self::PROFILS);
        $ids = [];
        for ($i = 0; $i < $nb; $i++) {
            $femme = $i % 2 === 0;
            $id = DB::table('etudiants')->insertGetId([
                'nom' => self::NOMS[mt_rand(0, count(self::NOMS) - 1)], 'prenoms' => ($femme ? self::PRENOMS_F : self::PRENOMS_M)[mt_rand(0, 7)],
                'sexe' => $femme ? 'F' : 'M', 'date_naissance' => (2004 - (int) substr($annee, 2, 2) + 23).'-0'.mt_rand(1, 9).'-1'.mt_rand(0, 9),
                'lieu_naissance' => ['Tahoua', 'Niamey', 'Maradi', 'Zinder', 'Dosso'][mt_rand(0, 4)], 'nationalite' => 'Nigérienne',
                'filiere_id' => $fil->id, 'statut_etudiant' => 'actif', 'bac_serie' => $fil->code === 'MI' ? 'C' : 'A4', 'bac_annee' => (int) substr($annee, 0, 4),
                'telephone' => '96'.str_pad((string) mt_rand(0, 999999), 6, '0', STR_PAD_LEFT),
            ]);
            app(Matricules::class)->attribuer($id);
            DB::table('etudiants')->where('id', $id)->update(['email' => null]);
            $ids[$id] = $profils[$i % count($profils)];
            $this->profil[$id] = $profils[$i % count($profils)];
            $this->niveauEtudiant[$id] = 'L1';
            $this->filiereEtudiant[$id] = $fil->id;
        }

        return $ids;
    }

    private array $profil = [];

    private array $niveauEtudiant = [];

    private array $filiereEtudiant = [];

    private array $diplomes = [];

    private array $aPasser = [];

    /** Une année : inscriptions, maquettes, PV normale + rattrapage par semestre, passages. */
    private function anneeAcademique(string $annee, array $filieres, bool $enCours): void
    {
        $anneeId = $this->annees[$annee];
        $this->aPasser = [];
        $this->line("  {$annee}…");
        foreach ($filieres as $fil) {
            foreach (['L1' => ['S1', 'S2'], 'L2' => ['S3', 'S4'], 'L3' => ['S5', 'S6']] as $niv => $sems) {
                $etudiants = array_keys(array_filter($this->niveauEtudiant, fn ($n, $id) => $n === $niv && $this->filiereEtudiant[$id] === $fil->id && ! isset($this->diplomes[$id]), ARRAY_FILTER_USE_BOTH));
                if (! $etudiants) {
                    continue;
                }
                $promo = app(Inscriptions::class)->promotion($fil->id, $this->niveaux[$niv], $anneeId);
                $this->maquette($promo, $fil, $niv, $sems);
                foreach ($etudiants as $et) {
                    $ins = DB::table('inscriptions')->insertGetId(['etudiant_id' => $et, 'promotion_id' => $promo, 'niveau_id' => $this->niveaux[$niv], 'annee_id' => $anneeId,
                        'date_inscription' => substr($annee, 0, 4).'-10-15', 'statut' => 'inscrit', 'montant_paye' => Inscriptions::FRAIS, 'mode_paiement' => 'especes', 'saisie_par' => $this->admin]);
                    $paye = $this->profil[$et] === 'faible' ? 25000 : 50000;
                    DB::table('paiements')->insert(['etudiant_id' => $et, 'inscription_id' => $ins, 'type_paiement' => 'scolarite', 'montant_du' => 50000, 'montant_paye' => $paye,
                        'date_paiement' => substr($annee, 0, 4).'-10-15', 'mode_paiement' => 'especes', 'statut' => $paye < 50000 ? 'partiel' : 'complet', 'saisi_par' => $this->admin, 'annee_id' => $anneeId]);
                    foreach ($sems as $s) {
                        DB::table('inscriptions_pedagogiques')->insert(['etudiant_id' => $et, 'inscription_id' => $ins, 'semestre_id' => $this->semestres[$s], 'annee_id' => $anneeId,
                            'statut' => 'actif', 'date_inscription' => substr($annee, 0, 4).'-10-20', 'saisie_par' => $this->admin]);
                    }
                }
                foreach ($sems as $k => $s) {
                    // Année en cours : S1/S3/S5 publiés, S2/S4/S6 en saisie (brouillon, notes partielles)
                    $this->semestre($promo, $s, $etudiants, $enCours ? ($k === 0 ? 'publie' : 'saisie') : 'complet', $annee);
                }
                if (! $enCours) {
                    $this->passages($fil, $niv, $sems, $etudiants, $promo, $anneeId);
                }
            }
        }
        foreach ($this->aPasser as $et => $niveau) {
            $this->niveauEtudiant[$et] = $niveau;
        }
    }

    /** Maquette d'un niveau : 2 UE par semestre, 2 matières par UE (dont une avec TD). */
    private function maquette(int $promo, object $fil, string $niv, array $sems): void
    {
        if (DB::table('unites_enseignement')->where('promotion_id', $promo)->exists()) {
            return;
        }
        $intitules = $fil->code === 'MI'
            ? [['Analyse', 'Algèbre'], ['Algorithmique', 'Programmation'], ['Probabilités', 'Statistique'], ['Bases de données', 'Réseaux']]
            : [['Droit civil', 'Droit constitutionnel'], ['Introduction à l\'économie', 'Histoire du droit'], ['Droit des obligations', 'Droit pénal'], ['Méthodologie', 'Anglais juridique']];
        foreach ($sems as $k => $s) {
            for ($u = 0; $u < 2; $u++) {
                $ue = DB::table('unites_enseignement')->insertGetId(['promotion_id' => $promo, 'semestre_id' => $this->semestres[$s],
                    'code' => "{$fil->code}{$s}U".($u + 1), 'libelle' => 'UE '.($u + 1)." — $s", 'credits_total' => 15, 'ordre' => $u + 1]);
                foreach ($intitules[($k * 2 + $u) % 4] as $m => $libelle) {
                    $avecTd = $m === 1;
                    DB::table('matieres')->insert(['ue_id' => $ue, 'code' => "{$fil->code}{$s}".($u + 1).($m + 1), 'libelle' => "$libelle ($niv)",
                        'note_max_examen' => $avecTd ? 14 : 20, 'note_max_cc' => $avecTd ? 6 : 0, 'note_max_totale' => 20,
                        'credits' => $m === 0 ? 8 : 7, 'coefficient' => 1, 'ordre' => $m + 1, 'volume_horaire_prevu' => 45, 'heures_faites' => mt_rand(20, 45)]);
                }
            }
        }
    }

    /**
     * PV d'un semestre. $etat : complet (normale + rattrapage publiés),
     * publie (normale publiée), saisie (normale en brouillon, notes partielles).
     */
    private function semestre(int $promo, string $s, array $etudiants, string $etat, string $annee): void
    {
        $semId = $this->semestres[$s];
        $libelle = "PV $s — ".DB::table('promotions as p')->join('filieres as f', 'f.id', '=', 'p.filiere_id')->where('p.id', $promo)->value('f.libelle')." — $annee";
        $normale = DB::table('pv_sessions')->insertGetId(['promotion_id' => $promo, 'semestre_id' => $semId, 'type_session' => 'normale', 'libelle' => $libelle,
            'date_pv' => (int) substr($annee, 5).'-0'.($s === 'S1' || $s === 'S3' || $s === 'S5' ? 2 : 6).'-15', 'statut' => 'brouillon', 'created_by' => $this->admin]);
        $pv = app(Pv::class)->trouver($normale);
        $matieres = app(SaisieNotes::class)->matieres($pv);
        $saisies = [];
        foreach ($etudiants as $i => $et) {
            if ($etat === 'saisie' && $i % 3 === 2) {
                continue; // notes pas encore toutes saisies
            }
            [$cible, , $absent] = self::PROFILS[$this->profil[$et]];
            foreach ($matieres as $j => $m) {
                $saisies[$et][$m->id] = ($absent && $j === 0) ? [null, null] : $this->note($cible, $m);
            }
        }
        app(SaisieNotes::class)->enregistrer($pv, $saisies, $this->admin);
        if ($etat === 'saisie') {
            return;
        }
        $this->calculer($normale, $etudiants);
        DB::table('pv_sessions')->where('id', $normale)->update(['statut' => 'publie', 'verrouille' => $etat === 'complet' ? 1 : 0]);
        if ($etat !== 'complet') {
            return;
        }
        // Rattrapage : étudiants non validés, notes copiées puis améliorées selon le profil
        $ratt = DB::table('pv_sessions')->insertGetId(['promotion_id' => $promo, 'semestre_id' => $semId, 'type_session' => 'rattrapage', 'pv_normale_id' => $normale,
            'libelle' => str_replace("PV $s", "PV $s Rattrapage", $libelle), 'statut' => 'brouillon', 'created_by' => $this->admin]);
        $pvR = app(Pv::class)->trouver($ratt);
        $nonValides = app(Pv::class)->etudiants($pvR)->pluck('e.id')->all();
        $saisiesR = [];
        foreach ($nonValides as $et) {
            [$cible, $gain] = self::PROFILS[$this->profil[$et]];
            foreach ($matieres as $m) {
                $saisiesR[$et][$m->id] = $this->note($cible + $gain, $m);
            }
        }
        app(SaisieNotes::class)->enregistrer($pvR, $saisiesR, $this->admin);
        $this->calculer($ratt, $nonValides);
        DB::table('pv_sessions')->where('id', $ratt)->update(['statut' => 'publie', 'verrouille' => 1]);
    }

    /** [examen, TD] autour de la moyenne visée, sur le barème de la matière. */
    private function note(float $cible, object $m): array
    {
        $sur20 = max(0, min(20, $cible + mt_rand(-30, 30) / 10));
        $max = (float) $m->note_max_examen;
        $td = (float) $m->note_max_cc > 0 ? round(min((float) $m->note_max_cc, $sur20 / 20 * (float) $m->note_max_cc + mt_rand(-5, 5) / 10), 2) : null;

        return [round(min($max, max(0, $sur20 / 20 * $max)), 2), $td];
    }

    private function calculer(int $pvId, array $etudiants): void
    {
        foreach ($etudiants as $et) {
            app(Resultats::class)->recalculer($pvId, (int) $et);
        }
        app(Resultats::class)->recalculerRangs($pvId);
    }

    /** Fin d'année : semestres validés (normale ou rattrapage) → passage, redoublement ou licence validée. */
    private function passages(object $fil, string $niv, array $sems, array $etudiants, int $promo, int $anneeId): void
    {
        $suivant = ['L1' => 'L2', 'L2' => 'L3', 'L3' => 'L3'][$niv];
        $libelles = array_keys($this->annees);
        $anneeSuivante = $this->annees[$libelles[array_search($anneeId, array_values($this->annees), true) + 1]];
        foreach ($etudiants as $et) {
            $valides = DB::table('resultats_semestre as rs')->join('pv_sessions as ps', 'ps.id', '=', 'rs.pv_id')
                ->where('rs.etudiant_id', $et)->where('rs.valide', 1)->where('ps.promotion_id', $promo)->distinct()->count('ps.semestre_id');
            $decision = $valides === count($sems) ? ($niv === 'L3' ? 'licence_validee' : 'passage') : 'redoublement';
            app(Inscriptions::class)->enregistrerPassage($et, $fil->id, $this->niveaux[$niv], $this->niveaux[$decision === 'passage' ? $suivant : $niv], $anneeId, $anneeSuivante, $decision);
            if ($decision === 'passage') {
                $this->aPasser[$et] = $suivant; // appliqué en fin d'année
            } elseif ($decision === 'licence_validee') {
                $this->diplomes[$et] = true;
                DB::table('etudiants')->where('id', $et)->update(['statut_etudiant' => 'diplome']);
            }
        }
    }

    /** Comptes étudiant et parent reliés à un étudiant réel du jeu, affectation de l'enseignant. */
    private function finitions(): void
    {
        $etudiant = DB::table('etudiants')->orderBy('id')->first();
        DB::table('utilisateurs')->insert(['email' => 'etudiant@demo.test', 'nom' => $etudiant->nom, 'prenoms' => $etudiant->prenoms,
            'mot_de_passe' => Hash::make(self::MOT_DE_PASSE), 'role' => 'etudiant', 'etudiant_id' => $etudiant->id, 'actif' => 1]);
        $parent = DB::table('parents')->insertGetId(['nom' => $etudiant->nom, 'prenoms' => 'Parent', 'telephone' => '90000001', 'mot_de_passe_hash' => Hash::make(self::MOT_DE_PASSE), 'actif' => 1]);
        DB::table('parents_etudiants')->insert(['parent_id' => $parent, 'etudiant_id' => $etudiant->id, 'lien' => 'parent']);
        $ens = DB::table('enseignants')->value('id');
        $active = end($this->annees);
        foreach (DB::table('matieres as m')->join('unites_enseignement as ue', 'ue.id', '=', 'm.ue_id')->join('promotions as p', 'p.id', '=', 'ue.promotion_id')
            ->join('filieres as f', 'f.id', '=', 'p.filiere_id')->where('p.annee_id', $active)->where('f.code', 'MI')->pluck('m.id') as $m) {
            DB::table('affectations')->insert(['enseignant_id' => $ens, 'matiere_id' => $m, 'annee_id' => $active, 'type_charge' => 'cours_td']);
        }
        DB::table('mesr_comptes')->insert(['login' => 'mesr', 'mot_de_passe' => Hash::make(self::MOT_DE_PASSE), 'nom_complet' => 'Direction des statistiques', 'role' => 'admin']);
        DB::table('anab_comptes')->insert(['login' => 'anab', 'mot_de_passe' => Hash::make(self::MOT_DE_PASSE), 'nom_complet' => 'Service des bourses', 'role' => 'admin']);
    }
}
