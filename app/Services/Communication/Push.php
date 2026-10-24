<?php

namespace App\Services\Communication;

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;
use Throwable;

/**
 * Notifications sur téléphone (application mobile) par Firebase Cloud
 * Messaging (API HTTP v1). Le serveur s'authentifie auprès de Google avec la
 * clé du compte de service (fichier JSON désigné par FIREBASE_CREDENTIALS,
 * hors du dossier public et hors de Git) : jeton OAuth signé localement
 * (RS256), gardé 50 minutes.
 *
 * Sans clé configurée, rien n'est envoyé. Un téléphone dont le jeton n'est
 * plus valide (application désinstallée) est retiré. Un échec n'empêche
 * jamais l'action d'origine (publication, appel…).
 */
class Push
{
    private ?array $compte = null;

    public function actif(): bool
    {
        return $this->compte() !== null && Schema::hasTable('appareils_push');
    }

    /** Enregistre (ou rattache) le téléphone d'un compte ; jeton vide : le retire. */
    public function enregistrer(string $type, int $compteId, string $jeton, ?string $plateforme = null): void
    {
        $jeton = trim($jeton);
        if ($jeton === '' || strlen($jeton) > 255) {
            return;
        }
        DB::table('appareils_push')->upsert([['type_compte' => $type, 'compte_id' => $compteId, 'jeton' => $jeton,
            'plateforme' => $plateforme ? mb_substr($plateforme, 0, 20) : null, 'maj_le' => now()]], ['jeton'], ['type_compte', 'compte_id', 'plateforme', 'maj_le']);
    }

    public function retirer(string $jeton): void
    {
        if (Schema::hasTable('appareils_push')) {
            DB::table('appareils_push')->where('jeton', trim($jeton))->delete();
        }
    }

    /** Notifie l'étudiant (historique de la cloche + ses téléphones). Retourne le nombre de notifications envoyées. */
    public function etudiant(int $etudiantId, string $titre, string $texte, array $donnees = []): int
    {
        $this->historiser('etudiant', [$etudiantId], $titre, $texte, $donnees['type'] ?? 'info');

        return $this->comptes('etudiant', [$etudiantId], $titre, $texte, $donnees);
    }

    /** Notifie les parents actifs liés à l'étudiant. */
    public function parentsDe(int $etudiantId, string $titre, string $texte, array $donnees = []): int
    {
        $parents = DB::table('parents_etudiants as pe')->join('parents as p', 'p.id', '=', 'pe.parent_id')
            ->where(['pe.etudiant_id' => $etudiantId, 'p.actif' => 1])->pluck('p.id')->all();
        $this->historiser('parent', $parents, $titre, $texte, $donnees['type'] ?? 'info');

        return $this->comptes('parent', $parents, $titre, $texte, $donnees + ['etudiant_id' => (string) $etudiantId]);
    }

    /** Historique affiché par la cloche de l'application (même sans téléphone enregistré). */
    private function historiser(string $type, array $ids, string $titre, string $texte, string $categorie): void
    {
        if (! $ids || ! Schema::hasTable('notifications_mobile')) {
            return;
        }
        DB::table('notifications_mobile')->insert(array_map(fn ($id) => ['type_compte' => $type, 'compte_id' => (int) $id,
            'categorie' => mb_substr($categorie, 0, 20), 'titre' => mb_substr($titre, 0, 150), 'texte' => mb_substr($texte, 0, 500), 'cree_le' => now()], $ids));
    }

    /**
     * Annonce publiée : étudiants ciblés (tous, étudiants, filière, niveau de
     * l'année active) et leurs parents (sauf « étudiants uniquement ») ; rien
     * pour les annonces aux enseignants. Une seule fois par annonce, et pas
     * avant sa date de début. Retourne le nombre de notifications envoyées.
     */
    public function annonce(object $annonce): int
    {
        if (! $this->actif() || ! $annonce->publiee || $annonce->cible === 'enseignants'
            || ($annonce->date_debut && $annonce->date_debut > now()->toDateString())
            || ! Cache::add('annonce_push|'.$annonce->id, 1, now()->addYear())) {
            return 0;
        }
        $etudiants = DB::table('etudiants as e')->select('e.id');
        if ($annonce->cible === 'filiere') {
            $etudiants->where('e.filiere_id', (int) $annonce->filiere_id);
        } elseif ($annonce->cible === 'niveau') {
            $etudiants->whereExists(fn ($s) => $s->from('inscriptions as i')->join('annees_academiques as a', 'a.id', '=', 'i.annee_id')
                ->whereColumn('i.etudiant_id', 'e.id')->where(['i.statut' => 'inscrit', 'a.active' => 1, 'i.niveau_id' => (int) $annonce->niveau_id]));
        }
        $jetons = DB::table('appareils_push')->where('type_compte', 'etudiant')->whereIn('compte_id', $etudiants)->pluck('jeton');
        if ($annonce->cible !== 'etudiants') {
            $parents = DB::table('parents_etudiants as pe')->join('parents as p', 'p.id', '=', 'pe.parent_id')->where('p.actif', 1)
                ->when($annonce->cible !== 'tous', fn ($q) => $q->whereIn('pe.etudiant_id', $etudiants))->select('pe.parent_id');
            $jetons = $jetons->merge(DB::table('appareils_push')->where('type_compte', 'parent')->whereIn('compte_id', $parents)->pluck('jeton'));
        }
        $texte = mb_strimwidth(trim(preg_replace('/\s+/u', ' ', strip_tags((string) $annonce->contenu))), 0, 160, '…');
        $nb = 0;
        foreach ($jetons->unique() as $jeton) {
            $nb += (int) $this->envoyer($jeton, '📢 '.$annonce->titre, $texte, ['type' => 'annonce', 'annonce_id' => (string) $annonce->id]);
        }

        return $nb;
    }

