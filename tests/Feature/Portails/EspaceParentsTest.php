<?php

namespace Tests\Feature\Portails;

use App\Models\ParentEleve;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\RateLimiter;
use Tests\TestCase;

/** Espace Parents : connexion, enfants liés, résultats publiés, compte. */
class EspaceParentsTest extends TestCase
{
    private int $parent;

    private int $enfant;

    private int $autreEnfant;

    protected function setUp(): void
    {
        parent::setUp();
        $this->installerLicence();
        RateLimiter::clear('connexion_parent|udh|127.0.0.1');
        $annee = DB::table('annees_academiques')->insertGetId(['libelle' => '2025-2026', 'active' => 1]);
        $promo = $this->creerPromotion($annee);
        $s1 = DB::table('semestres')->insertGetId(['code' => 'S1', 'libelle' => 'Semestre 1']);
        $s2 = DB::table('semestres')->insertGetId(['code' => 'S2', 'libelle' => 'Semestre 2']);
        $pv1 = DB::table('pv_sessions')->insertGetId(['promotion_id' => $promo, 'semestre_id' => $s1, 'type_session' => 'normale', 'libelle' => 'PV S1', 'statut' => 'publie']);
        $pv2 = DB::table('pv_sessions')->insertGetId(['promotion_id' => $promo, 'semestre_id' => $s2, 'type_session' => 'normale', 'libelle' => 'PV S2', 'statut' => 'brouillon']);
        $this->enfant = DB::table('etudiants')->insertGetId(['nom' => 'ALPHA', 'prenoms' => 'Awa', 'numero' => '00001']);
        $this->autreEnfant = DB::table('etudiants')->insertGetId(['nom' => 'GAMMA', 'prenoms' => 'Zara', 'numero' => '00003']);
        DB::table('resultats_semestre')->insert([
            ['pv_id' => $pv1, 'etudiant_id' => $this->enfant, 'moyenne_generale' => 13.25, 'valide' => 1, 'mention' => 'Assez bien'],
            ['pv_id' => $pv2, 'etudiant_id' => $this->enfant, 'moyenne_generale' => 7.5, 'valide' => 0, 'mention' => 'Ajourné'],
        ]);
        DB::table('paiements')->insert(['etudiant_id' => $this->enfant, 'type_paiement' => 'inscription', 'montant_du' => 50000, 'montant_paye' => 30000, 'statut' => 'partiel', 'date_paiement' => '2025-10-02']);
        $this->parent = DB::table('parents')->insertGetId(['nom' => 'ALPHA', 'prenoms' => 'Moussa', 'telephone' => '+22790000001', 'mot_de_passe_hash' => Hash::make('Parent123'), 'actif' => 1]);
        DB::table('parents_etudiants')->insert(['parent_id' => $this->parent, 'etudiant_id' => $this->enfant]);
    }

    private function seConnecter(string $mdp = 'Parent123')
    {
        return $this->post($this->url('parents/connexion'), ['telephone' => '+227 90 00 00 01', 'mot_de_passe' => $mdp]);
    }

    public function test_connexion_et_portail(): void
    {
        $this->get($this->url('parents'))->assertRedirect($this->url('parents/connexion'));
        $this->get($this->url('parents/connexion'))->assertOk()->assertSee('ESPACE PARENTS');
        $this->seConnecter('mauvais')->assertSessionHas('erreur', 'Numéro de téléphone ou mot de passe incorrect.');
        $this->seConnecter()->assertRedirect($this->url('parents'));
        $this->assertNotNull(DB::table('parents')->where('id', $this->parent)->value('derniere_connexion'));

        // Résultats publiés seulement ; pas d'accès à un enfant non lié
        $this->get($this->url('parents'))->assertOk()->assertSee('ALPHA Awa')->assertSee('13.25/20')->assertDontSee('7.50/20')->assertSee('30 000 FCFA');
        $this->get($this->url("parents?et_id={$this->autreEnfant}"))->assertOk()->assertSee('ALPHA Awa')->assertDontSee('GAMMA');
        // Les pages du personnel restent fermées
        $this->get($this->url('etudiants'))->assertRedirect($this->url('connexion'));

        $this->post($this->url('parents/deconnexion'))->assertRedirect($this->url('parents/connexion'));
        $this->get($this->url('parents'))->assertRedirect($this->url('parents/connexion'));
    }

    public function test_compte_desactive_limite_et_expiration(): void
    {
        DB::table('parents')->where('id', $this->parent)->update(['actif' => 0]);
        $this->seConnecter()->assertSessionHas('erreur', "Ce compte a été désactivé. Contactez l'établissement.");
        DB::table('parents')->where('id', $this->parent)->update(['actif' => 1]);

        $this->seConnecter()->assertRedirect($this->url('parents'));
        $this->withSession(['parent_activite' => time() - 3600])->get($this->url('parents'))->assertRedirect($this->url('parents/connexion?expired=1'));

        // Compte désactivé pendant la session : déconnecté
        $this->seConnecter();
        DB::table('parents')->where('id', $this->parent)->update(['actif' => 0]);
        $this->app['auth']->forgetGuards(); // nouvelle requête : le compte est relu en base
        $this->get($this->url('parents'))->assertRedirect($this->url('parents/connexion'));

        DB::table('parents')->where('id', $this->parent)->update(['actif' => 1]);
        $this->post($this->url('parents/deconnexion'));
        for ($i = 0; $i < 5; $i++) {
            $this->seConnecter('mauvais');
        }
        $this->seConnecter()->assertRedirect();
        $this->get($this->url('parents'))->assertRedirect($this->url('parents/connexion'));
        $this->get($this->url('parents/connexion'))->assertSee('Trop de tentatives');
    }

    public function test_mon_compte(): void
    {
        $this->seConnecter();
        $this->post($this->url('parents/compte'), ['email' => 'moussa@exemple.ne', 'mot_de_passe_actuel' => 'faux'])->assertSessionHas('erreur_compte', 'Mot de passe actuel incorrect.');
        $this->post($this->url('parents/compte'), ['mot_de_passe_actuel' => 'Parent123', 'nouveau_mot_de_passe' => '1234', 'nouveau_mot_de_passe_confirmation' => '1234'])
            ->assertSessionHas('erreur_compte');
        $this->post($this->url('parents/compte'), ['email' => 'moussa@exemple.ne', 'mot_de_passe_actuel' => 'Parent123',
            'nouveau_mot_de_passe' => 'Nouveau123', 'nouveau_mot_de_passe_confirmation' => 'Nouveau123'])
            ->assertSessionHas('succes_compte', 'Informations mises à jour avec succès.');
        $parent = ParentEleve::find($this->parent);
        $this->assertSame('moussa@exemple.ne', $parent->email);
        $this->assertTrue(Hash::check('Nouveau123', $parent->mot_de_passe_hash));
    }
}
