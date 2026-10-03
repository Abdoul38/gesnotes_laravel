<?php

namespace Tests\Feature;

use App\Services\Matricules;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class MatriculesTest extends TestCase
{
    private function etudiant(?string $numero = null): int
    {
        return DB::table('etudiants')->insertGetId(['nom' => 'TEST', 'prenoms' => 'X'.uniqid(), 'numero' => $numero]);
    }

    public function test_format_sur_cinq_chiffres(): void
    {
        $this->assertSame('00001', Matricules::formater(1));
        $this->assertSame('00010', Matricules::formater(10));
        $this->assertSame('00100', Matricules::formater(100));
        $this->assertSame('99999', Matricules::formater(99999));
        $this->assertSame('100000', Matricules::formater(100000));
    }

    public function test_premier_matricule(): void
    {
        $this->assertSame('00001', app(Matricules::class)->attribuer($this->etudiant()));
    }

    public function test_suite_du_plus_grand_matricule_numerique(): void
    {
        $this->etudiant('00007');
        $this->etudiant('00099');
        $this->etudiant('UDH20260500'); // ancien format de la fiche étudiant : ignoré
        $this->etudiant('0');

        $id = $this->etudiant();
        $this->assertSame('00100', app(Matricules::class)->attribuer($id));
        $this->assertSame('00100', DB::table('etudiants')->where('id', $id)->value('numero'));
    }

    public function test_un_etudiant_qui_a_deja_un_matricule_le_garde(): void
    {
        $this->etudiant('00050');
        $id = $this->etudiant('00003');
        $this->assertSame('00003', app(Matricules::class)->attribuer($id));
    }

    public function test_matricule_zero_remplace(): void
    {
        $this->etudiant('00004');
        $id = $this->etudiant('0');
        $this->assertSame('00005', app(Matricules::class)->attribuer($id));
    }

    public function test_attributions_successives_sans_doublon(): void
    {
        $service = app(Matricules::class);
        $numeros = array_map(fn () => $service->attribuer($this->etudiant()), range(1, 5));
        $this->assertSame(['00001', '00002', '00003', '00004', '00005'], $numeros);
    }

    public function test_le_verrou_est_libere_meme_en_cas_d_erreur(): void
    {
        $service = app(Matricules::class);
        try {
            $service->sousVerrou(fn () => throw new \RuntimeException('échec simulé'));
        } catch (\RuntimeException) {
        }
        $nom = 'gesnotes_matricule_'.DB::connection()->getDatabaseName();
        $this->assertSame(1, (int) DB::scalar('SELECT IS_FREE_LOCK(?)', [$nom]));
    }
}