    private function comptes(string $type, array $ids, string $titre, string $texte, array $donnees): int
    {
        if (! $ids || ! $this->actif()) {
            return 0;
        }
        $nb = 0;
        foreach (DB::table('appareils_push')->where('type_compte', $type)->whereIn('compte_id', $ids)->pluck('jeton') as $jeton) {
            $nb += (int) $this->envoyer($jeton, $titre, $texte, $donnees);
        }

        return $nb;
    }

    /** Envoi à un téléphone. */
    public function envoyer(string $jeton, string $titre, string $texte, array $donnees = []): bool
    {
        $compte = $this->compte();
        if (! $compte) {
            return false;
        }
        try {
            $acces = $this->jetonAcces($compte);
            $reponse = Http::timeout(10)->withToken($acces)->post('https://fcm.googleapis.com/v1/projects/'.$compte['project_id'].'/messages:send', [
                'message' => [
                    'token' => $jeton,
                    'notification' => ['title' => mb_substr($titre, 0, 100), 'body' => mb_substr($texte, 0, 500)],
                    'data' => array_map('strval', $donnees),
                    'android' => ['priority' => 'high', 'notification' => ['sound' => 'default']],
                ],
            ]);
            if ($reponse->successful()) {
                return true;
            }
            // Téléphone qui n'est plus enregistré (application désinstallée…) : retiré
            if ($reponse->status() === 404 || str_contains($reponse->body(), 'UNREGISTERED')) {
                $this->retirer($jeton);
            } else {
                Log::warning('Notification push refusée', ['statut' => $reponse->status(), 'reponse' => mb_substr($reponse->body(), 0, 300)]);
            }
        } catch (Throwable $e) {
            Log::warning('Notification push impossible', ['erreur' => $e->getMessage()]);
        }

        return false;
    }

    /** Jeton OAuth 2 de Google (assertion JWT signée avec la clé privée du compte de service), gardé 50 minutes. */
    private function jetonAcces(array $compte): string
    {
        return Cache::remember('fcm_jeton|'.$compte['client_email'], 3000, function () use ($compte) {
            $b64 = fn (string $s) => rtrim(strtr(base64_encode($s), '+/', '-_'), '=');
            $maintenant = time();
            $entete = $b64(json_encode(['alg' => 'RS256', 'typ' => 'JWT']));
            $charge = $b64(json_encode(['iss' => $compte['client_email'], 'scope' => 'https://www.googleapis.com/auth/firebase.messaging',
                'aud' => $compte['token_uri'], 'iat' => $maintenant, 'exp' => $maintenant + 3600]));
            openssl_sign($entete.'.'.$charge, $signature, $compte['private_key'], OPENSSL_ALGO_SHA256);
            $reponse = Http::asForm()->timeout(10)->post($compte['token_uri'], [
                'grant_type' => 'urn:ietf:params:oauth:grant-type:jwt-bearer', 'assertion' => $entete.'.'.$charge.'.'.$b64($signature),
            ])->throw();

            return (string) $reponse->json('access_token');
        });
    }

    /** Clé du compte de service, ou null si absente ou illisible. */
    private function compte(): ?array
    {
        if ($this->compte !== null) {
            return $this->compte ?: null;
        }
        $chemin = (string) config('gesnotes.firebase_credentials');
        $donnees = $chemin !== '' && is_file($chemin) ? json_decode((string) file_get_contents($chemin), true) : null;
        $valide = is_array($donnees) && isset($donnees['project_id'], $donnees['client_email'], $donnees['private_key'], $donnees['token_uri']);
        $this->compte = $valide ? $donnees : [];

        return $this->compte ?: null;
    }
}
