<?php

namespace Tests\Feature\Documents;

use App\Models\Utilisateur;
use App\Services\CleVerification;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/** Attestations, diplômes, liste des éligibles, registre. */
class DocumentsTest extends TestCase
{
    private Utilisateur $admin;

    private int $annee;

    private int $filiere;

    private int $facB;

    private int $alpha;

    private int $beta;

    /** @var array<string, int> */
    private array $niveaux = [];

    /** @var array<string, int> */
    private array $semestres = [];

    protected function setUp(): void
    {
        parent::setUp();
        $this->installerLicence();
        $this->admin = $this->creerUtilisateur('admin');
        $this->annee = DB::table('annees_academiques')->insertGetId(['libelle' => '2025-2026', 'active' => 1]);
        $this->facB = $this->insererMinimal('facultes', ['code' => 'FB', 'libelle' => 'Faculté B']);
        $this->filiere = DB::table('filieres')->insertGetId(['code' => 'ECO', 'libelle' => 'Économie', 'departement' => '', 'faculte_id' => $this->facB]);
        foreach (['S1', 'S2', 'S3', 'S4', 'S5', 'S6'] as $i => $code) {
            $this->semestres[$code] = DB::table('semestres')->insertGetId(['code' => $code, 'libelle' => 'Semestre '.($i + 1)]);
        }
        $this->alpha = DB::table('etudiants')->insertGetId(['nom' => 'ALPHA', 'prenoms' => 'Awa', 'numero' => '00001', 'sexe' => 'F', 'filiere_id' => $this->filiere]);
        $this->beta = DB::table('etudiants')->insertGetId(['nom' => 'BETA', 'prenoms' => 'Bio', 'numero' => '00002', 'sexe' => 'M', 'filiere_id' => $this->filiere]);
        foreach (['S1' => 'L1', 'S2' => 'L1', 'S3' => 'L2', 'S4' => 'L2'] as $sem => $niv) {
            $this->valider($this->alpha, $niv, $sem, 14);
        }
        $this->valider($this->beta, 'L1', 'S1', 12);
        $this->valider($this->beta, 'L1', 'S2', 12);
        $this->valider($this->beta, 'L2', 'S4', 12);
        $this->valider($this->beta, 'L2', 'S3', 8, false);   // S3 non validé
    }

    /** Résultat de semestre (PV de session normale de la promotion niveau × filière). */
    private function valider(int $et, string $niveau, string $sem, float $moyenne, bool $valide = true): int
    {
        $this->niveaux[$niveau] ??= DB::table('niveaux')->insertGetId(['code' => $niveau, 'libelle' => $niveau]);
        $promo = DB::table('promotions')->where(['filiere_id' => $this->filiere, 'niveau_id' => $this->niveaux[$niveau], 'annee_id' => $this->annee])->value('id')
            ?? $this->creerPromotion($this->annee, ['filiere_id' => $this->filiere, 'niveau_id' => $this->niveaux[$niveau]]);
        $pv = DB::table('pv_sessions')->where(['promotion_id' => $promo, 'semestre_id' => $this->semestres[$sem], 'type_session' => 'normale'])->value('id')
            ?? DB::table('pv_sessions')->insertGetId(['promotion_id' => $promo, 'semestre_id' => $this->semestres[$sem], 'type_session' => 'normale', 'libelle' => "PV $sem $niveau"]);
        DB::table('resultats_semestre')->insert(['pv_id' => $pv, 'etudiant_id' => $et, 'moyenne_generale' => $moyenne, 'credits_obtenus' => $valide ? 30 : 12,
            'credits_total' => 30, 'valide' => $valide ? 1 : 0, 'defaillant' => 0, 'mention' => $valide ? 'Bien' : 'Ajourné', 'decision' => $valide ? 'Admis' : 'Ajourné']);

        return $pv;
    }

    private function agentAutreFaculte(string $role = 'sc_entite'): Utilisateur
    {
        $facA = $this->insererMinimal('facultes', ['code' => 'FA', 'libelle' => 'Faculté A']);
        $this->donnerPermissions($role, ['inscription_ped', 'pv_voir']);

        return $this->creerUtilisateur($role, ['faculte_id' => $facA, 'email' => $role.'-a@test.local']);
    }

