<?php

namespace Tests\Feature\Portails;

use App\Models\ParentEleve;
use App\Models\Utilisateur;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

/** Gestion des comptes parents par le personnel : périmètre, liens, mots de passe. */
class GestionParentsTest extends TestCase
{
    private Utilisateur $admin;

    private int $facA;

    private int $etA;

    private int $etB;

    protected function setUp(): void
    {
        parent::setUp();
        $this->installerLicence();
        $this->admin = $this->creerUtilisateur('admin');
        $this->facA = $this->insererMinimal('facultes', ['code' => 'FA', 'libelle' => 'Faculté A']);
        $facB = $this->insererMinimal('facultes', ['code' => 'FB', 'libelle' => 'Faculté B']);
        $filA = DB::table('filieres')->insertGetId(['code' => 'FA1', 'libelle' => 'Filière A', 'departement' => '', 'faculte_id' => $this->facA]);
        $filB = DB::table('filieres')->insertGetId(['code' => 'FB1', 'libelle' => 'Filière B', 'departement' => '', 'faculte_id' => $facB]);
        $this->etA = DB::table('etudiants')->insertGetId(['nom' => 'ALPHA', 'prenoms' => 'A', 'numero' => '00001', 'filiere_id' => $filA]);
        $this->etB = DB::table('etudiants')->insertGetId(['nom' => 'BETA', 'prenoms' => 'B', 'numero' => '00002', 'filiere_id' => $filB]);
    }

    private function doyenA(): Utilisateur
    {
        $this->donnerPermissions('doyen', ['utilisateurs_gerer']);

        return $this->creerUtilisateur('doyen', ['faculte_id' => $this->facA]);
    }

    public function test_creation_liens_et_regles(): void
    {
        $this->connecte($this->admin);
        $champs = ['nom' => 'ALPHA', 'prenoms' => 'Moussa', 'telephone' => '+227 90 00 00 01', 'mot_de_passe' => 'Parent123'];
        $this->post($this->url('parents/comptes'), ['mot_de_passe' => '1234'] + $champs)->assertSessionHas('erreur');
        $this->post($this->url('parents/comptes'), $champs)->assertSessionHas('succes');
        $p = ParentEleve::first();
        $this->assertSame('+22790000001', $p->telephone);
        $this->assertTrue(Hash::check('Parent123', $p->mot_de_passe_hash));
        $this->post($this->url('parents/comptes'), $champs)->assertSessionHas('erreur', 'Ce numéro de téléphone est déjà utilisé par un autre compte parent.');

        $this->post($this->url('parents/comptes/lier'), ['parent_id' => $p->id, 'etudiant_id' => $this->etA, 'lien' => 'pere'])->assertSessionHas('succes');
        $this->post($this->url('parents/comptes/lier'), ['parent_id' => $p->id, 'etudiant_id' => $this->etA, 'lien' => 'pere'])->assertSessionHas('erreur');
        $this->post($this->url('parents/comptes/lier'), ['parent_id' => $p->id, 'etudiant_id' => $this->etB, 'lien' => 'roi'])->assertSessionHas('erreur');
        $this->get($this->url("parents/comptes?parent_id={$p->id}"))->assertOk()->assertSee('ALPHA A')->assertSee('Père');

        $lien = DB::table('parents_etudiants')->value('id');
        $this->post($this->url('parents/comptes/delier'), ['parent_id' => 999, 'lien_id' => $lien])->assertSessionHas('erreur', 'Lien introuvable.');
        $this->post($this->url('parents/comptes/statut'), ['parent_id' => $p->id])->assertSessionHas('succes');
        $this->assertFalse((bool) $p->fresh()->actif);
        $this->post($this->url('parents/comptes/delier'), ['parent_id' => $p->id, 'lien_id' => $lien])->assertSessionHas('succes');
        $this->assertSame(0, DB::table('parents_etudiants')->count());
        $this->assertDatabaseHas('audit_log', ['module' => 'parent', 'action' => 'lier']);
    }

    public function test_doyen_ne_detourne_pas_un_etudiant_hors_perimetre(): void
    {
        $parentB = DB::table('parents')->insertGetId(['nom' => 'BETA', 'prenoms' => 'Pere', 'telephone' => '+22790000002', 'mot_de_passe_hash' => Hash::make('Secret123'), 'actif' => 1]);
        DB::table('parents_etudiants')->insert(['parent_id' => $parentB, 'etudiant_id' => $this->etB]);
        $doyen = $this->doyenA();
        $this->connecte($doyen);

        // Son propre compte « parent » : lier un étudiant de la faculté B est refusé
        $this->post($this->url('parents/comptes'), ['nom' => 'X', 'prenoms' => 'Y', 'telephone' => '+22790000099', 'mot_de_passe' => 'Pirate123'])->assertSessionHas('succes');
        $sien = (int) DB::table('parents')->where('telephone', '+22790000099')->value('id');
        $this->post($this->url('parents/comptes/lier'), ['parent_id' => $sien, 'etudiant_id' => $this->etB, 'lien' => 'pere'])
            ->assertSessionHas('erreur', 'Choisissez un étudiant de votre périmètre dans la liste.');
        $this->post($this->url('parents/comptes/lier'), ['parent_id' => $sien, 'etudiant_id' => $this->etA, 'lien' => 'pere'])->assertSessionHas('succes');

        // Le parent de BETA : invisible, non modifiable, non réinitialisable
        $this->get($this->url('parents/comptes'))->assertOk()->assertDontSee('+22790000002');
        $this->post($this->url('parents/comptes/modifier'), ['parent_id' => $parentB, 'nom' => 'BETA', 'prenoms' => 'Pere', 'telephone' => '+22790000099',
            'nouveau_mot_de_passe' => 'Pirate123'])->assertSessionHas('erreur', 'Compte parent introuvable.');
        $this->assertTrue(Hash::check('Secret123', DB::table('parents')->where('id', $parentB)->value('mot_de_passe_hash')));

        // Parent avec un enfant dans chaque faculté : visible mais non modifiable
        DB::table('parents_etudiants')->insert(['parent_id' => $parentB, 'etudiant_id' => $this->etA]);
        $this->get($this->url("parents/comptes?parent_id=$parentB"))->assertOk()->assertSee('hors de votre périmètre');
        $this->post($this->url('parents/comptes/statut'), ['parent_id' => $parentB])->assertSessionHas('erreur');
        $this->assertSame(1, (int) DB::table('parents')->where('id', $parentB)->value('actif'));
    }
}
