<?php

namespace App\Services;

use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Règles communes du portail candidats (ancien candidature/includes/
 * functions.php) : configuration du portail, listes de choix, pièces du
 * dossier, statuts.
 */
class Candidatures
{
    public const STATUTS = [
        'brouillon' => ['⏸️', 'Brouillon', 'sbadge-brouillon'], 'soumis' => ['⏳', 'Soumis', 'sbadge-soumis'],
        'en_cours' => ['🔄', 'En cours', 'sbadge-en'], 'accepte' => ['✅', 'Accepté', 'sbadge-accepte'],
        'refuse' => ['❌', 'Refusé', 'sbadge-refuse'], 'incomplet' => ['⚠️', 'Incomplet', 'sbadge-soumis'],
    ];

    /** Statuts dans lesquels le candidat peut encore modifier son dossier. */
    public const MODIFIABLES = ['brouillon', 'incomplet'];

    public const BACS = [
        'BAC A' => 'BAC A — Littéraire', 'BAC A4' => 'BAC A4 — Langues', 'BAC B' => 'BAC B — Sciences Économiques',
        'BAC C' => 'BAC C — Mathématiques & Sciences Physiques', 'BAC D' => 'BAC D — Sciences de la Nature et de la Vie',
        'BAC E' => 'BAC E — Technique', 'BAC F' => 'BAC F — Technique Industriel', 'BAC G' => 'BAC G — Sciences et Techniques de Gestion',
        'BAC TL' => 'BAC TL — Technique Littéraire', 'BAC TMG' => 'BAC TMG — Technique de Management et Gestion',
    ];

    public const LIEUX = ['Niamey', 'Tahoua', 'Agadez', 'Dosso', 'Maradi', 'Tillabéri', 'Zinder', 'Diffa', 'Autre'];

    public const MENTIONS = ['Passable', 'Assez-Bien', 'Bien', 'Très Bien'];

    /** Pièces : champ du formulaire => [colonne, icône, libellé, obligatoire, aide]. */
    public const PIECES = [
        'photo' => ['doc_photo', '📷', "Photo d'identité", true, 'JPG, PNG — fond blanc, format passeport'],
        'extrait' => ['doc_extrait', '📄', 'Extrait de naissance', true, 'PDF, JPG, PNG — copie certifiée conforme'],
        'nationalite' => ['doc_nationalite', '🪪', 'Certificat de nationalité', true, 'PDF, JPG, PNG — document officiel'],
        'releve' => ['doc_releve', '📋', 'Relevé de notes BAC', true, 'PDF, JPG, PNG — relevé officiel'],
        'attestation' => ['doc_attestation', '🎓', 'Attestation BAC', true, "PDF, JPG, PNG — délivrée par l'office du BAC"],
        'bulletin' => ['doc_bulletin', '📊', 'Bulletin scolaire (IUT)', false, 'PDF, JPG, PNG — facultatif, pour candidature IUT uniquement'],
    ];

    public const TAILLE_MAX = 3 * 1024 * 1024;

    /** Valeur de portail_candidats_config. */
    public function config(string $cle, string $defaut = ''): string
    {
        $v = DB::table('portail_candidats_config')->where('cle', $cle)->value('valeur');

        return $v !== null ? (string) $v : $defaut;
    }

    public function ouvert(): bool
    {
        return $this->config('ouvert', '1') !== '0';
    }

    /** Année académique active du système (repli : configuration du portail). */
    public function annee(): string
    {
        return (string) (DB::table('annees_academiques')->where('active', 1)->value('libelle') ?? $this->config('annee', ''));
    }

    /** Filières proposées (actives), par faculté. */
    public function filieres(): Collection
    {
        return DB::table('filieres')->where('actif', 1)->orderBy('faculte')->orderBy('libelle')->get(['id', 'libelle', 'faculte']);
    }

    /** Dossier avec les libellés de ses trois choix. */
    public function dossier(int $id, ?int $candidatId = null): ?object
    {
        return $this->requete()->where('c.id', $id)->when($candidatId !== null, fn ($q) => $q->where('c.candidat_id', $candidatId))->first();
    }