    public function test_attestation_de_reussite_seulement_pour_un_admis(): void
    {
        $pvS3 = DB::table('pv_sessions')->where('libelle', 'PV S3 L2')->value('id');
        $this->connecte($this->admin)->get($this->url('attestations/reussite'))->assertOk()->assertSee('Économie — L2 — S3', false);
        $this->get($this->url("attestations/reussite?pv_id=$pvS3&et_id={$this->alpha}"))->assertOk()
            ->assertSee('Attestation de Réussite')->assertSee('ALPHA Awa')->assertSee('Admise')
            // Civilité selon le sexe (avant : « M. (Mlle.) » pour tous)
            ->assertSee('Mlle ALPHA Awa')->assertSee('Inscrite sous le n°')->assertDontSee('M. (Mlle')->assertDontSee('Née (e)');
        // BETA n'a pas validé S3 : plus de fausse attestation « Admis »
        $this->get($this->url("attestations/reussite?pv_id=$pvS3&et_id={$this->beta}"))->assertRedirect($this->url('attestations/reussite'))
            ->assertSessionHas('erreur', "Pas d'attestation de réussite : l'étudiant n'est pas admis dans ce PV.");
        $this->get($this->url("attestations/reussite?pv_id=$pvS3&masse=1"))->assertOk()->assertSee('ALPHA')->assertDontSee('BETA');

        // Numéro enregistré : le même à chaque réimpression, vérifiable sur la page publique
        $numero = DB::table('attestations_emises')->where(['etudiant_id' => $this->alpha, 'type' => "reussite_$pvS3"])->value('numero');
        $this->assertMatchesRegularExpression('/^AR\d{2}-000001$/', $numero);
        $this->assertSame(1, DB::table('attestations_emises')->count());
        $this->get($this->url("attestations/reussite?pv_id=$pvS3&et_id={$this->alpha}"))->assertSee("N° $numero")->assertDontSee('ANM/DepD');
        $this->post($this->url('deconnexion'));
        $this->get($this->url('verification?num='.$numero.'&code='.app(CleVerification::class)->code($numero)))->assertOk()->assertSee('Attestation de réussite (semestre)');

        $this->connecte($this->agentAutreFaculte())->get($this->url("attestations/reussite?pv_id=$pvS3&masse=1"))->assertRedirect($this->url('attestations/reussite'));
        $this->donnerPermissions('enseignant', ['inscription_ped']);
        $this->connecte($this->creerUtilisateur('enseignant'))->get($this->url('attestations/reussite'))->assertRedirect($this->url('accueil'));
    }

    public function test_attestation_de_validation_et_registre(): void
    {
        $this->connecte($this->admin)->get($this->url("attestations?et_id={$this->alpha}&type=validation_l2"))->assertOk()
            ->assertSee('ATTESTATION DE VALIDATION')->assertSee('LICENCE 2ÈME ANNÉE')->assertSee('A validé avec succès les quatre semestres')
            ->assertSee('Mention <strong>Bien</strong>', false)->assertSee('A'.date('y').'-VA-000001');
        $page = $this->get($this->url("attestations?et_id={$this->alpha}&type=validation_l2"))->assertOk()->assertSee('A'.date('y').'-VA-000001');
        // Le QR code porte la clé du document
        $page->assertSee(e(app(CleVerification::class)->url('A'.date('y').'-VA-000001')), false);
        // … et l'adresse officielle de vérification est imprimée en clair, sans la clé
        $page->assertSee('Site officiel')->assertSee(CleVerification::site(app(CleVerification::class)->url('X')));
        $this->assertSame(1, DB::table('attestations_emises')->count());
        $this->assertEquals(14, DB::table('attestations_emises')->value('moyenne'));

        $this->get($this->url("attestations?et_id={$this->beta}&type=validation_l2"))->assertRedirect($this->url('diplomes'))
            ->assertSessionHas('erreur', "Impossible de générer cette attestation : le semestre S3 n'est pas (ou plus) validé pour cet étudiant.");
        $this->get($this->url("attestations?et_id={$this->alpha}&type=licence"))->assertSessionHas('erreur');
        $this->get($this->url("attestations?et_id={$this->alpha}&type=nimporte"))->assertSessionHas('erreur', 'Paramètres invalides.');

        $this->connecte($this->agentAutreFaculte())->get($this->url("attestations?et_id={$this->alpha}&type=validation_l2"))
            ->assertSessionHas('erreur', 'Étudiant introuvable.');
    }

