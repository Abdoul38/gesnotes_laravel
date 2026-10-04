<?php

namespace Tests\Feature\Administration;

use App\Models\Utilisateur;
use App\Services\Licence;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

/** Administration : utilisateurs, rôles, signataires, paramètres, licence, sauvegarde, journaux. */
class AdministrationTest extends TestCase
{
    private const PNG = 'iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mNk+M9QDwADhgGAWjR9awAAAABJRU5ErkJggg==';

    private Utilisateur $admin;

    private int $facA;

    private int $facB;

    private string $temp;

    protected function setUp(): void
    {
        parent::setUp();
        $this->installerLicence();
        $this->admin = $this->creerUtilisateur('admin');
        $this->facA = $this->insererMinimal('facultes', ['code' => 'FA', 'libelle' => 'Faculté A', 'actif' => 1]);
        $this->facB = $this->insererMinimal('facultes', ['code' => 'FB', 'libelle' => 'Faculté B', 'actif' => 1]);
        $this->temp = sys_get_temp_dir().DIRECTORY_SEPARATOR.'admin_test_'.uniqid();
        config(['gesnotes.dossier_uploads' => $this->temp.DIRECTORY_SEPARATOR.'uploads', 'gesnotes.dossier_sauvegardes' => $this->temp.DIRECTORY_SEPARATOR.'sauvegardes']);
    }

