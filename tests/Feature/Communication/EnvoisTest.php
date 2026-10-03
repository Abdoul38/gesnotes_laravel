<?php

namespace Tests\Feature\Communication;

use App\Events\PvPublie;
use App\Models\Utilisateur;
use App\Services\Communication\Courriel;
use App\Services\Communication\Sms;
use App\Services\Parametres;
use Illuminate\Mail\Transport\ArrayTransport;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/** E-mails, SMS, paramètres d'envoi, notification de publication d'un PV. */
class EnvoisTest extends TestCase
{
    private Utilisateur $admin;

    protected function setUp(): void
    {
        parent::setUp();
        $this->installerLicence();
        $this->admin = $this->creerUtilisateur('admin');
    }

    private function parametrer(array $valeurs): void
    {
        foreach ($valeurs as $cle => $valeur) {
            DB::table('parametres')->upsert([['cle' => $cle, 'valeur' => $valeur]], ['cle'], ['valeur']);
        }
        $this->app->forgetInstance(Parametres::class);
        $this->app->forgetInstance(Courriel::class);
    }

    public function test_courriel_simule_puis_envoye_sans_secret_au_journal(): void
    {
        $courriel = app(Courriel::class);
        $this->assertFalse($courriel->envoyer('etu@exemple.ne', 'Sujet', '<p>x</p>', 'test'));
        $this->assertSame('simule', DB::table('email_logs')->value('statut'));
        $this->assertFalse($courriel->envoyer('pas-une-adresse', 'Sujet', 'x'));

        $this->parametrer(['email_actif' => '1', 'email_smtp_host' => 'smtp.exemple.ne', 'email_smtp_user' => 'compte@exemple.ne',
            'email_smtp_pass' => 'MotDePasseSmtpSecret', 'email_from_adresse' => 'scolarite@exemple.ne', 'email_from_nom' => 'Scolarité']);
        $courriel = app(Courriel::class);
        $courriel->utiliserTransport($transport = new ArrayTransport);
        // Sujet avec retour à la ligne : plus d'en-tête injecté ; ligne réduite à « . » : message complet
        $this->assertTrue($courriel->envoyer('etu@exemple.ne', "Résultats\r\nBcc: espion@exemple.ne", "<p>a</p>\r\n.\r\n<p>suite</p>", 'test', 7));
        $message = $transport->messages()->first()->getOriginalMessage();
        $this->assertSame('Résultats Bcc: espion@exemple.ne', $message->getSubject());
        $this->assertCount(1, $message->getTo());
        $this->assertEmpty($message->getBcc());
        $this->assertStringContainsString('<p>suite</p>', $message->getHtmlBody());
        $journal = DB::table('email_logs')->where('statut', 'envoye')->first();
        $this->assertSame(['test', 7], [$journal->contexte, (int) $journal->reference_id]);
        $this->assertStringNotContainsString(base64_encode('MotDePasseSmtpSecret'), (string) DB::table('email_logs')->pluck('reponse_brute')->implode(' '));
    }

    public function test_sms_passerelle_https_et_journal(): void
    {
        Http::fake(['https://sms.exemple.ne/*' => Http::response(['ok' => true], 200)]);
        $this->assertFalse(app(Sms::class)->envoyer('+227 90 12 34 56', 'Bonjour', 'test'));
        $this->assertSame('simule', DB::table('sms_logs')->value('statut'));

        $this->parametrer(['sms_actif' => '1', 'sms_provider_url' => 'https://sms.exemple.ne/send', 'sms_api_key' => 'CleSmsSecrete', 'sms_sender_id' => 'UDH']);
        $this->assertTrue(app(Sms::class)->envoyer('+227 90 12 34 56', 'Bonjour', 'test'));
        Http::assertSent(fn ($r) => $r->url() === 'https://sms.exemple.ne/send' && $r->hasHeader('Authorization', 'Bearer CleSmsSecrete')
            && $r['to'] === '+22790123456' && $r['sender_id'] === 'UDH');
        $this->assertFalse(app(Sms::class)->envoyer('abc', 'x'));

        // Passerelle en http : considérée comme non configurée (la clé ne part pas)
        $this->parametrer(['sms_provider_url' => 'http://sms.exemple.ne/send']);
        $this->assertFalse(app(Sms::class)->envoyer('+22790123456', 'x'));
        Http::assertSentCount(1);
    }

