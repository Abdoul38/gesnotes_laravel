<?php

namespace Tests\Feature\Portails;

use App\Models\Utilisateur;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

/** Portail étudiant : résultats publiés, détail, périmètre du personnel, profil. */
class PortailEtudiantTest extends TestCase
{
    private Utilisateur $etudiant;

    private int $et;

    private int $pvPublie;

    private int $pvFinalise;

    private int $facB;

    protected function setUp(): void
    {
        parent::setUp();
        $this->installerLicence();
        $annee = DB::table('annees_academiques')->insertGetId(['libelle' => '2025-2026', 'active' => 1]);
        $this->facB = $this->insererMinimal('facultes', ['code' => 'FB', 'libelle' => 'Faculté B']);
        $filiere = DB::table('filieres')->insertGetId(['code' => 'ECO', 'libelle' => 'Économie', 'departement' => '', 'faculte_id' => $this->facB]);
        $promo = $this->creerPromotion($annee, ['filiere_id' => $filiere]);
        $s1 = DB::table('semestres')->insertGetId(['code' => 'S1', 'libelle' => 'Semestre 1']);
        $s2 = DB::table('semestres')->insertGetId(['code' => 'S2', 'libelle' => 'Semestre 2']);
        $ue = DB::table('unites_enseignement')->insertGetId(['promotion_id' => $promo, 'semestre_id' => $s1, 'code' => 'UE1', 'libelle' => 'UE Fondamentale', 'credits_total' => 6, 'ordre' => 1]);
        $m = DB::table('matieres')->insertGetId(['ue_id' => $ue, 'code' => 'M1', 'libelle' => 'Microéconomie', 'note_max_examen' => 20, 'note_max_cc' => 0, 'note_max_totale' => 20, 'credits' => 6, 'ordre' => 1]);
        $this->et = DB::table('etudiants')->insertGetId(['nom' => 'ALPHA', 'prenoms' => 'Awa', 'numero' => '00001', 'filiere_id' => $filiere, 'telephone' => '+22790000001']);
        DB::table('inscriptions')->insert(['etudiant_id' => $this->et, 'promotion_id' => $promo, 'statut' => 'inscrit', 'date_inscription' => '2025-10-01']);
        $this->pvPublie = DB::table('pv_sessions')->insertGetId(['promotion_id' => $promo, 'semestre_id' => $s1, 'type_session' => 'normale', 'libelle' => 'PV S1', 'statut' => 'publie']);
        $this->pvFinalise = DB::table('pv_sessions')->insertGetId(['promotion_id' => $promo, 'semestre_id' => $s2, 'type_session' => 'normale', 'libelle' => 'PV S2', 'statut' => 'finalise']);
        DB::table('notes')->insert(['pv_id' => $this->pvPublie, 'etudiant_id' => $this->et, 'matiere_id' => $m, 'note_examen' => 14, 'note_finale' => 14, 'observation' => 'AV']);
        DB::table('resultats_semestre')->insert([
            ['pv_id' => $this->pvPublie, 'etudiant_id' => $this->et, 'moyenne_generale' => 14, 'credits_obtenus' => 6, 'valide' => 1, 'mention' => 'Bien', 'decision' => 'Admis'],
            ['pv_id' => $this->pvFinalise, 'etudiant_id' => $this->et, 'moyenne_generale' => 9.5, 'credits_obtenus' => 0, 'valide' => 0, 'mention' => 'Ajourné', 'decision' => 'Ajourné'],
        ]);
        $this->etudiant = $this->creerUtilisateur('etudiant', ['etudiant_id' => $this->et, 'email' => 'awa@exemple.ne', 'mot_de_passe' => 'Actuel123']);
    }

    public function test_etudiant_voit_ses_resultats_finalises_ou_publies(): void
    {
        // PV finalisé ou publié : visible (décision de l'université) ; en brouillon : caché
        $this->connecte($this->etudiant)->get($this->url('portail/etudiant'))->assertOk()
            ->assertSee('ALPHA Awa')->assertSee('Semestre 1')->assertSee('Semestre 2')->assertDontSee('Ouvrir le dossier');
        $this->get($this->url("portail/etudiant?pv_id={$this->pvPublie}"))->assertOk()->assertSee('Microéconomie')->assertSee('14.00/ 20')->assertSee('Admis');
        $this->get($this->url("portail/etudiant?pv_id={$this->pvFinalise}"))->assertOk()->assertSee('Ajourné');
        DB::table('pv_sessions')->where('id', $this->pvFinalise)->update(['statut' => 'brouillon']);
        $this->get($this->url('portail/etudiant'))->assertOk()->assertDontSee('Semestre 2');
        // PV en brouillon, autre étudiant via et_id : rien
        $this->get($this->url("portail/etudiant?pv_id={$this->pvFinalise}"))->assertOk()->assertDontSee('Ajourné');
        $autre = DB::table('etudiants')->insertGetId(['nom' => 'BETA', 'prenoms' => 'B', 'numero' => '00002']);
        $this->get($this->url("portail/etudiant?et_id=$autre"))->assertOk()->assertSee('ALPHA Awa')->assertDontSee('BETA');
        // Pages du personnel : renvoyé vers le portail
        $this->get($this->url('etudiants'))->assertRedirect($this->url('portail/etudiant'));
    }

