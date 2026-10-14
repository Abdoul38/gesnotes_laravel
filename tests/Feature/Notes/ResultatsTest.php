<?php

namespace Tests\Feature\Notes;

use App\Services\Maquette;
use App\Services\Resultats;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * Scénarios calculés à la main pour le moteur de résultats. La comparaison
 * complète avec l'ancien code se fait par outils/comparaison-resultats.
 *
 * Maquette : UE1 (6 cr.) = M1 examen/40 + TD/20 (3 cr.) et M2 examen/40 + TD/20 (3 cr.) ;
 *            UE2 (4 cr.) = M3 examen/20 (4 cr.).
 */
class ResultatsTest extends TestCase
{
    private Resultats $r;

    private int $annee;

    private int $promo;

    private int $sem;

    private int $pv;

    private array $m = [];

    private array $ue = [];

    protected function setUp(): void
    {
        parent::setUp();
        $this->r = app(Resultats::class);
        $this->annee = DB::table('annees_academiques')->insertGetId(['libelle' => '2025-2026']);
        $this->promo = $this->creerPromotion($this->annee);
        $this->sem = DB::table('semestres')->insertGetId(['code' => 'S1', 'libelle' => 'Semestre 1']);
        $this->ue['UE1'] = DB::table('unites_enseignement')->insertGetId(['promotion_id' => $this->promo, 'semestre_id' => $this->sem, 'code' => 'UE1', 'libelle' => 'UE1', 'credits_total' => 6, 'ordre' => 1]);
        $this->ue['UE2'] = DB::table('unites_enseignement')->insertGetId(['promotion_id' => $this->promo, 'semestre_id' => $this->sem, 'code' => 'UE2', 'libelle' => 'UE2', 'credits_total' => 4, 'ordre' => 2]);
        $this->m['M1'] = $this->matiere('UE1', 'M1', 40, 20, 3);
        $this->m['M2'] = $this->matiere('UE1', 'M2', 40, 20, 3);
        $this->m['M3'] = $this->matiere('UE2', 'M3', 20, 0, 4);
        $this->pv = $this->pv('normale');
    }

    private function matiere(string $ue, string $code, int $ex, int $cc, int $cr, string $type = 'obligatoire'): int
    {
        return DB::table('matieres')->insertGetId(['ue_id' => $this->ue[$ue], 'code' => $code, 'libelle' => $code, 'note_max_examen' => $ex, 'note_max_cc' => $cc, 'note_max_totale' => $ex + $cc, 'credits' => $cr, 'type_matiere' => $type, 'ordre' => 1]);
    }

    private function pv(string $type, ?int $normale = null): int
    {
        return DB::table('pv_sessions')->insertGetId(['promotion_id' => $this->promo, 'semestre_id' => $this->sem, 'type_session' => $type, 'pv_normale_id' => $normale, 'libelle' => "PV $type"]);
    }

    /** @param array<string, array{0: ?float, 1: ?float}> $notes code => [examen, TD] */
    private function etudiant(array $notes, ?int $pv = null): int
    {
        $et = DB::table('etudiants')->insertGetId(['nom' => 'E'.uniqid(), 'prenoms' => 'X']);
        foreach ($notes as $code => [$ex, $cc]) {
            DB::table('notes')->insert(['pv_id' => $pv ?? $this->pv, 'etudiant_id' => $et, 'matiere_id' => $this->m[$code], 'note_examen' => $ex, 'note_cc' => $cc]);
        }

        return $et;
    }

    private function resultat(int $et, ?int $pv = null): object
    {
        $this->r->recalculer($pv ?? $this->pv, $et);

        return DB::table('resultats_semestre')->where(['pv_id' => $pv ?? $this->pv, 'etudiant_id' => $et])->first();
    }

    private function obs(int $et, string $code, ?int $pv = null): ?string
    {
        return DB::table('notes')->where(['pv_id' => $pv ?? $this->pv, 'etudiant_id' => $et, 'matiere_id' => $this->m[$code]])->value('observation');
    }

