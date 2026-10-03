<?php

namespace Tests\Feature\Communication;

use App\Models\Utilisateur;
use App\Services\Communication\Courriel;
use App\Services\Parametres;
use Illuminate\Mail\Transport\ArrayTransport;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/** Annonces, messagerie, e-mails aux étudiants, vue d'ensemble. */
class PagesCommunicationTest extends TestCase
{
    private Utilisateur $admin;

    private int $facA;

    private int $etA;

    private int $etB;

    private int $niveau;

    protected function setUp(): void
    {
        parent::setUp();
        $this->installerLicence();
        $this->admin = $this->creerUtilisateur('admin');
        $annee = DB::table('annees_academiques')->insertGetId(['libelle' => '2025-2026', 'active' => 1]);
        $this->facA = $this->insererMinimal('facultes', ['code' => 'FA', 'libelle' => 'Faculté A']);
        $facB = $this->insererMinimal('facultes', ['code' => 'FB', 'libelle' => 'Faculté B']);
        $this->niveau = DB::table('niveaux')->insertGetId(['code' => 'L1', 'libelle' => 'Licence 1']);
        $this->etA = $this->etudiant('ALPHA', 'alpha@exemple.ne', $this->facA, 'FA1', $annee);
        $this->etB = $this->etudiant('BETA', 'beta@exemple.ne', $facB, 'FB1', $annee);
    }

    private function etudiant(string $nom, string $email, int $faculte, string $code, int $annee): int
    {
        $filiere = DB::table('filieres')->insertGetId(['code' => $code, 'libelle' => "Filière $code", 'departement' => '', 'faculte_id' => $faculte]);
        $promo = $this->creerPromotion($annee, ['filiere_id' => $filiere, 'niveau_id' => $this->niveau]);
        $et = DB::table('etudiants')->insertGetId(['nom' => $nom, 'prenoms' => 'X', 'numero' => (string) random_int(10000, 99999), 'email' => $email, 'filiere_id' => $filiere]);
        DB::table('inscriptions')->insert(['etudiant_id' => $et, 'promotion_id' => $promo, 'statut' => 'inscrit', 'date_inscription' => '2025-10-01']);

        return $et;
    }

    private function agent(string $role, array $permissions): Utilisateur
    {
        $this->donnerPermissions($role, $permissions);

        return $this->creerUtilisateur($role, ['faculte_id' => $this->facA, 'email' => "$role-a@test.local"]);
    }

    public function test_annonces(): void
    {
        $annonce = ['titre' => 'Rentrée', 'contenu' => 'Début des cours le 6.', 'type' => 'info', 'cible' => 'tous', 'publiee' => '1'];
        $this->connecte($this->admin)->post($this->url('annonces'), $annonce)->assertSessionHas('succes');
        $this->post($this->url('annonces'), ['type' => 'pub'] + $annonce)->assertSessionHas('erreur');
        $this->post($this->url('annonces'), ['cible' => 'filiere'] + $annonce)->assertSessionHas('erreur', 'Choisissez la filière ou le niveau ciblé.');
        $id = DB::table('annonces')->value('id');
        $this->get($this->url('annonces'))->assertOk()->assertSee('Rentrée')->assertSee('data-annonce=', false);

        // Lecture seule : rien ne s'écrit
        $lecteur = $this->agent('secretaire', ['etudiants_voir']);
        $this->connecte($lecteur)->get($this->url('annonces'))->assertOk()->assertSee('Rentrée')->assertDontSee('Nouvelle annonce');
        $this->post($this->url('annonces/supprimer'), ['id' => $id])->assertRedirect($this->url('accueil'));

        // Agent de faculté : ses annonces seulement
        $agent = $this->agent('sc_entite', ['etudiants_voir', 'etudiants_gerer']);
        $this->connecte($agent)->post($this->url('annonces/supprimer'), ['id' => $id])->assertSessionHas('erreur', 'Annonce introuvable.');
        $this->post($this->url('annonces'), ['titre' => 'Faculté A'] + $annonce)->assertSessionHas('succes');
        $sienne = DB::table('annonces')->where('titre', 'Faculté A')->value('id');
        $this->post($this->url('annonces/publication'), ['id' => $sienne]);
        $this->assertSame(0, (int) DB::table('annonces')->where('id', $sienne)->value('publiee'));
        $this->post($this->url('annonces/supprimer'), ['id' => $sienne])->assertSessionHas('succes');
        $this->assertSame(1, DB::table('annonces')->count());
    }

