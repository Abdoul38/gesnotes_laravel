<?php

namespace Tests\Feature\Notes;

use App\Events\PvPublie;
use App\Models\Utilisateur;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Tests\TestCase;

/**
 * PV : liste, création, saisie, simulation, actions.
 * Maquette : UE1 (6 cr.) = M1 examen/40 + TD/20 (3 cr.), M2 examen/20 (3 cr.).
 */
class PvTest extends TestCase
{
    private Utilisateur $admin;

    private int $annee;

    private int $filiere;

    private int $promo;

    private int $sem;

    private int $m1;

    private int $m2;

    private int $pv;

    protected function setUp(): void
    {
        parent::setUp();
        $this->installerLicence();
        $this->admin = $this->creerUtilisateur('admin');
        $this->annee = DB::table('annees_academiques')->insertGetId(['libelle' => '2025-2026', 'active' => 1]);
        $this->filiere = DB::table('filieres')->insertGetId(['code' => 'ECO', 'libelle' => 'Économie', 'departement' => '']);
        $this->promo = $this->creerPromotion($this->annee, ['filiere_id' => $this->filiere]);
        $this->sem = DB::table('semestres')->insertGetId(['code' => 'S1', 'libelle' => 'Semestre 1']);
        $ue = DB::table('unites_enseignement')->insertGetId(['promotion_id' => $this->promo, 'semestre_id' => $this->sem, 'code' => 'UE1', 'libelle' => 'UE 1', 'credits_total' => 6, 'ordre' => 1]);
        $this->m1 = DB::table('matieres')->insertGetId(['ue_id' => $ue, 'code' => 'M1', 'libelle' => 'Microéconomie', 'note_max_examen' => 40, 'note_max_cc' => 20, 'note_max_totale' => 60, 'credits' => 3, 'ordre' => 1]);
        $this->m2 = DB::table('matieres')->insertGetId(['ue_id' => $ue, 'code' => 'M2', 'libelle' => 'Statistiques', 'note_max_examen' => 20, 'note_max_cc' => 0, 'note_max_totale' => 20, 'credits' => 3, 'ordre' => 2]);
        $this->pv = DB::table('pv_sessions')->insertGetId(['promotion_id' => $this->promo, 'semestre_id' => $this->sem, 'type_session' => 'normale', 'libelle' => 'PV S1 Éco']);
    }

    private function etudiant(string $nom, bool $pedagogique = true): int
    {
        $et = DB::table('etudiants')->insertGetId(['nom' => $nom, 'prenoms' => 'X', 'numero' => (string) random_int(10000, 99999)]);
        $ins = DB::table('inscriptions')->insertGetId(['etudiant_id' => $et, 'promotion_id' => $this->promo, 'statut' => 'inscrit', 'date_inscription' => '2025-10-01']);
        if ($pedagogique) {
            DB::table('inscriptions_pedagogiques')->insert(['etudiant_id' => $et, 'inscription_id' => $ins, 'semestre_id' => $this->sem, 'annee_id' => $this->annee]);
        }

        return $et;
    }

    private function saisir(array $notes, ?int $pv = null)
    {
        return $this->connecte($this->admin)->post($this->url('pv/'.($pv ?? $this->pv).'/notes'), ['notes' => $notes]);
    }

    public function test_liste_et_statut(): void
    {
        Event::fake([PvPublie::class]);
        $this->connecte($this->admin)->get($this->url('pv'))->assertOk()->assertSee('PV S1 Éco')->assertSee('1 PV trouvé(s)');

        $this->postJson($this->url('pv/statut'), ['pv_id' => $this->pv, 'statut' => 'publie'])->assertOk()->assertJson(['ok' => true]);
        $this->assertSame('publie', DB::table('pv_sessions')->value('statut'));
        Event::assertDispatched(PvPublie::class, fn ($e) => $e->pvId === $this->pv);
        $this->assertDatabaseHas('audit_log', ['action' => 'publier', 'module' => 'pv']);

        // Agent d'une autre faculté : refusé
        $facA = $this->insererMinimal('facultes', ['code' => 'FA', 'libelle' => 'A']);
        $facB = $this->insererMinimal('facultes', ['code' => 'FB', 'libelle' => 'B']);
        DB::table('filieres')->update(['faculte_id' => $facB]);
        $this->donnerPermissions('sc_entite', ['pv_voir']);
        $agent = $this->creerUtilisateur('sc_entite', ['faculte_id' => $facA]);
        $this->connecte($agent)->get($this->url('pv'))->assertOk()->assertDontSee('PV S1 Éco');
        $this->postJson($this->url('pv/statut'), ['pv_id' => $this->pv, 'statut' => 'brouillon'])->assertForbidden();
        $this->assertSame('publie', DB::table('pv_sessions')->value('statut'));
    }