    public function test_admis_et_note_finale_calculee(): void
    {
        $et = $this->etudiant(['M1' => [30, 15], 'M2' => [28, 14], 'M3' => [14, null]]);
        $r = $this->resultat($et);

        // M1 45/60 = 15 ; M2 42/60 = 14 ; UE1 87/120 = 14.5 ; UE2 = 14 ; moyenne 14.25
        $this->assertSame(['14.25', 10, 10, 1, 0, 'Bien', 'Admis', 1], [$r->moyenne_generale, (int) $r->credits_obtenus, (int) $r->credits_total, (int) $r->valide, (int) $r->defaillant, $r->mention, $r->decision, (int) $r->rang]);
        $this->assertSame('45.00', DB::table('notes')->where(['etudiant_id' => $et, 'matiere_id' => $this->m['M1']])->value('note_finale'));
        $this->assertSame(['AV', 'AV', 'AV'], [$this->obs($et, 'M1'), $this->obs($et, 'M2'), $this->obs($et, 'M3')]);
    }

    public function test_compensation_dans_l_ue(): void
    {
        // M1 24/60 = 8 (NV seule) ; M2 42/60 = 14 ; UE1 = 11 ≥ 10 → M1 compensée
        $et = $this->etudiant(['M1' => [16, 8], 'M2' => [28, 14], 'M3' => [12, null]]);
        $r = $this->resultat($et);
        $this->assertSame(['Admis', 10], [$r->decision, (int) $r->credits_obtenus]);
        $this->assertSame('AC', $this->obs($et, 'M1'));
    }

    public function test_compensation_entre_ue(): void
    {
        // UE1 = 15 ; UE2 = 8 (non validée) ; moyenne 11.5, une seule UE non validée → compensée
        $et = $this->etudiant(['M1' => [30, 15], 'M2' => [30, 15], 'M3' => [8, null]]);
        $r = $this->resultat($et);
        $this->assertSame(['11.50', 'Admis', 10], [$r->moyenne_generale, $r->decision, (int) $r->credits_obtenus]);
        $this->assertSame('AC', $this->obs($et, 'M3'));
        $this->assertSame(1, (int) DB::table('resultats_ue')->where(['etudiant_id' => $et, 'ue_id' => $this->ue['UE2']])->value('valide'));
    }

    public function test_defaillant_bloque_toute_compensation(): void
    {
        // TD de M2 manquant : défaillant ; M1 reste capitalisée (AV), mais pas de compensation
        $et = $this->etudiant(['M1' => [30, 15], 'M2' => [38, null], 'M3' => [16, null]]);
        $r = $this->resultat($et);
        $this->assertSame(['Défaillant', 'Défaillant', 1, 7, null], [$r->decision, $r->mention, (int) $r->defaillant, (int) $r->credits_obtenus, $r->rang]);
        $this->assertSame(['AV', 'Déf.', 'AV'], [$this->obs($et, 'M1'), $this->obs($et, 'M2'), $this->obs($et, 'M3')]);
    }

    public function test_note_eliminatoire_et_regle_figee_pour_l_annee(): void
    {
        // M3 4/20 < 5 : éliminatoire → « Elim » (aucune note ne manque), toujours défaillant pour les règles
        $et = $this->etudiant(['M1' => [38, 18], 'M2' => [38, 18], 'M3' => [4, null]]);
        $r = $this->resultat($et);
        $this->assertSame(['Elim', 'Elim', 1, null], [$r->decision, $r->mention, (int) $r->defaillant, $r->rang]);
        // La matière elle-même : « Elim » (et non « NV »), sans crédits
        $this->assertSame('Elim', $this->obs($et, 'M3'));
        $ru = DB::table('resultats_ue')->where(['etudiant_id' => $et, 'ue_id' => $this->ue['UE2']])->first();
        $this->assertSame('Elim', Resultats::observationUe($ru, false, 10));
        $this->assertSame('Déf.', Resultats::observationUe($ru, true, 10));

        // Une case vide l'emporte sur la note éliminatoire : « Défaillant »
        $vide = $this->etudiant(['M1' => [38, null], 'M2' => [38, 18], 'M3' => [4, null]]);
        $this->assertSame(['Défaillant', 'Défaillant'], [$this->resultat($vide)->decision, $this->resultat($vide)->mention]);

        // Moyenne d'UE sous la note éliminatoire d'UE, sans note éliminatoire de matière : « Elim »
        DB::table('parametres_annee')->insert(['cle' => 'note_eliminatoire', 'annee_id' => $this->annee, 'valeur' => '0']);
        $this->assertSame('Elim', $this->resultat($et)->decision);

        // Note éliminatoire désactivée pour CETTE année : compensation entre UE possible
        DB::table('parametres_annee')->insert(['cle' => 'note_eliminatoire_ue', 'annee_id' => $this->annee, 'valeur' => '0']);
        $this->assertSame('Admis', $this->resultat($et)->decision);
    }