    public function test_diplomes_licence_et_dut(): void
    {
        $this->connecte($this->admin);
        // 4 semestres seulement : ni licence, ni DUT (pas de cycle DUT)
        $this->get($this->url("diplomes/document?et_id={$this->alpha}&niveau=licence"))->assertRedirect($this->url('diplomes'))
            ->assertSessionHas('erreur', "Impossible de générer ce diplôme : le semestre S5 n'est pas (ou plus) validé pour cet étudiant.");
        $this->get($this->url("diplomes/document?et_id={$this->alpha}&niveau=dut"))
            ->assertSessionHas('erreur', "Impossible de générer ce diplôme : l'étudiant n'a pas suivi de cycle DUT.");

        $this->valider($this->alpha, 'L3', 'S5', 16);
        $this->valider($this->alpha, 'L3', 'S6', 16);
        $this->get($this->url("diplomes/document?et_id={$this->alpha}&niveau=licence"))->assertOk()
            ->assertSee('Diplôme de LICENCE')->assertSee('ALPHA Awa')->assertSee('Madame')->assertSee('Année académique 2025-2026')
            ->assertSee('D'.date('y').'-LI-000001');
        // Moyenne des 6 semestres : (14×4 + 16×2) / 6 = 14,67 → Bien
        $this->assertEquals(['Bien', 14.67], [DB::table('diplomes_emis')->value('mention_grade'), (float) DB::table('diplomes_emis')->value('moyenne')]);
        $this->get($this->url('diplomes/document?et_id='.$this->alpha.'&niveau=autre'))->assertSessionHas('erreur', 'Paramètres invalides.');
    }

    public function test_master_manuel_securise(): void
    {
        $this->connecte($this->admin);
        $url = $this->url("diplomes/document?et_id={$this->beta}&niveau=master");
        // Les anciens paramètres GET ne créent plus rien
        $this->get($url.'&confirme=1&domaine=X&mention_master=Y&specialite=Z&mention_grade=Tr%C3%A8s+Bien&session=2020')->assertOk()
            ->assertSee('Informations du diplôme de Master');
        $this->assertSame(0, DB::table('diplomes_emis')->count());

        $champs = ['et_id' => $this->beta, 'domaine' => 'Sciences', 'mention_master' => 'Informatique', 'specialite' => 'Gestion',
            'type_master' => 'Recherche', 'credits' => 120, 'session' => 'Juillet 2022', 'mention_grade' => 'Bien'];
        $this->post($this->url('diplomes/document/master'), ['mention_grade' => 'Excellent'] + $champs)->assertSessionHas('erreur');
        $this->post($this->url('diplomes/document/master'), $champs)->assertOk()->assertSee('DIPLÔME DE MASTER RECHERCHE')->assertSee('Informatique');
        $this->assertSame('Bien', DB::table('diplomes_emis')->value('mention_grade'));

        // Un diplôme enregistré n'est jamais réécrit
        $this->get($url)->assertOk()->assertSee('Diplôme déjà enregistré');
        $this->post($this->url('diplomes/document/master'), ['mention_grade' => 'Très Bien', 'specialite' => 'Autre'] + $champs)->assertOk()->assertSee('Gestion');
        $this->assertSame(['Bien', 'Gestion', 1], [DB::table('diplomes_emis')->value('mention_grade'), DB::table('diplomes_emis')->value('specialite'), DB::table('diplomes_emis')->count()]);
        $this->assertDatabaseHas('audit_log', ['action' => 'creer', 'module' => 'diplome']);

        // Résultats de Master présents : saisie manuelle refusée
        $this->valider($this->alpha, 'M1', 'S1', 13);
        $this->post($this->url('diplomes/document/master'), ['et_id' => $this->alpha] + $champs)->assertSessionHas('erreur');
        $this->get($this->url("diplomes/document?et_id={$this->alpha}&niveau=master"))->assertRedirect($this->url('diplomes'));

        // Rôle non autorisé
        $this->donnerPermissions('doyen', ['inscription_ped']);
        $this->connecte($this->creerUtilisateur('doyen'))->post($this->url('diplomes/document/master'), $champs)->assertRedirect($this->url('accueil'));
    }