    public function test_saisie_et_recalcul(): void
    {
        $a = $this->etudiant('ALPHA');
        $b = $this->etudiant('BRAVO');

        $this->saisir([$a => [$this->m1 => ['exam' => '30', 'cc' => '15'], $this->m2 => ['exam' => '14,5']],
            $b => [$this->m1 => ['exam' => '10', 'cc' => '']]])
            ->assertRedirect($this->url("pv/{$this->pv}?ppage=1"))->assertSessionHas('succes', 'Notes enregistrées et résultats recalculés.');

        $this->assertSame('14.50', DB::table('notes')->where(['etudiant_id' => $a, 'matiere_id' => $this->m2])->value('note_examen'));
        $this->assertSame(['Admis', 'Défaillant'], [
            DB::table('resultats_semestre')->where('etudiant_id', $a)->value('decision'),
            DB::table('resultats_semestre')->where('etudiant_id', $b)->value('decision'),
        ]);
        $this->get($this->url("pv/{$this->pv}"))->assertOk()->assertSee('ALPHA')->assertSee('Admis')->assertSee('Défaillant')
            ->assertSee('notes['.$a.']['.$this->m1.'][exam]', false);
    }

    public function test_saisie_refusee_hors_bornes_hors_pv_ou_verrouille(): void
    {
        $a = $this->etudiant('ALPHA');
        $hors = $this->etudiant('HORS', false);

        $this->saisir([$a => [$this->m1 => ['exam' => '45', 'cc' => '10']]])->assertSessionHas('erreur', fn ($m) => str_contains($m, 'hors de 0 à 40'));
        $this->saisir([$hors => [$this->m2 => ['exam' => '10']]])->assertSessionHas('erreur', fn ($m) => str_contains($m, 'hors de ce PV'));
        $this->assertSame(0, DB::table('notes')->count());

        DB::table('pv_sessions')->update(['verrouille' => 1]);
        $this->saisir([$a => [$this->m2 => ['exam' => '10']]])->assertSessionHas('erreur', 'Ce PV est verrouillé ou vous ne pouvez pas le modifier.');
        $this->post($this->url("pv/{$this->pv}/action"), ['action_pv' => 'recalculer'])->assertSessionHas('erreur');
        $this->assertSame(0, DB::table('notes')->count());
    }

    public function test_simulation_identique_au_resultat_enregistre_et_sans_trace(): void
    {
        $a = $this->etudiant('ALPHA');
        $notes = [$this->m1 => ['exam' => '16', 'cc' => '8'], $this->m2 => ['exam' => '18']];

        $simulation = $this->connecte($this->admin)->postJson($this->url("pv/{$this->pv}/simuler"), ['etudiant_id' => $a, 'notes' => $notes])
            ->assertOk()->json();
        $this->assertSame(0, DB::table('notes')->count());
        $this->assertSame(0, DB::table('resultats_semestre')->count());

        $this->saisir([$a => $notes]);
        $res = DB::table('resultats_semestre')->where('etudiant_id', $a)->first();
        $this->assertSame([$res->decision, $res->moyenne_generale, (int) $res->credits_obtenus],
            [$simulation['semestre']['decision'], $simulation['semestre']['moyenne'], $simulation['semestre']['credits']]);
        // M1 8/20 compensée dans l'UE : (24 + 18) / 80 = 10,5
        $this->assertSame('AC', $simulation['matieres'][$this->m1]['obs']);
        $this->assertSame('AC', DB::table('notes')->where(['etudiant_id' => $a, 'matiere_id' => $this->m1])->value('observation'));
    }

