<?php

namespace Tests\Feature\Inscriptions;

use App\Models\Utilisateur;
use App\Services\Inscriptions;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class PassageTransfertTest extends TestCase
{
    private Utilisateur $admin;

    private int $annee;

    private int $suivante;

    private int $droit;

    private array $niv = [];

    private array $sem = [];

    protected function setUp(): void
    {
        parent::setUp();
        $this->installerLicence();
        $this->admin = $this->creerUtilisateur('admin');
        $this->annee = DB::table('annees_academiques')->insertGetId(['libelle' => '2024-2025']);
        $this->suivante = DB::table('annees_academiques')->insertGetId(['libelle' => '2025-2026', 'active' => 1]);
        $this->droit = DB::table('filieres')->insertGetId(['code' => 'DRT', 'libelle' => 'Droit', 'departement' => '', 'faculte' => 'FSJP']);
        foreach (['L1', 'L2', 'L3', 'M1', 'M2', 'DUT1', 'DUT2', 'LP'] as $code) {
            $this->niv[$code] = DB::table('niveaux')->insertGetId(['code' => $code, 'libelle' => $code]);
        }
        foreach (['S1', 'S2', 'S3', 'S4', 'S5', 'S6'] as $code) {
            $this->sem[$code] = DB::table('semestres')->insertGetId(['code' => $code, 'libelle' => $code]);
        }
    }

    private function etudiant(string $nom, string $niveau, array $valides = [], ?int $filiere = null): int
    {
        $filiere ??= $this->droit;
        $et = DB::table('etudiants')->insertGetId(['nom' => $nom, 'prenoms' => 'X', 'numero' => (string) random_int(10000, 99999), 'filiere_id' => $filiere]);
        $promo = $this->promo($filiere, $niveau, $this->annee);
        DB::table('inscriptions')->insert(['etudiant_id' => $et, 'promotion_id' => $promo, 'niveau_id' => $this->niv[$niveau], 'annee_id' => $this->annee, 'statut' => 'inscrit', 'date_inscription' => '2024-10-01']);
        foreach ($valides as $code) {
            $pv = $this->insererMinimal('pv_sessions', ['promotion_id' => $promo, 'semestre_id' => $this->sem[$code], 'type_session' => 'normale']);
            $this->insererMinimal('resultats_semestre', ['pv_id' => $pv, 'etudiant_id' => $et, 'valide' => 1]);
        }

        return $et;
    }

    private function promo(int $filiere, string $niveau, int $annee): int
    {
        return (int) (DB::table('promotions')->where(['filiere_id' => $filiere, 'niveau_id' => $this->niv[$niveau], 'annee_id' => $annee])->value('id')
            ?? $this->creerPromotion($annee, ['filiere_id' => $filiere, 'niveau_id' => $this->niv[$niveau]]));
    }

    private function decision(int $et): ?object
    {
        return DB::table('passages_niveau as pn')->join('niveaux as n', 'n.id', '=', 'pn.niveau_suivant_id')->where('pn.etudiant_id', $et)->first(['pn.*', 'n.code']);
    }

    public function test_regle_de_decision_unique(): void
    {
        $tous = ['S1', 'S2', 'S3', 'S4', 'S5', 'S6'];
        $this->assertSame(['L2', 'passage', 'passage'], Inscriptions::decisionPassage('L1', ['S1', 'S2']));
        $this->assertSame(['L3', 'passage', 'passage'], Inscriptions::decisionPassage('L1', ['S1', 'S2', 'S3', 'S4']));
        $this->assertSame(['L1', 'redoublement', 'redoublement'], Inscriptions::decisionPassage('L1', ['S1']));
        $this->assertSame(['L3', 'passage', 'passage'], Inscriptions::decisionPassage('L2', ['S3', 'S4']));
        // Licence validée : enregistrée comme telle (la page individuelle enregistrait « redoublement »)
        $this->assertSame(['L3', 'licence_validee', 'licence_validee'], Inscriptions::decisionPassage('L3', $tous));
        $this->assertSame(['L3', 'redoublement', 'redoublement'], Inscriptions::decisionPassage('L3', ['S3', 'S4', 'S5', 'S6']));
        // LP : S1 + S2 (validés en DUT1) ne suffisent plus
        $this->assertSame(['LP', 'redoublement', 'redoublement'], Inscriptions::decisionPassage('LP', ['S1', 'S2']));
        $this->assertSame(['LP', 'licence_validee', 'licence_validee'], Inscriptions::decisionPassage('LP', $tous));
        $this->assertSame(['DUT2', 'passage', 'passage'], Inscriptions::decisionPassage('DUT1', ['S1', 'S2']));
        $this->assertSame(['LP', 'passage', 'passage'], Inscriptions::decisionPassage('DUT2', ['S3', 'S4']));
        $this->assertSame(['M2', 'passage', 'passage'], Inscriptions::decisionPassage('M1', ['S1', 'S2']));
        $this->assertSame(['M2', 'redoublement', 'master_valide'], Inscriptions::decisionPassage('M2', ['S3', 'S4']));
        $this->assertSame(['D1', 'redoublement', 'redoublement'], Inscriptions::decisionPassage('D1', $tous));
    }

    public function test_passage_individuel(): void
    {
        $et = $this->etudiant('ALPHA', 'L1', ['S1', 'S2']);

        $this->connecte($this->admin)->get($this->url("inscriptions/transfert?et_id=$et"))->assertOk()
            ->assertSee('ALPHA X')->assertSee('📈 Passage', false)->assertSee('2024-2025');

        $this->post($this->url('inscriptions/transfert/passage'), ['etudiant_id' => $et, 'nouvel_annee_id' => $this->suivante])
            ->assertRedirect($this->url("inscriptions/transfert?et_id=$et"))
            ->assertSessionHas('succes', "✅ Décision enregistrée pour ALPHA X : 📈 Passage → L2. L'inscription académique se fait via Inscription Scolarité.");
        $d = $this->decision($et);
        $this->assertSame(['L2', 'passage', $this->annee, $this->suivante, $this->droit], [$d->code, $d->decision, (int) $d->annee_depart_id, (int) $d->annee_arrivee_id, (int) $d->filiere_id]);
        $this->assertDatabaseHas('audit_log', ['module' => 'passage_niveau', 'cible_id' => $et]);

        // Même année que l'inscription actuelle : refusé
        $this->post($this->url('inscriptions/transfert/passage'), ['etudiant_id' => $et, 'nouvel_annee_id' => $this->annee])
            ->assertSessionHas('erreur', "La nouvelle année doit être différente de l'année de l'inscription actuelle.");
    }

    public function test_passage_individuel_vers_une_specialisation(): void
    {
        $prive = DB::table('filieres')->insertGetId(['code' => 'DP', 'libelle' => 'Droit privé', 'departement' => '', 'filiere_parente_id' => $this->droit]);
        $public = DB::table('filieres')->insertGetId(['code' => 'DPU', 'libelle' => 'Droit public', 'departement' => '', 'filiere_parente_id' => $this->droit]);
        $autre = DB::table('filieres')->insertGetId(['code' => 'ECO', 'libelle' => 'Économie', 'departement' => '']);
        foreach ([$prive, $public, $autre] as $f) {
            $this->promo($f, 'L3', $this->annee);
        }
        $et = $this->etudiant('BRAVO', 'L2', ['S3', 'S4']);

        $this->connecte($this->admin)->get($this->url("inscriptions/transfert?et_id=$et"))->assertOk()
            ->assertSee('Spécialisation L3')->assertSee('Droit privé')->assertDontSee('>Économie</option>', false);
        $this->post($this->url('inscriptions/transfert/passage'), ['etudiant_id' => $et, 'nouvel_annee_id' => $this->suivante, 'filiere_l3_id' => $autre])
            ->assertSessionHas('erreur', "Cette filière n'est pas proposée pour ce passage.");
        $this->post($this->url('inscriptions/transfert/passage'), ['etudiant_id' => $et, 'nouvel_annee_id' => $this->suivante, 'filiere_l3_id' => $prive]);
        $this->assertSame($prive, (int) $this->decision($et)->filiere_id);
    }

    public function test_transfert_de_filiere(): void
    {
        $eco = DB::table('filieres')->insertGetId(['code' => 'ECO', 'libelle' => 'Économie', 'departement' => '']);
        $et = $this->etudiant('CHARLIE', 'L1');

        $this->connecte($this->admin)->post($this->url('inscriptions/transfert'), ['etudiant_id' => $et, 'nouvelle_filiere_id' => $eco, 'annee_id' => $this->annee])
            ->assertRedirect($this->url("etudiants/dossier?id=$et"))->assertSessionHas('succes');
        $this->assertSame($eco, (int) DB::table('etudiants')->where('id', $et)->value('filiere_id'));
        $promo = DB::table('inscriptions')->where('etudiant_id', $et)->value('promotion_id');
        $this->assertSame([$eco, $this->niv['L1']], [(int) DB::table('promotions')->where('id', $promo)->value('filiere_id'), (int) DB::table('promotions')->where('id', $promo)->value('niveau_id')]);
        $this->assertSame(1, DB::table('inscriptions')->where('etudiant_id', $et)->count());
    }

    public function test_perimetre(): void
    {
        $facA = $this->insererMinimal('facultes', ['code' => 'FA', 'libelle' => 'A']);
        $facB = $this->insererMinimal('facultes', ['code' => 'FB', 'libelle' => 'B']);
        DB::table('filieres')->where('id', $this->droit)->update(['faculte_id' => $facB]);
        $et = $this->etudiant('DELTA', 'L1', ['S1', 'S2']);
        $this->donnerPermissions('sc_entite', ['passage_niveau']);
        $agent = $this->creerUtilisateur('sc_entite', ['faculte_id' => $facA]);

        $this->connecte($agent)->post($this->url('inscriptions/transfert/passage'), ['etudiant_id' => $et, 'nouvel_annee_id' => $this->suivante])
            ->assertSessionHas('erreur', "Cet étudiant n'appartient pas à votre faculté.");
        $this->post($this->url('inscriptions/passage'), ['annee_id' => $this->annee, 'filiere_id' => $this->droit, 'niveau_id' => $this->niv['L1'], 'nouvel_annee_id' => $this->suivante, 'etudiants' => [$et]])
            ->assertSessionHas('erreur', "Cette filière n'appartient pas à votre faculté.");
        $this->assertNull($this->decision($et));
    }

    public function test_passage_en_masse(): void
    {
        $a = $this->etudiant('ALPHA', 'L1', ['S1', 'S2']);
        $b = $this->etudiant('BRAVO', 'L1', ['S1']);
        $hors = $this->etudiant('HORS', 'L2', ['S3', 'S4']);
        $url = "inscriptions/passage?annee_id={$this->annee}&filiere_id={$this->droit}&niveau_id={$this->niv['L1']}";

        $this->connecte($this->admin)->get($this->url($url))->assertOk()
            ->assertSee('ALPHA X')->assertSee('BRAVO X')->assertDontSee('HORS X')->assertSee('Sélectionner les passages (1)');

        $this->post($this->url('inscriptions/passage'), ['annee_id' => $this->annee, 'filiere_id' => $this->droit, 'niveau_id' => $this->niv['L1'],
            'nouvel_annee_id' => $this->suivante, 'etudiants' => [$a, $b, $hors]])
            ->assertRedirect($this->url($url))->assertSessionHas('succes', fn ($m) => str_contains($m, '2 étudiant(s) traité(s)') && str_contains($m, '1 ignoré(s)'));
        $this->assertSame(['L2', 'passage'], [$this->decision($a)->code, $this->decision($a)->decision]);
        $this->assertSame(['L1', 'redoublement'], [$this->decision($b)->code, $this->decision($b)->decision]);
        $this->assertNull($this->decision($hors));

        $this->post($this->url('inscriptions/passage'), ['annee_id' => $this->annee, 'filiere_id' => $this->droit, 'niveau_id' => $this->niv['L1'], 'nouvel_annee_id' => $this->annee, 'etudiants' => [$a]])
            ->assertSessionHas('erreur');
    }
}
