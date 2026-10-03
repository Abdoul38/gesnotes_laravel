<?php

namespace App\Services;

use Closure;
use Illuminate\Support\Facades\DB;
use RuntimeException;

/**
 * Matricules étudiants — un seul service pour les 9 endroits de l'ancienne
 * application qui les calculaient chacun de leur côté.
 *
 * Format confirmé par l'université : numéro séquentiel sur 5 chiffres
 * (00001, 00002 … 00010 … 00100). Prochain = plus grand matricule
 * entièrement numérique + 1. Les autres valeurs (« 0 », vide, anciens
 * matricules « UDH2026… » produits par la fiche étudiant) sont ignorées.
 *
 * Différences voulues avec l'ancien code :
 *  - la fiche étudiant produisait « UDH » + année + 4 chiffres, calculé sur
 *    le premier groupe de chiffres de n'importe quel matricule : format
 *    unifié ici ;
 *  - le calcul « MAX + 1 » est fait sous verrou : deux inscriptions
 *    simultanées ne tombent plus sur le même numéro (la seconde échouait
 *    sur la contrainte d'unicité de etudiants.numero).
 */
class Matricules
{
    private const LONGUEUR = 5;

    private const ATTENTE_VERROU_SECONDES = 10;

    /** Formate un rang en matricule (au-delà de 99999, le numéro s'allonge). */
    public static function formater(int $rang): string
    {
        return str_pad((string) $rang, self::LONGUEUR, '0', STR_PAD_LEFT);
    }

    /** Plus grand matricule numérique actuel (0 s'il n'y en a pas). */
    public function dernier(): int
    {
        return (int) DB::scalar("SELECT MAX(CAST(numero AS UNSIGNED)) FROM etudiants WHERE numero REGEXP '^[0-9]+$'");
    }

    /**
     * Donne un matricule à l'étudiant s'il n'en a pas encore (vide, NULL ou
     * « 0 »), et retourne son matricule.
     */
    public function attribuer(int $etudiantId): string
    {
        return $this->sousVerrou(function () use ($etudiantId) {
            $actuel = DB::table('etudiants')->where('id', $etudiantId)->value('numero');
            if ($actuel !== null && $actuel !== '' && $actuel !== '0') {
                return $actuel;
            }
            $nouveau = self::formater($this->dernier() + 1);
            DB::table('etudiants')->where('id', $etudiantId)->update(['numero' => $nouveau]);

            return $nouveau;
        });
    }

    /**
     * Exécute $travail sous le verrou des matricules, avec le prochain rang
     * libre — pour les imports qui numérotent plusieurs étudiants d'affilée :
     *   $matricules->sousVerrou(fn () => … $matricules->dernier() + 1 …)
     */
    public function sousVerrou(Closure $travail): mixed
    {
        $nom = 'gesnotes_matricule_'.DB::connection()->getDatabaseName();
        if ((int) DB::scalar('SELECT GET_LOCK(?, ?)', [$nom, self::ATTENTE_VERROU_SECONDES]) !== 1) {
            throw new RuntimeException('Attribution des matricules occupée, réessayez dans un instant.');
        }
        try {
            return $travail();
        } finally {
            DB::scalar('SELECT RELEASE_LOCK(?)', [$nom]);
        }
    }
}
