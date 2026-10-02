<?php

namespace App\Services;

use Illuminate\Support\Facades\DB;
use Throwable;

/**
 * Paramètres de l'université (table `parametres`), lus une fois par requête.
 *
 * Deux lectures coexistent dans l'ancien code, avec une nuance à conserver :
 *  - valeur()  = ancien param()    : repli sur le défaut si absent OU vide ;
 *  - brut()    = ancien getParam() : repli seulement si absent (une valeur
 *                vide est renvoyée telle quelle) + clés signataire_*.
 */
class Parametres
{
    private ?array $valeurs = null;

    private ?array $signataire = null;

    public function valeur(string $cle, string $defaut = ''): string
    {
        $v = $this->charger()[$cle] ?? null;

        return ($v === null || $v === '') ? $defaut : (string) $v;
    }

    public function brut(string $cle, string $defaut = ''): string
    {
        if (str_starts_with($cle, 'signataire_')) {
            $sig = $this->signataireActif();
            if ($sig !== null && array_key_exists($cle, $sig)) {
                return (string) $sig[$cle];
            }
        }

        return (string) ($this->charger()[$cle] ?? $defaut);
    }

    /** À appeler après avoir enregistré des paramètres. */
    public function oublier(): void
    {
        $this->valeurs = null;
        $this->signataire = null;
    }

    private function charger(): array
    {
        if ($this->valeurs === null) {
            try {
                $this->valeurs = DB::table('parametres')->pluck('valeur', 'cle')->all();
            } catch (Throwable) {
                $this->valeurs = [];
            }
        }

        return $this->valeurs;
    }

    /** Signataire global actif — ancien getParam() le mêlait aux paramètres. */
    private function signataireActif(): ?array
    {
        if ($this->signataire === null) {
            try {
                $s = DB::table('signataires')->where('actif', 1)->first();
                $this->signataire = $s ? [
                    'signataire_titre' => $s->titre,
                    'signataire_nom' => $s->nom,
                    'signataire_qualite' => $s->qualite ?? '',
                ] : [];
            } catch (Throwable) {
                $this->signataire = [];
            }
        }

        return $this->signataire ?: null;
    }
}
