<?php

namespace Tests\Feature\Espaces;

use App\Models\Espaces\CompteAnab;
use App\Models\Espaces\CompteMesr;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\RateLimiter;
use Tests\TestCase;

/** Étape 12 : espaces Ministère et ANAB (sous-domaines, comptes, lectures multi-universités). */
class EspacesTest extends TestCase
{
    /** @var array<string, array<string, int>> identifiants créés dans chaque base */
    private array $ids = [];

    protected function setUp(): void
    {
        parent::setUp();
        RateLimiter::clear('espace:ministere:mesr|127.0.0.1');
        foreach (['uam' => 3, 'udh' => 2] as $code => $nbEtudiants) {
            $this->universite($code);
            $this->ids[$code] = $this->peupler($code, $nbEtudiants);
        }
        // Comptes dans la base UDH (gesnotes.espaces_comptes), base courante par défaut
    }

    /** Une année, une filière L1, $nb inscrits (dont une femme), un PV publié et un PV brouillon. */
    private function peupler(string $code, int $nb): array
    {
        $annee = DB::table('annees_academiques')->insertGetId(['libelle' => '2026-2027', 'active' => 1]);
        $fac = $this->insererMinimal('facultes', ['code' => 'FS', 'libelle' => 'Faculté des Sciences', 'actif' => 1]);
        $fil = DB::table('filieres')->insertGetId(['code' => 'MATH', 'libelle' => 'Mathématiques', 'departement' => '', 'faculte' => 'Faculté des Sciences', 'faculte_id' => $fac, 'actif' => 1]);
        $niv = DB::table('niveaux')->insertGetId(['code' => 'L1', 'libelle' => 'Licence 1']);
        $promo = $this->creerPromotion($annee, ['filiere_id' => $fil, 'niveau_id' => $niv]);
        $sem = DB::table('semestres')->insertGetId(['code' => 'S1', 'libelle' => 'Semestre 1']);
        $pub = DB::table('pv_sessions')->insertGetId(['promotion_id' => $promo, 'semestre_id' => $sem, 'type_session' => 'normale', 'libelle' => 'PV S1', 'statut' => 'publie']);
        $brouillon = DB::table('pv_sessions')->insertGetId(['promotion_id' => $promo, 'semestre_id' => $sem, 'type_session' => 'rattrapage', 'libelle' => 'PV R', 'statut' => 'brouillon']);
        $ue = DB::table('unites_enseignement')->insertGetId(['promotion_id' => $promo, 'semestre_id' => $sem, 'code' => 'UE1', 'libelle' => 'UE 1', 'credits_total' => 30, 'ordre' => 1]);
        $mat = DB::table('matieres')->insertGetId(['ue_id' => $ue, 'code' => 'M1', 'libelle' => 'Algèbre', 'note_max_examen' => 20, 'note_max_cc' => 0, 'note_max_totale' => 20, 'credits' => 6, 'ordre' => 1]);
        $etudiants = [];
        for ($i = 1; $i <= $nb; $i++) {
            $et = DB::table('etudiants')->insertGetId(['numero' => sprintf('%05d', $i), 'nom' => strtoupper($code)."-ETU$i", 'prenoms' => 'P', 'sexe' => $i === 1 ? 'F' : 'M', 'filiere_id' => $fil]);
            DB::table('inscriptions')->insert(['etudiant_id' => $et, 'promotion_id' => $promo, 'statut' => 'inscrit', 'date_inscription' => '2026-10-01']);
            DB::table('resultats_semestre')->insert(['pv_id' => $pub, 'etudiant_id' => $et, 'moyenne_generale' => 12, 'valide' => 1, 'credits_obtenus' => 30, 'credits_total' => 30, 'decision' => 'Admis']);
            DB::table('passages_niveau')->insert(['etudiant_id' => $et, 'filiere_id' => $fil, 'niveau_actuel_id' => $niv, 'niveau_suivant_id' => $niv, 'annee_depart_id' => $annee,
                'annee_arrivee_id' => $annee, 'decision' => $i === 1 ? 'licence_validee' : 'passage', 'date_decision' => '2027-07-01']);
            DB::table('notes')->insert(['etudiant_id' => $et, 'matiere_id' => $mat, 'pv_id' => $pub, 'note_examen' => 12]);
            $etudiants[] = $et;
        }

        return compact('annee', 'fil', 'niv', 'promo', 'sem', 'pub', 'brouillon', 'mat') + ['etudiants' => $etudiants];
    }