    public function test_creation_normale_et_rattrapage(): void
    {
        DB::table('pv_sessions')->delete();
        $a = $this->etudiant('ALPHA');
        $b = $this->etudiant('BRAVO');

        $this->connecte($this->admin)->post($this->url('pv/nouveau'), ['promotion_id' => $this->promo, 'semestre_id' => $this->sem, 'type_session' => 'normale', 'libelle' => 'PV normal'])
            ->assertSessionHas('succes', 'PV créé avec succès.');
        $normal = (int) DB::table('pv_sessions')->value('id');
        $this->saisir([$a => [$this->m1 => ['exam' => '30', 'cc' => '15'], $this->m2 => ['exam' => '15']],
            $b => [$this->m1 => ['exam' => '30', 'cc' => '15'], $this->m2 => ['exam' => '3']]], $normal);

        // PV normal d'un autre semestre comme « 1ère session » : refusé
        $s2 = DB::table('semestres')->insertGetId(['code' => 'S2', 'libelle' => 'Semestre 2']);
        $this->post($this->url('pv/nouveau'), ['promotion_id' => $this->promo, 'semestre_id' => $s2, 'type_session' => 'rattrapage', 'pv_normale_id' => $normal, 'libelle' => 'X'])
            ->assertSessionHas('erreurs_pv', ['Le PV de 1ère session doit être un PV normal de la même promotion et du même semestre.']);

        $this->post($this->url('pv/nouveau'), ['promotion_id' => $this->promo, 'semestre_id' => $this->sem, 'type_session' => 'rattrapage', 'pv_normale_id' => $normal, 'libelle' => 'PV ratt'])
            ->assertSessionHas('succes', 'PV rattrapage créé — 1 étudiant(s) non validés copiés depuis S1.');
        $ratt = (int) DB::table('pv_sessions')->where('type_session', 'rattrapage')->value('id');
        $this->assertSame([$b], DB::table('notes')->where('pv_id', $ratt)->distinct()->pluck('etudiant_id')->map(fn ($i) => (int) $i)->all());
        // M1 acquise en 1ère session : non modifiable dans le rattrapage
        $this->get($this->url("pv/$ratt"))->assertOk()->assertSee('BRAVO')->assertDontSee('ALPHA')
            ->assertDontSee('notes['.$b.']['.$this->m1.'][exam]', false)->assertSee('notes['.$b.']['.$this->m2.'][exam]', false);
        $this->saisir([$b => [$this->m1 => ['exam' => '0', 'cc' => '0'], $this->m2 => ['exam' => '12']]], $ratt)->assertSessionHas('succes');
        $this->assertSame('30.00', DB::table('notes')->where(['pv_id' => $ratt, 'etudiant_id' => $b, 'matiere_id' => $this->m1])->value('note_examen'));
        $this->assertSame('Admis', DB::table('resultats_semestre')->where(['pv_id' => $ratt, 'etudiant_id' => $b])->value('decision'));
    }

