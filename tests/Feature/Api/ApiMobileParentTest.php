<?php

namespace Tests\Feature\Api;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Testing\TestResponse;
use Tests\TestCase;

/** API mobile, Espace parent : connexion, enfants liés seulement, suivi en lecture seule. */
class ApiMobileParentTest extends TestCase
{
    private int $parent;

    private int $enfant;

    private int $autre;

    private int $pv;

    protected function setUp(): void
    {
        parent::setUp();
        $this->installerLicence();
        RateLimiter::clear('api_mobile_parents|udh|127.0.0.1|90112233');
        $annee = DB::table('annees_academiques')->insertGetId(['libelle' => '2025-2026', 'active' => 1]);
        $filiere = DB::table('filieres')->insertGetId(['code' => 'ECO', 'libelle' => 'Économie', 'departement' => '', 'actif' => 1]);
        $promo = $this->creerPromotion($annee, ['filiere_id' => $filiere, 'niveau_id' => DB::table('niveaux')->insertGetId(['code' => 'L1', 'libelle' => 'Licence 1'])]);
        $sem = DB::table('semestres')->insertGetId(['code' => 'S1', 'libelle' => 'Semestre 1']);
        $ue = DB::table('unites_enseignement')->insertGetId(['promotion_id' => $promo, 'semestre_id' => $sem, 'code' => 'UE1', 'libelle' => 'UE 1', 'credits_total' => 6, 'ordre' => 1]);
        $mat = DB::table('matieres')->insertGetId(['ue_id' => $ue, 'code' => 'M1', 'libelle' => 'Microéconomie', 'note_max_examen' => 20, 'note_max_cc' => 0, 'note_max_totale' => 20, 'credits' => 6, 'ordre' => 1]);
        $this->enfant = DB::table('etudiants')->insertGetId(['numero' => '00001', 'nom' => 'ALPHA', 'prenoms' => 'Awa', 'filiere_id' => $filiere]);
        $this->autre = DB::table('etudiants')->insertGetId(['numero' => '00002', 'nom' => 'BRAVO', 'prenoms' => 'Ben', 'filiere_id' => $filiere]);
        $ins = DB::table('inscriptions')->insertGetId(['etudiant_id' => $this->enfant, 'promotion_id' => $promo, 'annee_id' => $annee, 'statut' => 'inscrit', 'date_inscription' => '2025-10-01']);
        DB::table('inscriptions_pedagogiques')->insert(['etudiant_id' => $this->enfant, 'inscription_id' => $ins, 'semestre_id' => $sem, 'annee_id' => $annee]);
        $this->pv = DB::table('pv_sessions')->insertGetId(['promotion_id' => $promo, 'semestre_id' => $sem, 'type_session' => 'normale', 'libelle' => 'PV S1', 'statut' => 'brouillon']);
        DB::table('notes')->insert(['pv_id' => $this->pv, 'etudiant_id' => $this->enfant, 'matiere_id' => $mat, 'note_examen' => 14, 'note_finale' => 14, 'observation' => 'AV']);
        DB::table('resultats_semestre')->insert(['pv_id' => $this->pv, 'etudiant_id' => $this->enfant, 'moyenne_generale' => 14, 'valide' => 1, 'mention' => 'Bien', 'credits_obtenus' => 6]);
        DB::table('paiements')->insert(['etudiant_id' => $this->enfant, 'montant_du' => 15000, 'montant_paye' => 10000, 'date_paiement' => '2025-10-01', 'statut' => 'partiel']);
        $seance = DB::table('seances')->insertGetId(['matiere_id' => $mat, 'promotion_id' => $promo, 'date_seance' => '2025-11-03', 'type_seance' => 'CM']);
        DB::table('presences')->insert(['seance_id' => $seance, 'etudiant_id' => $this->enfant, 'statut' => 'absent', 'motif' => 'Malade']);
        DB::table('emploi_temps')->insert(['promotion_id' => $promo, 'annee_id' => $annee, 'semestre_id' => $sem, 'jour' => 'Lundi', 'heure_debut' => '08:00', 'heure_fin' => '10:00', 'matiere_id' => $mat, 'salle' => 'Amphi A']);
        DB::table('planning_examens')->insert(['promotion_id' => $promo, 'semestre_id' => $sem, 'session' => 'normale', 'matiere_id' => $mat, 'date_examen' => now()->addDays(10)->toDateString(),
            'heure_debut' => '08:00', 'heure_fin' => '10:00', 'salle' => 'Salle 5']);
        DB::table('annonces')->insert(['titre' => 'Rentrée', 'contenu' => 'Bienvenue', 'cible' => 'tous', 'publiee' => 1, 'date_debut' => now()->subDay()->toDateString()]);
        $this->parent = DB::table('parents')->insertGetId(['nom' => 'ALPHA', 'prenoms' => 'Moussa', 'telephone' => '90112233', 'mot_de_passe_hash' => Hash::make('Parent123')]);
        DB::table('parents_etudiants')->insert(['parent_id' => $this->parent, 'etudiant_id' => $this->enfant, 'lien' => 'pere']);
    }