    private function compteMesr(string $role = 'admin', string $login = 'mesr'): CompteMesr
    {
        return CompteMesr::create(['login' => $login, 'nom_complet' => "Agent $login", 'role' => $role, 'mot_de_passe' => 'Ministere2026', 'actif' => 1]);
    }

    private function mesr(CompteMesr $c): static
    {
        return $this->actingAs($c, 'mesr')->withSession(['ministere_activite' => time()]);
    }

    public function test_aiguillage_par_sous_domaine(): void
    {
        $this->get($this->url('', 'ministere'))->assertRedirect($this->url('ministere', 'ministere'));
        $this->get($this->url('accueil', 'ministere'))->assertRedirect($this->url('ministere', 'ministere'));
        $this->get($this->url('ministere', 'ministere'))->assertRedirect($this->url('ministere/connexion', 'ministere'));
        $this->get($this->url('anab', 'ministere'))->assertRedirect($this->url('ministere', 'ministere'));
        $this->get($this->url('ministere/connexion', 'ministere'))->assertOk()->assertSee("Ministère de l'Enseignement Supérieur");
        // Les routes des espaces n'existent pas sur une université
        $this->get($this->url('ministere/connexion'))->assertNotFound();
        $this->get($this->url('anab/connexion'))->assertNotFound();
    }

    public function test_connexion_et_cloisonnement(): void
    {
        $c = $this->compteMesr();
        $this->post($this->url('ministere/connexion', 'ministere'), ['user' => 'mesr', 'pass' => 'mauvais'])->assertSessionHas('erreur', 'Identifiants incorrects.');
        $this->post($this->url('ministere/connexion', 'ministere'), ['user' => 'mesr', 'pass' => 'Ministere2026'])->assertRedirect($this->url('ministere', 'ministere'));
        $this->assertNotNull($c->refresh()->derniere_connexion);
        $this->assertAuthenticatedAs($c, 'mesr');
        // Un compte du ministère n'ouvre pas l'ANAB
        $this->get($this->url('anab', 'anab'))->assertRedirect($this->url('anab/connexion', 'anab'));

        // Compte désactivé entre-temps : déconnecté
        $c->forceFill(['actif' => 0])->save();
        $this->mesr($c)->get($this->url('ministere', 'ministere'))->assertRedirect($this->url('ministere/connexion', 'ministere'));
        // Session inactive depuis plus de 30 min
        $c->forceFill(['actif' => 1])->save();
        $this->actingAs($c, 'mesr')->withSession(['ministere_activite' => time() - 3600])->get($this->url('ministere', 'ministere'))->assertRedirect($this->url('ministere/connexion', 'ministere'));

        // 5 essais puis blocage
        for ($i = 0; $i < 5; $i++) {
            $this->post($this->url('ministere/connexion', 'ministere'), ['user' => 'mesr', 'pass' => 'x']);
        }
        $this->post($this->url('ministere/connexion', 'ministere'), ['user' => 'mesr', 'pass' => 'Ministere2026'])->assertSessionHas('erreur');
    }

