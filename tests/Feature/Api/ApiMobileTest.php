<?php

namespace Tests\Feature\Api;

use App\Models\Utilisateur;
use App\Services\Communication\Courriel;
use App\Services\Parametres;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Testing\TestResponse;
use Tests\TestCase;

/** API mobile : connexion, jetons, résultats publiés, messagerie, réclamations, réinitialisation par code. */
class ApiMobileTest extends TestCase
{
    private int $etudiant;

    private int $pvPublie;

    private int $matiere;

    protected function setUp(): void
    {
        parent::setUp();
        $this->installerLicence();
        foreach (['login', 'reset_password', 'reset_demande'] as $a) {
            RateLimiter::clear("api_mobile|udh|$a|127.0.0.1|00001");
        }
        $this->creerUtilisateur('admin');
        $annee = DB::table('annees_academiques')->insertGetId(['libelle' => '2026-2027', 'active' => 1]);
        $filiere = DB::table('filieres')->insertGetId(['code' => 'ECO', 'libelle' => 'Économie', 'departement' => '', 'faculte' => 'FSEJ', 'actif' => 1]);
        $niveau = DB::table('niveaux')->insertGetId(['code' => 'L1', 'libelle' => 'Licence 1']);
        $promo = $this->creerPromotion($annee, ['filiere_id' => $filiere, 'niveau_id' => $niveau]);
        $sem = DB::table('semestres')->insertGetId(['code' => 'S1', 'libelle' => 'Semestre 1']);
        $ue = DB::table('unites_enseignement')->insertGetId(['promotion_id' => $promo, 'semestre_id' => $sem, 'code' => 'UE1', 'libelle' => 'UE 1', 'credits_total' => 6, 'ordre' => 1]);
        $this->matiere = DB::table('matieres')->insertGetId(['ue_id' => $ue, 'code' => 'M1', 'libelle' => 'Microéconomie', 'note_max_examen' => 20, 'note_max_cc' => 0, 'note_max_totale' => 20, 'credits' => 6, 'ordre' => 1]);
        $this->etudiant = DB::table('etudiants')->insertGetId(['numero' => '00001', 'nom' => 'ALPHA', 'prenoms' => 'Awa', 'date_naissance' => '2005-04-03',
            'filiere_id' => $filiere, 'email' => 'awa@exemple.ne', 'photo' => 'photo_1.jpg']);
        $ins = DB::table('inscriptions')->insertGetId(['etudiant_id' => $this->etudiant, 'promotion_id' => $promo, 'niveau_id' => $niveau, 'annee_id' => $annee,
            'date_inscription' => '2026-10-01', 'statut' => 'inscrit', 'montant_paye' => 10000]);
        DB::table('inscriptions_pedagogiques')->insert(['etudiant_id' => $this->etudiant, 'inscription_id' => $ins, 'semestre_id' => $sem, 'annee_id' => $annee]);
        $this->pvPublie = DB::table('pv_sessions')->insertGetId(['promotion_id' => $promo, 'semestre_id' => $sem, 'type_session' => 'normale', 'libelle' => 'PV S1', 'statut' => 'publie']);
        DB::table('notes')->insert(['pv_id' => $this->pvPublie, 'etudiant_id' => $this->etudiant, 'matiere_id' => $this->matiere, 'note_examen' => 12.5, 'note_finale' => 12.5, 'observation' => 'AV']);
        DB::table('resultats_semestre')->insert(['pv_id' => $this->pvPublie, 'etudiant_id' => $this->etudiant, 'moyenne_generale' => 12.5, 'valide' => 1, 'mention' => 'Assez bien']);
        $finalise = DB::table('pv_sessions')->insertGetId(['promotion_id' => $promo, 'semestre_id' => $sem, 'type_session' => 'reclamation', 'libelle' => 'PV non publié', 'statut' => 'finalise']);
        DB::table('resultats_semestre')->insert(['pv_id' => $finalise, 'etudiant_id' => $this->etudiant, 'moyenne_generale' => 3.25, 'valide' => 0, 'mention' => 'Ajourné']);
        DB::table('annonces')->insert([['titre' => 'Rentrée', 'contenu' => 'Bienvenue', 'cible' => 'tous', 'publiee' => 1, 'date_debut' => '2026-01-01'],
            ['titre' => 'Brouillon', 'contenu' => 'x', 'cible' => 'tous', 'publiee' => 0, 'date_debut' => '2026-01-01'],
            ['titre' => 'Niveau L1', 'contenu' => 'y', 'cible' => 'niveau', 'publiee' => 1, 'date_debut' => '2026-01-01']]);
        DB::table('annonces')->where('titre', 'Niveau L1')->update(['niveau_id' => $niveau]);
    }