    public function test_parametres_reserves_a_l_admin_et_secrets_masques(): void
    {
        $this->parametrer(['email_smtp_pass' => 'MotDePasseSmtpSecret', 'sms_api_key' => 'CleSmsSecrete']);
        $this->connecte($this->admin)->get($this->url('parametres/email'))->assertOk()->assertSee('Configuration du serveur SMTP')
            ->assertDontSee('MotDePasseSmtpSecret')->assertSee('laisser vide pour ne pas changer');
        $this->get($this->url('parametres/sms'))->assertOk()->assertDontSee('CleSmsSecrete');

        // Champs vides : secrets conservés ; passerelle http refusée ; hôte invalide refusé
        $this->post($this->url('parametres/sms'), ['sms_provider_url' => 'https://sms.exemple.ne/send', 'sms_api_key' => '', 'sms_actif' => '1'])->assertSessionHas('succes');
        $this->assertSame('CleSmsSecrete', DB::table('parametres')->where('cle', 'sms_api_key')->value('valeur'));
        $this->post($this->url('parametres/sms'), ['sms_provider_url' => 'http://intranet.local/x'])->assertSessionHas('erreur');
        $this->post($this->url('parametres/email'), ['email_smtp_host' => "smtp.exemple.ne\r\nX", 'email_smtp_port' => 587])->assertSessionHas('erreur');
        $this->post($this->url('parametres/email'), ['email_smtp_host' => 'smtp.exemple.ne', 'email_smtp_port' => 587, 'email_smtp_pass' => ''])->assertSessionHas('succes');
        $this->assertSame('MotDePasseSmtpSecret', DB::table('parametres')->where('cle', 'email_smtp_pass')->value('valeur'));
        $this->assertStringNotContainsString('MotDePasseSmtpSecret', (string) DB::table('audit_log')->pluck('description')->implode(' '));

        foreach (['sc_entite', 'doyen', 'chef_departement', 'sc_centrale'] as $role) {
            $this->donnerPermissions($role, ['inscription_ped']);
            $u = $this->creerUtilisateur($role, ['email' => "$role-param@test.local"]);
            $this->connecte($u)->get($this->url('parametres/email'))->assertRedirect($this->url('accueil'));
            $this->post($this->url('parametres/email'), ['email_smtp_host' => 'smtp.pirate.ne'])->assertRedirect($this->url('accueil'));
        }
        $this->assertSame('smtp.exemple.ne', DB::table('parametres')->where('cle', 'email_smtp_host')->value('valeur'));
    }

    public function test_publication_pv_notifiee_une_seule_fois(): void
    {
        $annee = DB::table('annees_academiques')->insertGetId(['libelle' => '2025-2026', 'active' => 1]);
        $promo = $this->creerPromotion($annee);
        $sem = DB::table('semestres')->insertGetId(['code' => 'S1', 'libelle' => 'Semestre 1']);
        $pv = DB::table('pv_sessions')->insertGetId(['promotion_id' => $promo, 'semestre_id' => $sem, 'type_session' => 'normale', 'libelle' => 'PV S1']);
        $et = DB::table('etudiants')->insertGetId(['nom' => 'ALPHA', 'prenoms' => 'Awa', 'numero' => '00001', 'email' => 'awa@exemple.ne', 'telephone' => '+22790000001']);
        DB::table('resultats_semestre')->insert(['pv_id' => $pv, 'etudiant_id' => $et, 'moyenne_generale' => 12, 'valide' => 1]);
        $parent = $this->insererMinimal('parents', ['nom' => 'PARENT', 'email' => 'parent@exemple.ne', 'telephone' => '+22790000002', 'actif' => 1]);
        DB::table('parents_etudiants')->insert(['parent_id' => $parent, 'etudiant_id' => $et] + $this->colonnesObligatoires('parents_etudiants', ['parent_id', 'etudiant_id']));

        event(new PvPublie($pv));
        app()->terminate();
        $this->assertSame(2, DB::table('email_logs')->where(['contexte' => 'pv_publie', 'reference_id' => $pv])->count());
        $this->assertSame(2, DB::table('sms_logs')->where(['contexte' => 'pv_publie', 'reference_id' => $pv])->count());
        $this->assertStringContainsString('Awa ALPHA', (string) DB::table('sms_logs')->value('message'));

        // Republication : rien de renvoyé
        event(new PvPublie($pv));
        app()->terminate();
        $this->assertSame(2, DB::table('email_logs')->where('contexte', 'pv_publie')->count());
    }

    /** Colonnes NOT NULL sans défaut d'une table (valeurs neutres). */
    private function colonnesObligatoires(string $table, array $exclues): array
    {
        return collect(DB::select("SHOW COLUMNS FROM $table"))
            ->filter(fn ($c) => $c->Null === 'NO' && $c->Default === null && $c->Extra === '' && ! in_array($c->Field, $exclues, true))
            ->mapWithKeys(fn ($c) => [$c->Field => str_contains($c->Type, 'int') ? 1 : (str_starts_with($c->Type, 'enum') ? trim(explode(',', substr($c->Type, 5))[0], "')") : 'x')])->all();
    }
}
