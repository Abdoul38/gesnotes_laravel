<?php

namespace Tests\Feature\Etudiants;

use App\Models\Utilisateur;
use App\Support\Tableur;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class ImportEtudiantsTest extends TestCase
{
    private Utilisateur $admin;

    private array $choix;

    protected function setUp(): void
    {
        parent::setUp();
        $this->installerLicence();
        $this->admin = $this->creerUtilisateur('admin');
        $this->choix = [
            'annee_id' => DB::table('annees_academiques')->insertGetId(['libelle' => '2025-2026', 'active' => 1]),
            'filiere_id' => DB::table('filieres')->insertGetId(['code' => 'ECO', 'libelle' => 'Économie', 'departement' => '']),
            'niveau_id' => DB::table('niveaux')->insertGetId(['code' => 'L1', 'libelle' => 'Licence 1']),
            'montant_def' => 10000,
            'mode_def' => 'especes',
        ];
    }

    private function importer($fichier, array $choix = [])
    {
        return $this->connecte($this->admin)->from($this->url('etudiants/import-masse'))
            ->post($this->url('etudiants/import-masse'), ['fichier' => $fichier] + $choix + $this->choix);
    }

    public function test_page(): void
    {
        $this->connecte($this->admin)->get($this->url('etudiants/import-masse'))->assertOk()
            ->assertSee('2025-2026 (En cours)')->assertSee('L1 — Licence 1')->assertSee('Etablissement_Origine')
            ->assertSee('data-confirm=', false);
    }

    public function test_template_reimporte_tel_quel(): void
    {
        $reponse = $this->connecte($this->admin)->get($this->url('etudiants/import-masse/template'))->assertOk()
            ->assertDownload('template_import_etudiants.xlsx');
        $chemin = $reponse->baseResponse->getFile()->getPathname();
        $lignes = Tableur::xlsx($chemin);
        $this->assertSame(['Matricule', 'Nom*', 'Prenoms*'], array_slice($lignes[2], 0, 3));
        $copie = tempnam(sys_get_temp_dir(), 'tpl');
        copy($chemin, $copie);

        $this->importer(new \Illuminate\Http\UploadedFile($copie, 'template.xlsx', null, null, true))
            ->assertRedirect($this->url('etudiants/import-masse'))
            ->assertSessionHas('succes', '✅ Import terminé — 2 étudiant(s) créé(s)');

        // Matricule fourni (00012) puis suivant calculé APRÈS lui
        $this->assertSame(['ABDOU' => '00012', 'MOUSSA' => '00013'], DB::table('etudiants')->orderBy('nom')->pluck('numero', 'nom')->all());
        $promo = DB::table('promotions')->where(['filiere_id' => $this->choix['filiere_id'], 'niveau_id' => $this->choix['niveau_id']])->value('id');
        $this->assertNotNull($promo);
        $ins = DB::table('inscriptions')->where('promotion_id', $promo)->get();
        $this->assertCount(2, $ins);
        $this->assertSame($this->admin->id, (int) $ins[0]->saisie_par);
        $this->assertDatabaseHas('paiements', ['type_paiement' => 'inscription', 'montant_paye' => 10000, 'statut' => 'complet', 'annee_id' => $this->choix['annee_id']]);
        $e = DB::table('etudiants')->where('nom', 'ABDOU')->first();
        $this->assertSame(['Ibrahim Ali', 'M', '2002-05-15', 'Quartier Centre', 'D', '2021', 'D123456'],
            [$e->prenoms, $e->sexe, $e->date_naissance, $e->adresse, $e->bac_serie, (string) $e->bac_annee, $e->numero_dossier]);
    }

    public function test_csv_dont_l_en_tete_commence_par_nom(): void
    {
        DB::table('etudiants')->insert(['numero' => '00041', 'nom' => 'X', 'prenoms' => 'Y']);
        $csv = "Nom;Prenoms;Sexe;Montant_Paye;Mode_Paiement\nsani;issa;;5 000;MyNITA\nidi;hawa;f;0;inconnu\n";

        $this->importer($this->fichierTexte($csv))->assertSessionHas('succes');
        $sani = DB::table('etudiants')->where('nom', 'SANI')->first();
        $this->assertSame('00042', $sani->numero);
        $this->assertNull($sani->sexe);   // l'ancien import mettait « M »
        $this->assertDatabaseHas('inscriptions', ['etudiant_id' => $sani->id, 'montant_paye' => 5000, 'mode_paiement' => 'mobile_money']);
        $idi = DB::table('etudiants')->where('nom', 'IDI')->first();
        $this->assertDatabaseHas('inscriptions', ['etudiant_id' => $idi->id, 'montant_paye' => 0, 'mode_paiement' => 'especes']);
        $this->assertDatabaseMissing('paiements', ['etudiant_id' => $idi->id]);
    }

    public function test_doublons_matricule_pris_et_nom_manquant(): void
    {
        DB::table('etudiants')->insert(['numero' => '00007', 'nom' => 'GARBA', 'prenoms' => 'Ali', 'date_naissance' => '2001-01-01']);
        $csv = "Matricule,Nom,Prenoms,Date_Naissance\n,garba,ali,01/01/2001\n00007,NEUF,Test,\n,,Sans Nom,\n,BON,Eleve,\n";

        $this->importer($this->fichierTexte($csv))
            ->assertSessionHas('avertissement', '✅ Import terminé — 1 étudiant(s) créé(s), 2 doublon(s) ignoré(s), 1 erreur(s)')
            ->assertSessionHas('details_import', [
                'Ligne 2 : GARBA Ali — étudiant déjà existant, ignoré',
                'Ligne 3 : Matricule 00007 déjà existant — ignoré',
                'Ligne 4 : Nom manquant — ignorée',
            ]);
        $this->assertSame('00008', DB::table('etudiants')->where('nom', 'BON')->value('numero'));
    }

    public function test_promotion_existante_reutilisee(): void
    {
        $promo = $this->creerPromotion($this->choix['annee_id'], ['filiere_id' => $this->choix['filiere_id'], 'niveau_id' => $this->choix['niveau_id']]);
        $this->importer($this->fichierTexte("Nom;Prenoms\nA;B\n"));
        $this->assertSame(1, DB::table('promotions')->count());
        $this->assertDatabaseHas('inscriptions', ['promotion_id' => $promo]);
    }

    public function test_erreurs(): void
    {
        $this->importer($this->fichierTexte("Nom\nA\n"), ['annee_id' => 0, 'niveau_id' => 999999])
            ->assertRedirect($this->url('etudiants/import-masse'))->assertSessionHas('erreur', 'Année académique requise. Niveau requis.');
        $this->importer($this->fichierTexte("Prenom;Sexe\nA;M\n"))->assertSessionHas('erreur', fn ($m) => str_contains($m, "Ligne d'en-têtes introuvable"));
        $this->importer($this->fichierTexte(''))->assertSessionHas('erreur', "Le fichier est vide ou ne contient que l'en-tête.");
        $this->assertSame(0, DB::table('etudiants')->count());
        $this->assertSame(0, DB::table('promotions')->count());
    }

    public function test_permission_requise(): void
    {
        $this->connecte($this->creerUtilisateur('enseignant'))->get($this->url('etudiants/import-masse'))->assertRedirect();
    }
}