    public function test_actions(): void
    {
        $a = $this->etudiant('ALPHA');
        $this->saisir([$a => [$this->m1 => ['exam' => '30', 'cc' => '15'], $this->m2 => ['exam' => '15']]]);

        // Vider une matière : admin seulement
        $this->donnerPermissions('sc_entite', ['pv_voir']);
        $this->connecte($this->creerUtilisateur('sc_entite'))->post($this->url("pv/{$this->pv}/action"), ['action_pv' => 'vider_matiere', 'matiere_id' => $this->m2])->assertSessionHas('erreur', 'Accès refusé.');
        $this->connecte($this->admin)->post($this->url("pv/{$this->pv}/action"), ['action_pv' => 'vider_matiere', 'matiere_id' => $this->m2])->assertSessionHas('succes', '🗑️ 1 note(s) supprimée(s) pour cette matière.');
        $this->assertSame('Défaillant', DB::table('resultats_semestre')->value('decision'));

        $this->post($this->url("pv/{$this->pv}/action"), ['action_pv' => 'verrouiller'])->assertSessionHas('succes', 'PV verrouillé');
        $this->post($this->url("pv/{$this->pv}/action"), ['action_pv' => 'supprimer'])->assertSessionHas('erreur');
        $this->post($this->url("pv/{$this->pv}/action"), ['action_pv' => 'deverrouiller'])->assertSessionHas('succes', 'PV déverrouillé');
        $this->post($this->url("pv/{$this->pv}/action"), ['action_pv' => 'finaliser']);
        $this->post($this->url("pv/{$this->pv}/action"), ['action_pv' => 'supprimer'])
            ->assertSessionHas('erreur', 'Seul un PV en brouillon peut être supprimé (ses notes et résultats seraient effacés).');
        DB::table('pv_sessions')->update(['statut' => 'brouillon']);
        $this->post($this->url("pv/{$this->pv}/action"), ['action_pv' => 'supprimer'])->assertRedirect($this->url('pv'));
        $this->assertSame(0, DB::table('pv_sessions')->count());
    }

    public function test_recalcul_progressif_sur_les_etudiants_du_pv(): void
    {
        $a = $this->etudiant('ALPHA');
        $this->etudiant('SANS_PEDAGO', false);

        $this->connecte($this->admin)->postJson($this->url("pv/{$this->pv}/recalcul"), ['mode' => 'start'])->assertJson(['ok' => true, 'total' => 1, 'ids' => [$a]]);
        $this->postJson($this->url("pv/{$this->pv}/recalcul"), ['etudiant_id' => $a])->assertJson(['ok' => true]);
        $this->assertSame(1, DB::table('resultats_semestre')->count());

        $this->donnerPermissions('enseignant', ['pv_voir']);
        $this->connecte($this->creerUtilisateur('enseignant'))->postJson($this->url("pv/{$this->pv}/recalcul"), ['mode' => 'start'])->assertForbidden();
    }

    public function test_acces_rapide_a_la_saisie(): void
    {
        $this->etudiant('ALPHA');
        DB::table('pv_sessions')->insert(['promotion_id' => $this->promo, 'semestre_id' => $this->sem, 'type_session' => 'rattrapage', 'libelle' => 'PV verrouillé', 'verrouille' => 1]);

        $this->connecte($this->admin)->get($this->url('notes'))->assertOk()
            ->assertSee('PV S1 Éco')->assertDontSee('PV verrouillé')->assertSee('PV disponibles pour saisie (1)');
        $this->get($this->url("notes?pv_id={$this->pv}"))->assertRedirect($this->url("pv/{$this->pv}"));
    }

    public function test_impression(): void
    {
        $a = $this->etudiant('ALPHA');
        // Étudiant d'une AUTRE promotion de la filière qui compose ce semestre en avance
        $autreNiveau = DB::table('niveaux')->insertGetId(['code' => 'LX', 'libelle' => 'Autre niveau']);
        $autrePromo = $this->creerPromotion($this->annee, ['filiere_id' => $this->filiere, 'niveau_id' => $autreNiveau]);
        $avance = DB::table('etudiants')->insertGetId(['nom' => 'AVANCE', 'prenoms' => 'Y', 'numero' => '77777']);
        $ins = DB::table('inscriptions')->insertGetId(['etudiant_id' => $avance, 'promotion_id' => $autrePromo, 'statut' => 'inscrit', 'date_inscription' => '2025-10-01']);
        DB::table('inscriptions_pedagogiques')->insert(['etudiant_id' => $avance, 'inscription_id' => $ins, 'semestre_id' => $this->sem, 'annee_id' => $this->annee]);
        $this->saisir([$a => [$this->m1 => ['exam' => '30', 'cc' => '15'], $this->m2 => ['exam' => '15']]]);
        DB::table('parametres')->updateOrInsert(['cle' => 'institution_ville'], ['valeur' => 'Maradi']);
        $this->app->forgetInstance(\App\Services\Parametres::class);
        DB::table('pv_sessions')->update(['date_pv' => '2026-02-10']);

        $this->connecte($this->admin)->get($this->url("pv/impression?id={$this->pv}"))->assertOk()
            ->assertSee('RÉSULTATS DES EXAMENS — SEMESTRE 1')->assertSee('ALPHA X')->assertSee('AVANCE Y')
            ->assertSee('Maradi, le 10/02/2026')->assertSee('1/2')->assertSee('Admis');
        $this->get($this->url("pv/impression?id={$this->pv}&et_id=$avance"))->assertOk()->assertDontSee('ALPHA X</td>', false)->assertSee('AVANCE Y');

        $this->donnerPermissions('enseignant', ['pv_imprimer']);
        $this->connecte($this->creerUtilisateur('enseignant'))->get($this->url("pv/impression?id={$this->pv}"))->assertRedirect($this->url('pv'));
    }

