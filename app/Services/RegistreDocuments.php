<?php

namespace App\Services;

use Illuminate\Support\Facades\DB;
use RuntimeException;

/**
 * Registres des documents officiels numérotés (diplomes_emis,
 * attestations_emises, certificats_emis) : un numéro par document, attribué
 * une fois puis réutilisé à chaque réimpression ; c'est ce numéro que vérifie
 * la page publique.
 *
 * Différence voulue : numéro suivant = plus grand numéro du préfixe + 1, sous
 * verrou (l'ancien COUNT + 1 sans verrou donnait des doublons après une
 * suppression ou deux impressions simultanées : l'impression échouait).
 */
class RegistreDocuments
{
    private const TABLES = ['diplomes_emis', 'attestations_emises', 'certificats_emis'];

    public function existant(string $table, array $cle): ?object
    {
        return DB::table($this->table($table))->where($cle)->first();
    }

    /**
     * @param  array  $cle  colonnes qui identifient le document (ex. etudiant_id + niveau)
     * @param  array  $valeurs  colonnes mises à jour à chaque émission
     */
    public function enregistrer(string $table, string $prefixe, array $cle, array $valeurs): string
    {
        $table = $this->table($table);
        $verrou = 'gesnotes_'.$table.'_'.DB::connection()->getDatabaseName();
        if ((int) DB::scalar('SELECT GET_LOCK(?, 10)', [$verrou]) !== 1) {
            throw new RuntimeException('Registre occupé, réessayez dans un instant.');
        }
        try {
            $numero = DB::table($table)->where($cle)->value('numero');
            if (! $numero) {
                $dernier = (int) DB::table($table)->where('numero', 'like', $prefixe.'%')
                    ->max(DB::raw('CAST(SUBSTRING(numero, '.(strlen($prefixe) + 1).') AS UNSIGNED)'));
                $numero = $prefixe.str_pad((string) ($dernier + 1), 6, '0', STR_PAD_LEFT);
            }
            DB::table($table)->upsert([['numero' => $numero] + $cle + $valeurs], ['numero'], array_keys($valeurs));
        } finally {
            DB::scalar('SELECT RELEASE_LOCK(?)', [$verrou]);
        }

        return $numero;
    }

    private function table(string $table): string
    {
        return in_array($table, self::TABLES, true) ? $table : throw new RuntimeException("Registre inconnu : $table");
    }
}