    public function test_personnel_dans_son_perimetre(): void
    {
        $admin = $this->creerUtilisateur('admin');
        $this->connecte($admin)->get($this->url('portail/etudiant?q=ALPHA'))->assertOk()->assertSee('ALPHA Awa');
        $this->get($this->url("portail/etudiant?et_id={$this->et}"))->assertOk()->assertSee('Semestre 2')->assertSee('📁 Dossier');
        $this->get($this->url("portail/etudiant?et_id={$this->et}&tab=dossier"))->assertOk()->assertSee('Ouvrir le dossier');

        $facA = $this->insererMinimal('facultes', ['code' => 'FA', 'libelle' => 'Faculté A']);
        $this->donnerPermissions('sc_entite', ['portail_etudiant']);
        $agent = $this->creerUtilisateur('sc_entite', ['faculte_id' => $facA]);
        $this->connecte($agent)->get($this->url('portail/etudiant?q=ALPHA'))->assertOk()->assertSee('Aucun étudiant trouvé dans votre périmètre');
        $this->get($this->url("portail/etudiant?et_id={$this->et}"))->assertRedirect($this->url('portail/etudiant'));

        $this->donnerPermissions('enseignant', ['portail_etudiant']);
        $this->connecte($this->creerUtilisateur('enseignant'))->get($this->url('portail/etudiant'))->assertRedirect($this->url('accueil'));
    }

    public function test_profil_et_mot_de_passe(): void
    {
        $this->connecte($this->etudiant);
        $this->post($this->url('portail/etudiant/profil'), ['telephone' => '+227 91 00 00 00', 'adresse' => 'Tahoua'])->assertSessionHas('succes', 'Profil mis à jour.');
        $this->assertSame('Tahoua', DB::table('etudiants')->where('id', $this->et)->value('adresse'));

        // Mot de passe : actuel exigé, règles, confirmation
        $this->post($this->url('portail/etudiant/profil'), ['mdp' => 'Nouveau123', 'mdp_confirmation' => 'Nouveau123'])
            ->assertSessionHas('erreur', "Mot de passe actuel incorrect : l'e-mail et le mot de passe n'ont pas été modifiés.");
        $this->post($this->url('portail/etudiant/profil'), ['mdp' => 'court', 'mdp_confirmation' => 'court', 'mot_de_passe_actuel' => 'Actuel123'])->assertSessionHas('erreur');
        $this->post($this->url('portail/etudiant/profil'), ['mdp' => 'Nouveau123', 'mdp_confirmation' => 'Autre1234', 'mot_de_passe_actuel' => 'Actuel123'])
            ->assertSessionHas('erreur', 'La confirmation du nouveau mot de passe ne correspond pas.');
        $this->post($this->url('portail/etudiant/profil'), ['mdp' => 'Nouveau123', 'mdp_confirmation' => 'Nouveau123', 'mot_de_passe_actuel' => 'Actuel123'])
            ->assertSessionHas('succes', 'Profil et mot de passe mis à jour.');
        $this->assertTrue(Hash::check('Nouveau123', $this->etudiant->fresh()->mot_de_passe));

        // E-mail de connexion : mot de passe exigé, unicité
        $this->creerUtilisateur('secretaire', ['email' => 'pris@exemple.ne']);
        $this->post($this->url('portail/etudiant/profil'), ['email' => 'nouvel@exemple.ne'])->assertSessionHas('erreur');
        $this->post($this->url('portail/etudiant/profil'), ['email' => 'pris@exemple.ne', 'mot_de_passe_actuel' => 'Nouveau123'])
            ->assertSessionHas('erreur', 'Cette adresse e-mail est déjà utilisée par un autre compte.');
        $this->post($this->url('portail/etudiant/profil'), ['email' => 'nouvel@exemple.ne', 'mot_de_passe_actuel' => 'Nouveau123'])->assertSessionHas('succes');
        $this->assertSame('nouvel@exemple.ne', $this->etudiant->fresh()->email);
        $this->assertDatabaseHas('audit_log', ['module' => 'etudiant', 'action' => 'modifier']);
    }
}
