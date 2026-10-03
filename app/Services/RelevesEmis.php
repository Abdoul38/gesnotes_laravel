<?php

namespace App\Services;

use Illuminate\Support\Facades\DB;
use RuntimeException;

/**
 * Registre des relevés émis (QR d'authentification) — ancien bloc de
 * releve_notes.php. Un numéro par (étudiant, PV), conservé d'une impression
 * à l'autre ; nom, mention et moyenne remis à jour.
 *
 * Différence voulue : numéro suivant = plus grand numéro de l'année + 1, sous
 * verrou (l'ancien COUNT + 1, sans verrou, pouvait donner deux fois le même
 * numéro après une suppression ou deux impressions simultanées).
 */
class RelevesEmis
{
    public function enregistrer(object $etudiant, object $pv, ?string $mention, $moyenne): string
    {
        $verrou = 'gesnotes_releve_'.DB::connection()->getDatabaseName();
        if ((int) DB::scalar('SELECT GET_LOCK(?, 10)', [$verrou]) !== 1) {
            throw new RuntimeException('Registre des relevés occupé, réessayez dans un instant.');
        }
        try {
            $numero = DB::table('releves_emis')->where(['etudiant_id' => $etudiant->id, 'pv_id' => $pv->id])->value('numero');
            if (! $numero) {
                $prefixe = 'R'.date('y').'-';
                $dernier = (int) DB::table('releves_emis')->where('numero', 'like', $prefixe.'%')
                    ->max(DB::raw('CAST(SUBSTRING(numero, '.(strlen($prefixe) + 1).') AS UNSIGNED)'));
                $numero = $prefixe.str_pad((string) ($dernier + 1), 6, '0', STR_PAD_LEFT);
            }
            DB::table('releves_emis')->upsert(
                [['numero' => $numero, 'etudiant_id' => $etudiant->id, 'pv_id' => $pv->id, 'nom' => $etudiant->nom, 'prenoms' => $etudiant->prenoms,
                    'semestre' => $pv->sem_libelle, 'mention_grade' => $mention ?: null, 'moyenne' => $moyenne, 'annee_academique' => $pv->annee]],
                ['numero'],
                ['nom', 'prenoms', 'mention_grade', 'moyenne', 'annee_academique'],
            );
        } finally {
            DB::scalar('SELECT RELEASE_LOCK(?)', [$verrou]);
        }

        return $numero;
    }
}