    private function jeton(): string
    {
        return $this->postJson($this->url('api/mobile/parents?action=login'), ['telephone' => '90 11 22 33', 'password' => 'Parent123'])->assertOk()->json('data.token');
    }

    private function api(string $jeton, string $action, array $params = []): TestResponse
    {
        return $this->getJson($this->url('api/mobile/parents?'.http_build_query(['action' => $action] + $params)), ['Authorization' => "Bearer $jeton"]);
    }

    public function test_connexion_et_enfants_lies(): void
    {
        $this->postJson($this->url('api/mobile/parents?action=login'), ['telephone' => '90112233', 'password' => 'mauvais'])->assertJson(['success' => false]);
        $r = $this->postJson($this->url('api/mobile/parents?action=login'), ['telephone' => '90112233', 'password' => 'Parent123'])->assertOk()
            ->assertJsonPath('data.parent.prenoms', 'Moussa')->assertJsonPath('data.parent.enfants.0.nom', 'ALPHA')->assertJsonPath('data.parent.enfants.0.niveau', 'L1')
            ->assertJsonCount(1, 'data.parent.enfants');
        $jeton = $r->json('data.token');
        // Jeton enregistré sous forme d'empreinte
        $this->assertSame(hash('sha256', $jeton), DB::table('mobile_tokens_parents')->value('token'));

        // Sans jeton, ou jeton d'étudiant : refusé ; enfant non lié : refusé
        $this->getJson($this->url('api/mobile/parents?action=profil'))->assertStatus(401);
        $this->api($jeton, 'resultats', ['et_id' => $this->autre])->assertStatus(403);
        $this->api($jeton, 'enfant', ['et_id' => $this->autre])->assertStatus(403);

        // Compte désactivé : session refusée
        DB::table('parents')->where('id', $this->parent)->update(['actif' => 0]);
        $this->api($jeton, 'profil')->assertStatus(401);
        $this->postJson($this->url('api/mobile/parents?action=login'), ['telephone' => '90112233', 'password' => 'Parent123'])->assertStatus(403);
    }

    public function test_suivi_de_l_enfant(): void
    {
        $jeton = $this->jeton();
        $et = ['et_id' => $this->enfant];

        // PV en brouillon : ni notes ni résultat
        $this->api($jeton, 'resultats', $et)->assertOk()->assertJsonCount(0, 'data.resultats')->assertJsonPath('data.notes.0.note_finale', null);
        DB::table('pv_sessions')->where('id', $this->pv)->update(['statut' => 'publie']);
        $this->api($jeton, 'resultats', $et)->assertOk()->assertJsonPath('data.resultats.0.mention', 'Bien')->assertJsonPath('data.notes.0.matiere', 'Microéconomie');

        $this->api($jeton, 'enfant', $et)->assertOk()->assertJsonPath('data.etudiant.nom', 'ALPHA')->assertJsonPath('data.inscription.filiere', 'Économie')
            ->assertJsonPath('data.dernier_resultat.mention', 'Bien')->assertJsonPath('data.absences', 1)->assertJsonPath('data.taux_presence', 0)
            ->assertJsonPath('data.reste_a_payer', 5000)->assertJsonPath('data.prochains_examens.0.salle', 'Salle 5')->assertJsonPath('data.nb_annonces', 1)
            ->assertJsonMissingPath('data.etudiant.photo');
        $this->api($jeton, 'presences', $et)->assertOk()->assertJsonPath('data.presences.0.motif', 'Malade');
        $this->api($jeton, 'paiements', $et)->assertOk()->assertJsonPath('data.total_paye', 10000);
        $this->api($jeton, 'emploi_du_temps', $et)->assertOk()->assertJsonPath('data.0.jour', 'Lundi')->assertJsonPath('data.0.seances.0.salle', 'Amphi A');
        $this->api($jeton, 'examens', $et)->assertOk()->assertJsonPath('data.0.matiere', 'Microéconomie');
        $this->api($jeton, 'annonces', $et)->assertOk()->assertJsonPath('data.0.titre', 'Rentrée');
    }