    private function api(string $action, array $donnees = [], ?string $jeton = null, string $methode = 'post'): TestResponse
    {
        $entetes = $jeton ? ['Authorization' => "Bearer $jeton"] : [];

        return $methode === 'get'
            ? $this->getJson($this->url('api_mobile.php?'.http_build_query(['action' => $action] + $donnees)), $entetes)
            : $this->postJson($this->url("api_mobile.php?action=$action"), $donnees, $entetes);
    }

    private function jeton(): string
    {
        return $this->api('login', ['identifiant' => '00001', 'password' => 'Secret123', 'date_naissance' => '2005-04-03'])->json('data.token');
    }

    public function test_sans_sous_domaine_universite_par_defaut(): void
    {
        // localhost (essai par câble USB) ou domaine nu : du JSON pour l'application, pas la page de présentation
        foreach (['http://localhost/api_mobile.php?action=login', 'http://127.0.0.1/api_mobile.php?action=login'] as $adresse) {
            $this->postJson($adresse, ['identifiant' => '00001', 'password' => 'Secret123', 'date_naissance' => '2005-04-03'])
                ->assertOk()->assertJsonPath('data.etudiant.nom', 'ALPHA');
        }
        // Les pages web gardent la page de présentation
        $this->get('http://localhost/')->assertOk()->assertSee('<h1>Cursus</h1>', false);
    }

