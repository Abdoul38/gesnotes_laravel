<?php

namespace App\Services;

use Illuminate\Support\Facades\DB;
use RuntimeException;

/**
 * Registre des cartes d'étudiant émises (table cartes_emises).
 *
 * Numéro : « CE » + année sur 2 chiffres + « - » + 6 chiffres (CE26-000001),
 * une carte par étudiant et par année académique (réimprimer réutilise le
 * même numéro, encodé dans le QR code de vérification).
 *
 * Différence voulue : l'original numérotait par COUNT(*) + 1 — après une
 * suppression, le numéro calculé existait déjà et l'impression échouait ;
 * deux impressions simultanées aussi. Ici : plus grand numéro + 1, sous verrou.
 */
class CartesEtudiants
{
    /** @return array{numero:string, date_emission:?string} */
    public function enregistrer(object $etudiant, string $annee, string $filiere): array
    {
        $verrou = 'gesnotes_carte_'.DB::connection()->getDatabaseName();
        if ((int) DB::scalar('SELECT GET_LOCK(?, 10)', [$verrou]) !== 1) {
            throw new RuntimeException('Registre des cartes occupé, réessayez dans un instant.');
        }
        try {
            $numero = DB::table('cartes_emises')->where('etudiant_id', $etudiant->id)->where('annee_academique', $annee)->value('numero');
            if (! $numero) {
                $prefixe = 'CE'.date('y').'-';
                $dernier = (int) DB::table('cartes_emises')->where('numero', 'like', $prefixe.'%')
                    ->max(DB::raw('CAST(SUBSTRING(numero, '.(strlen($prefixe) + 1).') AS UNSIGNED)'));
                $numero = $prefixe.str_pad((string) ($dernier + 1), 6, '0', STR_PAD_LEFT);
            }
            DB::table('cartes_emises')->upsert(
                [['numero' => $numero, 'etudiant_id' => $etudiant->id, 'annee_academique' => $annee, 'nom' => $etudiant->nom, 'prenoms' => $etudiant->prenoms, 'filiere' => $filiere]],
                ['numero'],
                ['nom', 'prenoms', 'filiere'],
            );
        } finally {
            DB::scalar('SELECT RELEASE_LOCK(?)', [$verrou]);
        }

        return ['numero' => $numero, 'date_emission' => DB::table('cartes_emises')->where('numero', $numero)->value('date_emission')];
    }
}