    public function test_tableau_national_lecture_seule(): void
    {
        $admin = $this->compteMesr();
        $page = $this->mesr($admin)->get($this->url('ministere', 'ministere'))->assertOk();
        // 3 inscrits UAM + 2 UDH ; licence validée comptée une seule fois : 100 %
        $page->assertSee('UAM')->assertSee('UDH')->assertSee('TOTAL NATIONAL');
        $this->assertSame(5, $page->viewData('totaux')['inscrits']);
        $this->assertSame(5, $page->viewData('totaux')['passages']);
        $this->assertSame(100.0, (float) $page->viewData('totaux')['taux_passage']);
        $this->assertSame(2, $page->viewData('totaux')['femmes']);
        $this->assertSame(1, DB::connection('espaces')->table('mesr_logs_consultation')->where('action', 'consultation_dashboard')->count());

        foreach (['evolution', 'comparaison', 'detail', 'journal'] as $onglet) {
            $this->get($this->url("ministere?onglet=$onglet", 'ministere'))->assertOk();
        }
        $this->get($this->url('ministere?onglet=comparaison', 'ministere'))->assertSee('Mathématiques');
        $this->get($this->url('ministere?onglet=detail', 'ministere'))->assertSee('Faculté des Sciences');
        // Aucune écriture dans les bases des universités (l'ancienne page marquait des matières « finalisées »)
        $this->universite('uam');
        $this->assertSame(0, (int) DB::table('matieres')->where('id', $this->ids['uam']['mat'])->value('finalisee'));
        $this->universite('udh');

        // Le journal est réservé aux administrateurs
        $lecteur = $this->compteMesr('lecteur', 'dsi');
        $this->mesr($lecteur)->get($this->url('ministere?onglet=journal', 'ministere'))->assertOk()->assertViewHas('onglet', 'national');
    }

    public function test_compte_mysql_dedie_aux_espaces(): void
    {
        // Compte inexistant : preuve que les lectures des universités passent par lui…
        config(['gesnotes.espaces_db' => ['username' => 'compte_inexistant_lecture', 'password' => 'x']]);
        $page = $this->mesr($this->compteMesr())->get($this->url('ministere', 'ministere'))->assertOk()->assertSee('Hors ligne');
        $this->assertTrue(collect($page->viewData('stats'))->every(fn ($s) => $s['erreur']));
        // …et que le compte principal est rendu ensuite (journal du ministère écrit, requêtes normales)
        $this->assertSame(1, DB::connection('espaces')->table('mesr_logs_consultation')->count());
        $this->assertNotSame('compte_inexistant_lecture', config('database.connections.universite.username'));
        config(['gesnotes.espaces_db' => ['username' => null, 'password' => null]]);
        $this->get($this->url('ministere', 'ministere'))->assertOk()->assertViewHas('totaux', fn ($t) => $t['inscrits'] === 5);
    }

    public function test_taux_de_passage_sans_decision(): void
    {
        // Année en cours sans décision de passage : tous les inscrits comptent redoublants
        // (règle de l'université : admis = passage ou licence validée, redoublants = tous les autres)
        foreach (['uam', 'udh'] as $code) {
            $this->universite($code);
            DB::table('passages_niveau')->delete();
        }
        $page = $this->mesr($this->compteMesr())->get($this->url('ministere', 'ministere'))->assertOk();
        $totaux = $page->viewData('totaux');
        $this->assertSame([0, $totaux['inscrits'], 0.0], [$totaux['passages'], $totaux['redoublants'], (float) $totaux['taux_passage']]);
        $this->get($this->url('ministere/rapport', 'ministere'))->assertOk()->assertSee('<strong>0%</strong>Taux de passage', false);
    }

