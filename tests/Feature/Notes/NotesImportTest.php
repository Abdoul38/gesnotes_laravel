<?php

namespace Tests\Feature\Notes;

use App\Models\Utilisateur;
use App\Support\Tableur;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class NotesImportTest extends TestCase
{
    private Utilisateur $admin;

    private int $annee;

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
        $this->promo = $this->creerPromotion($this->annee);
        $this->sem = DB::table('semestres')->insertGetId(['code' => 'S1', 'libelle' => 'Semestre 1']);
        $ue = DB::table('unites_enseignement')->insertGetId(['promotion_id' => $this->promo, 'semestre_id' => $this->sem, 'code' => 'UE1', 'libelle' => 'UE 1', 'credits_total' => 6, 'ordre' => 1]);
        $this->m1 = DB::table('matieres')->insertGetId(['ue_id' => $ue, 'code' => 'M1', 'libelle' => 'Microéconomie', 'note_max_examen' => 40, 'note_max_cc' => 20, 'note_max_totale' => 60, 'credits' => 3, 'ordre' => 1]);
        $this->m2 = DB::table('matieres')->insertGetId(['ue_id' => $ue, 'code' => 'M2', 'libelle' => 'Statistiques', 'note_max_examen' => 20, 'note_max_cc' => 0, 'note_max_totale' => 20, 'credits' => 3, 'ordre' => 2]);
        $this->pv = DB::table('pv_sessions')->insertGetId(['promotion_id' => $this->promo, 'semestre_id' => $this->sem, 'type_session' => 'normale', 'libelle' => 'PV S1']);
    }

    private function etudiant(string $numero, string $nom): int
    {
        $et = DB::table('etudiants')->insertGetId(['nom' => $nom, 'prenoms' => 'X', 'numero' => $numero]);
        $ins = DB::table('inscriptions')->insertGetId(['etudiant_id' => $et, 'promotion_id' => $this->promo, 'statut' => 'inscrit', 'date_inscription' => '2025-10-01']);
        DB::table('inscriptions_pedagogiques')->insert(['etudiant_id' => $et, 'inscription_id' => $ins, 'semestre_id' => $this->sem, 'annee_id' => $this->annee]);

        return $et;
    }

    private function importer(int $mat, $fichier, ?int $pv = null, ?Utilisateur $u = null)
    {
        return $this->connecte($u ?? $this->admin)->post($this->url('notes/import/matiere'), ['pv_id' => $pv ?? $this->pv, 'mat_id' => $mat, 'fichier' => $fichier]);
    }

    public function test_modele_puis_import_avec_recalcul(): void
    {
        $a = $this->etudiant('00012', 'ALPHA');
        $b = $this->etudiant('00013', 'BRAVO');
        $this->etudiant('00014', 'CHARLIE');

        $modele = $this->connecte($this->admin)->get($this->url("notes/import/modele?pv_id={$this->pv}&mat_id={$this->m1}"))->assertOk()
            ->assertDownload('template_notes_Micro_conomie.xlsx');
        $lignes = Tableur::xlsx($modele->baseResponse->getFile()->getPathname());
        $this->assertSame(['N° Etudiant', 'Nom', 'Prénoms', 'Examen/40', 'TD/20'], $lignes[2]);
        $this->assertSame('00012', $lignes[3][0]);

        // Modèle rempli : 12 sans zéros, décimale à virgule, une ligne vide, un inconnu
        $fichier = $this->fichierXlsx([1 => ['Microéconomie'], 2 => $lignes[2],
            3 => ['12', 'ALPHA', 'X', '30,5', '15'], 4 => ['00013', 'BRAVO', 'X', '', ''], 5 => ['99999', 'INCONNU', 'X', '10', '10']]);
        $this->importer($this->m1, $fichier)->assertRedirect($this->url("notes/import?pv_id={$this->pv}"))
            ->assertSessionHas('resultat_import', fn ($r) => $r['ok'] === 1 && $r['ignorees'] === 1 && $r['erreurs'] === 1);

        $n = DB::table('notes')->where(['etudiant_id' => $a, 'matiere_id' => $this->m1])->first();
        $this->assertSame(['30.50', '15.00', '45.50'], [$n->note_examen, $n->note_cc, $n->note_finale]);
        // Résultats recalculés (l'ancien import par matière ne le faisait pas)
        $this->assertDatabaseHas('resultats_semestre', ['pv_id' => $this->pv, 'etudiant_id' => $a]);
        $this->assertDatabaseMissing('notes', ['etudiant_id' => $b]);
    }

    public function test_note_hors_bornes_et_note_acquise(): void
    {
        $a = $this->etudiant('00012', 'ALPHA');
        $b = $this->etudiant('00013', 'BRAVO');
        DB::table('notes')->insert(['pv_id' => $this->pv, 'etudiant_id' => $b, 'matiere_id' => $this->m2, 'note_examen' => 15, 'note_finale' => 15, 'observation' => 'AV']);

        $csv = "N° Etudiant;Nom;Prénoms;Examen/20\n00012;ALPHA;X;25\n00013;BRAVO;X;2\n";
        $this->importer($this->m2, $this->fichierTexte($csv))
            ->assertSessionHas('resultat_import', fn ($r) => $r['erreurs'] === 1 && $r['ignorees'] === 1 && str_contains($r['lignes'][0], 'hors de 0 à 20'));
        $this->assertDatabaseMissing('notes', ['etudiant_id' => $a]);
        $this->assertSame('15.00', DB::table('notes')->where('etudiant_id', $b)->value('note_examen'));
    }

    public function test_rattrapage_meilleure_note_et_absents(): void
    {
        $a = $this->etudiant('00012', 'ALPHA');
        $b = $this->etudiant('00013', 'BRAVO');
        DB::table('notes')->insert([
            ['pv_id' => $this->pv, 'etudiant_id' => $a, 'matiere_id' => $this->m2, 'note_examen' => 8, 'note_finale' => 8, 'observation' => 'NV'],
            ['pv_id' => $this->pv, 'etudiant_id' => $b, 'matiere_id' => $this->m2, 'note_examen' => 7, 'note_finale' => 7, 'observation' => 'NV'],
        ]);
        DB::table('resultats_semestre')->insert([['pv_id' => $this->pv, 'etudiant_id' => $a, 'valide' => 0], ['pv_id' => $this->pv, 'etudiant_id' => $b, 'valide' => 0]]);
        $ratt = DB::table('pv_sessions')->insertGetId(['promotion_id' => $this->promo, 'semestre_id' => $this->sem, 'type_session' => 'rattrapage', 'pv_normale_id' => $this->pv, 'libelle' => 'Ratt']);

        // ALPHA fait moins bien au rattrapage : sa note de 1ère session est gardée ; BRAVO absent
        $this->importer($this->m2, $this->fichierTexte("N° Etudiant;Nom;Prénoms;Examen\n00012;ALPHA;X;5\n"), $ratt)
            ->assertSessionHas('resultat_import', fn ($r) => $r['ok'] === 1 && $r['absents'] === 1);
        $this->assertSame('8.00', DB::table('notes')->where(['pv_id' => $ratt, 'etudiant_id' => $a, 'matiere_id' => $this->m2])->value('note_examen'));
        $absent = DB::table('notes')->where(['pv_id' => $ratt, 'etudiant_id' => $b, 'matiere_id' => $this->m2])->first();
        $this->assertSame([null, 1], [$absent->note_examen, (int) $absent->defaillant]);
        $this->assertSame('Défaillant', DB::table('resultats_semestre')->where(['pv_id' => $ratt, 'etudiant_id' => $b])->value('decision'));
    }

    public function test_export_puis_reimport_du_pv_complet(): void
    {
        $a = $this->etudiant('00012', 'ALPHA');
        $b = $this->etudiant('00013', 'BRAVO');
        DB::table('notes')->insert([
            ['pv_id' => $this->pv, 'etudiant_id' => $a, 'matiere_id' => $this->m1, 'note_examen' => 30, 'note_cc' => 15, 'note_finale' => 45],
            ['pv_id' => $this->pv, 'etudiant_id' => $a, 'matiere_id' => $this->m2, 'note_examen' => 14, 'note_cc' => null, 'note_finale' => 14],
        ]);
        app(\App\Services\Resultats::class)->recalculer($this->pv, $a);

        $export = $this->connecte($this->admin)->get($this->url("pv/export?id={$this->pv}"))->assertOk()->assertDownload();
        $chemin = $export->baseResponse->getFile()->getPathname();
        $lignes = Tableur::xlsx($chemin);
        $this->assertSame(['Exam./40', 'TD/20', 'Note/60', 'Crédits(3)', 'Obs.', 'Exam./20', 'Crédits(3)', 'Obs.'], array_slice($lignes[4], 3, 8));
        $ligneA = collect($lignes)->first(fn ($l) => ($l[1] ?? '') === '00012');
        $this->assertSame(['00012', 'ALPHA X', '30', '15', '45', '3', 'AV'], array_slice($ligneA, 1, 7));
        $zip = new \ZipArchive;
        $zip->open($chemin);
        $this->assertStringContainsString('<pane ySplit="4"', $zip->getFromName('xl/worksheets/sheet1.xml'));   // en-têtes figés
        $zip->close();

        // On complète BRAVO dans le fichier et on le réimporte
        $numeroLigne = array_search($ligneA, $lignes, true) + 1;
        $lignes[$numeroLigne] = array_replace($lignes[$numeroLigne], [1 => '00013', 2 => 'BRAVO X', 3 => '20', 4 => '10', 8 => '9']);
        $this->post($this->url('notes/import/pv'), ['pv_id' => $this->pv, 'fichier_pv' => $this->fichierXlsx($lignes, 'pv.xlsx')])
            ->assertRedirect($this->url("pv/{$this->pv}"))->assertSessionHas('succes', '✅ Import PV complet réussi — 1 étudiant(s) mis à jour.');

        $notesB = DB::table('notes')->where(['pv_id' => $this->pv, 'etudiant_id' => $b])->orderBy('matiere_id')->get();
        $this->assertSame([['20.00', '10.00', '30.00'], ['9.00', null, '9.00']], $notesB->map(fn ($n) => [$n->note_examen, $n->note_cc, $n->note_finale])->all());
        $this->assertDatabaseHas('resultats_semestre', ['pv_id' => $this->pv, 'etudiant_id' => $b]);
        // ALPHA, déjà acquis (AV), n'a pas été retouché
        $this->assertSame('30.00', DB::table('notes')->where(['etudiant_id' => $a, 'matiere_id' => $this->m1])->value('note_examen'));
    }

    public function test_droits(): void
    {
        $this->etudiant('00012', 'ALPHA');
        $ens = DB::table('enseignants')->insertGetId(['nom' => 'PROF', 'prenoms' => 'X']);
        DB::table('affectations')->insert(['enseignant_id' => $ens, 'matiere_id' => $this->m2, 'annee_id' => $this->annee, 'type_charge' => 'cours']);
        $this->donnerPermissions('enseignant', ['inscription_ped', 'notes_saisir', 'pv_voir']);
        $prof = $this->creerUtilisateur('enseignant', ['enseignant_id' => $ens]);

        // Sa matière : oui ; une autre matière : non ; PV complet : non
        $this->importer($this->m2, $this->fichierTexte("N° Etudiant;Nom;Prénoms;Examen\n00012;ALPHA;X;12\n"), null, $prof)->assertSessionHas('resultat_import');
        $this->importer($this->m1, $this->fichierTexte("N° Etudiant;Nom;Prénoms;Examen;TD\n00012;ALPHA;X;12;12\n"), null, $prof)->assertSessionHas('erreur');
        $this->post($this->url('notes/import/pv'), ['pv_id' => $this->pv, 'fichier_pv' => $this->fichierTexte('x')])
            ->assertSessionHas('erreur', "Vous n'êtes pas autorisé à importer le PV complet.");
        $this->assertSame(1, DB::table('notes')->whereNotNull('note_examen')->count());

        DB::table('pv_sessions')->update(['verrouille' => 1]);
        $this->connecte($this->admin)->get($this->url("notes/import?pv_id={$this->pv}"))->assertRedirect($this->url('notes'));
    }
}