    public function test_connexion_jetons_et_donnees(): void
    {
        $this->api('login', ['identifiant' => '00001', 'password' => 'Secret123'])->assertJson(['success' => false, 'message' => 'Date de naissance requise pour votre première connexion.']);
        $this->api('login', ['identifiant' => '00001', 'password' => 'Secret123', 'date_naissance' => '2005-04-04'])->assertJson(['success' => false, 'message' => 'Date de naissance incorrecte.']);
        $this->api('login', ['identifiant' => '00001', 'password' => 'abc', 'date_naissance' => '2005-04-03'])->assertJson(['success' => false]);
        $r = $this->api('login', ['identifiant' => '00001', 'password' => 'Secret123', 'date_naissance' => '2005-04-03'])->assertOk()->assertJson(['success' => true, 'message' => 'Connexion réussie']);
        $jeton = $r->json('data.token');
        $this->assertArrayNotHasKey('password_hash', $r->json('data.etudiant'));
        $this->assertSame(hash('sha256', $jeton), DB::table('mobile_tokens')->value('token'), 'jeton stocké sous forme d\'empreinte');

        $this->api('profil', [], null, 'get')->assertStatus(401)->assertJson(['message' => 'Token manquant']);
        $this->api('profil', ['token' => $jeton], null, 'get')->assertStatus(401);
        $profil = $this->api('profil', [], $jeton, 'get')->assertOk()->json('data');
        $this->assertSame('ALPHA', $profil['nom']);
        $this->assertStringContainsString('/api/mobile/photo/'.$this->etudiant.'?expires=', $profil['photo_url']);

        // Ancien jeton en clair (créé par l'ancienne API) accepté jusqu'à son expiration
        DB::table('mobile_tokens')->insert(['etudiant_id' => $this->etudiant, 'token' => str_repeat('a', 64), 'expires_at' => now()->addDay()]);
        $this->api('statistiques', [], str_repeat('a', 64), 'get')->assertJson(['success' => true, 'data' => ['messages_non_lus' => 0, 'nb_annonces' => 2]]);

        // Résultats : PV finalisés ou publiés, jamais en brouillon
        $res = $this->api('resultats', [], $jeton, 'get')->assertOk()->json('data');
        $this->assertSame('12.50', $res['notes'][0]['note_finale']);
        $this->assertEqualsCanonicalizing(['12.50', '3.25'], array_column($res['resultats'], 'moyenne_generale'));
        DB::table('pv_sessions')->where('statut', 'finalise')->update(['statut' => 'brouillon']);
        $this->assertSame(['12.50'], array_column($this->api('resultats', [], $jeton, 'get')->json('data.resultats'), 'moyenne_generale'));
        DB::table('pv_sessions')->where('statut', 'brouillon')->update(['statut' => 'finalise']);

        $annonces = array_column($this->api('annonces', [], $jeton, 'get')->json('data'), 'titre');
        $this->assertEqualsCanonicalizing(['Rentrée', 'Niveau L1'], $annonces);
        $carte = $this->api('carte', [], $jeton, 'get')->assertOk()->json('data');
        $this->assertNull($carte['cachet_url']);
        $this->assertSame('L1', $carte['inscription']['niveau_code']);
        $ins = $this->api('inscriptions', [], $jeton, 'get')->json('data.0.id');
        $this->api('recu', ['ins_id' => $ins], $jeton, 'get')->assertJson(['success' => true, 'data' => ['filiere' => 'Économie', 'niveau' => 'L1']]);
        $this->api('check_inscription', [], $jeton, 'get')->assertJson(['data' => ['deja_inscrit' => true]]);

        // Messagerie
        $this->api('envoyer_message', ['sujet' => 'Question', 'corps' => 'Bonjour'], $jeton)->assertJson(['success' => true]);
        $admin = DB::table('messages')->value('destinataire_id');
        $versAdmin = DB::table('messages')->insertGetId(['expediteur_type' => 'etudiant', 'expediteur_id' => 99, 'destinataire_type' => 'admin', 'destinataire_id' => $this->etudiant, 'sujet' => 's', 'corps' => 'c']);
        $this->api('marquer_lu', ['message_id' => $versAdmin], $jeton);
        $this->assertSame(0, (int) DB::table('messages')->where('id', $versAdmin)->value('lu'), 'un message adressé à la scolarité reste non lu');
        $this->assertNotNull($admin);
        $this->assertCount(1, $this->api('messages', [], $jeton, 'get')->json('data'));

        // Essais limités
        for ($i = 0; $i < 5; $i++) {
            $this->api('login', ['identifiant' => '00001', 'password' => 'Mauvais123']);
        }
        $this->api('login', ['identifiant' => '00001', 'password' => 'Secret123'])->assertStatus(429);
        $this->api('inconnue')->assertStatus(404);
    }

    public function test_delai_de_reclamation_compte_depuis_la_publication(): void
    {
        $jeton = $this->jeton();
        $motif = ['pv_id' => $this->pvPublie, 'matiere_id' => $this->matiere, 'motif' => str_repeat('Note mal reportée. ', 2)];
        // Publié il y a 4 jours, modifié à l'instant : le délai est expiré (avant : relancé par la modification)
        DB::table('pv_publications')->insert(['pv_id' => $this->pvPublie, 'publie_le' => now()->subDays(4)]);
        DB::table('pv_sessions')->where('id', $this->pvPublie)->update(['libelle' => 'PV S1 corrigé', 'updated_at' => now()]);
        $pv = $this->api('reclamations_data', [], $jeton, 'get')->json('data.pv_list.0');
        $this->assertSame([0, 0], [(int) $pv['reclamation_ouverte'], (int) $pv['secondes_restantes']]);
        $this->api('soumettre_reclamation', $motif, $jeton)->assertJson(['success' => false, 'message' => 'Le délai de réclamation de 72 heures est expiré pour ce PV']);

        // Publié il y a 10 heures, sans modification depuis 5 jours : encore ouvert
        DB::table('pv_publications')->where('pv_id', $this->pvPublie)->update(['publie_le' => now()->subHours(10)]);
        DB::table('pv_sessions')->where('id', $this->pvPublie)->update(['updated_at' => now()->subDays(5)]);
        $this->assertSame(1, (int) $this->api('reclamations_data', [], $jeton, 'get')->json('data.pv_list.0.reclamation_ouverte'));
        $this->api('soumettre_reclamation', $motif, $jeton)->assertJson(['success' => true]);
    }

