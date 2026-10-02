<?php

namespace Database\Seeders;

use App\Services\Licence;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use RuntimeException;

/**
 * Données de démonstration pour essayer l'application en local,
 * UNIQUEMENT dans une base de test :
 *   UNI_UDH_DB=gesnotes_test_udh php artisan db:seed --class=DemoSeeder
 *
 * Compte : demo.admin@test.local / Demo-Admin-2026
 */
class DemoSeeder extends Seeder
{
    public function run(): void
    {
        $base = DB::connection()->getDatabaseName();
        if (! str_contains((string) $base, '_test')) {
            throw new RuntimeException("DemoSeeder refuse de remplir « $base » (base non test).");
        }

        DB::table('utilisateurs')->updateOrInsert(['email' => 'demo.admin@test.local'], [
            'nom' => 'DEMO', 'prenoms' => 'Administrateur', 'role' => 'admin', 'actif' => 1,
            'mot_de_passe' => Hash::make('Demo-Admin-2026'),
        ]);
        DB::table('parametres')->updateOrInsert(['cle' => 'licence_cle'], [
            'valeur' => app(Licence::class)->generer('UDH', '2099-12-31'),
        ]);

        $annee = DB::table('annees_academiques')->insertGetId(['libelle' => '2026-2027', 'active' => 1]);
        $niveaux = [];
        foreach (['L1' => 'Licence 1', 'L2' => 'Licence 2', 'L3' => 'Licence 3'] as $code => $libelle) {
            $niveaux[$code] = DB::table('niveaux')->insertGetId(['code' => $code, 'libelle' => $libelle]);
        }
        $filieres = [
            ['code' => 'ECO', 'libelle' => 'Économie', 'faculte' => 'FADEG', 'departement' => 'Économie et Gestion'],
            ['code' => 'DRT', 'libelle' => 'Droit', 'faculte' => 'FADEG', 'departement' => 'Droit'],
            ['code' => 'AGR', 'libelle' => 'Agronomie', 'faculte' => 'FSA', 'departement' => 'Production végétale'],
        ];
        $semestre = DB::table('semestres')->insertGetId(['code' => 'S1', 'libelle' => 'Semestre 1']);

        $n = 0;
        foreach ($filieres as $i => $f) {
            $filiereId = DB::table('filieres')->insertGetId($f + ['actif' => 1]);
            foreach ($niveaux as $code => $niveauId) {
                $promo = DB::table('promotions')->insertGetId([
                    'filiere_id' => $filiereId, 'niveau_id' => $niveauId, 'annee_id' => $annee,
                ]);
                foreach (range(1, 6 + 3 * $i) as $k) {
                    $n++;
                    $etudiant = DB::table('etudiants')->insertGetId([
                        'numero' => sprintf('D%05d', $n), 'nom' => 'ETUDIANT', 'prenoms' => "Démo $n",
                        'sexe' => $n % 3 ? 'M' : 'F', 'filiere_id' => $filiereId,
                    ]);
                    $inscription = DB::table('inscriptions')->insertGetId([
                        'etudiant_id' => $etudiant, 'promotion_id' => $promo, 'annee_id' => $annee,
                        'niveau_id' => $niveauId, 'statut' => 'inscrit', 'date_inscription' => '2026-10-01',
                    ]);
                    if ($k % 4) {
                        DB::table('inscriptions_pedagogiques')->insert([
                            'etudiant_id' => $etudiant, 'inscription_id' => $inscription,
                            'annee_id' => $annee, 'semestre_id' => $semestre, 'statut' => 'actif',
                        ]);
                    }
                }
            }
        }
    }
}
