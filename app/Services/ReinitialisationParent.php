<?php

namespace App\Services;

use App\Services\Communication\Sms;
use App\Universites\UniversiteCourante;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rules\Password;

/**
 * Mot de passe oublié des parents (site et application mobile) : code à 6
 * chiffres envoyé par SMS au numéro du compte, valable 15 minutes, 5 essais.
 * Mêmes règles que pour les étudiants : réponse identique que le numéro
 * existe ou non, un code par minute au plus, essais limités par adresse IP,
 * sessions mobiles fermées après réinitialisation.
 */
class ReinitialisationParent
{
    public const GENERIQUE = "Si ce numéro correspond à un compte parent, un code de vérification vient d'être envoyé par SMS. Il est valable 15 minutes.";

    private const INVALIDE = 'Code invalide ou expiré. Demandez un nouveau code.';

    public function __construct(private readonly Sms $sms, private readonly UniversiteCourante $universite) {}

    public static function telephone(string $brut): string
    {
        return preg_replace('/[^0-9+]/', '', $brut);
    }

    /** Envoie un code ; retourne un message d'erreur (essais épuisés) ou null. */
    public function demander(string $telephone, string $ip): ?string
    {
        $telephone = self::telephone($telephone);
        if ($telephone === '') {
            return 'Numéro de téléphone requis.';
        }
        if ($attente = $this->bloque('demande', $telephone, $ip)) {
            return $attente;
        }
        RateLimiter::hit($this->cle('demande', $telephone, $ip), 300);
        $parent = DB::table('parents')->where(['telephone' => $telephone, 'actif' => 1])->first();
        if (! $parent || DB::table('parents_reset_codes')->where('parent_id', $parent->id)->where('created_at', '>', now()->subMinute())->exists()) {
            return null;
        }
        $code = (string) random_int(100000, 999999);
        DB::table('parents_reset_codes')->where(['parent_id' => $parent->id, 'used' => 0])->update(['used' => 1]);
        DB::table('parents_reset_codes')->insert(['parent_id' => $parent->id, 'code_hash' => $this->empreinte((int) $parent->id, $code),
            'expires_at' => now()->addMinutes(15), 'created_at' => now()]);
        $u = $this->universite->get();
        $texte = 'Votre code de réinitialisation '.($u->sigle ?: strtoupper($u->code))." (espace parents) : $code (valable 15 min). Ne le communiquez à personne.";
        if (! $this->sms->envoyer((string) $parent->telephone, $texte, 'reset_parent', (int) $parent->id)) {
            Log::warning('Code de réinitialisation parent non envoyé par SMS', ['parent' => $parent->id]);
        }

        return null;
    }

    /** Vérifie le code et change le mot de passe ; retourne un message d'erreur ou null. */
    public function reinitialiser(string $telephone, string $code, string $nouveau, string $ip): ?string
    {
        $telephone = self::telephone($telephone);
        if ($telephone === '' || trim($code) === '' || $nouveau === '') {
            return 'Tous les champs sont requis.';
        }
        if ($attente = $this->bloque('reset', $telephone, $ip)) {
            return $attente;
        }
        $v = Validator::make(['mdp' => $nouveau], ['mdp' => [Password::min(8)->letters()->numbers()]], [], ['mdp' => 'nouveau mot de passe']);
        if ($v->fails()) {
            return $v->errors()->first();
        }
        $parent = DB::table('parents')->where(['telephone' => $telephone, 'actif' => 1])->first();
        $ligne = $parent ? DB::table('parents_reset_codes')->where(['parent_id' => $parent->id, 'used' => 0])->where('expires_at', '>', now())->orderByDesc('id')->first() : null;
        if (! $ligne || $ligne->tentatives >= 5 || ! hash_equals($ligne->code_hash, $this->empreinte((int) $parent->id, trim($code)))) {
            if ($ligne) {
                DB::table('parents_reset_codes')->where('id', $ligne->id)->increment('tentatives');
            }
            RateLimiter::hit($this->cle('reset', $telephone, $ip), 300);

            return self::INVALIDE;
        }
        DB::transaction(function () use ($ligne, $parent, $nouveau) {
            DB::table('parents_reset_codes')->where('id', $ligne->id)->update(['used' => 1]);
            DB::table('parents')->where('id', $parent->id)->update(['mot_de_passe_hash' => Hash::make($nouveau)]);
            DB::table('mobile_tokens_parents')->where('parent_id', $parent->id)->delete();
        });
        RateLimiter::clear($this->cle('reset', $telephone, $ip));

        return null;
    }

    private function empreinte(int $parent, string $code): string
    {
        return hash('sha256', 'parent|'.$parent.'|'.$code);
    }

    private function cle(string $action, string $telephone, string $ip): string
    {
        return 'reset_parent|'.$this->universite->get()->code.'|'.$action.'|'.$ip.'|'.$telephone;
    }

    /** 5 essais puis 5 minutes d'attente. */
    private function bloque(string $action, string $telephone, string $ip): ?string
    {
        $cle = $this->cle($action, $telephone, $ip);

        return RateLimiter::tooManyAttempts($cle, 5)
            ? 'Trop de tentatives. Réessayez dans '.(int) ceil(RateLimiter::availableIn($cle) / 60).' minute(s).' : null;
    }
}