    public function test_pv_publies_seulement_et_exports(): void
    {
        $this->mesr($this->compteMesr());
        $uam = $this->ids['uam'];
        $this->get($this->url('ministere?onglet=pv', 'ministere'))->assertOk()->assertSee('Mathématiques')->assertViewHas('pvs', fn ($l) => count($l) === 2);
        $this->get($this->url("ministere?onglet=pv&pv_code=uam&pv_id={$uam['pub']}", 'ministere'))->assertOk()->assertSee('UAM-ETU1')->assertSee('Algèbre');
        $this->assertSame(1, DB::connection('espaces')->table('mesr_logs_consultation')->where('action', 'consultation_pv')->count());
        // PV brouillon : ni détail ni impression
        $this->get($this->url("ministere?onglet=pv&pv_code=uam&pv_id={$uam['brouillon']}", 'ministere'))->assertOk()->assertViewHas('detail', null);
        $this->get($this->url("ministere/pv/uam/{$uam['brouillon']}/imprimer", 'ministere'))->assertRedirect();
        $this->get($this->url("ministere/pv/inconnue/{$uam['pub']}/imprimer", 'ministere'))->assertRedirect();
        $this->get($this->url("ministere/pv/uam/{$uam['pub']}/imprimer?type=admis", 'ministere'))->assertOk()->assertSee('LISTE DES ÉTUDIANTS ADMIS')->assertSee('Université Abdou Moumouni');

        $this->get($this->url('ministere/export?annee=2026-2027', 'ministere'))->assertOk()->assertDownload();
        $this->get($this->url('ministere/rapport', 'ministere'))->assertOk()->assertSee('RAPPORT NATIONAL');
        $this->assertSame(3, DB::connection('espaces')->table('mesr_logs_consultation')->whereIn('action', ['export_pdf_admis', 'export_excel', 'export_pdf_rapport_global'])->count());
    }

    public function test_comptes_du_ministere(): void
    {
        $admin = $this->compteMesr();
        $lecteur = $this->compteMesr('lecteur', 'dsi');
        $this->mesr($lecteur)->get($this->url('ministere/comptes', 'ministere'))->assertRedirect($this->url('ministere', 'ministere'));

        $this->mesr($admin)->get($this->url('ministere/comptes', 'ministere'))->assertOk()->assertSee('dsi');
        $base = ['login' => 'stat', 'nom' => 'Service Stat', 'role' => 'lecteur', 'mdp' => 'Statistique1'];
        $this->post($this->url('ministere/comptes', 'ministere'), ['role' => 'superadmin'] + $base)->assertSessionHas('erreur', 'Rôle invalide.');
        $this->post($this->url('ministere/comptes', 'ministere'), ['mdp' => 'court'] + $base)->assertSessionHas('erreur');
        $this->post($this->url('ministere/comptes', 'ministere'), ['login' => 'dsi'] + $base)->assertSessionHas('erreur', 'Login déjà utilisé.');
        $this->post($this->url('ministere/comptes', 'ministere'), $base)->assertSessionHas('succes');
        $this->assertTrue(CompteMesr::where('login', 'stat')->where('role', 'lecteur')->exists());

        // Compte principal et propre compte : jamais désactivés
        $this->post($this->url('ministere/comptes/basculer', 'ministere'), ['compte_id' => $admin->id])->assertSessionHas('erreur');
        $this->post($this->url('ministere/comptes/basculer', 'ministere'), ['compte_id' => $lecteur->id])->assertSessionHas('succes', 'Compte désactivé.');
        $this->post($this->url('ministere/comptes/mot-de-passe', 'ministere'), ['compte_id' => $lecteur->id, 'nouveau_mdp' => 'abc'])->assertSessionHas('erreur');
        $this->post($this->url('ministere/comptes/mot-de-passe', 'ministere'), ['compte_id' => $lecteur->id, 'nouveau_mdp' => 'Nouveau2026'])->assertSessionHas('succes');

        // Changer son propre mot de passe
        $this->post($this->url('ministere/mot-de-passe', 'ministere'), ['ancien_mdp' => 'faux', 'nouveau_mdp' => 'Autre2026x', 'confirmer_mdp' => 'Autre2026x'])->assertSessionHas('erreur', 'Ancien mot de passe incorrect.');
        $this->post($this->url('ministere/mot-de-passe', 'ministere'), ['ancien_mdp' => 'Ministere2026', 'nouveau_mdp' => 'Autre2026x', 'confirmer_mdp' => 'Autre2026y'])->assertSessionHas('erreur', 'Les mots de passe ne correspondent pas.');
        $this->post($this->url('ministere/mot-de-passe', 'ministere'), ['ancien_mdp' => 'Ministere2026', 'nouveau_mdp' => 'Autre2026x', 'confirmer_mdp' => 'Autre2026x'])->assertSessionHas('succes');
        $this->post($this->url('ministere/deconnexion', 'ministere'))->assertRedirect($this->url('ministere/connexion', 'ministere'));
    }

