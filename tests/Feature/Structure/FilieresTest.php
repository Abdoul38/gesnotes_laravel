<?php

namespace Tests\Feature\Structure;

use App\Models\Utilisateur;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class FilieresTest extends TestCase
{
    private Utilisateur $admin;

    private int $fac1;

    private int $fac2;

    private int $annee;

    private int $niveau;

    private int $sem;

    protected function setUp(): void
    {
        parent::setUp();
        $this->installerLicence();
        $this->admin = $this->creerUtilisateur('admin');
        $this->fac1 = DB::table('facultes')->insertGetId(['code' => 'FADEG', 'libelle' => 'Faculté de Droit']);
        $this->fac2 = DB::table('facultes')->insertGetId(['code' => 'FSA', 'libelle' => 'Faculté d\'Agronomie']);
        $this->annee = DB::table('annees_academiques')->insertGetId(['libelle' => '2026-2027', 'active' => 1]);
        $this->niveau = DB::table('niveaux')->insertGetId(['code' => 'L1', 'libelle' => 'Licence 1']);
        $this->sem = DB::table('semestres')->insertGetId(['code' => 'S1', 'libelle' => 'Semestre 1']);
    }

    private function filiere(string $code, ?int $faculte = null): int
    {
        return DB::table('filieres')->insertGetId(['code' => $code, 'libelle' => "Filière $code", 'departement' => '', 'faculte_id' => $faculte ?? $this->fac1, 'actif' => 1]);
    }

    private function promo(int $filiere, ?int $annee = null): int
    {
        return $this->creerPromotion($annee ?? $this->annee, ['filiere_id' => $filiere, 'niveau_id' => $this->niveau]);
    }

    private function ue(int $promo, string $code = 'UE1'): int
    {
        return DB::table('unites_enseignement')->insertGetId(['promotion_id' => $promo, 'semestre_id' => $this->sem, 'code' => $code, 'libelle' => "UE $code", 'credits_total' => 6, 'ordre' => 1]);
    }

    private function matiere(int $ue, string $code = 'M1', array $extra = []): int
    {
        return DB::table('matieres')->insertGetId($extra + ['ue_id' => $ue, 'code' => $code, 'libelle' => "Matière $code", 'note_max_examen' => 40, 'note_max_cc' => 20, 'note_max_totale' => 60, 'credits' => 4, 'ordre' => 1]);
    }

    private function retour(string $tab = 'structure', ?int $promo = null): string
    {
        return $this->url('filieres?'.http_build_query(array_filter(['tab' => $tab, 'promo' => $promo]), '', '&'));
    }

    // ── Affichage ─────────────────────────────────────────────

    public function test_les_trois_onglets_s_affichent(): void
    {
        $f = $this->filiere('ECO');
        DB::table('departements')->insert(['faculte_id' => $this->fac1, 'code' => 'DEP-ECO', 'libelle' => 'Dépt Économie']);
        $ue = $this->ue($this->promo($f));
        $m = $this->matiere($ue, 'MICRO', ['type_matiere' => 'optionnelle']);
        $ens = $this->insererMinimal('enseignants', ['nom' => 'SANI', 'prenoms' => 'Ali']);
        $this->insererMinimal('affectations', ['enseignant_id' => $ens, 'matiere_id' => $m, 'annee_id' => $this->annee]);

        $this->connecte($this->admin)->get($this->url('filieres'))->assertOk()->assertSee('Filière ECO');
        $this->connecte($this->admin)->get($this->url('filieres?tab=departements'))->assertOk()->assertSee('Dépt Économie');
        $this->connecte($this->admin)->get($this->url('filieres?tab=structure'))->assertOk()
            ->assertSee('UE UE1')->assertSee('Matière MICRO')->assertSee('OPT')->assertSee('SANI Ali')->assertSee('Semestre S1');
    }

    // ── Départements ──────────────────────────────────────────

    public function test_departement_creation_et_code_d_une_autre_faculte_refuse(): void
    {
        $this->connecte($this->admin)->post($this->url('departements'), ['faculte_id' => $this->fac1, 'code_dep' => 'dep-a', 'libelle_dep' => 'A', 'tab' => 'departements'])
            ->assertRedirect($this->retour('departements'))->assertSessionHas('succes', 'Département «DEP-A» créé.');

        // Même code dans une autre faculté : l'ancien code écrasait le département de FADEG
        $this->connecte($this->admin)->post($this->url('departements'), ['faculte_id' => $this->fac2, 'code_dep' => 'DEP-A', 'libelle_dep' => 'Écrasé ?'])
            ->assertSessionHas('erreur', "Le code «DEP-A» est déjà utilisé par un département d'une autre faculté.");
        $this->assertDatabaseHas('departements', ['code' => 'DEP-A', 'libelle' => 'A', 'faculte_id' => $this->fac1]);

        // Même faculté : mise à jour, comme avant
        $this->connecte($this->admin)->post($this->url('departements'), ['faculte_id' => $this->fac1, 'code_dep' => 'DEP-A', 'libelle_dep' => 'A modifié', 'chef_dep' => 'Dr X']);
        $this->assertDatabaseHas('departements', ['code' => 'DEP-A', 'libelle' => 'A modifié', 'chef' => 'Dr X']);
    }

    public function test_departement_suppression_bloquee_par_une_filiere(): void
    {
        $dep = DB::table('departements')->insertGetId(['faculte_id' => $this->fac1, 'code' => 'D', 'libelle' => 'D']);
        DB::table('filieres')->where('id', $this->filiere('ECO'))->update(['departement_id' => $dep]);

        $this->connecte($this->admin)->post($this->url('departements/supprimer'), ['id' => $dep, 'tab' => 'departements'])
            ->assertSessionHas('erreur', 'Impossible : 1 filière(s) utilisent ce département.');
    }

    // ── Filières ──────────────────────────────────────────────

    public function test_filiere_creation_avec_libelles_de_compatibilite(): void
    {
        $dep = DB::table('departements')->insertGetId(['faculte_id' => $this->fac1, 'code' => 'D-ECO', 'libelle' => 'Économie et Gestion']);

        $this->connecte($this->admin)->post($this->url('filieres'), [
            'code' => 'eco-ges', 'libelle' => 'Économie', 'faculte_id' => $this->fac1, 'departement_id' => $dep,
            'type_formation' => 'pirate', 'domaine' => 'Sciences éco', 'tab' => 'filieres',
        ])->assertRedirect($this->retour('filieres'))->assertSessionHas('succes', 'Filière «ECO-GES» créée.');

        $this->assertDatabaseHas('filieres', [
            'code' => 'ECO-GES', 'faculte' => 'Faculté de Droit', 'departement' => 'Économie et Gestion',
            'departement_id' => $dep, 'type_formation' => 'classique', 'domaine' => 'Sciences éco', 'mention_academique' => null,
        ]);
    }

    public function test_filiere_erreurs(): void
    {
        $f = $this->filiere('ECO');
        $this->connecte($this->admin)->post($this->url('filieres'), ['code' => 'X', 'libelle' => 'X'])
            ->assertSessionHas('erreur', 'Veuillez sélectionner une faculté.');
        $this->connecte($this->admin)->post($this->url('filieres'), ['code' => 'eco', 'libelle' => 'Doublon', 'faculte_id' => $this->fac1])
            ->assertSessionHas('erreur', 'Le code «ECO» est déjà utilisé par une autre filière.');
        $this->connecte($this->admin)->post($this->url('filieres'), ['id' => $f, 'code' => 'ECO', 'libelle' => 'E', 'faculte_id' => $this->fac1, 'filiere_parente_id' => $f])
            ->assertSessionHas('erreur', 'Une filière ne peut pas être sa propre filière mère.');
    }

    public function test_filiere_suppression_bloquee_puis_autorisee(): void
    {
        $avecPromo = $this->filiere('ECO');
        $this->promo($avecPromo);
        $this->connecte($this->admin)->post($this->url('filieres/supprimer'), ['id' => $avecPromo, 'tab' => 'filieres'])
            ->assertSessionHas('erreur', 'Impossible — cette filière a encore 1 promotion(s).');
        $this->assertDatabaseHas('promotions', ['filiere_id' => $avecPromo]);

        $avecEtudiant = $this->filiere('DRT');
        DB::table('etudiants')->insert(['nom' => 'A', 'prenoms' => 'B', 'filiere_id' => $avecEtudiant]);
        $this->connecte($this->admin)->post($this->url('filieres/supprimer'), ['id' => $avecEtudiant])
            ->assertSessionHas('erreur', 'Impossible — cette filière a encore 1 étudiant(s).');

        $libre = $this->filiere('LIB');
        $this->connecte($this->admin)->post($this->url('filieres/supprimer'), ['id' => $libre])->assertSessionHas('succes', 'Filière supprimée.');
        $this->assertDatabaseMissing('filieres', ['id' => $libre]);
        $this->assertDatabaseHas('journal_audit', ['action' => 'FILIERE_SUPPRIMER', 'enregistrement_id' => $libre]);
    }

    // ── Périmètre faculté ─────────────────────────────────────

    public function test_un_compte_de_faculte_ne_touche_pas_aux_autres_facultes(): void
    {
        $this->donnerPermissions('secretaire', ['inscription_ped']);
        $u = $this->creerUtilisateur('secretaire', ['faculte_id' => $this->fac1]);
        $autre = $this->filiere('AGR', $this->fac2);
        $promoAutre = $this->promo($autre);
        $ueAutre = $this->ue($promoAutre);
        $matAutre = $this->matiere($ueAutre);

        $this->connecte($u)->post($this->url('filieres'), ['id' => $autre, 'code' => 'AGR', 'libelle' => 'Piratée', 'faculte_id' => $this->fac2])->assertForbidden();
        $this->connecte($u)->post($this->url('filieres'), ['code' => 'NEW', 'libelle' => 'X', 'faculte_id' => $this->fac2])->assertForbidden();
        $this->connecte($u)->post($this->url('filieres/supprimer'), ['id' => $autre])->assertForbidden();
        $this->connecte($u)->post($this->url('ue'), ['ue_id_edit' => $ueAutre, 'code_ue' => 'X', 'libelle_ue' => 'X', 'sem_id' => $this->sem])->assertForbidden();
        $this->connecte($u)->post($this->url('matieres/supprimer'), ['mat_id' => $matAutre])->assertForbidden();
        $this->assertDatabaseHas('filieres', ['id' => $autre, 'libelle' => 'Filière AGR']);
        $this->assertSame(1, (int) DB::table('matieres')->where('id', $matAutre)->value('actif'));

        // Une promotion d'une autre faculté demandée dans l'URL n'est pas affichée
        $mienne = $this->promo($this->filiere('ECO'));
        $this->connecte($u)->get($this->url("filieres?tab=structure&promo=$promoAutre"))->assertOk()
            ->assertDontSee('UE UE1')->assertSee('value="'.$mienne.'" selected', false);
    }

    // ── Unités d'enseignement ─────────────────────────────────

    public function test_ue_ajout_mise_a_jour_et_modification(): void
    {
        $promo = $this->promo($this->filiere('ECO'));
        $donnees = ['promo_id' => $promo, 'sem_id' => $this->sem, 'code_ue' => 'UE1', 'libelle_ue' => 'Fondamentaux', 'credits' => 8, 'ordre' => 1, 'nb_options_a_choisir' => -3, 'tab' => 'structure'];

        $this->connecte($this->admin)->post($this->url('ue'), $donnees)
            ->assertRedirect($this->retour('structure', $promo))->assertSessionHas('succes', 'UE «UE1» ajoutée.');
        $this->assertDatabaseHas('unites_enseignement', ['promotion_id' => $promo, 'code' => 'UE1', 'credits_total' => 8, 'nb_options_a_choisir' => 0]);

        // Même code, même promotion et semestre : mise à jour, pas de doublon
        $this->connecte($this->admin)->post($this->url('ue'), ['libelle_ue' => 'Fondamentaux 2'] + $donnees);
        $this->assertSame(1, DB::table('unites_enseignement')->where('promotion_id', $promo)->count());

        $ue = DB::table('unites_enseignement')->value('id');
        $this->connecte($this->admin)->post($this->url('ue'), ['ue_id_edit' => $ue, 'code_ue' => 'UE1-B', 'libelle_ue' => 'Renommée', 'sem_id' => $this->sem, 'nb_options_a_choisir' => 1, 'promo_id_redirect' => $promo])
            ->assertSessionHas('succes', 'UE «UE1-B» mise à jour.');
        $this->assertDatabaseHas('unites_enseignement', ['id' => $ue, 'code' => 'UE1-B', 'nb_options_a_choisir' => 1]);
    }

    public function test_ue_suppression_protege_les_notes(): void
    {
        $promo = $this->promo($this->filiere('ECO'));
        $ue = $this->ue($promo);
        $mat = $this->matiere($ue);

        $this->connecte($this->admin)->post($this->url('ue/supprimer'), ['ue_id_edit' => $ue])
            ->assertSessionHas('erreur', "Impossible — cette UE contient encore 1 matière(s). Supprimez-les d'abord.");

        // Matière désactivée, mais avec une note : la cascade l'effacerait
        DB::table('matieres')->where('id', $mat)->update(['actif' => 0]);
        $pv = $this->insererMinimal('pv_sessions', ['promotion_id' => $promo, 'semestre_id' => $this->sem]);
        $etu = DB::table('etudiants')->insertGetId(['nom' => 'A', 'prenoms' => 'B']);
        $this->insererMinimal('notes', ['pv_id' => $pv, 'etudiant_id' => $etu, 'matiere_id' => $mat, 'note_finale' => 30]);
        $this->connecte($this->admin)->post($this->url('ue/supprimer'), ['ue_id_edit' => $ue])
            ->assertSessionHas('erreur', 'Impossible — des notes ou des résultats sont enregistrés pour cette UE.');
        $this->assertDatabaseHas('notes', ['matiere_id' => $mat]);

        $vide = $this->ue($promo, 'UE2');
        $this->connecte($this->admin)->post($this->url('ue/supprimer'), ['ue_id_edit' => $vide])->assertSessionHas('succes', 'UE supprimée.');
    }

    // ── Matières ──────────────────────────────────────────────

    public function test_matiere_ajout_calcule_le_total_et_reactive_une_matiere_supprimee(): void
    {
        $ue = $this->ue($this->promo($this->filiere('ECO')));
        $donnees = ['ue_id' => $ue, 'code_mat' => 'MICRO', 'libelle_mat' => 'Micro', 'max_exam' => 40, 'max_cc' => 20, 'credits_mat' => 4, 'coef_mat' => 0.1, 'type_matiere' => 'optionnelle', 'volume_prevu_mat' => '30,5'];

        $this->connecte($this->admin)->post($this->url('matieres'), $donnees)->assertSessionHas('succes', 'Matière «MICRO» ajoutée.');
        $this->assertDatabaseHas('matieres', ['code' => 'MICRO', 'note_max_totale' => 60, 'coefficient' => 0.5, 'type_matiere' => 'optionnelle', 'volume_horaire_prevu' => 30.5, 'actif' => 1]);

        $id = DB::table('matieres')->where('code', 'MICRO')->value('id');
        $this->connecte($this->admin)->post($this->url('matieres/supprimer'), ['mat_id' => $id])->assertSessionHas('succes', 'Matière supprimée.');
        $this->assertSame(0, (int) DB::table('matieres')->where('id', $id)->value('actif'));

        $this->connecte($this->admin)->post($this->url('matieres'), ['libelle_mat' => 'Micro (retour)'] + $donnees);
        $this->assertDatabaseHas('matieres', ['id' => $id, 'libelle' => 'Micro (retour)', 'actif' => 1]);
    }

    public function test_volume_horaire_conserve_sans_le_droit_de_le_fixer(): void
    {
        $ue = $this->ue($this->promo($this->filiere('ECO')));
        $mat = $this->matiere($ue, 'M1', ['volume_horaire_prevu' => 24]);
        $this->donnerPermissions('user', ['inscription_ped']);

        $this->connecte($this->creerUtilisateur('user'))->post($this->url('matieres'), ['mat_id' => $mat, 'libelle_mat' => 'Renommée', 'volume_prevu_mat' => ''])
            ->assertSessionHas('succes');
        $this->assertDatabaseHas('matieres', ['id' => $mat, 'libelle' => 'Renommée', 'volume_horaire_prevu' => 24]);
    }

    public function test_volume_en_masse_limite_aux_matieres_sans_volume_et_a_la_faculte(): void
    {
        $m1 = $this->matiere($this->ue($this->promo($this->filiere('ECO'))), 'A');
        $m2 = $this->matiere($this->ue($this->promo($this->filiere('DRT')), 'UE9'), 'B', ['volume_horaire_prevu' => 12]);
        $m3 = $this->matiere($this->ue($this->promo($this->filiere('AGR', $this->fac2)), 'UE8'), 'C');

        $this->donnerPermissions('chef_departement', ['inscription_ped']);
        $chef = $this->creerUtilisateur('chef_departement', ['faculte_id' => $this->fac1]);
        $this->connecte($chef)->post($this->url('matieres/volume-masse'), ['volume_masse' => '30'])
            ->assertSessionHas('succes', "1 matière(s) mise(s) à jour avec 30 h — les matières ayant déjà un volume propre n'ont pas été touchées.");

        $this->assertSame([30.0, 12.0, null], array_map(
            fn ($id) => ($v = DB::table('matieres')->where('id', $id)->value('volume_horaire_prevu')) === null ? null : (float) $v,
            [$m1, $m2, $m3],
        ));

        $this->donnerPermissions('user', ['inscription_ped']);
        $this->connecte($this->creerUtilisateur('user'))->post($this->url('matieres/volume-masse'), ['volume_masse' => '30'])
            ->assertSessionHas('erreur', "Seuls l'administration, le chef de département ou le doyen peuvent fixer le volume horaire.");
    }

    // ── Copie de maquette ─────────────────────────────────────

    public function test_copie_depuis_l_annee_precedente_puis_forcer(): void
    {
        $f = $this->filiere('ECO');
        $precedente = DB::table('annees_academiques')->insertGetId(['libelle' => '2025-2026']);
        $source = $this->promo($f, $precedente);
        $ue = $this->ue($source);
        $this->matiere($ue, 'A');
        $this->matiere($ue, 'B', ['actif' => 0]); // non copiée
        $cible = $this->promo($f);

        $this->connecte($this->admin)->post($this->url('ue/copier'), ['promo_id' => $cible, 'tab' => 'structure'])
            ->assertRedirect($this->retour('structure', $cible))->assertSessionHas('succes', '✅ 1 UE(s) copiées avec succès.');
        $ueCible = DB::table('unites_enseignement')->where('promotion_id', $cible)->value('id');
        $this->assertSame(['A'], DB::table('matieres')->where('ue_id', $ueCible)->pluck('code')->all());

        $this->connecte($this->admin)->post($this->url('ue/copier'), ['promo_id' => $cible])
            ->assertSessionHas('avertissement', '⚠️ Cette promotion a déjà des UE. Cochez « Forcer la copie » pour écraser.');

        DB::table('unites_enseignement')->where('id', $ueCible)->update(['libelle' => 'Modifiée localement']);
        $this->connecte($this->admin)->post($this->url('ue/copier'), ['promo_id' => $cible, 'forcer_copie' => '1'])
            ->assertSessionHas('succes', '✅ 1 UE(s) copiées avec succès.');
        $this->assertSame(['UE UE1'], DB::table('unites_enseignement')->where('promotion_id', $cible)->pluck('libelle')->all());
    }

    public function test_copie_d_une_ue_riche_en_matieres_sans_troncature(): void
    {
        $f = $this->filiere('ECO');
        $source = $this->promo($f, DB::table('annees_academiques')->insertGetId(['libelle' => '2025-2026']));
        $ue = $this->ue($source);
        // ~40 matières à long libellé : GROUP_CONCAT (1 024 caractères) aurait tronqué
        for ($i = 1; $i <= 40; $i++) {
            $this->matiere($ue, "MAT$i", ['libelle' => "Matière au libellé volontairement très long numéro $i", 'ordre' => $i]);
        }
        $cible = $this->promo($f);

        $this->connecte($this->admin)->post($this->url('ue/copier'), ['promo_id' => $cible]);
        $this->assertSame(40, DB::table('matieres')->whereIn('ue_id', DB::table('unites_enseignement')->where('promotion_id', $cible)->pluck('id'))->count());
    }
}
