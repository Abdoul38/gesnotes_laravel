<?php

namespace Tests\Feature\Candidature;

use App\Models\Candidat;
use App\Models\Utilisateur;
use App\Support\Tableur;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

/** Administration des candidatures : statuts, n° de dépôt, création d'étudiant, dépôt par l'admin, configuration, export. */
class CandidaturesAdminTest extends TestCase
{
    private Utilisateur $admin;

    private int $filiere;

    protected function setUp(): void
    {
        parent::setUp();
        $this->installerLicence();
        $this->admin = $this->creerUtilisateur('admin');
        DB::table('annees_academiques')->insert(['libelle' => '2026-2027', 'active' => 1]);
        $this->filiere = DB::table('filieres')->insertGetId(['code' => 'ECO', 'libelle' => 'Économie', 'departement' => '', 'faculte' => 'FSEJ', 'actif' => 1]);
    }

    private function dossier(string $numero, array $attrs = []): int
    {
        $c = Candidat::create(['nom' => 'ALPHA', 'prenom' => 'Awa', 'email' => strtolower($numero).'@exemple.ne', 'telephone' => '9', 'mot_de_passe' => 'Secret123', 'actif' => 1]);

        return DB::table('candidatures')->insertGetId($attrs + ['candidat_id' => $c->id, 'numero_dossier' => $numero, 'nom' => 'ALPHA', 'prenom' => 'Awa',
            'sexe' => 'F', 'bac_type' => 'BAC D', 'bac_annee' => '2025-2026', 'bac_mention' => 'Bien', 'choix1_id' => $this->filiere, 'statut' => 'soumis',
            'date_naissance' => '2006-01-02', 'created_at' => now()]);
    }

    public function test_onglets_statuts_et_creation_etudiant(): void
    {
        $id = $this->dossier('D100001');
        $autre = $this->dossier('D100002');
        $this->connecte($this->admin);
        foreach (['stats', 'avancees', 'dossiers', 'import_export', 'config'] as $tab) {
            $this->get($this->url("candidatures?tab=$tab"))->assertOk();
        }
        $this->get($this->url('candidatures?tab=dossiers&q=D100001'))->assertSee('D100001')->assertDontSee('D100002');
        $this->get($this->url("candidatures/$id"))->assertOk()->assertSee('ALPHA Awa');

        $this->post($this->url('candidatures/statut'), ['dos_id' => $id, 'statut' => 'n_importe_quoi'])->assertSessionHas('erreur');
        $this->post($this->url('candidatures/statut'), ['dos_id' => $id, 'statut' => 'accepte'])->assertSessionHas('succes');
        $depot = DB::table('candidatures')->where('id', $id)->value('numero_depot');
        $this->assertMatchesRegularExpression('/^udh\d{6}$/', $depot);
        $this->post($this->url('candidatures/statut'), ['dos_id' => $id, 'statut' => 'accepte'])->assertSessionHas('succes', "Statut mis à jour. N° dépôt existant : $depot");
        $this->assertDatabaseHas('audit_log', ['module' => 'candidature', 'action' => 'modifier']);

        // Création de l'étudiant : une seule fois, matricule vide, données valides — deux dossiers n'entrent pas en conflit
        $this->post($this->url('candidatures/etudiant'), ['dos_id' => $id])->assertSessionHas('succes');
        $this->post($this->url('candidatures/etudiant'), ['dos_id' => $id])->assertSessionHas('erreur', 'Une fiche étudiant est déjà liée à ce dossier.');
        $this->post($this->url('candidatures/etudiant'), ['dos_id' => $autre, 'depuis_dossier' => 1])->assertRedirect($this->url("candidatures/$autre"));
        $et = DB::table('etudiants')->where('id', DB::table('candidatures')->where('id', $id)->value('etudiant_id'))->first();
        $this->assertSame([null, 'D100001', $depot, '2026', 'actif'], [$et->numero, $et->numero_dossier, $et->numero_depot, (string) $et->bac_annee, $et->statut_etudiant]);
        $this->assertSame(2, DB::table('etudiants')->count());
        $this->assertNotNull(DB::table('candidatures')->where('id', $autre)->value('numero_depot'));

        // Quitus pour le personnel, sans jeton
        $this->get($this->url("candidatures/$id/quitus"))->assertOk()->assertSee($depot);
        $this->get($this->url("candidatures/$id/pieces/photo"))->assertNotFound();

        // Réservé à l'administration
        $this->connecte($this->creerUtilisateur('doyen'))->get($this->url('candidatures'))->assertRedirect();
    }

    public function test_depot_par_l_admin_configuration_et_export(): void
    {
        $this->connecte($this->admin);
        $champs = ['nom' => 'Beta', 'prenom' => 'Zara', 'email' => 'Zara@Exemple.ne', 'sexe' => 'F', 'choix1' => $this->filiere, 'bac_annee' => '2025-2026'];
        $this->post($this->url('candidatures'), ['choix1' => 999] + $champs)->assertSessionHas('erreur');
        $this->post($this->url('candidatures'), $champs)->assertSessionHas('succes');
        preg_match('/affiché une seule fois\) : (\S+)$/', session('succes'), $m);
        $candidat = Candidat::where('email', 'zara@exemple.ne')->first();
        $this->assertTrue(Hash::check($m[1], $candidat->mot_de_passe));
        $dossier = DB::table('candidatures')->where('candidat_id', $candidat->id)->first();
        $this->assertSame('soumis', $dossier->statut);
        $this->assertStringNotContainsString($m[1], (string) $dossier->notes_admin, 'mot de passe jamais conservé en clair');
        $this->post($this->url('candidatures'), $champs)->assertSessionHas('erreur', 'Un compte candidat existe déjà avec cet email.');

        // Configuration contrôlée ; restrictions supprimées en POST
        $this->post($this->url('candidatures/configuration'), ['ouvert' => '2'])->assertSessionHas('erreur');
        $this->post($this->url('candidatures/configuration'), ['ouvert' => '0', 'message_ferme' => 'Clos.', 'ins_type_candidat' => 'nouveau'])->assertSessionHas('succes');
        $this->assertSame('0', DB::table('portail_candidats_config')->where('cle', 'ouvert')->value('valeur'));
        $this->post($this->url('candidatures/configuration/restrictions'))->assertSessionHas('succes');
        $this->assertSame('', DB::table('portail_candidats_config')->where('cle', 'ins_type_candidat')->value('valeur'));

        // Export : nom de l'université, une ligne par dossier
        DB::table('candidatures')->where('id', $dossier->id)->update(['nom' => '=HYPERLINK("x")']);
        $export = $this->get($this->url('candidatures/export?type=soumis'))->assertOk();
        $lignes = array_values(Tableur::xlsx($export->baseResponse->getFile()->getPathname()));
        $this->assertStringContainsString('Dossiers Soumis', $lignes[0][0]);
        $this->assertSame($dossier->numero_dossier, $lignes[3][0]);
        $this->assertSame('=HYPERLINK("X")', $lignes[3][2]);
    }
}