    public function test_messagerie(): void
    {
        $recu = DB::table('messages')->insertGetId(['expediteur_type' => 'etudiant', 'expediteur_id' => $this->etB, 'destinataire_type' => 'admin',
            'destinataire_id' => 0, 'sujet' => 'Question bourse', 'corps' => 'Bonjour']);
        $this->connecte($this->admin)->get($this->url('messagerie'))->assertOk()->assertSee('Question bourse');
        $this->get($this->url("messagerie?msg=$recu"))->assertOk()->assertSee('Bonjour');
        $this->assertSame(1, (int) DB::table('messages')->where('id', $recu)->value('lu'));
        $this->post($this->url('messagerie'), ['dest_id' => $this->etB, 'parent_id' => $recu, 'sujet' => 'Re: Question bourse', 'corps' => 'Réponse'])
            ->assertSessionHas('succes');
        $this->assertSame($recu, (int) DB::table('messages')->where('sujet', 'Re: Question bourse')->value('parent_id'));

        // Doyen de la faculté A : ni lecture, ni suppression, ni envoi hors périmètre
        $doyen = $this->agent('doyen', ['etudiants_voir']);
        $this->connecte($doyen)->get($this->url('messagerie'))->assertOk()->assertDontSee('Question bourse');
        $this->get($this->url("messagerie?msg=$recu"))->assertOk()->assertDontSee('Bonjour');
        $this->post($this->url('messagerie/supprimer'), ['id' => $recu])->assertSessionHas('erreur', 'Message introuvable.');
        $this->post($this->url('messagerie'), ['dest_id' => $this->etB, 'sujet' => 'x', 'corps' => 'y'])->assertSessionHas('erreur', 'Destinataire introuvable.');
        $this->post($this->url('messagerie'), ['dest_id' => $this->etA, 'parent_id' => $recu, 'sujet' => 'Salut', 'corps' => 'y'])->assertSessionHas('succes');
        $this->assertNull(DB::table('messages')->where('sujet', 'Salut')->value('parent_id'));
        $this->assertSame(1, DB::table('messages')->where('id', $recu)->count());
    }

    public function test_emails_aux_etudiants(): void
    {
        foreach (['email_actif' => '1', 'email_smtp_host' => 'smtp.exemple.ne', 'email_from_adresse' => 'scolarite@exemple.ne', 'institution_nom' => 'Université Test'] as $cle => $valeur) {
            DB::table('parametres')->upsert([['cle' => $cle, 'valeur' => $valeur]], ['cle'], ['valeur']);
        }
        $this->app->forgetInstance(Parametres::class);
        $this->app->forgetInstance(Courriel::class);
        app(Courriel::class)->utiliserTransport($transport = new ArrayTransport);

        $mail = ['sujet' => 'Information', 'corps' => 'Bonjour [PRENOMS] [NOM], <b>test</b>'];
        $this->connecte($this->admin)->post($this->url('emails-etudiants'), ['destinataires' => 'niveau', 'niveau_id' => $this->niveau] + $mail)
            ->assertSessionHas('succes', '✅ 2 email(s) envoyé(s).');
        $corps = $transport->messages()->first()->getOriginalMessage()->getHtmlBody();
        $this->assertStringContainsString('UNIVERSITÉ TEST', $corps);
        $this->assertStringContainsString('Bonjour X ALPHA, &lt;b&gt;test&lt;/b&gt;', $corps);
        $this->assertSame(['envoye', 2], [DB::table('notifications_email')->value('statut'), (int) DB::table('notifications_email')->value('nb_envoyes')]);

        // Chef de département / agent limité : « tous » = son périmètre
        $doyen = $this->agent('doyen', ['etudiants_gerer']);
        $this->connecte($doyen)->get($this->url('emails-etudiants'))->assertOk()->assertSee('<strong>1</strong> étudiants ont un email', false);
        $this->post($this->url('emails-etudiants'), ['destinataires' => 'tous'] + $mail)->assertSessionHas('succes', '✅ 1 email(s) envoyé(s).');
        $this->assertSame(1, DB::table('email_logs')->where(['destinataire' => 'beta@exemple.ne', 'contexte' => 'email_etudiants'])->count());
        $this->post($this->url('emails-etudiants'), ['destinataires' => 'individuel', 'etudiant_id' => $this->etB] + $mail)
            ->assertSessionHas('erreur', 'Aucun destinataire avec une adresse email.');
    }

    public function test_vue_d_ensemble(): void
    {
        DB::table('etudiants')->where('id', $this->etA)->update(['telephone' => '+227 90 00 00 01']);
        DB::table('sms_logs')->insert(['numero' => '+22790000001', 'message' => 'x', 'statut' => 'simule']);
        $parentB = $this->insererMinimal('parents', ['nom' => 'PARENTB', 'prenoms' => 'Y', 'telephone' => '+22790000009', 'actif' => 1]);
        DB::table('parents_etudiants')->insert(['parent_id' => $parentB, 'etudiant_id' => $this->etB]);

        // ALPHA a reçu un SMS (numéro saisi avec espaces) : plus « jamais notifié »
        $this->connecte($this->admin)->get($this->url('notifications/vue-ensemble'))->assertOk()
            ->assertSee('PARENTB')->assertSee('BETA X')->assertDontSee('ALPHA X');
        $doyen = $this->agent('doyen', ['inscription_ped']);
        $this->connecte($doyen)->get($this->url('notifications/vue-ensemble'))->assertOk()->assertDontSee('PARENTB')->assertDontSee('BETA X');
    }
}
