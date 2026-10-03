<?php

namespace Tests\Feature\Inscriptions;

use App\Models\Utilisateur;
use App\Services\Inscriptions;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class InscriptionPedMasseTest extends TestCase
{
    private Utilisateur $admin;

    private int $annee;

    private int $filiere;

    private int $niveau;

    private int $promo;

    private array $sem = [];

    protected function setUp(): void
    {
        parent::setUp();
        $this->installerLicence();
        $this->admin = $this->creerUtilisateur('admin');
        $this->annee = DB::table('annees_academiques')->insertGetId(['libelle' => '2025-2026', 'active' => 1]);
        $this->filiere = DB::table('filieres')->insertGetId(['code' => 'DRT', 'libelle' => 'Droit', 'departement' => '']);
        $this->niveau = DB::table('niveaux')->insertGetId(['code' => 'L2', 'libelle' => 'Licence 2']);
        $this->promo = $this->creerPromotion($this->annee, ['filiere_id' => $this->filiere, 'niveau_id' => $this->niveau]);
        foreach (['S1', 'S2', 'S3', 'S4', 'S5', 'S6'] as $code) {
            $this->sem[$code] = DB::table('semestres')->insertGetId(['code' => $code, 'libelle' => "Semestre $code"]);
        }
    }

    private function inscrit(string $nom, array $valides = []): int
    {
        $et = DB::table('etudiants')->insertGetId(['nom' => $nom, 'prenoms' => 'X', 'numero' => (string) random_int(10000, 99999)]);
        DB::table('inscriptions')->insert(['etudiant_id' => $et, 'promotion_id' => $this->promo, 'statut' => 'inscrit', 'date_inscription' => '2025-10-01']);
        foreach ($valides as $code) {
            $pv = $this->insererMinimal('pv_sessions', ['promotion_id' => $this->promo, 'semestre_id' => $this->sem[$code], 'type_session' => 'normale']);
            $this->insererMinimal('resultats_semestre', ['pv_id' => $pv, 'etudiant_id' => $et, 'valide' => 1]);
        }

        return $et;
    }

    private function url_promo(): string
    {
        return $this->url("inscriptions/pedagogique/masse?annee_id={$this->annee}&filiere_id={$this->filiere}&niveau_id={$this->niveau}");
    }

    public function test_affichage_et_suggestions(): void
    {
        $a = $this->inscrit('ALPHA', ['S1']);
        $this->inscrit('BRAVO', ['S1', 'S2']);
        DB::table('inscriptions_pedagogiques')->insert(['etudiant_id' => $a, 'inscription_id' => DB::table('inscriptions')->where('etudiant_id', $a)->value('id'), 'semestre_id' => $this->sem['S2'], 'annee_id' => $this->annee]);

        $page = $this->connecte($this->admin)->get($this->url_promo())->assertOk()
            ->assertSee('ALPHA X')->assertSee('BRAVO X')->assertSee('S2</span>', false);
        // Droit, S1 seul validé → S2 + S3 + S4
        $page->assertSee('data-sugg="'.e(json_encode([$this->sem['S2'], $this->sem['S3'], $this->sem['S4']])).'"', false);
    }

    public function test_enregistrement(): void
    {
        $a = $this->inscrit('ALPHA');
        $b = $this->inscrit('BRAVO');
        $etranger = DB::table('etudiants')->insertGetId(['nom' => 'HORS', 'prenoms' => 'Promo']);

        $this->connecte($this->admin)->post($this->url('inscriptions/pedagogique/masse'), [
            'annee_id' => $this->annee, 'filiere_id' => $this->filiere, 'niveau_id' => $this->niveau,
            'etudiants_json' => json_encode([$a => [$this->sem['S1'], $this->sem['S2'], 999999], $b => [], $etranger => [$this->sem['S1']]]),
        ])->assertRedirect($this->url_promo())->assertSessionHas('succes', '✅ Inscription pédagogique en masse — 1 étudiant(s) inscrits. 1 ignoré(s).');

        $this->assertSame([$this->sem['S1'], $this->sem['S2']], DB::table('inscriptions_pedagogiques')->where('etudiant_id', $a)->orderBy('semestre_id')->pluck('semestre_id')->map(fn ($i) => (int) $i)->all());
        $this->assertSame(2, DB::table('inscriptions_pedagogiques')->count());
        $this->assertSame($this->admin->id, (int) DB::table('inscriptions_pedagogiques')->value('saisie_par'));

        // Un semestre annulé est réactivé
        DB::table('inscriptions_pedagogiques')->update(['statut' => 'annule']);
        $this->post($this->url('inscriptions/pedagogique/masse'), ['annee_id' => $this->annee, 'filiere_id' => $this->filiere, 'niveau_id' => $this->niveau, 'etudiants_json' => json_encode([$a => [$this->sem['S1']]])]);
        $this->assertSame('actif', DB::table('inscriptions_pedagogiques')->where('semestre_id', $this->sem['S1'])->value('statut'));
    }

    public function test_filiere_d_une_autre_faculte(): void
    {
        $facA = $this->insererMinimal('facultes', ['code' => 'FA', 'libelle' => 'A']);
        $facB = $this->insererMinimal('facultes', ['code' => 'FB', 'libelle' => 'B']);
        DB::table('filieres')->where('id', $this->filiere)->update(['faculte_id' => $facB]);
        $a = $this->inscrit('ALPHA');
        $this->donnerPermissions('sc_entite', ['inscription_ped']);
        $agent = $this->creerUtilisateur('sc_entite', ['faculte_id' => $facA]);

        $this->connecte($agent)->get($this->url_promo())->assertOk()->assertDontSee('ALPHA');
        $this->post($this->url('inscriptions/pedagogique/masse'), ['annee_id' => $this->annee, 'filiere_id' => $this->filiere, 'niveau_id' => $this->niveau, 'etudiants_json' => json_encode([$a => [$this->sem['S1']]])])
            ->assertSessionHas('erreur', "Cette filière n'appartient pas à votre faculté.");
        $this->assertSame(0, DB::table('inscriptions_pedagogiques')->count());
    }

    public function test_regles_de_suggestion(): void
    {
        $this->assertSame(['S1', 'S2'], Inscriptions::semestresSuggeres([], false));
        $this->assertSame(['S2', 'S3'], Inscriptions::semestresSuggeres(['S1'], false));
        $this->assertSame(['S2', 'S3', 'S4'], Inscriptions::semestresSuggeres(['S1'], true));
        $this->assertSame(['S1', 'S4'], Inscriptions::semestresSuggeres(['S2'], false));
        $this->assertSame(['S1', 'S3', 'S4'], Inscriptions::semestresSuggeres(['S2'], true));
        $this->assertSame(['S3', 'S4'], Inscriptions::semestresSuggeres(['S1', 'S2'], false));
        $this->assertSame(['S4'], Inscriptions::semestresSuggeres(['S1', 'S2', 'S3'], false));
        $this->assertSame(['S3'], Inscriptions::semestresSuggeres(['S1', 'S2', 'S4'], true));
        $this->assertSame(['S5', 'S6'], Inscriptions::semestresSuggeres(['S1', 'S2', 'S3', 'S4'], false));
        // L'ancienne version « masse » suggérait S1 + S2 ici
        $this->assertSame(['S5', 'S6'], Inscriptions::semestresSuggeres(['S1', 'S2', 'S3', 'S4', 'S5'], false));
    }
}