    public function test_acces_au_detail(): void
    {
        $this->donnerPermissions('enseignant', ['pv_voir']);
        $ens = DB::table('enseignants')->insertGetId(['nom' => 'PROF', 'prenoms' => 'X']);
        $prof = $this->creerUtilisateur('enseignant', ['enseignant_id' => $ens]);
        $this->connecte($prof)->get($this->url("pv/{$this->pv}"))->assertRedirect($this->url('pv'));

        DB::table('affectations')->insert(['enseignant_id' => $ens, 'matiere_id' => $this->m1, 'annee_id' => $this->annee, 'type_charge' => 'cours']);
        $this->get($this->url("pv/{$this->pv}"))->assertOk()->assertSee('Vous êtes en lecture seule');
    }

    public function test_deliberation(): void
    {
        $a = $this->etudiant('ALPHA');
        $b = $this->etudiant('BETA');
        $c = $this->etudiant('GAMMA');
        $this->saisir([
            $a => [$this->m1 => ['exam' => '30', 'cc' => '15'], $this->m2 => ['exam' => '15']],
            $b => [$this->m1 => ['exam' => '30', 'cc' => '15']],
            $c => [$this->m1 => ['exam' => '4', 'cc' => '2'], $this->m2 => ['exam' => '3']],
        ]);
        $this->connecte($this->admin)->get($this->url('deliberation'))->assertOk()->assertSee('PV S1 Éco');
        $this->get($this->url("deliberation?pv_id={$this->pv}"))->assertOk()
            ->assertSee('Procès-Verbal de Délibération')->assertSee('ALPHA')->assertDontSee('BETA')->assertDontSee('GAMMA')
            ->assertSee('33.3%');

        // Autre faculté : refusé, et PV absent de la liste
        $facA = $this->insererMinimal('facultes', ['code' => 'FA', 'libelle' => 'A']);
        $facB = $this->insererMinimal('facultes', ['code' => 'FB', 'libelle' => 'B']);
        DB::table('filieres')->update(['faculte_id' => $facB]);
        $this->donnerPermissions('doyen', ['pv_creer']);
        $doyen = $this->creerUtilisateur('doyen', ['faculte_id' => $facA]);
        $this->connecte($doyen)->get($this->url('deliberation'))->assertOk()->assertDontSee('PV S1 Éco');
        $this->get($this->url("deliberation?pv_id={$this->pv}"))->assertRedirect($this->url('deliberation'));

        $this->donnerPermissions('enseignant', ['pv_creer']);
        $this->connecte($this->creerUtilisateur('enseignant'))->get($this->url('deliberation'))->assertRedirect();
    }

