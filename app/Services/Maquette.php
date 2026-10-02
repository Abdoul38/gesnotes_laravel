<?php

namespace App\Services;

use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;

/**
 * Maquette pédagogique (UE et matières d'une promotion).
 */
class Maquette
{
    public const DEJA_DES_UE = -1;

    /**
     * Portage de copierUEsPromotion() (ancien includes/functions.php).
     *
     * Copie les UE et matières actives d'une promotion source vers une autre.
     * Source par défaut : la dernière promotion de même filière et niveau
     * (par libellé d'année décroissant). Retourne le nombre d'UE copiées,
     * 0 si rien à copier, DEJA_DES_UE si la cible a des UE et qu'on ne force pas.
     * En mode « forcer », les UE et matières de la cible sont d'abord supprimées.
     *
     * Différence voulue : l'original assemblait les matières avec GROUP_CONCAT,
     * tronqué à 1 024 caractères par MySQL — une UE riche en matières n'était
     * copiée qu'en partie, sans message. Les matières sont lues ici normalement.
     */
    public function copierUEs(int $promotionCible, ?int $promotionSource = null, bool $forcer = false): int
    {
        $promo = DB::table('promotions')->where('id', $promotionCible)->first(['filiere_id', 'niveau_id', 'annee_id']);
        if (! $promo) {
            return 0;
        }

        $source = $promotionSource ?: DB::table('promotions as p')
            ->join('annees_academiques as a', 'a.id', '=', 'p.annee_id')
            ->where('p.filiere_id', $promo->filiere_id)->where('p.niveau_id', $promo->niveau_id)
            ->where('p.id', '!=', $promotionCible)
            ->orderByDesc('a.libelle')
            ->value('p.id');
        if (! $source) {
            return 0;
        }

        if (! $forcer && DB::table('unites_enseignement')->where('promotion_id', $promotionCible)->exists()) {
            return self::DEJA_DES_UE;
        }

        return DB::transaction(function () use ($promotionCible, $source, $forcer) {
            if ($forcer) {
                $anciennes = DB::table('unites_enseignement')->where('promotion_id', $promotionCible)->pluck('id');
                DB::table('matieres')->whereIn('ue_id', $anciennes)->delete();
                DB::table('unites_enseignement')->where('promotion_id', $promotionCible)->delete();
            }

            $ues = DB::table('unites_enseignement')->where('promotion_id', $source)
                ->orderBy('semestre_id')->orderBy('ordre')->get();
            $copiees = 0;
            foreach ($ues as $ue) {
                try {
                    $nouvelle = DB::table('unites_enseignement')->insertGetId([
                        'promotion_id' => $promotionCible, 'semestre_id' => $ue->semestre_id,
                        'code' => $ue->code, 'libelle' => $ue->libelle,
                        'credits_total' => $ue->credits_total, 'ordre' => $ue->ordre,
                    ]);
                } catch (QueryException) {
                    continue; // doublon ignoré, comme l'original
                }
                $copiees++;

                $matieres = DB::table('matieres')->where('ue_id', $ue->id)->where('actif', 1)->orderBy('ordre')->get();
                foreach ($matieres as $m) {
                    DB::table('matieres')->insertOrIgnore([
                        'ue_id' => $nouvelle, 'code' => $m->code, 'libelle' => $m->libelle,
                        'note_max_examen' => $m->note_max_examen, 'note_max_cc' => $m->note_max_cc,
                        'note_max_totale' => $m->note_max_totale, 'credits' => $m->credits,
                        'coefficient' => $m->coefficient ?? 1, 'ordre' => $m->ordre ?? 0,
                    ]);
                }
            }

            return $copiees;
        });
    }
}
