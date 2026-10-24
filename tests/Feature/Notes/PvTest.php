<?php

namespace Tests\Feature\Notes;

use App\Events\PvPublie;
use App\Models\Utilisateur;
use App\Services\CleVerification;
use App\Services\Parametres;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\RateLimiter;
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

        // Observation de l'UE fournie complète : elle doit aussi pouvoir revenir de « Elim » / « Déf. » à AV
        $obsUe = fn (array $n) => array_values($this->postJson($this->url("pv/{$this->pv}/simuler"), ['etudiant_id' => $a, 'notes' => $n])->json('ues'))[0]['obs'];
        $this->assertSame('AV', $obsUe($notes));
        $this->assertSame('Elim', $obsUe([$this->m1 => ['exam' => '16', 'cc' => '8'], $this->m2 => ['exam' => '3']]));
        $this->assertSame('Déf.', $obsUe([$this->m1 => ['exam' => '', 'cc' => '8'], $this->m2 => ['exam' => '18']]));
    }

    public function test_repechage_trace_et_annulation(): void
    {
        $a = $this->etudiant('ALPHA');
        $b = $this->etudiant('BRAVO');
        // M2 3/20 : note éliminatoire → « Elim » ; UE à (36 + 3) / 80 = 9,75
        $this->saisir([$a => [$this->m1 => ['exam' => '24', 'cc' => '12'], $this->m2 => ['exam' => '3']],
            $b => [$this->m1 => ['exam' => '30', 'cc' => '15']]]);
        $decision = fn () => DB::table('resultats_semestre')->where(['pv_id' => $this->pv, 'etudiant_id' => $a])->value('decision');
        $this->assertSame('Elim', $decision());
        $url = $this->url("pv/{$this->pv}/repechages");
        $ok = ['etudiant_id' => $a, 'matiere_id' => $this->m2, 'points' => '2', 'motif' => 'Décision du jury du 22/10'];

        // Garde-fous : points, motif, maximum, note d'examen absente, étudiant ou matière hors du PV
        $this->post($url, ['points' => '0'] + $ok)->assertSessionHas('erreur', fn ($m) => str_contains($m, 'points'));
        $this->post($url, ['motif' => 'non'] + $ok)->assertSessionHas('erreur', fn ($m) => str_contains($m, 'motif'));
        $this->post($url, ['points' => '18'] + $ok)->assertSessionHas('erreur', fn ($m) => str_contains($m, 'maximum'));
        $this->post($url, ['etudiant_id' => $b] + $ok)->assertSessionHas('erreur', fn ($m) => str_contains($m, "pas de note d'examen"));
        $this->post($url, ['etudiant_id' => 999999] + $ok)->assertSessionHas('erreur');
        $this->assertSame(0, DB::table('repechages')->count());

        // Repêchage : 3 → 5, plus éliminatoire, compensé dans l'UE ((36 + 5) / 80 = 10,25) → Admis ; tout est tracé
        $this->post($url, $ok)->assertSessionHas('succes', fn ($m) => str_contains($m, '3 → 5'));
        $this->assertSame('Admis', $decision());
        $this->assertSame(['5.00', '5.00'], array_values((array) DB::table('notes')->where(['pv_id' => $this->pv, 'etudiant_id' => $a, 'matiere_id' => $this->m2])->first(['note_examen', 'note_finale'])));
        $r = DB::table('repechages')->first();
        $this->assertSame(['2.00', '3.00', '5.00', 'Elim', 'Admis', 'Décision du jury du 22/10', $this->admin->id, 0],
            [$r->points, $r->note_examen_avant, $r->note_examen_apres, $r->decision_avant, $r->decision_apres, $r->motif, (int) $r->fait_par, (int) $r->annule]);
        $this->assertDatabaseHas('audit_log', ['action' => 'repecher', 'module' => 'notes']);
        // Visible sur le PV (liste et marque « R ») et sur le PV imprimé
        $this->get($this->url("pv/{$this->pv}"))->assertOk()->assertSee('Repêchages du jury — 1 accordé(s)')->assertSee('Décision du jury du 22/10')
            ->assertSee('Repêché : 3.00 → 5.00', false);
        $this->get($this->url("pv/impression?id={$this->pv}"))->assertOk()->assertSee('Repêchages accordés par le jury')->assertSee('3.00 → 5.00 (+2)', false);

        // Annulation : note d'origine rétablie, trace conservée (marquée annulée)
        $this->post($this->url("pv/{$this->pv}/repechages/{$r->id}/annuler"))->assertSessionHas('succes');
        $this->assertSame('Elim', $decision());
        $this->assertSame('3.00', DB::table('notes')->where(['pv_id' => $this->pv, 'etudiant_id' => $a, 'matiere_id' => $this->m2])->value('note_examen'));
        $this->assertSame([1, 1], [(int) DB::table('repechages')->value('annule'), DB::table('repechages')->count()]);
        $this->post($this->url("pv/{$this->pv}/repechages/{$r->id}/annuler"))->assertSessionHas('erreur');
        $this->get($this->url("pv/impression?id={$this->pv}"))->assertDontSee('Repêchages accordés par le jury');

        // Note modifiée après un repêchage : l'annulation est refusée (pas d'écrasement)
        $this->post($url, $ok);
        $nouveau = (int) DB::table('repechages')->max('id');
        $this->saisir([$a => [$this->m1 => ['exam' => '24', 'cc' => '12'], $this->m2 => ['exam' => '12']]]);
        $this->post($this->url("pv/{$this->pv}/repechages/$nouveau/annuler"))->assertSessionHas('erreur', fn ($m) => str_contains($m, 'modifiée'));

        // PV verrouillé, ou utilisateur sans droit de gestion : refusé
        DB::table('pv_sessions')->where('id', $this->pv)->update(['verrouille' => 1]);
        $this->post($url, $ok)->assertSessionHas('erreur', fn ($m) => str_contains($m, 'verrouillé'));
        DB::table('pv_sessions')->where('id', $this->pv)->update(['verrouille' => 0]);
        $this->connecte($this->creerUtilisateur('enseignant', ['email' => 'prof@test.local']))->post($url, $ok);
        $this->assertSame(2, DB::table('repechages')->count());
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
        $this->app->forgetInstance(Parametres::class);
        DB::table('pv_sessions')->update(['date_pv' => '2026-02-10']);

        $this->connecte($this->admin)->get($this->url("pv/impression?id={$this->pv}"))->assertOk()
            ->assertSee('RÉSULTATS DES EXAMENS — SEMESTRE 1')->assertSee('ALPHA X')->assertSee('AVANCE Y')
            ->assertSee('Maradi, le 10/02/2026')->assertSee('Admis')
            // Pas de rang sur le PV imprimé ; « RÉSULTAT SEMESTRE » couvre les 4 colonnes restantes
            ->assertDontSee('RANG')->assertDontSee('1/2')->assertSee('<th colspan="4" data-g="r" class="th-resume">RÉSULTAT SEMESTRE</th>', false)
            // Tableau réduit pour tenir dans la largeur du papier (A4 paysage par défaut : 297 − 16 − 12 = 269 mm)
            ->assertSee('<div class="pv-zone" data-largeur-papier-mm="269">', false)
            // Groupes de colonnes (identité, UE, résultat) pour le découpage en bandes à l'impression
            ->assertSee('<th rowspan="3" data-g="f"', false)->assertSee('data-g="0" class="th-ue"', false)->assertSee('<th colspan="4" data-g="r"', false)
            ->assertSee('@page{size:A4 landscape', false)
            ->assertSee('<strong>Elim</strong> = Note éliminatoire', false)
            ->assertSee('assets/js/pv-impression.js', false)->assertSee('<option value="a3" >A3 paysage</option>', false);
        // Format A3 paysage pour les PV très chargés
        $this->get($this->url("pv/impression?id={$this->pv}&format=a3"))->assertOk()
            ->assertSee('<div class="pv-zone" data-largeur-papier-mm="392">', false)->assertSee('@page{size:A3 landscape', false);
        $this->get($this->url("pv/impression?id={$this->pv}&format=nimportequoi"))->assertSee('@page{size:A4 landscape', false);
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

    public function test_saisie_du_pv_de_rattrapage_td_calcule_depuis_l_examen(): void
    {
        $a = $this->etudiant('ALPHA');
        $this->saisir([$a => [$this->m1 => ['exam' => '10', 'cc' => '17'], $this->m2 => ['exam' => '8']]])->assertSessionHas('succes');
        $ratt = DB::table('pv_sessions')->insertGetId(['promotion_id' => $this->promo, 'semestre_id' => $this->sem, 'type_session' => 'rattrapage',
            'libelle' => 'PV S1 rattrapage', 'pv_normale_id' => $this->pv]);

        // Examen 20/40 au rattrapage : TD = 10/20 (le TD saisi et celui de 1ère session, 17, sont ignorés), note 30/60
        $this->saisir([$a => [$this->m1 => ['exam' => '20', 'cc' => '19']]], $ratt)->assertSessionHas('succes');
        $n = DB::table('notes')->where(['pv_id' => $ratt, 'etudiant_id' => $a, 'matiere_id' => $this->m1])->first();
        $this->assertSame(['20.00', '10.00', '30.00'], [$n->note_examen, $n->note_cc, $n->note_finale]);
        // Rattrapage moins bon que la 1ère session : examen, TD et note de 1ère session gardés
        $this->saisir([$a => [$this->m1 => ['exam' => '17', 'cc' => '7']]])->assertSessionHas('succes');   // 1ère session : 17 + 7 = 24
        $this->saisir([$a => [$this->m1 => ['exam' => '12']]], $ratt)->assertSessionHas('succes');           // rattrapage : 12 + 6 = 18 < 24
        $n = DB::table('notes')->where(['pv_id' => $ratt, 'etudiant_id' => $a, 'matiere_id' => $this->m1])->first();
        $this->assertSame(['17.00', '7.00', '24.00'], [$n->note_examen, $n->note_cc, $n->note_finale]);
        // TD affiché, non modifiable, dans le PV de rattrapage
        $this->get($this->url("pv/$ratt"))->assertOk()->assertSee("le TD est la note d'examen ramenée au barème du TD", false)
            ->assertDontSee("notes[$a][{$this->m1}][cc]", false);
    }

    public function test_journal_des_modifications_de_notes(): void
    {
        $a = $this->etudiant('ALPHA');
        $journal = fn () => DB::table('audit_log')->where('action', 'modifier_note')->pluck('description');

        // Première saisie : pas une modification
        $this->saisir([$a => [$this->m1 => ['exam' => '30', 'cc' => '15'], $this->m2 => ['exam' => '12']]])->assertSessionHas('succes');
        $this->assertCount(0, $journal());

        // Modification : ancienne et nouvelle valeur, étudiant, matière, PV, origine
        $this->saisir([$a => [$this->m1 => ['exam' => '30', 'cc' => '15'], $this->m2 => ['exam' => '14.5']]])->assertSessionHas('succes');
        $numero = DB::table('etudiants')->where('id', $a)->value('numero');
        $this->assertSame(["Note modifiée — ALPHA X ($numero) — Statistiques — PV S1 Éco : Examen 12 → 14,5 ; Note finale 12 → 14,5 [saisie du PV]"],
            $journal()->all());
        $this->connecte($this->admin)->get($this->url('logs?action=modifier_note'))->assertOk()->assertSee('Examen 12 → 14,5');

        // Matière vidée : notes supprimées tracées
        $this->post($this->url("pv/{$this->pv}/action"), ['action_pv' => 'vider_matiere', 'matiere_id' => $this->m1])->assertSessionHas('succes');
        $this->assertStringContainsString('Microéconomie — PV S1 Éco : note supprimée (Examen 30, TD 15, Note finale 45) [matière vidée]', $journal()->last());
    }

    public function test_verification_du_releve_affiche_ses_notes(): void
    {
        $a = $this->etudiant('ALPHA');
        $this->saisir([$a => [$this->m1 => ['exam' => '30', 'cc' => '15'], $this->m2 => ['exam' => '15']]]);
        $releve = $this->connecte($this->admin)->get($this->url("releve?pv_id={$this->pv}&et_id=$a"))->assertOk();
        $numero = DB::table('releves_emis')->value('numero');
        // Adresse officielle de vérification imprimée en clair sous le QR code
        $releve->assertSee('Relevé enregistré sous le N° <strong>'.$numero.'</strong> · Code de vérification : <strong>'.app(CleVerification::class)->code($numero).'</strong>', false)->assertSee('comparer les notes affichées')->assertSee('Site officiel')->assertSee('/verification')->assertSee("N° $numero");
        $this->assertNotNull(DB::table('releves_emis_contenu')->where('numero', $numero)->value('contenu'));
        $this->post($this->url('deconnexion'));
        RateLimiter::clear('verification|udh|127.0.0.1');
        $qr = app(CleVerification::class)->url($numero);

        // QR code : notes telles qu'imprimées (M1 45/60 → 15/20 ; M2 15/20), résultat du semestre
        $this->get($qr)->assertOk()->assertSee('Notes du relevé')->assertSee('UE1 — UE 1')->assertSee('Microéconomie')
            ->assertSee('45,00')->assertSee('15,00')->assertSee('6/6')->assertSee("telles qu'imprimées", false);
        // Numéro tapé à la main (sans la clé du QR code) : pas de notes
        $this->get($this->url("verification?num=$numero&nom=ALPHA"))->assertOk()->assertSee('authentique')->assertDontSee('Notes du relevé')->assertDontSee('Microéconomie');
        // Code de vérification imprimé : comme le QR code
        $this->get($this->url("verification?num=$numero&code=".app(CleVerification::class)->code($numero)))->assertOk()->assertSee('Notes du relevé');

        // Note corrigée après l'impression : la vérification montre toujours le relevé imprimé
        $this->saisir([$a => [$this->m1 => ['exam' => '30', 'cc' => '15'], $this->m2 => ['exam' => '12']]]);
        $this->post($this->url('deconnexion'));
        $this->get($qr)->assertOk()->assertSee('15,00')->assertDontSee('12,00');

        // Relevé imprimé avant cette fonction (sans contenu conservé) : notes actuelles, signalées comme telles
        DB::table('releves_emis_contenu')->delete();
        $this->get($qr)->assertOk()->assertSee('12,00')->assertSee('actuellement enregistrées');
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

    public function test_verification_des_resultats(): void
    {
        $a = $this->etudiant('ALPHA');
        $this->etudiant('BETA');
        $this->saisir([$a => [$this->m1 => ['exam' => '30', 'cc' => '15'], $this->m2 => ['exam' => '15']]]);
        $this->connecte($this->admin)->get($this->url("resultats/verification?pv_id={$this->pv}"))->assertOk()->assertSee('tous les résultats sont cohérents');

        // Résultat en base faussé : détecté, sans être modifié par la vérification
        DB::table('resultats_semestre')->where('etudiant_id', $a)->update(['moyenne_generale' => 3, 'valide' => 0]);
        $this->get($this->url("resultats/verification?pv_id={$this->pv}"))->assertOk()->assertSee('1 différence(s) détectée(s)')->assertSee('ALPHA');
        $this->assertEquals(3, DB::table('resultats_semestre')->where('etudiant_id', $a)->value('moyenne_generale'));

        // GET ne corrige plus rien ; POST corrige et journalise
        $this->get($this->url("resultats/verification?pv_id={$this->pv}&fix=1"))->assertOk();
        $this->assertEquals(3, DB::table('resultats_semestre')->where('etudiant_id', $a)->value('moyenne_generale'));
        $this->post($this->url('resultats/verification'), ['pv_id' => $this->pv])->assertRedirect($this->url("resultats/verification?pv_id={$this->pv}"));
        $this->assertSame(1, (int) DB::table('resultats_semestre')->where('etudiant_id', $a)->value('valide'));
        $this->assertDatabaseHas('audit_log', ['action' => 'recalculer', 'module' => 'pv']);

        // PV verrouillé : correction refusée
        DB::table('pv_sessions')->update(['verrouille' => 1]);
        DB::table('resultats_semestre')->where('etudiant_id', $a)->update(['valide' => 0]);
        $this->post($this->url('resultats/verification'), ['pv_id' => $this->pv])->assertSessionHas('erreur');
        $this->assertSame(0, (int) DB::table('resultats_semestre')->where('etudiant_id', $a)->value('valide'));

        // Autre faculté / rôle non autorisé
        $facA = $this->insererMinimal('facultes', ['code' => 'FA', 'libelle' => 'A']);
        $facB = $this->insererMinimal('facultes', ['code' => 'FB', 'libelle' => 'B']);
        DB::table('filieres')->update(['faculte_id' => $facB]);
        $this->donnerPermissions('sc_entite', ['inscription_ped']);
        $this->connecte($this->creerUtilisateur('sc_entite', ['faculte_id' => $facA]))->get($this->url("resultats/verification?pv_id={$this->pv}"))
            ->assertRedirect($this->url('resultats/verification'));
        $this->donnerPermissions('doyen', ['inscription_ped']);
        $this->connecte($this->creerUtilisateur('doyen'))->get($this->url('resultats/verification'))->assertRedirect($this->url('accueil'));
    }

    public function test_liste_des_admis(): void
    {
        $a = $this->etudiant('ALPHA');
        $b = $this->etudiant('BETA');
        $this->saisir([
            $a => [$this->m1 => ['exam' => '30', 'cc' => '15'], $this->m2 => ['exam' => '15']],
            $b => [$this->m1 => ['exam' => '30', 'cc' => '15']],
        ]);
        $this->connecte($this->admin)->get($this->url('admis'))->assertOk()->assertSee('2025-2026')->assertSee('Économie — ', false);
        $this->get($this->url("admis?pv_id={$this->pv}"))->assertOk()->assertSee('Ont définitivement validé le semestre 1')
            ->assertSee('ALPHA')->assertDontSee('BETA');

        $facA = $this->insererMinimal('facultes', ['code' => 'FA', 'libelle' => 'A']);
        $facB = $this->insererMinimal('facultes', ['code' => 'FB', 'libelle' => 'B']);
        DB::table('filieres')->update(['faculte_id' => $facB]);
        $this->donnerPermissions('chef_departement', ['pv_voir']);
        $chef = $this->creerUtilisateur('chef_departement', ['faculte_id' => $facA]);
        $this->connecte($chef)->get($this->url('admis'))->assertOk()->assertDontSee('Économie —', false);
        $this->get($this->url("admis?pv_id={$this->pv}"))->assertRedirect($this->url('admis'));
    }

    public function test_convocations(): void
    {
        $a = $this->etudiant('ALPHA');
        $hors = DB::table('etudiants')->insertGetId(['nom' => 'HORSPV', 'prenoms' => 'Z', 'numero' => '55555']);
        DB::table('planning_examens')->insert(['promotion_id' => $this->promo, 'semestre_id' => $this->sem, 'session' => 'normale', 'matiere_id' => $this->m1,
            'date_examen' => '2026-01-15', 'heure_debut' => '08:00:00', 'heure_fin' => '10:00:00', 'salle' => 'Amphi A']);
        // Planning du rattrapage : pas sur la convocation de la session normale
        DB::table('planning_examens')->insert(['promotion_id' => $this->promo, 'semestre_id' => $this->sem, 'session' => 'rattrapage', 'matiere_id' => $this->m2,
            'date_examen' => '2026-03-20', 'heure_debut' => '08:00:00', 'heure_fin' => '10:00:00', 'salle' => 'Amphi R']);
        $this->connecte($this->admin)->get($this->url('convocations'))->assertOk()->assertSee('PV S1 Éco');
        $this->get($this->url("convocations?pv_id={$this->pv}&masse=1"))->assertOk()
            ->assertSee('Convocation aux Examens')->assertSee('ALPHA X')->assertSee('15/01/2026')->assertSee('Amphi A')->assertSee('Microéconomie')
            ->assertDontSee('Amphi R');
        $this->get($this->url("convocations?pv_id={$this->pv}&et_id=$a"))->assertOk()->assertSee('Convocation individuelle');
        // Numéro enregistré une fois par étudiant et par PV, repris à la réimpression
        $numero = DB::table('convocations_emises')->where(['etudiant_id' => $a, 'pv_id' => $this->pv])->value('numero');
        $this->assertMatchesRegularExpression('/^CV\d{2}-\d{6}$/', $numero);
        $this->get($this->url("convocations?pv_id={$this->pv}&et_id=$a"))->assertSee("N° $numero");
        $this->assertSame(1, DB::table('convocations_emises')->where('etudiant_id', $a)->count());
        $this->get($this->url("convocations?pv_id={$this->pv}&et_id=$hors"))->assertRedirect($this->url('convocations'));

        $facA = $this->insererMinimal('facultes', ['code' => 'FA', 'libelle' => 'A']);
        $facB = $this->insererMinimal('facultes', ['code' => 'FB', 'libelle' => 'B']);
        DB::table('filieres')->update(['faculte_id' => $facB]);
        $this->donnerPermissions('sc_entite', ['pv_creer']);
        $this->connecte($this->creerUtilisateur('sc_entite', ['faculte_id' => $facA]))->get($this->url("convocations?pv_id={$this->pv}&masse=1"))
            ->assertRedirect($this->url('convocations'));
    }
}