    protected function tearDown(): void
    {
        if (is_dir($this->temp)) {
            $it = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($this->temp, \FilesystemIterator::SKIP_DOTS), \RecursiveIteratorIterator::CHILD_FIRST);
            foreach ($it as $f) {
                $f->isDir() ? rmdir($f->getPathname()) : unlink($f->getPathname());
            }
            rmdir($this->temp);
        }
        parent::tearDown();
    }

    public function test_utilisateurs_controles_serveur(): void
    {
        $base = ['email' => 'agent@test.local', 'nom' => 'AGENT', 'motdepasse' => 'Solide2026', 'role' => 'sc_entite', 'faculte_id' => $this->facA];
        $this->connecte($this->admin)->get($this->url('utilisateurs'))->assertOk()->assertSee('admin@test.local');

        $this->post($this->url('utilisateurs'), ['role' => 'superadmin'] + $base)->assertSessionHas('erreur', 'Rôle invalide.');
        $this->post($this->url('utilisateurs'), ['faculte_id' => ''] + $base)->assertSessionHas('erreur');
        $this->post($this->url('utilisateurs'), ['motdepasse' => 'court'] + $base)->assertSessionHas('erreur');
        $this->post($this->url('utilisateurs'), ['email' => 'pas-un-email'] + $base)->assertSessionHas('erreur', 'Email invalide.');
        $dep = $this->insererMinimal('departements', ['code' => 'DB1', 'libelle' => 'Dép B', 'faculte_id' => $this->facB]);
        $this->post($this->url('utilisateurs'), ['role' => 'chef_departement', 'departement_id' => $dep] + $base)->assertSessionHas('erreur', "Ce département n'appartient pas à la faculté choisie.");
        $this->assertSame(1, DB::table('utilisateurs')->count());

        $this->post($this->url('utilisateurs'), $base)->assertSessionHas('succes');
        $agent = DB::table('utilisateurs')->where('email', 'agent@test.local')->first();
        $this->assertSame([$this->facA, ''], [(int) $agent->faculte_id, $agent->prenoms]);
        $this->assertTrue(Hash::check('Solide2026', $agent->mot_de_passe));
        $this->post($this->url('utilisateurs'), $base)->assertSessionHas('erreur', 'Cet email est déjà utilisé par un autre compte.');

        // Modification : mot de passe vide = inchangé
        $this->post($this->url('utilisateurs/modifier'), ['id' => $agent->id, 'motdepasse' => ''] + $base)->assertSessionHas('succes');
        $this->assertTrue(Hash::check('Solide2026', DB::table('utilisateurs')->where('id', $agent->id)->value('mot_de_passe')));

        // Propre compte et dernier administrateur protégés
        $this->post($this->url('utilisateurs/modifier'), ['id' => $this->admin->id, 'email' => 'admin@test.local', 'role' => 'lecture'])->assertSessionHas('erreur', 'Vous ne pouvez pas changer votre propre rôle.');
        $this->post($this->url('utilisateurs/supprimer'), ['id' => $this->admin->id])->assertSessionHas('erreur', 'Impossible de supprimer votre propre compte.');
        $admin2 = $this->creerUtilisateur('admin', ['email' => 'admin2@test.local', 'actif' => 0]);
        $this->post($this->url('utilisateurs/supprimer'), ['id' => $admin2->id])->assertSessionHas('succes');
        $this->post($this->url('utilisateurs/supprimer'), ['id' => $agent->id])->assertSessionHas('succes');
        $this->assertSame(1, DB::table('utilisateurs')->count());
        $this->assertSame(4, DB::table('audit_log')->where('module', 'utilisateurs')->count());

        // Réservé à l'administrateur
        $this->donnerPermissions('secretaire', ['utilisateurs_gerer']);
        $this->connecte($this->creerUtilisateur('secretaire'))->post($this->url('utilisateurs'), ['role' => 'admin', 'email' => 'pirate@test.local'] + $base)->assertRedirect();
        $this->assertFalse(DB::table('utilisateurs')->where('email', 'pirate@test.local')->exists());
    }

    public function test_roles_valides_contre_les_listes(): void
    {
        DB::table('permissions')->insert([['code' => 'pv_voir', 'libelle' => 'Voir PV', 'categorie' => 'PV'], ['code' => 'stats_voir', 'libelle' => 'Stats', 'categorie' => 'Rapports']]);
        $this->connecte($this->admin)->get($this->url('roles?role=doyen'))->assertOk()->assertSee('Voir PV');
        $this->post($this->url('roles'), ['role' => 'admin', 'permissions' => ['pv_voir']])->assertSessionHas('erreur');
        $this->post($this->url('roles'), ['role' => 'inventé', 'permissions' => ['pv_voir']])->assertSessionHas('erreur');
        $this->assertSame(0, DB::table('role_permissions')->count());

        $this->post($this->url('roles'), ['role' => 'doyen', 'permissions' => ['pv_voir', 'code_bidon', 'stats_voir']])->assertSessionHas('succes');
        $this->assertEqualsCanonicalizing(['pv_voir', 'stats_voir'], DB::table('role_permissions')->where('role', 'doyen')->pluck('permission_code')->all());
        $this->post($this->url('roles'), ['role' => 'doyen'])->assertSessionHas('succes');
        $this->assertSame(0, DB::table('role_permissions')->count());
    }

    public function test_signataires_limites_a_la_faculte(): void
    {
        $sigB = DB::table('signataires')->insertGetId(['titre' => 'Le Doyen', 'nom' => 'DOYEN B', 'actif' => 0, 'faculte_id' => $this->facB]);
        $this->donnerPermissions('sc_entite', ['inscription_ped']);
        $agentA = $this->creerUtilisateur('sc_entite', ['faculte_id' => $this->facA]);

        $this->connecte($agentA)->get($this->url('signataires'))->assertOk()->assertDontSee('DOYEN B');
        foreach (['signataires/activer', 'signataires/supprimer'] as $chemin) {
            $this->post($this->url($chemin), ['id' => $sigB])->assertSessionHas('erreur', 'Signataire introuvable.');
        }
        $this->post($this->url('signataires/modifier'), ['id' => $sigB, 'nom' => 'X', 'faculte_id' => $this->facA])->assertSessionHas('erreur');
        $this->post($this->url('signataires'), ['nom' => 'Intrus', 'faculte_id' => $this->facB])->assertSessionHas('erreur');
        $this->post($this->url('signataires'), ['nom' => 'Global', 'faculte_id' => ''])->assertSessionHas('erreur');

        $this->post($this->url('signataires'), ['nom' => 'DOYEN A1', 'titre' => 'Le Doyen', 'faculte_id' => $this->facA])->assertSessionHas('succes');
        $this->post($this->url('signataires'), ['nom' => 'DOYEN A2', 'titre' => 'Le Doyen', 'faculte_id' => $this->facA])->assertSessionHas('succes');
        [$a1, $a2] = DB::table('signataires')->where('faculte_id', $this->facA)->orderBy('id')->pluck('id')->all();
        $this->post($this->url('signataires/activer'), ['id' => $a1])->assertSessionHas('succes');
        $this->post($this->url('signataires/activer'), ['id' => $a2])->assertSessionHas('succes');
        $this->assertSame([0, 1], DB::table('signataires')->whereIn('id', [$a1, $a2])->orderBy('id')->pluck('actif')->map(fn ($v) => (int) $v)->all());
        $this->post($this->url('signataires/supprimer'), ['id' => $a2])->assertSessionHas('erreur');
        $this->assertSame('DOYEN B', DB::table('signataires')->where('id', $sigB)->value('nom'));
    }

    public function test_parametres_generaux_et_regles(): void
    {
        $this->connecte($this->admin)->get($this->url('parametres'))->assertOk()->assertSee("Nom de l'institution");
        $this->assertSame('5', DB::table('parametres')->where('cle', 'note_eliminatoire')->value('valeur'));

        // Seules les clés de l'institution sont acceptées
        $this->post($this->url('parametres/general'), ['params' => ['institution_nom' => 'Université Test', 'licence_cle' => 'pirate', 'smtp_host' => 'evil']])->assertSessionHas('succes');
        $this->assertSame('Université Test', DB::table('parametres')->where('cle', 'institution_nom')->value('valeur'));
        $this->assertNotSame('pirate', DB::table('parametres')->where('cle', 'licence_cle')->value('valeur'));
        $this->assertFalse(DB::table('parametres')->where('cle', 'smtp_host')->exists());

        $regles = ['seuil_validation' => 10, 'note_eliminatoire' => '', 'note_eliminatoire_ue' => 4, 'compensation_ue' => 'non', 'compensation_semestre' => 'oui', 'mention_tres_bien' => 16, 'mention_bien' => 14, 'mention_assez_bien' => 12];
        $this->post($this->url('parametres/regles-pv'), ['note_eliminatoire_ue' => 12] + $regles)->assertSessionHas('erreur');
        $this->post($this->url('parametres/regles-pv'), ['mention_bien' => 17] + $regles)->assertSessionHas('erreur');
        $this->post($this->url('parametres/regles-pv'), $regles)->assertSessionHas('succes');
        $this->assertSame(['', '4', 'non'], [
            DB::table('parametres')->where('cle', 'note_eliminatoire')->value('valeur'),
            DB::table('parametres')->where('cle', 'note_eliminatoire_ue')->value('valeur'),
            DB::table('parametres')->where('cle', 'compensation_ue')->value('valeur'),
        ]);
        $this->assertTrue(DB::table('journal_audit')->where('action', 'MODIF_REGLES_PV')->exists());

        // Verrouillage d'une année : les règles actuelles sont figées
        $annee = DB::table('annees_academiques')->insertGetId(['libelle' => '2025-2026', 'active' => 0]);
        $this->post($this->url('parametres/annees/verrouiller'), ['annee_id' => 999999])->assertSessionHas('erreur');
        $this->post($this->url('parametres/annees/verrouiller'), ['annee_id' => $annee])->assertSessionHas('succes');
        $this->assertSame('non', DB::table('parametres_annee')->where('annee_id', $annee)->where('cle', 'compensation_ue')->value('valeur'));
        $this->get($this->url('parametres?tab=regles_pv'))->assertOk()->assertSee('🔒 Verrouillée');
        $this->post($this->url('parametres/annees/deverrouiller'), ['annee_id' => $annee])->assertSessionHas('succes');
        $this->assertSame(0, DB::table('parametres_annee')->count());

        // Couleur, modèle de carte, visas, filières
        $this->post($this->url('parametres/couleur'), ['couleur_marque' => 'red;}'])->assertSessionHas('erreur');
        $this->post($this->url('parametres/couleur'), ['couleur_marque' => '#123ABC'])->assertSessionHas('succes');
        $this->post($this->url('parametres/carte'), ['carte_etudiant_modele' => '../x'])->assertSessionHas('erreur');
        $this->post($this->url('parametres/visa'), ['niveau' => 'doctorat', 'visa_texte' => 'Vu'])->assertSessionHas('erreur');
        $this->post($this->url('parametres/visa'), ['niveau' => 'licence', 'visa_texte' => "Vu le décret\nVu l'arrêté"])->assertSessionHas('succes');
        $fil = DB::table('filieres')->insertGetId(['code' => 'F1', 'libelle' => 'Droit', 'departement' => '', 'faculte' => 'A', 'faculte_id' => $this->facA, 'actif' => 1]);
        $this->post($this->url('parametres/filieres'), ['domaine' => [$fil => 'Droit et Gestion'], 'mention_academique' => [$fil => 'Droit'], 'type_formation' => [$fil => 'pirate']])->assertSessionHas('succes');
        $this->assertSame(['Droit et Gestion', 'classique'], [DB::table('filieres')->where('id', $fil)->value('domaine'), DB::table('filieres')->where('id', $fil)->value('type_formation')]);
        $this->get($this->url('parametres?tab=visas_diplomes'))->assertOk()->assertSee('Vu l&#039;arrêté', false);

        // Agent d'une faculté : la page n'est plus ouverte
        $this->donnerPermissions('sc_entite', ['inscription_ped']);
        $this->connecte($this->creerUtilisateur('sc_entite', ['faculte_id' => $this->facA]))->get($this->url('parametres'))->assertRedirect($this->url('accueil'));
    }

    public function test_parametres_images_et_signataire(): void
    {
        $png = fn ($nom) => UploadedFile::fake()->createWithContent($nom, base64_decode(self::PNG));
        $this->connecte($this->admin);
        $this->post($this->url('parametres/logo'), ['logo_universite' => UploadedFile::fake()->createWithContent('logo.png', '<?php echo 1;')])->assertSessionHas('erreur');
        $this->post($this->url('parametres/logo'), ['logo_universite' => $png('logo.png')])->assertSessionHas('succes');
        $this->assertMatchesRegularExpression('/^logo_univ_\d+\.png$/', DB::table('parametres')->where('cle', 'logo_universite')->value('valeur'));

        $this->post($this->url('parametres/signature'), ['signature_autorite' => $png('sig.png')])->assertSessionHas('succes');
        $sig = DB::table('parametres')->where('cle', 'signature_autorite')->value('valeur');
        $this->assertFileExists($this->temp."/uploads/logos/$sig");
        $this->post($this->url('parametres/signature/supprimer'))->assertSessionHas('succes');
        $this->assertFileDoesNotExist($this->temp."/uploads/logos/$sig");

        $this->post($this->url('parametres/signataire'), ['faculte_id' => $this->facA, 'sig_titre' => 'Le Doyen', 'sig_nom' => 'NOUVEAU'])->assertSessionHas('succes');
        $this->post($this->url('parametres/signataire'), ['faculte_id' => $this->facA, 'sig_nom' => ''])->assertSessionHas('erreur');
        $this->assertSame(1, DB::table('signataires')->where('faculte_id', $this->facA)->where('actif', 1)->count());
        $this->get($this->url('parametres?tab=signataires'))->assertOk()->assertSee('NOUVEAU');
        $this->get($this->url('parametres'))->assertOk()->assertSee('Logo actuel');
    }

    public function test_licence_reservee_et_verifiee(): void
    {
        $this->connecte($this->admin)->get($this->url('licence'))->assertOk()->assertSee('Licence active');
        $this->post($this->url('licence'), ['cle_licence' => 'bidon.123'])->assertSessionHas('erreur');
        $autre = app(Licence::class)->generer('UAM');
        $this->post($this->url('licence'), ['cle_licence' => $autre])->assertSessionHas('erreur');
        $this->assertNotSame($autre, DB::table('parametres')->where('cle', 'licence_cle')->value('valeur'));
        $nouvelle = app(Licence::class)->generer('UDH', '2099-12-31', 5000);
        $this->post($this->url('licence'), ['cle_licence' => $nouvelle])->assertSessionHas('succes');
        $this->get($this->url('licence'))->assertSee('2099-12-31');

        $this->donnerPermissions('secretaire', ['inscription_ped']);
        $this->connecte($this->creerUtilisateur('secretaire'))->post($this->url('licence'), ['cle_licence' => $autre])->assertRedirect($this->url('accueil'));
        $this->assertSame($nouvelle, DB::table('parametres')->where('cle', 'licence_cle')->value('valeur'));
    }

    public function test_sauvegarde_par_universite(): void
    {
        DB::table('etudiants')->insert(['numero' => '00001', 'nom' => "O'NEIL", 'prenoms' => 'Awa']);
        $reponse = $this->connecte($this->admin)->post($this->url('sauvegarde'));
        $reponse->assertOk()->assertDownload();
        $s = DB::table('sauvegardes')->first();
        $this->assertSame('ok', $s->statut);
        $this->assertStringStartsWith('gesnotes_udh_', $s->nom_fichier);
        $contenu = file_get_contents($this->temp."/sauvegardes/udh/{$s->nom_fichier}");
        $this->assertStringContainsString('CREATE TABLE `etudiants`', $contenu);
        $this->assertStringContainsString("'O\\'NEIL'", $contenu);

        $this->get($this->url('sauvegarde'))->assertOk()->assertSee($s->nom_fichier);
        $this->post($this->url('sauvegarde/telecharger'), ['id' => $s->id])->assertDownload($s->nom_fichier);
        // Un nom de fichier forgé en base ne sort pas du dossier
        DB::table('sauvegardes')->where('id', $s->id)->update(['nom_fichier' => '../../.env']);
        $this->post($this->url('sauvegarde/telecharger'), ['id' => $s->id])->assertSessionHas('erreur', 'Fichier introuvable.');
        DB::table('sauvegardes')->where('id', $s->id)->update(['nom_fichier' => $s->nom_fichier]);
        $this->post($this->url('sauvegarde/supprimer'), ['id' => $s->id])->assertSessionHas('succes');
        $this->assertFileDoesNotExist($this->temp."/sauvegardes/udh/{$s->nom_fichier}");

        // Réservé à l'administrateur
        $this->donnerPermissions('secretaire', ['annees_gerer']);
        $this->connecte($this->creerUtilisateur('secretaire'))->post($this->url('sauvegarde'))->assertRedirect($this->url('accueil'));
    }

    public function test_journaux_filtres(): void
    {
        DB::table('audit_log')->insert([
            ['user_id' => $this->admin->id, 'user_nom' => 'TEST Admin', 'user_role' => 'admin', 'action' => 'connexion', 'module' => 'auth', 'description' => 'Connexion réussie', 'ip_address' => '10.0.0.1', 'created_at' => now()],
            ['user_id' => null, 'user_nom' => null, 'user_role' => null, 'action' => 'supprimer', 'module' => 'notes', 'description' => '<script>x</script>', 'ip_address' => null, 'created_at' => now()->subDays(30)],
        ]);
        $this->donnerPermissions('secretaire', ['annees_gerer']);
        $this->connecte($this->creerUtilisateur('secretaire'))->get($this->url('logs'))->assertOk()
            ->assertSee('Connexion réussie')->assertSee('&lt;script&gt;x&lt;/script&gt;', false)->assertDontSee('<script>x</script>', false);
        $this->get($this->url('logs?action=connexion'))->assertOk()->assertDontSee('&lt;script&gt;', false);
        $this->get($this->url('logs?user=Admin&date=pas-une-date'))->assertOk()->assertSee('Connexion réussie')->assertSee('1 entrée(s)');
        $this->connecte($this->creerUtilisateur('lecture'))->get($this->url('logs'))->assertRedirect();
    }
}
