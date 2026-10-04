<?php

namespace Tests\Feature;

use App\Services\IndicateursRectorat;
use App\Services\Inscriptions;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/** Corrections décidées après les essais sur données réelles (maquette, cache des indicateurs, saisie). */
class CorrectionsEssaisTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        $this->installerLicence();
    }

    public function test_copie_de_maquette_garde_volume_options_et_type(): void
    {
        $a1 = DB::table('annees_academiques')->insertGetId(['libelle' => '2025-2026', 'active' => 0]);
        $a2 = DB::table('annees_academiques')->insertGetId(['libelle' => '2026-2027', 'active' => 1]);
        $fil = DB::table('filieres')->insertGetId(['code' => 'F1', 'libelle' => 'Filière', 'departement' => '', 'actif' => 1]);
        $niv = DB::table('niveaux')->insertGetId(['code' => 'L1', 'libelle' => 'Licence 1']);
        $sem = DB::table('semestres')->insertGetId(['code' => 'S1', 'libelle' => 'Semestre 1']);
        $source = $this->creerPromotion($a1, ['filiere_id' => $fil, 'niveau_id' => $niv]);
        $ue = DB::table('unites_enseignement')->insertGetId(['promotion_id' => $source, 'semestre_id' => $sem, 'code' => 'UE1', 'libelle' => 'UE 1', 'credits_total' => 6, 'ordre' => 1, 'nb_options_a_choisir' => 1]);
        DB::table('matieres')->insert(['ue_id' => $ue, 'code' => 'M1', 'libelle' => 'Option A', 'credits' => 3, 'type_matiere' => 'optionnelle',
            'volume_horaire_prevu' => 42, 'heures_faites' => 40, 'finalisee' => 1]);

        $cible = app(Inscriptions::class)->promotion($fil, $niv, $a2);
        $copie = DB::table('unites_enseignement')->where('promotion_id', $cible)->first();
        $m = DB::table('matieres')->where('ue_id', $copie->id)->first();
        $this->assertSame(1, (int) $copie->nb_options_a_choisir);
        $this->assertSame(['optionnelle', 42.0], [$m->type_matiere, (float) $m->volume_horaire_prevu]);
        // Propres à une année : repartent de zéro
        $this->assertSame([0.0, 0], [(float) $m->heures_faites, (int) $m->finalisee]);
    }

    public function test_indicateurs_recalcules_apres_une_ecriture(): void
    {
        $annee = DB::table('annees_academiques')->insertGetId(['libelle' => '2026-2027', 'active' => 1]);
        $promo = $this->creerPromotion($annee);
        $sem = DB::table('semestres')->insertGetId(['code' => 'S1', 'libelle' => 'Semestre 1']);
        $ue = DB::table('unites_enseignement')->insertGetId(['promotion_id' => $promo, 'semestre_id' => $sem, 'code' => 'UE1', 'libelle' => 'UE 1', 'credits_total' => 6, 'ordre' => 1]);
        $mat = DB::table('matieres')->insertGetId(['ue_id' => $ue, 'code' => 'M1', 'libelle' => 'Matière', 'credits' => 6, 'volume_horaire_prevu' => 40, 'heures_faites' => 0]);
        $indicateurs = app(IndicateursRectorat::class);
        $this->assertSame(0.0, (float) $indicateurs->pour($annee)['taux_programme']);

        // Écriture directe en base : le cache garde l'ancien chiffre…
        DB::table('matieres')->where('id', $mat)->update(['heures_faites' => 20]);
        $this->assertSame(0.0, (float) $indicateurs->pour($annee)['taux_programme']);
        // …mais toute écriture faite dans l'application le périme (ici : création d'un niveau)
        $this->connecte($this->creerUtilisateur('admin'))->post($this->url('niveaux'), ['code' => 'L9', 'libelle' => 'Niveau 9'])->assertRedirect();
        $this->assertSame(50.0, (float) $indicateurs->pour($annee)['taux_programme']);
        // Une simple consultation ne périme rien
        DB::table('matieres')->where('id', $mat)->update(['heures_faites' => 40]);
        $this->get($this->url('niveaux'))->assertOk();
        $this->assertSame(50.0, (float) $indicateurs->pour($annee)['taux_programme']);
    }

    public function test_champs_de_note_limites_a_deux_decimales(): void
    {
        $annee = DB::table('annees_academiques')->insertGetId(['libelle' => '2026-2027', 'active' => 1]);
        $fil = DB::table('filieres')->insertGetId(['code' => 'F1', 'libelle' => 'Filière', 'departement' => '', 'actif' => 1]);
        $promo = $this->creerPromotion($annee, ['filiere_id' => $fil]);
        $sem = DB::table('semestres')->insertGetId(['code' => 'S1', 'libelle' => 'Semestre 1']);
        $ue = DB::table('unites_enseignement')->insertGetId(['promotion_id' => $promo, 'semestre_id' => $sem, 'code' => 'UE1', 'libelle' => 'UE 1', 'credits_total' => 6, 'ordre' => 1]);
        DB::table('matieres')->insert(['ue_id' => $ue, 'code' => 'M1', 'libelle' => 'Matière', 'credits' => 6, 'note_max_examen' => 14, 'note_max_cc' => 6, 'note_max_totale' => 20]);
        $et = DB::table('etudiants')->insertGetId(['numero' => '00001', 'nom' => 'ALPHA', 'prenoms' => 'Awa']);
        $ins = DB::table('inscriptions')->insertGetId(['etudiant_id' => $et, 'promotion_id' => $promo, 'statut' => 'inscrit', 'date_inscription' => '2026-10-01']);
        DB::table('inscriptions_pedagogiques')->insert(['etudiant_id' => $et, 'inscription_id' => $ins, 'semestre_id' => $sem, 'annee_id' => $annee, 'statut' => 'actif']);
        $pv = DB::table('pv_sessions')->insertGetId(['promotion_id' => $promo, 'semestre_id' => $sem, 'type_session' => 'normale', 'libelle' => 'PV S1']);

        $page = $this->connecte($this->creerUtilisateur('admin'))->get($this->url("pv/$pv"))->assertOk();
        $this->assertSame(2, substr_count($page->getContent(), 'step="0.01" data-decimales="2"'));
        $this->assertStringNotContainsString('step="any"', $page->getContent());
    }
}