    public function test_publication_enregistre_la_date_de_depart(): void
    {
        DB::table('pv_sessions')->where('id', $this->pvPublie)->update(['statut' => 'finalise']);
        $this->connecte(Utilisateur::where('role', 'admin')->first())->post($this->url('pv/statut'), ['pv_id' => $this->pvPublie, 'statut' => 'publie']);
        $this->assertNotNull(DB::table('pv_publications')->where('pv_id', $this->pvPublie)->value('publie_le'));
    }

    public function test_reclamations_et_reinitialisation(): void
    {
        $jeton = $this->jeton();
        $data = $this->api('reclamations_data', [], $jeton, 'get')->assertOk()->json('data');
        $this->assertSame([$this->pvPublie], array_column($data['pv_list'], 'id'));
        $this->assertCount(1, $this->api('matieres_pv', ['pv_id' => $this->pvPublie], $jeton, 'get')->json('data'));
        $this->api('soumettre_reclamation', ['pv_id' => $this->pvPublie, 'matiere_id' => $this->matiere, 'motif' => 'court'], $jeton)->assertJson(['success' => false]);
        $this->api('soumettre_reclamation', ['pv_id' => $this->pvPublie, 'matiere_id' => $this->matiere, 'motif' => str_repeat('Note mal reportée. ', 2)], $jeton)->assertJson(['success' => true]);
        $this->api('soumettre_reclamation', ['pv_id' => $this->pvPublie, 'matiere_id' => $this->matiere, 'motif' => str_repeat('Note mal reportée. ', 2)], $jeton)->assertJson(['success' => false]);
        $this->assertSame('12.50', DB::table('reclamations')->value('note_originale'));
        $this->assertCount(0, $this->api('matieres_pv', ['pv_id' => $this->pvPublie], $jeton, 'get')->json('data'));

        // Ancienne application (matricule + date de naissance) : mise à jour demandée
        $this->api('reset_password', ['identifiant' => '00001', 'date_naissance' => '2005-04-03', 'nouveau_mdp' => 'Pirate123'])->assertStatus(426);

        // Code envoyé par e-mail, puis nouveau mot de passe ; anciens jetons supprimés
        $courriel = new class(app(Parametres::class)) extends Courriel
        {
            public array $envois = [];

            public function envoyer(string $destinataire, string $sujet, string $corpsHtml, string $contexte = '', ?int $refId = null): bool
            {
                $this->envois[] = $corpsHtml;

                return true;
            }
        };
        $this->app->instance(Courriel::class, $courriel);
        $this->api('reset_password_demande', ['identifiant' => '99999'])->assertJson(['success' => true]);
        $this->api('reset_password_demande', ['identifiant' => '00001'])->assertJson(['success' => true]);
        $this->assertCount(1, $courriel->envois);
        preg_match('/(\d{6})/', $courriel->envois[0], $m);
        $this->api('reset_password', ['identifiant' => '00001', 'code' => '000000', 'nouveau_mdp' => 'Nouveau123'])->assertJson(['success' => false]);
        $this->api('reset_password', ['identifiant' => '00001', 'code' => $m[1], 'nouveau_mdp' => 'Nouveau123'])->assertJson(['success' => true]);
        $this->assertTrue(Hash::check('Nouveau123', DB::table('etudiants')->where('id', $this->etudiant)->value('password_hash')));
        $this->assertSame(0, DB::table('mobile_tokens')->count());
        $this->api('reset_password', ['identifiant' => '00001', 'code' => $m[1], 'nouveau_mdp' => 'Encore123'])->assertJson(['success' => false]);
        $this->api('profil', [], $jeton, 'get')->assertStatus(401);
    }
}