    public function test_anab_eligibilite_et_dossier(): void
    {
        // UDH : l'étudiant 1 valide S1 à S4 dans des PV publiés ; l'étudiant 2 a son S4 dans un PV en brouillon
        $u = $this->ids['udh'];
        [$et1, $et2] = $u['etudiants'];
        foreach (['S2', 'S3', 'S4'] as $code) {
            $sem = DB::table('semestres')->insertGetId(['code' => $code, 'libelle' => "Semestre $code"]);
            $pub = DB::table('pv_sessions')->insertGetId(['promotion_id' => $u['promo'], 'semestre_id' => $sem, 'type_session' => 'normale', 'libelle' => "PV $code", 'statut' => 'publie']);
            $brouillon = DB::table('pv_sessions')->insertGetId(['promotion_id' => $u['promo'], 'semestre_id' => $sem, 'type_session' => 'normale', 'libelle' => "PV $code b", 'statut' => 'brouillon']);
            DB::table('resultats_semestre')->insert(['pv_id' => $pub, 'etudiant_id' => $et1, 'moyenne_generale' => 14, 'valide' => 1]);
            DB::table('resultats_semestre')->insert(['pv_id' => $code === 'S4' ? $brouillon : $pub, 'etudiant_id' => $et2, 'moyenne_generale' => 15, 'valide' => 1]);
        }
        $agent = CompteAnab::create(['login' => 'agent1', 'nom_complet' => 'Agent Bourses', 'role' => 'agent', 'mot_de_passe' => 'Bourses2026', 'actif' => 1]);
        $this->post($this->url('anab/connexion', 'anab'), ['user' => 'agent1', 'pass' => 'Bourses2026'])->assertRedirect($this->url('anab', 'anab'));

        $this->get($this->url('anab', 'anab'))->assertOk()->assertSee("Sélectionner l'université");
        $liste = $this->get($this->url('anab?univ=udh', 'anab'))->assertOk();
        $liste->assertViewHas('eligibles', fn ($l) => count($l) === 1 && $l[0]->id === $et1 && $l[0]->moy4 === 13.5);
        $liste->assertSee('L2 · Assez Bien');
        // Mention selon les seuils de l'université (comme sur ses attestations), plus 16/14/12 fixes
        DB::table('parametres')->updateOrInsert(['cle' => 'mention_bien'], ['valeur' => '13.5']);
        $this->get($this->url('anab?univ=udh', 'anab'))->assertSee('L2 · Bien')->assertViewHas('seuils', ['tres_bien' => 16.0, 'bien' => 13.5, 'assez_bien' => 12.0]);
        $this->get($this->url('anab?univ=udh&q=inconnu', 'anab'))->assertViewHas('eligibles', []);
        $this->get($this->url('anab?univ=pirate', 'anab'))->assertOk()->assertViewHas('uni', null);

        $this->get($this->url("anab?univ=udh&etudiant=$et1", 'anab'))->assertOk()->assertSee('UDH-ETU1')->assertSee('Éligible bourse L2')
            ->assertSee('<th>Session</th>', false)->assertSee('Session normale');
        $this->assertTrue(DB::table('audit_log')->where('module', 'anab')->where('cible_id', $et1)->where('user_nom', 'ANAB — Agent Bourses')->exists());

        // Un agent ne gère pas les comptes
        $this->get($this->url('anab/comptes', 'anab'))->assertRedirect($this->url('anab', 'anab'));
    }
}
