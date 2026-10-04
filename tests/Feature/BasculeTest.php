<?php

namespace Tests\Feature;

use App\Models\Espaces\CompteMesr;
use App\Services\Communication\Courriel;
use App\Services\Licence;
use App\Services\Parametres;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

/** Étape 13 : dernières pages portées et redirection des anciennes adresses. */
class BasculeTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        $this->installerLicence();
    }

    private function faussePoste(): object
    {
        $courriel = new class(app(Parametres::class)) extends Courriel
        {
            public array $envois = [];

            public function envoyer(string $destinataire, string $sujet, string $corpsHtml, string $contexte = '', ?int $refId = null): bool
            {
                $this->envois[] = [$destinataire, $corpsHtml];

                return true;
            }
        };
        $this->app->instance(Courriel::class, $courriel);

        return $courriel;
    }

    public function test_mot_de_passe_oublie_et_reinitialisation(): void
    {
        $poste = $this->faussePoste();
        $u = $this->creerUtilisateur('secretaire', ['email' => 'sec@test.local', 'prenoms' => '<b>Awa</b>']);
        $message = 'Si cette adresse existe dans notre système, un lien de réinitialisation vient de lui être envoyé. Vérifiez aussi vos courriers indésirables.';

        $this->get($this->url('mot-de-passe-oublie'))->assertOk();
        $this->post($this->url('mot-de-passe-oublie'), ['email' => 'inconnu@test.local'])->assertSessionHas('succes', $message);
        $this->post($this->url('mot-de-passe-oublie'), ['email' => 'sec@test.local'])->assertSessionHas('succes', $message);
        $this->assertCount(1, $poste->envois);
        $this->assertStringNotContainsString('<b>Awa</b>', $poste->envois[0][1]);
        preg_match('/token=([0-9a-f]{64})/', $poste->envois[0][1], $m);
        $jeton = $m[1];
        $this->assertSame(hash('sha256', $jeton), DB::table('password_resets')->value('token_hash'));

        // Une seconde demande annule le premier lien
        $this->post($this->url('mot-de-passe-oublie'), ['email' => 'sec@test.local']);
        $this->get($this->url("reinitialiser-mot-de-passe?token=$jeton"))->assertOk()->assertSee('invalide');
        preg_match('/token=([0-9a-f]{64})/', $poste->envois[1][1], $m);
        $jeton = $m[1];

        $this->get($this->url("reinitialiser-mot-de-passe?token=$jeton"))->assertOk()->assertSee('Nouveau mot de passe');
        $this->post($this->url('reinitialiser-mot-de-passe'), ['token' => $jeton, 'mot_de_passe' => 'court', 'mot_de_passe2' => 'court'])->assertSessionHas('erreur');
        $this->post($this->url('reinitialiser-mot-de-passe'), ['token' => $jeton, 'mot_de_passe' => 'Nouveau2026', 'mot_de_passe2' => 'Autre2026'])->assertSessionHas('erreur', 'Les deux mots de passe ne correspondent pas.');
        $this->post($this->url('reinitialiser-mot-de-passe'), ['token' => $jeton, 'mot_de_passe' => 'Nouveau2026', 'mot_de_passe2' => 'Nouveau2026'])
            ->assertRedirect($this->url('connexion'));
        $this->assertTrue(Hash::check('Nouveau2026', DB::table('utilisateurs')->where('id', $u->id)->value('mot_de_passe')));
        // Lien à usage unique
        $this->post($this->url('reinitialiser-mot-de-passe'), ['token' => $jeton, 'mot_de_passe' => 'Pirate2026', 'mot_de_passe2' => 'Pirate2026'])->assertSee('invalide');

        // 5 demandes par adresse IP, puis blocage (tenu côté serveur, pas en session)
        for ($i = 0; $i < 3; $i++) {
            $this->post($this->url('mot-de-passe-oublie'), ['email' => 'x@test.local']);
        }
        $this->get($this->url('mot-de-passe-oublie'))->assertSee('Trop de demandes');
    }

    public function test_import_ue_matieres(): void
    {
        $annee = DB::table('annees_academiques')->insertGetId(['libelle' => '2026-2027', 'active' => 1]);
        $facA = $this->insererMinimal('facultes', ['code' => 'FA', 'libelle' => 'Faculté A']);
        $facB = $this->insererMinimal('facultes', ['code' => 'FB', 'libelle' => 'Faculté B']);
        $filA = DB::table('filieres')->insertGetId(['code' => 'FA1', 'libelle' => 'Filière A', 'departement' => '', 'faculte_id' => $facA, 'actif' => 1]);
        $filB = DB::table('filieres')->insertGetId(['code' => 'FB1', 'libelle' => 'Filière B', 'departement' => '', 'faculte_id' => $facB, 'actif' => 1]);
        $promoA = $this->creerPromotion($annee, ['filiere_id' => $filA]);
        $promoB = $this->creerPromotion($annee, ['filiere_id' => $filB]);
        DB::table('semestres')->insert(['code' => 'S1', 'libelle' => 'Semestre 1']);
        $fichier = fn () => $this->fichierXlsx([
            1 => ['SEMESTRE 1'],
            2 => ['', '', '', '', '', '', 'CM', 'TD', 'TP'],
            3 => ['', 'UE11', 'Mathématiques', 'M111', 'Algèbre', '', 20, 10, 0, '', '', 2, 4, 6],
            4 => ['', '', '', 'M112', 'Analyse', '', 10, 5, 0, '', '', 1, 2, ''],
            5 => ['TOTAL'],
        ]);
        $this->donnerPermissions('sc_entite', ['inscription_ped']);
        $this->connecte($this->creerUtilisateur('sc_entite', ['faculte_id' => $facA]));

        $this->get($this->url('filieres/import-ue'))->assertOk();
        $this->post($this->url('filieres/import-ue'), ['fichier' => $this->fichierTexte('a;b', 'x.csv')])->assertSessionHas('erreur');
        $apercu = $this->post($this->url('filieres/import-ue'), ['fichier' => $fichier()])->assertOk()->assertSee('Algèbre')->assertSee('examen /40');
        $apercu->assertViewHas('promotions', fn ($p) => $p->pluck('id')->all() === [$promoA]);

        // Promotion d'une autre faculté : ignorée
        $this->post($this->url('filieres/import-ue/confirmer'), ['promotion_semestre' => ['S1' => $promoB]])->assertRedirect($this->url('filieres'));
        $this->assertSame(0, DB::table('unites_enseignement')->count());
        // Le fichier temporaire est consommé : il faut recommencer
        $this->post($this->url('filieres/import-ue/confirmer'), ['promotion_semestre' => ['S1' => $promoA]])->assertSessionHas('erreur');

        $this->post($this->url('filieres/import-ue'), ['fichier' => $fichier()]);
        $this->post($this->url('filieres/import-ue/confirmer'), ['promotion_semestre' => ['S1' => $promoA]])->assertSessionHas('succes');
        $this->assertSame(6.0, (float) DB::table('unites_enseignement')->where('promotion_id', $promoA)->value('credits_total'));
        $this->assertSame([['M111', 40, 30.0], ['M112', 20, 15.0]], DB::table('matieres')->orderBy('code')->get()
            ->map(fn ($m) => [$m->code, (int) $m->note_max_examen, (float) $m->volume_horaire_prevu])->all());
        $this->assertCount(0, glob(storage_path('app/private/imports/*.xlsx')) ?: []);
    }

    public function test_commande_de_verification(): void
    {
        $this->creerUtilisateur('admin');
        config(['licence.secret' => 'secret-de-test', 'gesnotes.dossier_sauvegardes' => sys_get_temp_dir()]);
        // UDH : licence installée, administrateur ; UAM : ni l'un ni l'autre
        $this->artisan('gesnotes:verifier-bascule')
            ->expectsOutputToContain('Université UDH (udh)')
            ->expectsOutputToContain('Université UAM (uam)')
            ->doesntExpectOutputToContain('secret-de-test')
            ->assertFailed();
    }

    public function test_completer_schema_et_licence(): void
    {
        $this->universite('uam');
        DB::statement('DROP TABLE IF EXISTS mobile_reset_codes');
        $this->universite('udh');

        // Sans --appliquer : rien n'est créé
        $this->artisan('gesnotes:completer-schema', ['--universite' => ['uam']])->expectsOutputToContain('mobile_reset_codes')->assertSuccessful();
        $this->universite('uam');
        $this->assertFalse(Schema::hasTable('mobile_reset_codes'));
        $this->universite('udh');
        $this->artisan('gesnotes:completer-schema', ['--universite' => ['uam'], '--appliquer' => true])->expectsOutputToContain('créée : mobile_reset_codes')->assertSuccessful();
        $this->universite('uam');
        $this->assertTrue(Schema::hasTable('mobile_reset_codes'));
        $this->universite('udh');

        // Licence propre à UAM, installée dans sa base
        $this->artisan('gesnotes:licence', ['universite' => 'uam', '--installer' => true])
            ->expectsConfirmation('Enregistrer cette licence dans la base de UAM ?', 'yes')
            ->expectsOutputToContain('Licence installée et valide pour UAM')->assertSuccessful();
        $this->artisan('gesnotes:licence', ['universite' => 'inconnue'])->assertFailed();
        $this->artisan('gesnotes:licence', ['universite' => 'uam', '--expiration' => '31/12/2027'])->assertFailed();
        // UDH garde sa propre licence
        $this->assertSame('UDH', app(Licence::class)->verifierInstallation()['institution']);
    }

    public function test_configuration_complete(): void
    {
        // Une modification automatique ratée avait mis ces réglages en commentaire
        foreach (['dossier_sauvegardes', 'ancienne_app_chemin', 'dossier_images_portail', 'espaces_comptes'] as $cle) {
            $this->assertArrayHasKey($cle, config('gesnotes'), "Réglage gesnotes.$cle absent");
        }
        foreach (glob(base_path('{app,config,routes,resources/views}/**/*.php'), GLOB_BRACE) ?: [] as $fichier) {
            $this->assertStringNotContainsString('/x27', (string) file_get_contents($fichier), $fichier);
        }
        $this->assertStringNotContainsString('/x27', (string) file_get_contents(config_path('gesnotes.php')));
    }

    public function test_page_de_presentation_complete(): void
    {
        $this->get('http://localhost/')->assertOk()->assertSee('Des problèmes réels')->assertSee('//udh.localhost/connexion', false)
            ->assertSee('//anab.localhost/anab/connexion', false);
    }

    public function test_redirection_des_anciennes_adresses(): void
    {
        $udh = fn (string $c) => $this->url($c);
        // QR codes déjà imprimés
        $this->get($udh('index.php?page=verifier_diplome&num=R26-000001'))->assertStatus(301)->assertRedirect($udh('verification?num=R26-000001'));
        $this->get($udh('?page=verifier_diplome&num=R26-000001'))->assertRedirect($udh('verification?num=R26-000001'));
        $this->get($udh('index.php?page=pv_detail&id=5'))->assertRedirect($udh('pv/5'));
        $this->get($udh('index.php?page=pv_detail&id=abc'))->assertRedirect($udh('pv'));
        $this->get($udh('index.php?page=login'))->assertRedirect($udh('connexion'));
        $this->get($udh('index.php?page=liste_inscrits_pdf&promo=3'))->assertRedirect($udh('inscriptions/listes/pdf?promo=3'));
        $this->get($udh('index.php?page=../../etc/passwd'))->assertRedirect($udh('accueil'));
        $this->get($udh('index.php?page=inexistante'))->assertRedirect($udh('accueil'));
        $this->get($udh(''))->assertRedirect($udh('accueil'));
        // Portail candidats et application mobile
        $this->get($udh('candidature/index.php?page=register'))->assertRedirect($udh('candidature/compte'));
        $this->get($udh('candidature?page=login'))->assertRedirect($udh('candidature/connexion'));
        $this->get($udh('portail2/index.php?page=recu&ins_id=7'))->assertRedirect($udh('candidature/inscription/7/recu'));
        $this->get($udh('portail2/index.php?page=inscription&etape=1'))->assertRedirect($udh('candidature/inscription'));
        // Fichiers
        $this->get($udh('uploads/logos/logo_udh.png'))->assertRedirect($udh('fichiers/logos/logo_udh.png'));
        // Espaces nationaux
        $c = CompteMesr::create(['login' => 'mesr', 'nom_complet' => 'MESR', 'role' => 'admin', 'mot_de_passe' => 'Ministere2026', 'actif' => 1]);
        $this->get($this->url('index.php?page=gerer_comptes', 'ministere'))->assertRedirect($this->url('ministere/comptes', 'ministere'));
        $this->get($this->url('index.php?page=changer_mdp_anab', 'anab'))->assertRedirect($this->url('anab/mot-de-passe', 'anab'));
    }
}
