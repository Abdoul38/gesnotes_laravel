<?php

namespace Tests\Feature;

use Illuminate\Routing\Route;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Route as Routes;
use Tests\TestCase;

/**
 * Test de fumée : chaque page (GET sans paramètre d'adresse) s'ouvre sans
 * erreur serveur, pour l'administration, un enseignant et un doyen — avec
 * une année, une promotion, un PV, des notes et un étudiant.
 */
class FumeeTest extends TestCase
{
    /** Pages qui envoient un fichier ou attendent un appel d'API. */
    private const IGNOREES = ['api_mobile', 'api_mobile.nouvelle', 'api_mobile.parents', 'logout'];

    protected function setUp(): void
    {
        parent::setUp();
        $this->installerLicence();
        $annee = DB::table('annees_academiques')->insertGetId(['libelle' => '2025-2026', 'active' => 1]);
        $fac = $this->insererMinimal('facultes', ['code' => 'FA', 'libelle' => 'Faculté A']);
        $filiere = DB::table('filieres')->insertGetId(['code' => 'ECO', 'libelle' => 'Économie', 'departement' => '', 'faculte_id' => $fac, 'actif' => 1]);
        $promo = $this->creerPromotion($annee, ['filiere_id' => $filiere]);
        $sem = DB::table('semestres')->insertGetId(['code' => 'S1', 'libelle' => 'Semestre 1']);
        $ue = DB::table('unites_enseignement')->insertGetId(['promotion_id' => $promo, 'semestre_id' => $sem, 'code' => 'UE1', 'libelle' => 'UE 1', 'credits_total' => 6, 'ordre' => 1]);
        $mat = DB::table('matieres')->insertGetId(['ue_id' => $ue, 'code' => 'M1', 'libelle' => 'Microéconomie', 'note_max_examen' => 20, 'note_max_cc' => 0, 'note_max_totale' => 20, 'credits' => 6, 'ordre' => 1]);
        $pv = DB::table('pv_sessions')->insertGetId(['promotion_id' => $promo, 'semestre_id' => $sem, 'type_session' => 'normale', 'libelle' => 'PV S1', 'statut' => 'publie']);
        $et = DB::table('etudiants')->insertGetId(['numero' => '00001', 'nom' => 'ALPHA', 'prenoms' => 'Awa', 'filiere_id' => $filiere]);
        $ins = DB::table('inscriptions')->insertGetId(['etudiant_id' => $et, 'promotion_id' => $promo, 'annee_id' => $annee, 'statut' => 'inscrit', 'date_inscription' => '2025-10-01']);
        DB::table('inscriptions_pedagogiques')->insert(['etudiant_id' => $et, 'inscription_id' => $ins, 'semestre_id' => $sem, 'annee_id' => $annee]);
        DB::table('notes')->insert(['pv_id' => $pv, 'etudiant_id' => $et, 'matiere_id' => $mat, 'note_examen' => 14, 'note_finale' => 14, 'observation' => 'AV']);
        DB::table('resultats_semestre')->insert(['pv_id' => $pv, 'etudiant_id' => $et, 'moyenne_generale' => 14, 'valide' => 1, 'mention' => 'Bien', 'credits_obtenus' => 6]);
        $ens = DB::table('enseignants')->insertGetId(['nom' => 'PROF', 'prenoms' => 'Paul', 'faculte_id' => $fac]);
        DB::table('affectations')->insert(['enseignant_id' => $ens, 'matiere_id' => $mat, 'annee_id' => $annee, 'type_charge' => 'cours']);
        DB::table('presidents_jury')->insert(['promotion_id' => $promo, 'semestre_id' => $sem, 'enseignant_id' => $ens]);
    }

    /** @return list<string> */
    private function pages(): array
    {
        return collect(Routes::getRoutes()->getRoutes())
            ->filter(fn (Route $r) => in_array('GET', $r->methods(), true) && ! str_contains($r->uri(), '{') && $r->getName()
                && ! in_array($r->getName(), self::IGNOREES, true) && ! str_starts_with($r->uri(), '_') && ! str_starts_with($r->uri(), 'storage'))
            ->map(fn (Route $r) => $r->uri())->unique()->values()->all();
    }

    public function test_aucune_page_en_erreur(): void
    {
        $this->donnerPermissions('enseignant', ['notes_saisir', 'etudiants_voir']);
        $this->donnerPermissions('doyen', ['etudiants_voir', 'pv_voir', 'pv_creer', 'notes_saisir', 'inscription_ped', 'inscription_voir']);
        $comptes = [
            'admin' => $this->creerUtilisateur('admin'),
            'enseignant' => $this->creerUtilisateur('enseignant', ['enseignant_id' => DB::table('enseignants')->value('id'), 'email' => 'prof@test.local']),
            'doyen' => $this->creerUtilisateur('doyen', ['faculte_id' => DB::table('facultes')->value('id'), 'email' => 'doyen@test.local']),
        ];
        $pages = $this->pages();
        $this->assertGreaterThan(100, count($pages));
        $erreurs = [];
        foreach ($comptes as $role => $u) {
            foreach ($pages as $uri) {
                $r = $this->connecte($u)->get($this->url(ltrim($uri, '/')));
");
                if ($r->getStatusCode() >= 500) {
                    $erreurs[] = "$role → /$uri : HTTP {$r->getStatusCode()}";
                }
            }
        }
        $this->assertSame([], $erreurs, implode("\n", $erreurs));
    }
}