    public function test_commande_distinguer_elimines(): void
    {
        // Résultats enregistrés avant la distinction : tous « Défaillant »
        $elim = $this->etudiant(['M1' => [38, 18], 'M2' => [38, 18], 'M3' => [4, null]]);
        $vide = $this->etudiant(['M1' => [38, null], 'M2' => [38, 18], 'M3' => [4, null]]);
        // Données anciennes : TD vide mais note finale enregistrée (examen seul) → case vide, reste « Défaillant »
        $tdVide = $this->etudiant(['M2' => [38, 18], 'M3' => [16, null]]);
        DB::table('notes')->insert(['pv_id' => $this->pv, 'etudiant_id' => $tdVide, 'matiere_id' => $this->m['M1'], 'note_examen' => 38, 'note_cc' => null, 'note_finale' => 38]);
        $this->assertSame('Défaillant', $this->resultat($tdVide)->decision);
        $this->resultat($elim);
        $this->resultat($vide);
        DB::table('resultats_semestre')->update(['decision' => 'Défaillant', 'mention' => 'Défaillant']);
        DB::table('notes')->where('observation', 'Elim')->update(['observation' => 'NV']); // M3 à 4/20 des deux premiers

        $this->artisan('gesnotes:distinguer-elimines', ['--universite' => ['udh']])
            ->expectsOutputToContain('1 résultat(s) « Défaillant » et 2 matière(s) « NV » à passer en « Elim »')->assertSuccessful();
        $this->assertSame(0, DB::table('resultats_semestre')->where('decision', 'Elim')->count());
        $this->assertSame(0, DB::table('notes')->where('observation', 'Elim')->count());

        $this->artisan('gesnotes:distinguer-elimines', ['--universite' => ['udh'], '--appliquer' => true])->assertSuccessful();
        $decision = fn ($et) => DB::table('resultats_semestre')->where('etudiant_id', $et)->first(['decision', 'mention']);
        $this->assertEquals((object) ['decision' => 'Elim', 'mention' => 'Elim'], $decision($elim));
        $this->assertEquals((object) ['decision' => 'Défaillant', 'mention' => 'Défaillant'], $decision($vide));
        $this->assertEquals((object) ['decision' => 'Défaillant', 'mention' => 'Défaillant'], $decision($tdVide));
        $this->assertSame(['Elim', 'Elim'], array_values(DB::table('notes')->where('matiere_id', $this->m['M3'])->whereIn('etudiant_id', [$elim, $vide])->pluck('observation')->all()));
        $this->assertSame('AV', $this->obs($tdVide, 'M3'));
    }

    public function test_rattrapage_conserve_les_matieres_acquises(): void
    {
        $et = $this->etudiant(['M1' => [30, 15], 'M2' => [10, 5], 'M3' => [4, null]]);
        $this->assertSame('Elim', $this->resultat($et)->decision);
        $ratt = $this->pv('rattrapage', $this->pv);
        DB::table('notes')->insert([
            ['pv_id' => $ratt, 'etudiant_id' => $et, 'matiere_id' => $this->m['M2'], 'note_examen' => 30, 'note_cc' => 14],
            ['pv_id' => $ratt, 'etudiant_id' => $et, 'matiere_id' => $this->m['M3'], 'note_examen' => 12, 'note_cc' => null],
        ]);

        $r = $this->resultat($et, $ratt);
        $this->assertSame('Admis', $r->decision);
        // M1 n'a pas été repassée : reprise de la session normale, observation conservée
        $this->assertSame('AV', $this->obs($et, 'M1', $ratt));
        $this->assertSame('45.00', DB::table('notes')->where(['pv_id' => $ratt, 'etudiant_id' => $et, 'matiere_id' => $this->m['M1']])->value('note_finale'));
    }