    public function test_releve_de_notes(): void
    {
        $a = $this->etudiant('ALPHA');
        $b = $this->etudiant('BETA');
        $this->saisir([
            $a => [$this->m1 => ['exam' => '30', 'cc' => '15'], $this->m2 => ['exam' => '15']],
            $b => [$this->m1 => ['exam' => '30', 'cc' => '15']],
        ]);
        $this->connecte($this->admin)->get($this->url("releve?pv_id={$this->pv}&et_id=$a"))->assertOk()
            ->assertSee('RELEVE DE NOTES')->assertSee('ALPHA')->assertSee('Microéconomie')->assertSee('AV')
            ->assertSee('RESULTAT DU SEMESTRE (VALIDATION) : OUI')->assertSee('R'.date('y').'-000001');
        // Deuxième impression : même numéro ; BETA (défaillant) reçoit le suivant
        $this->get($this->url("releve?pv_id={$this->pv}&et_id=$a"))->assertOk();
        $this->get($this->url("releve?pv_id={$this->pv}&et_id=$b"))->assertOk()->assertSee('Déf.')->assertSee('VALIDATION) : NON', false);
        $this->assertSame(['R'.date('y').'-000001', 'R'.date('y').'-000002'], DB::table('releves_emis')->orderBy('id')->pluck('numero')->all());
        $this->assertDatabaseHas('audit_log', ['action' => 'creer', 'module' => 'releve']);

        // Masse : validés seulement
        $this->get($this->url("releve?pv_id={$this->pv}&masse=1"))->assertOk()->assertSee('1 relevés')->assertSee('ALPHA')->assertDontSee('BETA');

        // Étudiant sans résultat dans ce PV, PV inconnu
        $c = $this->etudiant('GAMMA');
        $this->get($this->url("releve?pv_id={$this->pv}&et_id=$c"))->assertRedirect($this->url("pv/{$this->pv}"));
        $this->get($this->url('releve?pv_id=999&et_id='.$a))->assertRedirect($this->url('pv'));

        // Enseignant sans matière dans ce PV : refusé
        $this->connecte($this->creerUtilisateur('enseignant'))->get($this->url("releve?pv_id={$this->pv}&et_id=$a"))->assertRedirect($this->url('pv'));
    }

    public function test_releves_liste_et_apercu(): void
    {
        $a = $this->etudiant('ALPHA');
        $b = $this->etudiant('BETA');
        // ALPHA : M1 8/20 compensée par M2 → AC ; BETA défaillant
        $this->saisir([
            $a => [$this->m1 => ['exam' => '12', 'cc' => '12'], $this->m2 => ['exam' => '16']],
            $b => [$this->m1 => ['exam' => '30', 'cc' => '15']],
        ]);
        $this->assertSame('AC', DB::table('notes')->where(['etudiant_id' => $a, 'matiere_id' => $this->m1])->value('observation'));

        $this->connecte($this->admin)->get($this->url('releves'))->assertOk()->assertSee('Sélectionner un PV');
        $this->get($this->url("releves?pv_id={$this->pv}"))->assertOk()->assertSee('1 étudiant(s) validé(s)')->assertSee('ALPHA')->assertDontSee('BETA');
        $this->get($this->url("releves?pv_id={$this->pv}&q=zzz"))->assertOk()->assertSee('Aucun étudiant trouvé');
        $this->get($this->url("releves?pv_id={$this->pv}&et_id=$a"))->assertOk()->assertSee('RELEVÉ DE NOTES')->assertSee('>AC<', false)->assertSee('Admis');
        $this->get($this->url("releves?pv_id={$this->pv}&et_id=999"))->assertRedirect($this->url("releves?pv_id={$this->pv}"));

        // Autre faculté : PV refusé
        $facA = $this->insererMinimal('facultes', ['code' => 'FA', 'libelle' => 'A']);
        $facB = $this->insererMinimal('facultes', ['code' => 'FB', 'libelle' => 'B']);
        DB::table('filieres')->update(['faculte_id' => $facB]);
        $this->donnerPermissions('sc_entite', ['releve_notes']);
        $agent = $this->creerUtilisateur('sc_entite', ['faculte_id' => $facA]);
        $this->connecte($agent)->get($this->url('releves'))->assertOk()->assertDontSee('Économie');
        $this->get($this->url("releves?pv_id={$this->pv}&et_id=$a"))->assertRedirect($this->url('releves'));
    }
}
