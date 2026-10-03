<?php

namespace Tests\Feature\Candidature;

use App\Models\Candidat;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\RateLimiter;
use Tests\TestCase;

/** Portail candidats : comptes, dépôt du dossier en 4 étapes, pièces, quitus. */
class EspaceCandidatTest extends TestCase
{
    private string $pieces;

    private int $filA;

    private int $filB;

    protected function setUp(): void
    {
        parent::setUp();
        $this->installerLicence();
        RateLimiter::clear('connexion_candidat|udh|127.0.0.1');
        $this->pieces = sys_get_temp_dir().DIRECTORY_SEPARATOR.'pieces_test_'.uniqid();
        config(['gesnotes.dossier_pieces_candidats' => $this->pieces]);
        DB::table('annees_academiques')->insert(['libelle' => '2026-2027', 'active' => 1]);
        $this->filA = DB::table('filieres')->insertGetId(['code' => 'ECO', 'libelle' => 'Économie', 'departement' => '', 'faculte' => 'FSEJ', 'actif' => 1]);
        $this->filB = DB::table('filieres')->insertGetId(['code' => 'DRT', 'libelle' => 'Droit', 'departement' => '', 'faculte' => 'FSEJ', 'actif' => 1]);
    }

    protected function tearDown(): void
    {
        File::deleteDirectory($this->pieces);
        parent::tearDown();
    }

    private function compte(string $email = 'awa@exemple.ne'): Candidat
    {
        return Candidat::create(['nom' => 'ALPHA', 'prenom' => 'Awa', 'email' => $email, 'telephone' => '90000000', 'mot_de_passe' => 'Secret123', 'actif' => 1]);
    }

    private function seConnecter(Candidat $c)
    {
        return $this->post($this->url('candidature/connexion'), ['email' => $c->email, 'mdp' => 'Secret123']);
    }

    private function identite(): array
    {
        return ['nom' => 'alpha', 'prenom' => 'awa', 'date_naissance' => '2006-05-01', 'lieu_naissance' => 'Tahoua', 'nationalite' => 'Nigérienne',
            'sexe' => 'F', 'telephone' => '+227 90 00 00 00', 'email' => 'awa@exemple.ne', 'adresse' => 'Quartier Nord', 'bac_type' => 'BAC D',
            'bac_lieu' => 'Tahoua', 'bac_annee' => '2025-2026', 'bac_mention' => 'Bien'];
    }

    public function test_creation_de_compte_et_connexion(): void
    {
        $this->get($this->url('candidature'))->assertOk()->assertSee('Portail de Préinscription');
        $this->get($this->url('candidature/tableau-de-bord'))->assertRedirect($this->url('candidature/connexion'));
        $champs = ['nom' => 'beta', 'prenom' => 'zara', 'email' => 'Zara@Exemple.ne', 'telephone' => '91000000'];
        $this->post($this->url('candidature/compte'), $champs + ['mdp' => 'abc123', 'mdp2' => 'abc123'])->assertSessionHas('erreur');
        $this->post($this->url('candidature/compte'), $champs + ['mdp' => 'Secret123', 'mdp2' => 'Secret123'])->assertRedirect($this->url('candidature/tableau-de-bord'));
        $c = Candidat::where('email', 'zara@exemple.ne')->first();
        $this->assertSame(['BETA', 'Zara'], [$c->nom, $c->prenom]);
        $this->assertTrue(Hash::check('Secret123', $c->mot_de_passe));
        $this->get($this->url('candidature/tableau-de-bord'))->assertOk()->assertSee('Bonjour, Zara');

        $this->post($this->url('candidature/deconnexion'))->assertRedirect($this->url('candidature'));
        $this->post($this->url('candidature/compte'), $champs + ['mdp' => 'Secret123', 'mdp2' => 'Secret123'])->assertSessionHas('erreur', 'Cet email est déjà utilisé.');

        // Les pages du personnel restent fermées au candidat
        $this->seConnecter($this->compte());
        $this->get($this->url('etudiants'))->assertRedirect($this->url('connexion'));

        // Essais limités
        $this->post($this->url('candidature/deconnexion'));
        for ($i = 0; $i < 5; $i++) {
            $this->post($this->url('candidature/connexion'), ['email' => 'awa@exemple.ne', 'mdp' => 'faux'])->assertSessionHas('erreur', 'Email ou mot de passe incorrect.');
        }
        $this->post($this->url('candidature/connexion'), ['email' => 'awa@exemple.ne', 'mdp' => 'Secret123']);
        $this->get($this->url('candidature/tableau-de-bord'))->assertRedirect();
        $this->get($this->url('candidature/connexion'))->assertSee('Trop de tentatives');
    }