    public function test_option_non_choisie_ignoree(): void
    {
        DB::table('unites_enseignement')->where('id', $this->ue['UE2'])->update(['nb_options_a_choisir' => 1]);
        $opt = $this->matiere('UE2', 'OPT', 20, 0, 4, 'optionnelle');
        $this->m['OPT'] = $opt;
        DB::table('matieres')->where('id', $this->m['M3'])->update(['type_matiere' => 'optionnelle']);
        $et = $this->etudiant(['M1' => [30, 15], 'M2' => [30, 15], 'M3' => [14, null]]);
        DB::table('choix_options_etudiants')->insert(['etudiant_id' => $et, 'matiere_id' => $this->m['M3'], 'ue_id' => $this->ue['UE2'], 'annee_id' => $this->annee, 'promotion_id' => $this->promo, 'semestre_id' => $this->sem]);

        // OPT (sans note) n'est pas choisie : ni défaillance ni observation
        $this->assertSame('Admis', $this->resultat($et)->decision);
        $this->assertNull($this->obs($et, 'OPT'));
    }

    public function test_rangs_des_admis(): void
    {
        $a = $this->etudiant(['M1' => [30, 15], 'M2' => [30, 15], 'M3' => [12, null]]);
        $b = $this->etudiant(['M1' => [38, 18], 'M2' => [38, 18], 'M3' => [18, null]]);
        $this->resultat($a);
        $this->resultat($b);
        $this->assertSame([2, 1], [(int) DB::table('resultats_semestre')->where('etudiant_id', $a)->value('rang'), (int) DB::table('resultats_semestre')->where('etudiant_id', $b)->value('rang')]);
    }

    public function test_report_des_notes_validees_d_une_annee_precedente(): void
    {
        $nouvelleAnnee = DB::table('annees_academiques')->insertGetId(['libelle' => '2026-2027']);
        $filiere = DB::table('promotions')->where('id', $this->promo)->value('filiere_id');
        $niveau = DB::table('promotions')->where('id', $this->promo)->value('niveau_id');
        $promo2 = DB::table('promotions')->insertGetId(['filiere_id' => $filiere, 'niveau_id' => $niveau, 'annee_id' => $nouvelleAnnee]);
        app(Maquette::class)->copierUEs($promo2, $this->promo);
        $pv2 = DB::table('pv_sessions')->insertGetId(['promotion_id' => $promo2, 'semestre_id' => $this->sem, 'type_session' => 'normale', 'libelle' => 'PV 2']);

        // M1 validée (15/20), M2 non validée (6/20), M3 TD absent n'existe pas : 16/20 validée
        $et = $this->etudiant(['M1' => [30, 15], 'M2' => [12, 6], 'M3' => [16, null]]);
        $this->resultat($et);
        $m2Nouvelle = DB::table('matieres as m')->join('unites_enseignement as ue', 'ue.id', '=', 'm.ue_id')->where('ue.promotion_id', $promo2)->where('m.code', 'M2')->value('m.id');
        DB::table('notes')->insert(['pv_id' => $pv2, 'etudiant_id' => $et, 'matiere_id' => $m2Nouvelle, 'note_examen' => 20, 'note_cc' => 10]);

        $this->assertSame(2, $this->r->reporterNotes($et, $pv2));
        $reportees = DB::table('notes')->where(['pv_id' => $pv2, 'etudiant_id' => $et, 'reportee' => 1])->get();
        $this->assertCount(2, $reportees);
        $this->assertSame([$this->pv, $this->annee], [(int) $reportees[0]->pv_origine_id, (int) $reportees[0]->annee_origine_id]);
        // La note saisie à la main pour M2 n'est pas remplacée
        $this->assertSame('20.00', DB::table('notes')->where(['pv_id' => $pv2, 'matiere_id' => $m2Nouvelle])->value('note_examen'));
    }
}
