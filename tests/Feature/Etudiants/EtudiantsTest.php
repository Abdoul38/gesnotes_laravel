<?php

namespace Tests\Feature\Etudiants;

use App\Models\Utilisateur;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class EtudiantsTest extends TestCase
{
    private Utilisateur $admin;

    private int $annee;

    protected function setUp(): void
    {
        parent::setUp();
        $this->installerLicence();
        $this->admin = $this->creerUtilisateur('admin');
        $this->annee = DB::table('annees_academiques')->insertGetId(['libelle' => '2026-2027', 'active' => 1]);
    }

    private function etudiant(array $valeurs = []): int
    {
        return DB::table('etudiants')->insertGetId($valeurs + ['nom' => 'MOUSSA', 'prenoms' => 'Ali '.uniqid(), 'numero' => null]);
    }

    private function inscrire(int $etudiant, int $promo): void
    {
        DB::table('inscriptions')->insert(['etudiant_id' => $etudiant, 'promotion_id' => $promo, 'statut' => 'inscrit', 'date_inscription' => '2026-10-01']);
    }

    // ── Liste ─────────────────────────────────────────────────

    public function test_liste_recherche_et_filtre_promotion(): void
    {
        $promo = $this->creerPromotion($this->annee);
        $inscrit = $this->etudiant(['nom' => 'ISSA', 'prenoms' => 'Fati', 'numero' => '00012']);
        $this->inscrire($inscrit, $promo);
        $this->etudiant(['nom' => 'BOUBACAR', 'prenoms' => 'Sani']);

        $page = $this->connecte($this->admin)->get($this->url('etudiants'))->assertOk();
        $page->assertSee('2 étudiant(s) trouvé(s)')->assertSee('00012')->assertSee('⏳ Non inscrit')->assertSee('1 sans inscription');
        $page->assertDontSee('Supprimer matricule 0');

        $this->connecte($this->admin)->get($this->url('etudiants?q=bouba'))->assertSee('1 étudiant(s) trouvé(s)')->assertSee('BOUBACAR')->assertDontSee('ISSA');
        $this->connecte($this->admin)->get($this->url("etudiants?promo=$promo"))->assertSee('1 étudiant(s) trouvé(s)')->assertSee('ISSA');
    }

    public function test_tri_par_matricule_decroissant_et_pagination(): void
    {
        foreach (range(1, 55) as $n) {
            $this->etudiant(['numero' => sprintf('%05d', $n)]);
        }
        $page1 = $this->connecte($this->admin)->get($this->url('etudiants'))->getContent();
        $this->assertLessThan(strpos($page1, '00054'), strpos($page1, '00055'));
        $this->assertStringNotContainsString('>00005<', $page1);
        $this->connecte($this->admin)->get($this->url('etudiants?p=2'))->assertSee('00005')->assertDontSee('00055');
    }

    public function test_un_compte_de_faculte_voit_sa_faculte_mais_recherche_partout(): void
    {
        $fac1 = DB::table('facultes')->insertGetId(['code' => 'A', 'libelle' => 'Faculté A']);
        $fac2 = DB::table('facultes')->insertGetId(['code' => 'B', 'libelle' => 'Faculté B']);
        $fA = DB::table('filieres')->insertGetId(['code' => 'FA', 'libelle' => 'FA', 'departement' => '', 'faculte_id' => $fac1]);
        $fB = DB::table('filieres')->insertGetId(['code' => 'FB', 'libelle' => 'FB', 'departement' => '', 'faculte_id' => $fac2]);
        $this->inscrire($this->etudiant(['nom' => 'DEFAC']), $this->creerPromotion($this->annee, ['filiere_id' => $fA]));
        $this->inscrire($this->etudiant(['nom' => 'AUTREFAC']), $this->creerPromotion($this->annee, ['filiere_id' => $fB]));

        $this->donnerPermissions('secretaire', ['etudiants_voir']);
        $u = $this->creerUtilisateur('secretaire', ['faculte_id' => $fac1]);
        $this->connecte($u)->get($this->url('etudiants'))->assertSee('DEFAC')->assertDontSee('AUTREFAC')->assertSee('Vous ne voyez que les étudiants de cette faculté');
        $this->connecte($u)->get($this->url('etudiants?q=AUTRE'))->assertSee('AUTREFAC')->assertSee('Recherche en cours sur toutes les facultés');
    }

    // ── Fiche ─────────────────────────────────────────────────

    public function test_creation_sans_promotion_sans_matricule_plusieurs_fois(): void
    {
        foreach (['ABDOU', 'ZEINABOU'] as $nom) {
            $this->connecte($this->admin)->post($this->url('etudiants/fiche'), ['nom' => $nom, 'prenoms' => 'test', 'sexe' => 'F', 'statut_etudiant' => 'pirate'])
                ->assertSessionHas('succes', "Étudiant créé — sans matricule (il sera attribué à l'inscription).");
        }
        // Avant : le second échouait (« Duplicate entry '0' ») — matricule NULL désormais
        $this->assertSame(2, DB::table('etudiants')->whereNull('numero')->count());
        $this->assertDatabaseHas('etudiants', ['nom' => 'ZEINABOU', 'prenoms' => 'Test', 'statut_etudiant' => 'actif']);
    }

    public function test_creation_avec_promotion_donne_un_matricule_et_inscrit(): void
    {
        $this->etudiant(['numero' => '00041']);
        $promo = $this->creerPromotion($this->annee);

        $this->connecte($this->admin)->post($this->url('etudiants/fiche'), [
            'nom' => 'élodie', 'prenoms' => 'MARIE-CLAIRE', 'promotion_id' => $promo,
            'nationalite' => '__autre__', 'nationalite_autre' => 'Mauritanienne', 'email' => 'Test@Mail.NE',
        ])->assertSessionHas('succes', 'Étudiant créé et inscrit — Matricule : 00042');

        $e = DB::table('etudiants')->where('numero', '00042')->first();
        $this->assertSame(['ÉLODIE', 'Marie-Claire', 'Mauritanienne', 'test@mail.ne'], [$e->nom, $e->prenoms, $e->nationalite, $e->email]);
        $this->assertDatabaseHas('inscriptions', ['etudiant_id' => $e->id, 'promotion_id' => $promo, 'statut' => 'inscrit']);
        $this->assertDatabaseHas('audit_log', ['action' => 'creer', 'module' => 'etudiant', 'cible_id' => $e->id]);
    }

    public function test_modification_et_matricule_deja_pris(): void
    {
        $this->etudiant(['numero' => '00002']);
        $id = $this->etudiant(['numero' => '00001']);

        $this->connecte($this->admin)->from($this->url("etudiants/fiche?id=$id"))
            ->post($this->url('etudiants/fiche'), ['id' => $id, 'nom' => 'X', 'numero' => '00002'])
            ->assertSessionHas('erreur', 'Le matricule « 00002 » est déjà attribué à un autre étudiant.');

        $this->connecte($this->admin)->post($this->url('etudiants/fiche'), ['id' => $id, 'nom' => 'NOUVEAU', 'numero' => '00009', 'statut_etudiant' => 'abandonne'])
            ->assertSessionHas('succes', 'Étudiant mis à jour.');
        $this->assertDatabaseHas('etudiants', ['id' => $id, 'nom' => 'NOUVEAU', 'numero' => '00009', 'statut_etudiant' => 'abandonne']);
    }

    public function test_formulaire_affiche(): void
    {
        $id = $this->etudiant(['nom' => 'HAROUNA', 'numero' => '00003']);
        $this->connecte($this->admin)->get($this->url("etudiants/fiche?id=$id"))->assertOk()->assertSee('HAROUNA')->assertSee('value="00003"', false);
        $this->connecte($this->admin)->get($this->url('etudiants/fiche'))->assertOk()->assertSee("Créer l'étudiant");
        $this->connecte($this->admin)->get($this->url('etudiants/fiche?id=999999'))->assertRedirect($this->url('etudiants'));
    }

    // ── Suppression ───────────────────────────────────────────

    public function test_suppression_fiche_vide_autorisee(): void
    {
        $id = $this->etudiant();
        $this->connecte($this->admin)->post($this->url('etudiants/supprimer'), ['id' => $id])->assertSessionHas('succes', 'Étudiant supprimé.');
        $this->assertDatabaseMissing('etudiants', ['id' => $id]);
    }

    public function test_suppression_refusee_avec_historique(): void
    {
        $id = $this->etudiant(['numero' => '00001']);
        $this->insererMinimal('paiements', ['etudiant_id' => $id]);

        $this->connecte($this->admin)->post($this->url('etudiants/supprimer'), ['id' => $id])
            ->assertRedirect($this->url("etudiants/fiche?id=$id"))
            ->assertSessionHas('erreur', 'Suppression impossible : cet étudiant a un historique (1 paiement(s)). Passez plutôt son statut à « Abandonné ».');
        $this->assertDatabaseHas('etudiants', ['id' => $id]);
    }

    public function test_suppression_reservee_a_l_admin(): void
    {
        $id = $this->etudiant();
        $this->donnerPermissions('secretaire', ['etudiants_voir']);
        $this->connecte($this->creerUtilisateur('secretaire'))->post($this->url('etudiants/supprimer'), ['id' => $id])->assertForbidden();
        $this->assertDatabaseHas('etudiants', ['id' => $id]);
    }

    // ── API de recherche ──────────────────────────────────────

    public function test_api_de_recherche(): void
    {
        $this->etudiant(['nom' => 'MAHAMANE', 'prenoms' => 'Ibrahim', 'numero' => '00077', 'email' => 'm@x.ne']);

        $this->connecte($this->admin)->getJson($this->url('api/etudiants?q=maha'))
            ->assertOk()->assertJsonPath('0.numero', '00077')->assertJsonPath('0.email', 'm@x.ne');
        $this->connecte($this->admin)->getJson($this->url('api/etudiants?q=m'))->assertExactJson([]);
        $this->connecte($this->admin)->getJson($this->url('api/etudiants/recherche?q=00077'))
            ->assertOk()->assertJsonPath('0.nom', 'MAHAMANE')->assertJsonMissingPath('0.email');
    }

    public function test_photo_reservee_au_personnel(): void
    {
        $this->get($this->url('fichiers/photos/x.jpg'))->assertRedirect($this->url('connexion'));
    }
}