    public function test_liste_des_eligibles_et_registre(): void
    {
        $this->connecte($this->admin)->get($this->url('diplomes'))->assertOk()
            ->assertSee('ALPHA Awa')->assertDontSee('BETA Bio')->assertSee('14.00/20')->assertSee('📋 4 sem.');
        $this->get($this->url("attestations?et_id={$this->alpha}&type=validation_l2"))->assertOk();
        $this->get($this->url('diplomes/registre'))->assertOk()->assertSee('A'.date('y').'-VA-000001')->assertSee('Attestation validation');
        $this->get($this->url('diplomes/registre?type_doc=diplome'))->assertOk()->assertDontSee('A'.date('y').'-VA-000001');
        $this->get($this->url('verification?num=A'.date('y').'-VA-000001&code='.app(CleVerification::class)->code('A'.date('y').'-VA-000001')))->assertOk()->assertSee('authentique');

        $agent = $this->agentAutreFaculte();
        $this->connecte($agent)->get($this->url('diplomes'))->assertOk()->assertDontSee('ALPHA Awa');
        $this->get($this->url('diplomes/registre'))->assertOk()->assertDontSee('A'.date('y').'-VA-000001');
    }

    public function test_certificat_de_scolarite(): void
    {
        $promo = DB::table('promotions')->where('niveau_id', $this->niveaux['L2'])->value('id');
        $insA = DB::table('inscriptions')->insertGetId(['etudiant_id' => $this->alpha, 'promotion_id' => $promo, 'statut' => 'inscrit', 'date_inscription' => '2025-10-01']);
        $insB = DB::table('inscriptions')->insertGetId(['etudiant_id' => $this->beta, 'promotion_id' => $promo, 'statut' => 'inscrit', 'date_inscription' => '2025-10-01']);
        $this->connecte($this->admin)->get($this->url("certificat-scolarite?ins_id=$insA"))->assertOk()
            ->assertSee('Certificat de Scolarité')->assertSee('ALPHA Awa')->assertSee('Est inscrite')->assertSee('2025-2026')
            ->assertSee('Site officiel')->assertSee('/verification');
        // Par ins_id, chaque étudiant a son propre numéro (avant : étudiant 0, numéro partagé)
        $this->get($this->url("certificat-scolarite?ins_id=$insB"))->assertOk()->assertSee('BETA Bio');
        $this->get($this->url("certificat-scolarite?et_id={$this->alpha}"))->assertOk();
        $this->assertEquals([$this->alpha, $this->beta], DB::table('certificats_emis')->orderBy('id')->pluck('etudiant_id')->all());
        $this->get($this->url('verification?num=C'.date('y').'-000001&code='.app(CleVerification::class)->code('C'.date('y').'-000001')))->assertSee('ALPHA Awa')->assertDontSee('BETA');

        // Inscription annulée : pas de certificat
        DB::table('inscriptions')->where('id', $insB)->update(['statut' => 'annule']);
        $this->get($this->url("certificat-scolarite?ins_id=$insB"))->assertSessionHas('erreur');

        $agent = $this->agentAutreFaculte();
        $this->donnerPermissions('sc_entite', ['certificat_sco']);
        $this->connecte($agent)->get($this->url("certificat-scolarite?ins_id=$insA"))->assertSessionHas('erreur');
    }
}