    /** Dossiers d'un candidat, du plus récent au plus ancien. */
    public function dossiersDe(int $candidatId): Collection
    {
        return $this->requete()->where('c.candidat_id', $candidatId)->orderByDesc('c.created_at')->orderByDesc('c.id')->get();
    }

    /** Numéro de dossier unique « D » + 6 chiffres (tiré au hasard comme avant, sans collision). */
    public function numeroDossier(): string
    {
        do {
            $numero = 'D'.random_int(100000, 999999);
        } while (DB::table('candidatures')->where('numero_dossier', $numero)->exists());

        return $numero;
    }

    /** Pièces obligatoires manquantes (libellés). */
    public function piecesManquantes(object $dossier): array
    {
        return array_values(array_map(fn ($p) => $p[2], array_filter(self::PIECES, fn ($p) => $p[3] && empty($dossier->{$p[0]}))));
    }

    /**
     * Change le statut d'un dossier (administration). Accepté : n° de dépôt
     * « <sigle> + 6 chiffres » attribué s'il manque. Retourne le n° de dépôt.
     */
    public function changerStatut(object $dossier, string $statut, int $utilisateur, string $sigle): ?string
    {
        $maj = ['statut' => $statut, 'traite_par' => $utilisateur, 'date_traitement' => now()];
        $depot = $dossier->numero_depot ?: null;
        if ($statut === 'accepte' && ! $depot) {
            do {
                $depot = strtolower($sigle).random_int(100000, 999999);
            } while (DB::table('candidatures')->where('numero_depot', $depot)->exists());
            $maj['numero_depot'] = $depot;
        }
        DB::table('candidatures')->where('id', $dossier->id)->update($maj);

        return $depot;
    }

    /**
     * Crée la fiche étudiant d'un dossier (matricule à attribuer) et accepte
     * le dossier. Null si une fiche est déjà liée.
     */
    public function creerEtudiant(object $dossier, int $utilisateur, string $sigle): ?int
    {
        if ($dossier->etudiant_id) {
            return null;
        }

        return DB::transaction(function () use ($dossier, $utilisateur, $sigle) {
            $depot = $this->changerStatut($dossier, 'accepte', $utilisateur, $sigle);
            $annee = preg_match('/(\d{4})$/', (string) $dossier->bac_annee, $m) ? $m[1] : null;
            $id = DB::table('etudiants')->insertGetId([
                'numero' => null, 'numero_dossier' => $dossier->numero_dossier, 'numero_depot' => $depot,
                'nom' => $dossier->nom, 'prenoms' => $dossier->prenom, 'sexe' => $dossier->sexe ?: 'M',
                'date_naissance' => $dossier->date_naissance, 'lieu_naissance' => $dossier->lieu_naissance, 'nationalite' => $dossier->nationalite,
                'telephone' => $dossier->telephone, 'email' => $dossier->email, 'adresse' => $dossier->adresse,
                'nom_pere' => $dossier->nom_pere, 'nom_mere' => $dossier->nom_mere, 'tel_urgence' => $dossier->tel_urgence,
                'bac_serie' => $dossier->bac_type, 'bac_annee' => $annee, 'bac_mention' => $dossier->bac_mention,
                'etablissement_origine' => $dossier->bac_etablissement,
            ]);
            DB::table('candidatures')->where('id', $dossier->id)->update(['etudiant_id' => $id]);

            return $id;
        });
    }

    private function requete()
    {
        return DB::table('candidatures as c')
            ->leftJoin('filieres as f1', 'f1.id', '=', 'c.choix1_id')
            ->leftJoin('filieres as f2', 'f2.id', '=', 'c.choix2_id')
            ->leftJoin('filieres as f3', 'f3.id', '=', 'c.choix3_id')
            ->select(['c.*', 'f1.libelle as ch1_lib', 'f2.libelle as ch2_lib', 'f3.libelle as ch3_lib']);
    }
}