    public function test_mot_de_passe_oublie_par_sms(): void
    {
        RateLimiter::clear('reset_parent|udh|demande|127.0.0.1|90112233');
        RateLimiter::clear('reset_parent|udh|reset|127.0.0.1|90112233');
        $jeton = $this->jeton();
        $api = fn (string $action, array $d) => $this->postJson($this->url("api/mobile/parents?action=$action"), $d);

        // Même réponse pour un numéro inconnu ; aucun SMS
        $api('reset_password_demande', ['telephone' => '99999999'])->assertJson(['success' => true]);
        $this->assertSame(0, DB::table('sms_logs')->count());
        $api('reset_password_demande', ['telephone' => '90 11 22 33'])->assertJson(['success' => true]);
        $sms = DB::table('sms_logs')->where('contexte', 'reset_parent')->first();
        $this->assertSame('90112233', $sms->numero);
        preg_match('/(\d{6})/', $sms->message, $m);

        $api('reset_password', ['telephone' => '90112233', 'code' => '000000', 'nouveau_mdp' => 'Nouveau123'])->assertJson(['success' => false]);
        $api('reset_password', ['telephone' => '90112233', 'code' => $m[1], 'nouveau_mdp' => 'court'])->assertJson(['success' => false]);
        $api('reset_password', ['telephone' => '90112233', 'code' => $m[1], 'nouveau_mdp' => 'Nouveau123'])->assertJson(['success' => true]);
        $this->assertTrue(Hash::check('Nouveau123', DB::table('parents')->value('mot_de_passe_hash')));
        // Code à usage unique ; sessions mobiles fermées
        $api('reset_password', ['telephone' => '90112233', 'code' => $m[1], 'nouveau_mdp' => 'Autre1234'])->assertJson(['success' => false]);
        $this->api($jeton, 'profil')->assertStatus(401);
    }

    public function test_mot_de_passe_oublie_sur_le_site(): void
    {
        RateLimiter::clear('reset_parent|udh|demande|127.0.0.1|90112233');
        $this->get($this->url('parents/connexion'))->assertOk()->assertSee('Mot de passe oublié ?');
        $this->post($this->url('parents/mot-de-passe-oublie/code'), ['telephone' => '90112233'])->assertRedirect($this->url('parents/mot-de-passe-oublie'));
        $this->get($this->url('parents/mot-de-passe-oublie'))->assertOk()->assertSee('Code envoyé au')->assertSee('90112233');
        preg_match('/(\d{6})/', DB::table('sms_logs')->value('message'), $m);
        $this->post($this->url('parents/mot-de-passe-oublie'), ['code' => $m[1], 'nouveau' => 'Nouveau123'])->assertRedirect($this->url('parents/connexion'));
        $this->post($this->url('parents/connexion'), ['telephone' => '90112233', 'mot_de_passe' => 'Nouveau123'])->assertRedirect($this->url('parents'));
    }

    public function test_mot_de_passe_et_deconnexion(): void
    {
        $jeton = $this->jeton();
        $autre = $this->jeton();
        $poster = fn (string $j, string $action, array $d = []) => $this->postJson($this->url("api/mobile/parents?action=$action"), $d, ['Authorization' => "Bearer $j"]);

        $poster($jeton, 'mot_de_passe', ['actuel' => 'faux', 'nouveau' => 'Nouveau123'])->assertJson(['success' => false]);
        $poster($jeton, 'mot_de_passe', ['actuel' => 'Parent123', 'nouveau' => 'court'])->assertJson(['success' => false]);
        $poster($jeton, 'mot_de_passe', ['actuel' => 'Parent123', 'nouveau' => 'Nouveau123'])->assertJson(['success' => true]);
        // Les autres appareils sont déconnectés, pas celui-ci
        $this->api($autre, 'profil')->assertStatus(401);
        $this->api($jeton, 'profil')->assertOk();
        $this->assertTrue(Hash::check('Nouveau123', DB::table('parents')->value('mot_de_passe_hash')));

        $poster($jeton, 'logout')->assertOk();
        $this->api($jeton, 'profil')->assertStatus(401);
    }
}
