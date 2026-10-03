<?php

namespace Tests\Feature\Inscriptions;

use App\Models\Utilisateur;
use App\Support\Tableur;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class ListesInscritsTest extends TestCase
{
    private Utilisateur $admin;

    private int $annee;

    private int $facA;

    private int $facB;

    private int $droit;

    private int $eco;

    private int $l1;

    private int $s1;

    protected function setUp(): void
    {
        parent::setUp();
        $this->installerLicence();
        $this->admin = $this->creerUtilisateur('admin');
        $this->annee = DB::table('annees_academiques')->insertGetId(['libelle' => '2025-2026', 'active' => 1]);
        $this->facA = $this->insererMinimal('facultes', ['code' => 'FSJP', 'libelle' => 'Faculté de droit']);
        $this->facB = $this->insererMinimal('facultes', ['code' => 'FSEG', 'libelle' => 'Faculté économie']);
        $this->droit = DB::table('filieres')->insertGetId(['code' => 'DRT', 'libelle' => 'Droit', 'departement' => 'Droit privé', 'faculte' => 'FSJP', 'faculte_id' => $this->facA]);
        $this->eco = DB::table('filieres')->insertGetId(['code' => 'ECO', 'libelle' => 'Économie', 'departement' => 'Éco', 'faculte' => 'FSEG', 'faculte_id' => $this->facB]);
        $this->l1 = DB::table('niveaux')->insertGetId(['code' => 'L1', 'libelle' => 'Licence 1']);
        $this->s1 = DB::table('semestres')->insertGetId(['code' => 'S1', 'libelle' => 'Semestre 1']);
        $this->inscrire('ABDOU', 'M', '00012', $this->droit, true);
        $this->inscrire('BINTA', 'F', '00013', $this->droit, false);
        $this->inscrire('CISSE', 'M', '00014', $this->eco, true);
    }

    private function inscrire(string $nom, string $sexe, string $numero, int $filiere, bool $pedagogique): void
    {
        $et = DB::table('etudiants')->insertGetId(['nom' => $nom, 'prenoms' => 'X', 'sexe' => $sexe, 'numero' => $numero]);
        $promo = (int) (DB::table('promotions')->where(['filiere_id' => $filiere, 'niveau_id' => $this->l1, 'annee_id' => $this->annee])->value('id')
            ?? $this->creerPromotion($this->annee, ['filiere_id' => $filiere, 'niveau_id' => $this->l1]));
        $ins = DB::table('inscriptions')->insertGetId(['etudiant_id' => $et, 'promotion_id' => $promo, 'statut' => 'inscrit', 'date_inscription' => '2025-10-01']);
        if ($pedagogique) {
            DB::table('inscriptions_pedagogiques')->insert(['etudiant_id' => $et, 'inscription_id' => $ins, 'semestre_id' => $this->s1, 'annee_id' => $this->annee]);
        }
    }

    public function test_listes_scolarite_et_pedagogique(): void
    {
        $this->connecte($this->admin)->get($this->url("inscriptions/listes?annee_id={$this->annee}&type=filiere"))->assertOk()
            ->assertSee('📚 Droit')->assertSee('📚 Économie')->assertSee('2 étudiant(s) — 1H / 1F')->assertSee('BINTA');

        $this->get($this->url("inscriptions/listes?annee_id={$this->annee}&type=faculte&filtre_id=FSJP&pedagogique=1"))->assertOk()
            ->assertSee('🏫 Faculté de droit')->assertSee('ABDOU')->assertDontSee('BINTA')->assertDontSee('CISSE')->assertSee('Semestre 1');

        $this->get($this->url("inscriptions/listes?annee_id={$this->annee}&type=semestre&semestre_id={$this->s1}"))->assertOk()
            ->assertSee('📅 Semestre 1 — Droit L1')->assertSee('📅 Semestre 1 — Économie L1');
    }

    public function test_agent_de_faculte_limite_aussi_en_pdf_et_excel(): void
    {
        $this->donnerPermissions('sc_entite', ['inscription_voir']);
        $agent = $this->creerUtilisateur('sc_entite', ['faculte_id' => $this->facA]);

        $this->connecte($agent)->get($this->url("inscriptions/listes?annee_id={$this->annee}"))->assertOk()->assertSee('ABDOU')->assertDontSee('CISSE');
        // L'ancien PDF ignorait la faculté de l'agent
        $this->get($this->url("inscriptions/listes/pdf?annee_id={$this->annee}&type=filiere"))->assertOk()
            ->assertSee('ABDOU')->assertDontSee('CISSE')->assertSee('Critère :</strong> Par filière', false);
        $xlsx = $this->get($this->url("inscriptions/listes/excel?annee_id={$this->annee}"))->assertOk();
        $lignes = Tableur::xlsx($xlsx->baseResponse->getFile()->getPathname());
        $this->assertFalse(collect($lignes)->flatten()->contains('CISSE'));
    }

    public function test_excel_garde_les_zeros_et_recapitule(): void
    {
        $reponse = $this->connecte($this->admin)->get($this->url("inscriptions/listes/excel?annee_id={$this->annee}&type=filiere"))->assertOk()
            ->assertDownload('liste_inscrits_2025_2026_'.date('Ymd').'.xlsx');
        $chemin = $reponse->baseResponse->getFile()->getPathname();
        $lignes = Tableur::xlsx($chemin);
        $this->assertSame(['1', '00012', 'ABDOU', 'X', 'M', 'L1'], array_slice($lignes[6], 0, 6));

        $zip = new \ZipArchive;
        $zip->open($chemin);
        $this->assertStringContainsString('name="Récapitulatif"', $zip->getFromName('xl/workbook.xml'));
        $this->assertNotFalse($zip->getFromName('xl/worksheets/sheet3.xml'));
        $zip->close();
    }

    public function test_noms_de_feuilles_uniques(): void
    {
        $c = new \App\Support\Classeur;
        $long = str_repeat('Filière très longue ', 3);
        $c->feuille($long.'A', [['cells' => [['v' => 'x']]]]);
        $c->feuille($long.'B', [['cells' => [['v' => 'y']]]]);
        $zip = new \ZipArchive;
        $zip->open($c->enregistrer());
        preg_match_all('/name="([^"]+)"/', $zip->getFromName('xl/workbook.xml'), $m);
        $this->assertCount(2, array_unique($m[1]));
    }

    public function test_reserve_au_personnel_pour_les_exports(): void
    {
        $this->donnerPermissions('enseignant', ['inscription_voir']);
        $prof = $this->creerUtilisateur('enseignant');
        $this->connecte($prof)->get($this->url("inscriptions/listes?annee_id={$this->annee}"))->assertOk();
        $this->get($this->url("inscriptions/listes/pdf?annee_id={$this->annee}"))->assertRedirect();
    }
}
