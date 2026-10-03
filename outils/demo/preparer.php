<?php
// Base de démonstration FICTIVE pour vérifier les écrans dans un navigateur.
// Usage : php outils/demo/preparer.php   puis   UNI_UDH_DB=gesnotes_demo php artisan serve --port=8011
// Les identifiants du compte de démonstration sont écrits dans outils/demo/identifiants.txt (ignoré par git).
chdir(__DIR__.'/../..');
require 'vendor/autoload.php';
$app = require 'bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;

$base = 'gesnotes_demo';
DB::statement("DROP DATABASE IF EXISTS $base");
DB::statement("CREATE DATABASE $base CHARACTER SET utf8mb4");
config(['database.connections.universite.database' => $base]);
DB::purge('universite');
DB::setDefaultConnection('universite');
DB::unprepared(file_get_contents('database/schema/universite-schema.sql'));

$motDePasse = 'Demo-'.bin2hex(random_bytes(4));
DB::table('utilisateurs')->insert(['nom' => 'DEMO', 'prenoms' => 'Admin', 'email' => 'admin@demo.test', 'mot_de_passe' => Hash::make($motDePasse), 'role' => 'admin', 'actif' => 1]);
file_put_contents(__DIR__.'/identifiants.txt', "admin@demo.test\n$motDePasse\n");
// Licence de démonstration (même mécanisme que les tests)
DB::table('parametres')->updateOrInsert(['cle' => 'licence_cle'], ['valeur' => $app->make(App\Services\Licence::class)->generer('UDH', '', 0)]);

mt_srand(7);
$annee = DB::table('annees_academiques')->insertGetId(['libelle' => '2025-2026', 'active' => 1]);
$filiere = DB::table('filieres')->insertGetId(['code' => 'ECO', 'libelle' => 'Économie', 'departement' => 'Économie', 'faculte' => 'FSEG']);
$niveau = DB::table('niveaux')->insertGetId(['code' => 'L1', 'libelle' => 'Licence 1']);
$sem = DB::table('semestres')->insertGetId(['code' => 'S1', 'libelle' => 'Semestre 1']);
$promo = DB::table('promotions')->insertGetId(['filiere_id' => $filiere, 'niveau_id' => $niveau, 'annee_id' => $annee]);
$ue1 = DB::table('unites_enseignement')->insertGetId(['promotion_id' => $promo, 'semestre_id' => $sem, 'code' => 'UE1', 'libelle' => 'Fondamentaux', 'credits_total' => 6, 'ordre' => 1]);
$ue2 = DB::table('unites_enseignement')->insertGetId(['promotion_id' => $promo, 'semestre_id' => $sem, 'code' => 'UE2', 'libelle' => 'Méthodes', 'credits_total' => 4, 'ordre' => 2]);
$mats = [
    DB::table('matieres')->insertGetId(['ue_id' => $ue1, 'code' => 'MIC', 'libelle' => 'Microéconomie', 'note_max_examen' => 40, 'note_max_cc' => 20, 'note_max_totale' => 60, 'credits' => 3, 'ordre' => 1]),
    DB::table('matieres')->insertGetId(['ue_id' => $ue1, 'code' => 'MAC', 'libelle' => 'Macroéconomie', 'note_max_examen' => 40, 'note_max_cc' => 20, 'note_max_totale' => 60, 'credits' => 3, 'ordre' => 2]),
    DB::table('matieres')->insertGetId(['ue_id' => $ue2, 'code' => 'STA', 'libelle' => 'Statistiques', 'note_max_examen' => 20, 'note_max_cc' => 0, 'note_max_totale' => 20, 'credits' => 4, 'ordre' => 1]),
];
$pv = DB::table('pv_sessions')->insertGetId(['promotion_id' => $promo, 'semestre_id' => $sem, 'type_session' => 'normale', 'libelle' => 'PV S1 – L1 Économie – 1ère Session']);
$noms = ['ABDOU', 'BOUBACAR', 'DJIBO', 'GARBA', 'HAMANI', 'ISSA', 'MAHAMANE', 'MOUSSA', 'OUMAROU', 'SALEY', 'YACOUBA', 'ZAKARI'];
foreach ($noms as $i => $nom) {
    $et = DB::table('etudiants')->insertGetId(['nom' => $nom, 'prenoms' => 'Étudiant '.($i + 1), 'numero' => sprintf('%05d', $i + 1), 'filiere_id' => $filiere, 'sexe' => $i % 2 ? 'F' : 'M']);
    $ins = DB::table('inscriptions')->insertGetId(['etudiant_id' => $et, 'promotion_id' => $promo, 'niveau_id' => $niveau, 'annee_id' => $annee, 'statut' => 'inscrit', 'date_inscription' => '2025-10-01']);
    DB::table('inscriptions_pedagogiques')->insert(['etudiant_id' => $et, 'inscription_id' => $ins, 'semestre_id' => $sem, 'annee_id' => $annee]);
    if ($i < 10) {
        foreach ($mats as $k => $m) {
            $max = $k < 2 ? 40 : 20;
            DB::table('notes')->insert(['pv_id' => $pv, 'etudiant_id' => $et, 'matiere_id' => $m, 'note_examen' => mt_rand(4, $max), 'note_cc' => $k < 2 ? ($i === 3 ? null : mt_rand(5, 20)) : null]);
        }
        $app->make(App\Services\Resultats::class)->recalculer($pv, $et);
    }
}
echo "Base $base prête (PV n° $pv). Identifiants : outils/demo/identifiants.txt\n";
