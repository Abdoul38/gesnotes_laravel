<?php

namespace Tests\Feature;

use App\Universites\RegistreUniversites;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/** Répétition de la bascule sur des copies des bases (ici, les bases de test). */
class RepetitionBasculeTest extends TestCase
{
    private function bases(): array
    {
        return array_column(DB::select("SELECT schema_name AS n FROM information_schema.schemata WHERE schema_name LIKE 'gesnotes\\_test\\_%'"), 'n');
    }

    public function test_repetition_sur_des_copies_sans_toucher_aux_originales(): void
    {
        foreach (['uam', 'udh'] as $code) {
            $this->universite($code);
            $this->installerLicence(strtoupper($code));
            $this->creerUtilisateur('admin');
            $annee = DB::table('annees_academiques')->insertGetId(['libelle' => '2026-2027', 'active' => 1]);
            $promo = $this->creerPromotion($annee);
            $et = DB::table('etudiants')->insertGetId(['nom' => 'ALPHA', 'prenoms' => 'X', 'numero' => '00001']);
            DB::table('inscriptions')->insert(['etudiant_id' => $et, 'promotion_id' => $promo, 'statut' => 'inscrit', 'date_inscription' => '2026-10-01']);
        }
        $this->universite('udh');
        DB::table('mesr_comptes')->insert(['login' => 'dsi', 'nom_complet' => 'DSI', 'role' => 'admin', 'mot_de_passe' => 'x', 'actif' => 1]);
        // Une décision de passage vide dans l'original : réparée dans la copie seulement
        DB::statement("SET SESSION sql_mode = ''");
        DB::statement("ALTER TABLE passages_niveau MODIFY decision enum('passage','redoublement') NOT NULL DEFAULT 'passage'");
        DB::table('passages_niveau')->insert(['etudiant_id' => 1, 'filiere_id' => 1, 'niveau_actuel_id' => 1, 'niveau_suivant_id' => 1,
            'annee_depart_id' => 1, 'annee_arrivee_id' => 2, 'decision' => 'licence_validee', 'date_decision' => '2026-10-09']);
        $avant = $this->bases();

        $this->artisan('gesnotes:repetition-bascule')
            ->expectsOutputToContain('gesnotes_test_udh → gesnotes_test_udh_repetition')
            ->expectsOutputToContain('UDH (gesnotes_test_udh_repetition) : 1 inscrits')
            ->expectsOutputToContain('Copies supprimées.');

        // Copies supprimées ; originales intactes (l'anomalie y est toujours, le registre pointe à nouveau sur elles)
        $this->assertSame($avant, $this->bases());
        $this->app->forgetInstance(RegistreUniversites::class);
        $this->universite('udh');
        $this->assertSame('', DB::table('passages_niveau')->value('decision'));
        $this->assertSame(1, DB::table('mesr_comptes')->count());
        DB::statement("ALTER TABLE passages_niveau MODIFY decision enum('passage','redoublement','licence_validee') NOT NULL DEFAULT 'passage'");
    }

    public function test_suffixe_controle(): void
    {
        $this->artisan('gesnotes:repetition-bascule', ['--suffixe' => '`; DROP DATABASE x'])->assertFailed();
    }
}
