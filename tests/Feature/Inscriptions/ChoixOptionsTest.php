<?php

namespace Tests\Feature\Inscriptions;

use App\Models\Utilisateur;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class ChoixOptionsTest extends TestCase
{
    private Utilisateur $admin;

    private int $annee;

    private int $promo;

    private int $s1;

    private int $s2;

    private int $ue;

    private int $ueAutreSem;

    private array $opt = [];

    private int $oblig;

    protected function setUp(): void
    {
        parent::setUp();
        $this->installerLicence();
        $this->admin = $this->creerUtilisateur('admin');
        $this->annee = DB::table('annees_academiques')->insertGetId(['libelle' => '2025-2026', 'active' => 1]);
        $this->promo = $this->creerPromotion($this->annee);
        $this->s1 = DB::table('semestres')->insertGetId(['code' => 'S1', 'libelle' => 'Semestre 1']);
        $this->s2 = DB::table('semestres')->insertGetId(['code' => 'S2', 'libelle' => 'Semestre 2']);
        $this->ue = $this->ue($this->s1, 2);
        $this->ueAutreSem = $this->ue($this->s2, 1);
        $this->oblig = $this->matiere($this->ue, 'OBL', 'obligatoire');
        foreach (['A', 'B', 'C'] as $l) {
            $this->opt[$l] = $this->matiere($this->ue, "OPT$l", 'optionnelle');
        }
        $this->opt['X'] = $this->matiere($this->ueAutreSem, 'OPTX', 'optionnelle');
    }

    private function ue(int $sem, int $nb): int
    {
        return DB::table('unites_enseignement')->insertGetId(['promotion_id' => $this->promo, 'semestre_id' => $sem, 'code' => 'UE'.uniqid(), 'libelle' => 'UE options', 'credits_total' => 6, 'ordre' => 1, 'nb_options_a_choisir' => $nb]);
    }

    private function matiere(int $ue, string $code, string $type): int
    {
        return DB::table('matieres')->insertGetId(['ue_id' => $ue, 'code' => $code, 'libelle' => "Matière $code", 'note_max_examen' => 40, 'note_max_cc' => 20, 'note_max_totale' => 60, 'credits' => 2, 'ordre' => 1, 'type_matiere' => $type]);
    }

    private function etudiant(string $nom, bool $pedagogique = true): int
    {
        $et = DB::table('etudiants')->insertGetId(['nom' => $nom, 'prenoms' => 'X']);
        $ins = DB::table('inscriptions')->insertGetId(['etudiant_id' => $et, 'promotion_id' => $this->promo, 'statut' => 'inscrit', 'date_inscription' => '2025-10-01']);
        if ($pedagogique) {
            DB::table('inscriptions_pedagogiques')->insert(['etudiant_id' => $et, 'inscription_id' => $ins, 'semestre_id' => $this->s1, 'annee_id' => $this->annee]);
        }

        return $et;
    }

    private function enregistrer(array $etudiants, array $choix, ?Utilisateur $u = null)
    {
        return $this->connecte($u ?? $this->admin)->post($this->url('inscriptions/options'), ['promo_id' => $this->promo, 'sem_id' => $this->s1, 'etudiants' => $etudiants, 'choix' => $choix]);
    }

    private function choixDe(int $et): array
    {
        return DB::table('choix_options_etudiants')->where('etudiant_id', $et)->orderBy('matiere_id')->pluck('matiere_id')->map(fn ($i) => (int) $i)->all();
    }

    public function test_affichage(): void
    {
        $this->etudiant('ALPHA');
        $this->etudiant('HORS', false);

        $this->connecte($this->admin)->get($this->url("inscriptions/options?promo_id={$this->promo}&sem_id={$this->s1}"))->assertOk()
            ->assertSee('ALPHA X')->assertDontSee('HORS X')->assertSee('Choisir <strong>2</strong>', false)
            ->assertSee('Obligatoires : OBL')->assertSee('Matière OPTA')->assertDontSee('Matière OPTX')->assertSee('type="checkbox"', false);
    }

    public function test_enregistrement_et_controles(): void
    {
        $a = $this->etudiant('ALPHA');
        $hors = $this->etudiant('HORS', false);

        $this->enregistrer([$a, $hors], [
            $this->ue => [$a => [$this->opt['A'], $this->opt['B'], $this->opt['C'], $this->oblig], $hors => [$this->opt['A']]],
            $this->ueAutreSem => [$a => [$this->opt['X']]],
        ])->assertRedirect($this->url("inscriptions/options?annee_id={$this->annee}&promo_id={$this->promo}&sem_id={$this->s1}"))
            ->assertSessionHas('succes');

        // 2 options au plus, pas d'obligatoire, pas l'UE d'un autre semestre, pas l'étudiant non inscrit
        $this->assertSame([$this->opt['A'], $this->opt['B']], $this->choixDe($a));
        $this->assertSame([], $this->choixDe($hors));
        $c = DB::table('choix_options_etudiants')->first();
        $this->assertSame([$this->annee, $this->s1, $this->admin->id], [(int) $c->annee_id, (int) $c->semestre_id, (int) $c->saisie_par]);
    }

    public function test_tout_decocher_retire_les_choix(): void
    {
        $a = $this->etudiant('ALPHA');
        $b = $this->etudiant('BRAVO');
        $this->enregistrer([$a, $b], [$this->ue => [$a => [$this->opt['A']], $b => [$this->opt['C']]]]);

        // ALPHA décoche tout ; BRAVO absent du formulaire (ajouté après son affichage) : intact
        $this->enregistrer([$a], []);
        $this->assertSame([], $this->choixDe($a));
        $this->assertSame([$this->opt['C']], $this->choixDe($b));
    }

    public function test_perimetre_et_roles(): void
    {
        $facA = $this->insererMinimal('facultes', ['code' => 'FA', 'libelle' => 'A']);
        $facB = $this->insererMinimal('facultes', ['code' => 'FB', 'libelle' => 'B']);
        DB::table('filieres')->update(['faculte_id' => $facB]);
        $a = $this->etudiant('ALPHA');
        $this->donnerPermissions('sc_entite', ['inscription_ped']);
        $this->enregistrer([$a], [$this->ue => [$a => [$this->opt['A']]]], $this->creerUtilisateur('sc_entite', ['faculte_id' => $facA]))
            ->assertSessionHas('erreur', "Cette promotion n'appartient pas à votre faculté.");
        $this->assertSame([], $this->choixDe($a));

        $this->donnerPermissions('sc_centrale', ['inscription_ped']);
        $this->connecte($this->creerUtilisateur('sc_centrale'))->get($this->url('inscriptions/options'))->assertRedirect();
    }
}
