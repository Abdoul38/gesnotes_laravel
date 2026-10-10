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

    public function test_regles_du_rattrapage_matiere_avec_td(): void
    {
        // 1ère session de M1 (Exam./40 + TD/20 = /60) : tous non validés, sauf ECHO qui a acquis M1
        $s1 = ['ALPHA' => [10, 5], 'BRAVO' => [18, 6], 'CHARLIE' => [12, 4], 'DELTA' => [12, 4], 'ECHO' => [40, 20]];
        $ids = [];
        foreach ($s1 as $nom => [$exam, $td]) {
            $ids[$nom] = $this->etudiant('000'.(count($ids) + 21), $nom);
            DB::table('notes')->insert(['pv_id' => $this->pv, 'etudiant_id' => $ids[$nom], 'matiere_id' => $this->m1, 'note_examen' => $exam, 'note_cc' => $td,
                'note_finale' => $exam + $td, 'observation' => $nom === 'ECHO' ? 'AV' : 'NV']);
            DB::table('notes')->insert(['pv_id' => $this->pv, 'etudiant_id' => $ids[$nom], 'matiere_id' => $this->m2, 'note_examen' => 6, 'note_finale' => 6, 'observation' => 'NV']);
            DB::table('resultats_semestre')->insert(['pv_id' => $this->pv, 'etudiant_id' => $ids[$nom], 'valide' => 0]);
        }
        $ratt = DB::table('pv_sessions')->insertGetId(['promotion_id' => $this->promo, 'semestre_id' => $this->sem, 'type_session' => 'rattrapage', 'pv_normale_id' => $this->pv, 'libelle' => 'Ratt']);
        DB::table('notes')->insert(['pv_id' => $ratt, 'etudiant_id' => $ids['ECHO'], 'matiere_id' => $this->m1, 'note_examen' => 40, 'note_cc' => 20, 'note_finale' => 60, 'observation' => 'AV']);

        $this->importer($this->m1, $this->fichierTexte("N° Etudiant;Nom;Prénoms;Examen/40;TD/20\n"
            ."00021;ALPHA;X;30;\n"        // a composé, mieux qu'en 1ère session : 30 + TD de 1ère session (5) = 35
            ."00022;BRAVO;X;10;\n"        // a composé, moins bien : 10 + 6 = 16 < 24 → la 1ère session (24) est gardée
            ."00024;DELTA;X;;15\n"        // examen vide (TD saisi) : n'a pas composé → note écrasée
            ."00025;ECHO;X;5;0\n"),        // matière acquise en 1ère session : ignorée
            $ratt)->assertSessionHas('resultat_import', fn ($r) => $r['ok'] === 3 && $r['absents'] === 1);   // CHARLIE, absent du fichier

        $note = fn ($nom) => DB::table('notes')->where(['pv_id' => $ratt, 'etudiant_id' => $ids[$nom], 'matiere_id' => $this->m1])->first();
        $this->assertSame(['30.00', '5.00', '35.00', 0], [$note('ALPHA')->note_examen, $note('ALPHA')->note_cc, $note('ALPHA')->note_finale, (int) $note('ALPHA')->defaillant]);
        $this->assertSame(['18.00', '6.00', '24.00'], [$note('BRAVO')->note_examen, $note('BRAVO')->note_cc, $note('BRAVO')->note_finale]);
        foreach (['CHARLIE', 'DELTA'] as $absent) {
            $this->assertSame([null, null, 1], [$note($absent)->note_examen, $note($absent)->note_finale, (int) $note($absent)->defaillant], $absent);
            $this->assertSame('Défaillant', DB::table('resultats_semestre')->where(['pv_id' => $ratt, 'etudiant_id' => $ids[$absent]])->value('decision'));
        }
        $this->assertSame('60.00', $note('ECHO')->note_finale);
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
        // Filtres d'Excel sur chaque colonne (ligne 4) ; la ligne 4 n'est plus fusionnée avec la ligne 3
        $this->assertStringContainsString('<autoFilter ref="A4:U6"/>', $zip->getFromName('xl/worksheets/sheet1.xml'));
        $this->assertStringContainsString('<definedName name="_xlnm._FilterDatabase" localSheetId="0" hidden="1">PV!$A$4:$U$6</definedName>', $zip->getFromName('xl/workbook.xml'));
        $this->assertStringNotContainsString('S3:S4', $zip->getFromName('xl/worksheets/sheet1.xml'));
        $this->assertSame(['N°', 'Matricule', 'Nom & Prénoms'], array_slice($lignes[4], 0, 3));
        $this->assertSame(['Total/80', 'Moy./20', 'Crédits (6)', 'Validée', 'Résultat UE', 'Crédits', 'Moyenne', 'Décision', 'Mention', 'Rang'], array_slice($lignes[4], 11, 10));
        // Matières regroupées par UE : nom de l'UE une fois au-dessus de ses colonnes (matières et résultats), matières repliables
        $this->assertSame('UE1 — UE 1 (6 crédits)', $lignes[2][3]);
        $this->assertSame(['', '', '', '', '', '', '', ''], array_slice($lignes[2], 4, 8));
        $this->assertSame("Résultats de l'UE", $lignes[3][11]);
        $feuille = (string) $zip->getFromName('xl/worksheets/sheet1.xml');
        foreach (['D2:P2', 'D3:H3', 'I3:K3', 'L3:P3'] as $zone) {
            $this->assertStringContainsString('<mergeCell ref="'.$zone.'"/>', $feuille);
        }
        $this->assertStringContainsString('<sheetPr><outlinePr summaryRight="1"/><pageSetUpPr fitToPage="1"/></sheetPr>', $feuille);
        // Feuille protégée : seules les cases Examen/TD (style 17, déverrouillé) se modifient ; ALPHA (AV) reste verrouillé
        $this->assertMatchesRegularExpression('/<sheetProtection algorithmName="SHA-512" hashValue="[^"]+" saltValue="[^"]+" spinCount="100000" sheet="1" objects="1" scenarios="1" formatColumns="0" autoFilter="0"\/>/', $feuille);
        $this->assertStringContainsString('<c r="D5" s="0"><v>30</v></c>', $feuille);
        $this->assertStringContainsString('<c r="D6" s="17"/>', $feuille);
        $this->assertStringContainsString('<c r="E6" s="17"/>', $feuille);
        $this->assertStringContainsString('<c r="I6" s="17"/>', $feuille);
        $this->assertStringContainsString('<protection locked="0"/>', (string) $zip->getFromName('xl/styles.xml'));
        // Notes refusées hors de 0 au maximum : Examen/40, TD/20, Examen/20
        foreach (['D5:D6' => 40, 'E5:E6' => 20, 'I5:I6' => 20] as $plage => $max) {
            $this->assertMatchesRegularExpression('/<dataValidation type="decimal" operator="between" allowBlank="1"[^>]*sqref="'.$plage.'"><formula1>0<\/formula1><formula2>'.$max.'<\/formula2><\/dataValidation>/', $feuille);
        }
        $this->assertStringContainsString('error="Examen — Microéconomie : saisir une note de 0 à 40."', $feuille);
        // Structure du classeur libre : la feuille PV se copie (impression) ; impression A4 paysage, une page en largeur, en-têtes répétés
        $this->assertStringNotContainsString('workbookProtection', (string) $zip->getFromName('xl/workbook.xml'));
        $this->assertStringContainsString('<definedName name="_xlnm.Print_Titles" localSheetId="0">PV!$1:$4</definedName>', (string) $zip->getFromName('xl/workbook.xml'));
        $this->assertStringContainsString('<pageSetUpPr fitToPage="1"/>', $feuille);
        $this->assertStringContainsString('<pageSetup paperSize="9" orientation="landscape" fitToWidth="1" fitToHeight="0"/>', $feuille);
        $this->assertSame(8, substr_count($feuille, 'outlineLevel="1"/>'));                     // D à K : les 8 colonnes des matières
        $this->assertStringContainsString('<col min="11" max="11" width="6" customWidth="1" outlineLevel="1"/><col min="12" max="12" width="7" customWidth="1"/>', $feuille);
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

    public function test_export_avec_formules_des_regles_du_moteur(): void
    {
        // UE1 : M1 (Exam./40 + TD/20 = /60, 3 crédits), M2 (/20, 3 crédits) ; note éliminatoire 5/20
        $cas = [
            'ALPHA' => [30, 15, 14],     // tout validé → Admis
            'BRAVO' => [20, 10, 9],      // UE 9,75 → Ajourné
            'CHARLIE' => [38, 18, 4],    // 4/20 < 5 → Défaillant, même avec une UE à 15
            'DELTA' => [28, 12, 8],      // UE 12 → M2 compensée (AC) → Admis
            'ECHO' => [30, 15, null],    // examen M2 absent → Défaillant
            'FOXTROT' => [40, 20, 5],    // 5/20 n'est pas sous la note éliminatoire → compensée → Admis
        ];
        $ids = [];
        foreach (array_keys($cas) as $i => $nom) {
            $ids[$nom] = $this->etudiant(sprintf('%05d', $i + 1), $nom);
            [$exam, $td, $m2] = $cas[$nom];
            DB::table('notes')->insert([
                ['pv_id' => $this->pv, 'etudiant_id' => $ids[$nom], 'matiere_id' => $this->m1, 'note_examen' => $exam, 'note_cc' => $td, 'note_finale' => $exam + $td],
                ['pv_id' => $this->pv, 'etudiant_id' => $ids[$nom], 'matiere_id' => $this->m2, 'note_examen' => $m2, 'note_cc' => null, 'note_finale' => $m2],
            ]);
            app(\App\Services\Resultats::class)->recalculer($this->pv, $ids[$nom]);
        }
        app(\App\Services\Resultats::class)->recalculerRangs($this->pv);
        $this->assertSame(['Admis', 'Ajourné', 'Défaillant', 'Admis', 'Défaillant', 'Admis'],
            array_map(fn ($id) => DB::table('resultats_semestre')->where('etudiant_id', $id)->value('decision'), array_values($ids)));

        $this->connecte($this->admin);
        $lire = fn ($reponse) => $reponse->assertOk()->assertDownload()->baseResponse->getFile()->getPathname();
        $valeurs = Tableur::xlsx($lire($this->get($this->url("pv/export?id={$this->pv}"))));
        $chemin = $lire($this->get($this->url("pv/export?id={$this->pv}&formules=1")));
        $this->assertEquals(array_slice($valeurs, 1, null, true), array_slice(Tableur::xlsx($chemin), 1, null, true));

        $zip = new \ZipArchive;
        $zip->open($chemin);
        $pv = (string) $zip->getFromName('xl/worksheets/sheet1.xml');
        $calculs = (string) $zip->getFromName('xl/worksheets/sheet2.xml');
        $textes = (string) $zip->getFromName('xl/sharedStrings.xml');
        // Feuille « Calculs » invisible (ne se réaffiche pas depuis Excel) et protégée
        $this->assertStringContainsString('<sheet name="Calculs" sheetId="2" state="veryHidden"', (string) $zip->getFromName('xl/workbook.xml'));
        $this->assertStringContainsString('<sheetProtection', (string) $zip->getFromName('xl/worksheets/sheet2.xml'));
        $zip->close();
        $this->assertNotFalse((new \DOMDocument)->loadXML($calculs));
        // Feuille PV, ligne de CHARLIE (7) : observation, crédits, validation de l'UE et décision en formules
        foreach (['<f>IF(NOT(ISNUMBER(I7)),&quot;Déf.&quot;,IF(Calculs!I7,&quot;AC&quot;,IF(Calculs!H7,&quot;AV&quot;,&quot;NV&quot;)))</f><v>NV</v>',
            '<f>IF(OR(K7=&quot;AV&quot;,K7=&quot;AC&quot;),3,0)</f><v>0</v>', '<f>Calculs!O7&amp;&quot;/6&quot;</f><v>3/6</v>',
            '<f>IF(Calculs!P7,&quot;Défaillant&quot;,IF(Calculs!S7&gt;=6,&quot;Admis&quot;,&quot;Ajourné&quot;))</f><v>Défaillant</v>'] as $attendu) {
            $this->assertStringContainsString($attendu, $pv);
        }
        // Feuille Calculs : note éliminatoire strictement inférieure à 5/20 (M2 sur 20 → 5 ; M1 sur 60 → 15)
        $this->assertStringContainsString('<f>OR(NOT(ISNUMBER(PV!I7)),IF(ISNUMBER(PV!I7),PV!I7&lt;5,FALSE))</f><v>1</v>', $calculs);
        $this->assertStringContainsString('PV!F7&lt;15,FALSE)', $calculs);
        $this->assertStringContainsString('strictement inférieure à 5/20', $textes);
        // Chaque ligne a reçu les formules des règles
        $this->assertSame(6, substr_count($pv, '&quot;Défaillant&quot;,IF(Calculs!'));
    }

    public function test_export_avec_formules_memes_valeurs_que_le_pv(): void
    {
        $a = $this->etudiant('00012', 'ALPHA');
        $b = $this->etudiant('00013', 'BRAVO');
        DB::table('notes')->insert([
            ['pv_id' => $this->pv, 'etudiant_id' => $a, 'matiere_id' => $this->m1, 'note_examen' => 30, 'note_cc' => 15, 'note_finale' => 45],
            ['pv_id' => $this->pv, 'etudiant_id' => $a, 'matiere_id' => $this->m2, 'note_examen' => 14, 'note_cc' => null, 'note_finale' => 14],
            ['pv_id' => $this->pv, 'etudiant_id' => $b, 'matiere_id' => $this->m1, 'note_examen' => 20, 'note_cc' => 10, 'note_finale' => 30],
            ['pv_id' => $this->pv, 'etudiant_id' => $b, 'matiere_id' => $this->m2, 'note_examen' => 9, 'note_cc' => null, 'note_finale' => 9],
        ]);
        foreach ([$a, $b] as $et) {
            app(\App\Services\Resultats::class)->recalculer($this->pv, $et);
        }
        app(\App\Services\Resultats::class)->recalculerRangs($this->pv);
        $this->connecte($this->admin);
        $lire = fn ($reponse) => $reponse->assertOk()->assertDownload()->baseResponse->getFile()->getPathname();
        $valeurs = Tableur::xlsx($lire($this->get($this->url("pv/export?id={$this->pv}"))));
        $chemin = $lire($this->get($this->url("pv/export?id={$this->pv}&formules=1")));
        $avecFormules = Tableur::xlsx($chemin);

        // Mêmes chiffres que l'export en valeurs (titre mis à part) : l'ouverture et la réimportation voient le PV enregistré
        $this->assertStringContainsString('(avec formules)', $avecFormules[1][0]);
        $this->assertEquals(array_slice($valeurs, 1, null, true), array_slice($avecFormules, 1, null, true));

        $zip = new \ZipArchive;
        $zip->open($chemin);
        $feuille = (string) $zip->getFromName('xl/worksheets/sheet1.xml');
        $classeur = (string) $zip->getFromName('xl/workbook.xml');
        $zip->close();
        $this->assertNotFalse((new \DOMDocument)->loadXML($feuille));
        $this->assertStringContainsString('fullCalcOnLoad="1"', $classeur);
        // Ligne 5 (ALPHA) : note = Exam. + TD, total et moyenne de l'UE, moyenne générale, mention, rang
        foreach (['<c r="F5" s="0"><f>IF(AND(ISNUMBER(D5),ISNUMBER(E5)),D5+E5,&quot;&quot;)</f><v>45</v>',
            '<f>SUM(F5,I5)</f><v>59</v>', '<f>ROUND(L5/80*20,2)</f><v>14.75</v>', '<f>ROUND(AVERAGE(M5),2)</f><v>14.75</v>',
            '<f>IF(S5=&quot;Admis&quot;,IF(R5&gt;=16,&quot;Très bien&quot;,IF(R5&gt;=14,&quot;Bien&quot;', '<f>IF(S5=&quot;Admis&quot;,SUMPRODUCT(($S$5:$S$6=&quot;Admis&quot;)*($R$5:$R$6&gt;R5))+1,&quot;&quot;)</f><v>1</v>'] as $attendu) {
            $this->assertStringContainsString($attendu, $feuille);
        }
        // Validations, observations, crédits et décisions restent des valeurs
        $this->assertStringNotContainsString('<c r="S5" s="14"><f>', $feuille);

        // Une note fixée à la main (réclamation : note finale ≠ Exam. + TD) garde sa valeur, sans formule
        DB::table('notes')->where(['etudiant_id' => $a, 'matiere_id' => $this->m1])->update(['note_finale' => 50]);
        app(\App\Services\Resultats::class)->recalculer($this->pv, $a);
        $zip->open($lire($this->get($this->url("pv/export?id={$this->pv}&formules=1"))));
        $this->assertStringContainsString('<c r="F5" s="0"><v>50</v></c>', (string) $zip->getFromName('xl/worksheets/sheet1.xml'));
        $zip->close();
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