    public function test_depot_complet_puis_dossier_fige(): void
    {
        $c = $this->compte();
        $this->seConnecter($c);
        $this->post($this->url('candidature/dossiers'))->assertRedirect();
        $id = (int) DB::table('candidatures')->value('id');
        $this->post($this->url('candidature/dossiers'));
        $this->assertSame(1, DB::table('candidatures')->count(), 'un seul dossier en cours');
        $etape = fn (int $n) => $this->url("candidature/dossiers/$id/etape/$n");

        $this->get($etape(3))->assertOk()->assertSee('Informations personnelles'); // étape non atteinte : ramené à l'étape 1
        $this->post($etape(1), ['sexe' => 'X'] + $this->identite())->assertSessionHas('erreur');
        $this->post($etape(1), $this->identite())->assertRedirect($etape(2));
        $this->assertSame(['ALPHA', 'Awa', 'F'], array_values((array) DB::table('candidatures')->where('id', $id)->first(['nom', 'prenom', 'sexe'])));

        $inactive = DB::table('filieres')->insertGetId(['code' => 'OLD', 'libelle' => 'Fermée', 'departement' => '', 'faculte' => 'FSEJ', 'actif' => 0]);
        $this->post($etape(2), ['choix1' => $inactive])->assertSessionHas('erreur');
        $this->post($etape(2), ['choix1' => $this->filA, 'choix2' => $this->filA])->assertSessionHas('erreur');
        $this->post($etape(2), ['choix1' => $this->filA, 'choix2' => $this->filB])->assertRedirect($etape(3));

        // Faux PDF et photo PDF refusés ; vraies pièces acceptées, rangées sous un nom aléatoire
        $pdf = fn (string $nom) => UploadedFile::fake()->createWithContent($nom, "%PDF-1.4\n%test");
        $this->post($etape(3), ['extrait' => UploadedFile::fake()->createWithContent('extrait.pdf', '<html>pas un pdf</html>')])->assertSessionHas('erreur');
        $this->post($etape(3), ['photo' => $pdf('photo.pdf')])->assertSessionHas('erreur');
        $this->post($this->url("candidature/dossiers/$id/soumettre"), ['certifie' => 1])->assertSessionHas('erreur');
        $this->post($etape(3), ['photo' => UploadedFile::fake()->createWithContent('photo.png', base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mNk+M9QDwADhgGAWjR9awAAAABJRU5ErkJggg==')), 'extrait' => $pdf('e.pdf'), 'nationalite' => $pdf('n.pdf'),
            'releve' => $pdf('r.pdf'), 'attestation' => $pdf('a.pdf')])->assertRedirect($etape(4));
        $d = DB::table('candidatures')->find($id);
        $this->assertMatchesRegularExpression('/^photo_\d+_[0-9a-f]{16}\.png$/', $d->doc_photo);
        $this->assertFileExists($this->pieces.DIRECTORY_SEPARATOR.$d->doc_extrait);

        $this->post($this->url("candidature/dossiers/$id/soumettre"))->assertSessionHas('erreur', "Vous devez certifier l'exactitude des informations.");
        $this->post($this->url("candidature/dossiers/$id/soumettre"), ['certifie' => 1])->assertRedirect($this->url("candidature/dossiers/$id/soumis"));
        $this->assertSame('soumis', DB::table('candidatures')->where('id', $id)->value('statut'));
        $this->get($this->url("candidature/dossiers/$id/soumis"))->assertOk()->assertSee('Dossier soumis avec succès');

        // Dossier soumis : plus de modification (avant : choix changés après coup et dossier re-soumis)
        $this->post($etape(2), ['choix1' => $this->filB])->assertSessionHas('erreur', 'Ce dossier a été soumis : il ne peut plus être modifié.');
        $this->assertSame($this->filA, (int) DB::table('candidatures')->where('id', $id)->value('choix1_id'));

        // Quitus : QR produit dans la page (plus de service tiers)
        $this->get($this->url("candidature/dossiers/$id/quitus"))->assertOk()->assertSee('Quittance de Préinscription')
            ->assertSee('data-qr="UDH|'.$d->numero_dossier.'|ALPHA Awa"', false)->assertDontSee('qrserver');
        $this->get($this->url("candidature/dossiers/$id/pieces/photo"))->assertOk()->assertHeader('Content-Type', 'image/png');

        // Notes internes de l'administration jamais envoyées au candidat
        DB::table('candidatures')->where('id', $id)->update(['notes_admin' => 'Note interne confidentielle']);
        $this->get($this->url("candidature/dossiers?voir=$id"))->assertOk()->assertSee('Économie')->assertDontSee('Note interne confidentielle');

        // Un autre candidat n'y a pas accès
        $this->post($this->url('candidature/deconnexion'));
        $this->seConnecter($this->compte('autre@exemple.ne'));
        $this->get($this->url("candidature/dossiers/$id/pieces/photo"))->assertNotFound();
        $this->get($this->url("candidature/dossiers/$id/quitus"))->assertRedirect();
        $this->get($etape(1))->assertRedirect($this->url('candidature/tableau-de-bord'));
    }

    public function test_espaces_publics_et_session_du_personnel(): void
    {
        // Étudiant connecté à son portail : l'inscription en ligne lui reste ouverte
        $et = DB::table('etudiants')->insertGetId(['numero' => '00001', 'nom' => 'ALPHA', 'prenoms' => 'Awa']);
        $this->connecte($this->creerUtilisateur('etudiant', ['etudiant_id' => $et]))->get($this->url('candidature/inscription'))->assertOk();
        $this->get($this->url('etudiants'))->assertRedirect($this->url('portail/etudiant'));

        // Session du personnel expirée : la page publique s'affiche, le compte est simplement déconnecté
        $this->actingAs($this->creerUtilisateur('admin'))->withSession(['universite' => 'udh', 'derniere_activite' => time() - 7200])
            ->get($this->url('candidature'))->assertOk()->assertSee('Portail de Préinscription');
        $this->assertGuest('web');
    }

    public function test_portail_ferme_profil_et_dossier_incomplet(): void
    {
        $c = $this->compte();
        $this->seConnecter($c);
        DB::table('portail_candidats_config')->insert([['cle' => 'ouvert', 'valeur' => '0'], ['cle' => 'message_ferme', 'valeur' => 'Dépôts clos.']]);
        $this->post($this->url('candidature/dossiers'))->assertSessionHas('erreur', 'Dépôts clos.');
        $this->assertSame(0, DB::table('candidatures')->count());

        $id = DB::table('candidatures')->insertGetId(['candidat_id' => $c->id, 'numero_dossier' => 'D123456', 'nom' => 'ALPHA', 'prenom' => 'Awa', 'statut' => 'incomplet', 'etape_actuelle' => 4]);
        $this->post($this->url("candidature/dossiers/$id/etape/1"), $this->identite())->assertSessionHas('erreur', 'Dépôts clos.');
        DB::table('portail_candidats_config')->where('cle', 'ouvert')->update(['valeur' => '1']);
        $this->post($this->url("candidature/dossiers/$id/etape/1"), $this->identite())->assertRedirect(); // incomplet : modifiable
        $this->get($this->url('candidature/tableau-de-bord'))->assertSee('Votre dossier est incomplet');

        $this->post($this->url('candidature/profil'), ['nom' => 'alpha', 'prenom' => 'awa', 'mdp' => 'Nouveau123', 'mdp2' => 'Nouveau123', 'mdp_actuel' => 'faux'])
            ->assertSessionHas('erreur', 'Mot de passe actuel incorrect.');
        $this->post($this->url('candidature/profil'), ['nom' => 'alpha', 'prenom' => 'awa', 'mdp' => 'Nouveau123', 'mdp2' => 'Autre1234', 'mdp_actuel' => 'Secret123'])
            ->assertSessionHas('erreur', 'La confirmation ne correspond pas au nouveau mot de passe.');
        $this->post($this->url('candidature/profil'), ['nom' => 'alpha', 'prenom' => 'awa', 'telephone' => '92000000', 'mdp' => 'Nouveau123', 'mdp2' => 'Nouveau123', 'mdp_actuel' => 'Secret123'])
            ->assertSessionHas('succes', 'Profil et mot de passe mis à jour.');
        $this->assertTrue(Hash::check('Nouveau123', $c->fresh()->mot_de_passe));
    }
}
