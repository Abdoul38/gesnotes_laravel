<?php

namespace App\Http\Controllers\Administration;

use App\Autorisations\Roles;
use App\Http\Controllers\Controller;
use App\Services\Journal;
use Illuminate\Database\QueryException;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rules\Password;
use Illuminate\View\View;

/**
 * Comptes du personnel — portage de pages/comptes/utilisateurs.php.
 *
 * Corrections : rôle, faculté, département et liaisons vérifiés côté
 * serveur (faculté obligatoire pour les rôles cantonnés : sans elle, ils
 * voyaient toutes les facultés), mot de passe robuste, email valide,
 * dernier administrateur protégé, propre rôle non modifiable.
 * Les « permissions par défaut » de l'ancienne création n'étaient jamais
 * écrites (variable non définie) : les permissions viennent du rôle.
 */
class UtilisateursController extends Controller
{
    public const LIBELLES = [
        'admin' => 'Admin', 'secretaire' => 'Secrétaire', 'sc_entite' => 'Agent Scolarité Entité',
        'sc_centrale' => 'Agent Scolarité Centrale', 'enseignant' => 'Enseignant', 'etudiant' => 'Étudiant',
        'user' => 'Utilisateur', 'lecture' => 'Lecture seule', 'recteur' => 'Recteur',
        'chef_departement' => 'Chef de département', 'doyen' => 'Doyen',
    ];

    public const COULEURS = [
        'admin' => '#dc2626', 'secretaire' => '#2563eb', 'sc_entite' => '#7c3aed', 'sc_centrale' => '#0d9488',
        'enseignant' => '#15803d', 'etudiant' => '#d97706', 'user' => '#6b7280', 'lecture' => '#9ca3af',
        'recteur' => '#1A3A5C', 'chef_departement' => '#0369a1', 'doyen' => '#7c2d12',
    ];

    public const DESCRIPTIONS = [
        'admin' => ['🔴', 'Accès complet — gestion des utilisateurs, paramètres, tout'],
        'secretaire' => ['🔵', 'Saisie des notes, gestion des PV, planning, impressions'],
        'sc_entite' => ['🟣', 'Notes/PV + inscription pédagogique de SA faculté uniquement'],
        'sc_centrale' => ['🩵', 'Inscription scolarité, listes, paiements — pas de notes'],
        'enseignant' => ['🟢', 'Saisie de ses propres notes via le portail enseignant'],
        'etudiant' => ['🟡', 'Consultation de ses notes et résultats via le portail étudiant'],
        'user' => ['⚪', 'Consultation uniquement — pas de modification'],
        'lecture' => ['⚫', 'Accès lecture aux PV et résultats'],
        'recteur' => ['👑', "Vue d'ensemble en lecture seule via le tableau de bord Rectorat (KPIs, évolution, PV)"],
        'chef_departement' => ['📘', "Peut finaliser le statut des matières de son département et suivre l'exécution du programme"],
        'doyen' => ['🎓', "Vue d'ensemble de sa faculté (tous départements) — PV, réclamations, exécution du programme"],
    ];

    /** Rôles cantonnés à une faculté : la faculté est obligatoire. */
    private const AVEC_FACULTE = ['sc_entite', 'chef_departement', 'doyen'];

    public function __construct(private readonly Journal $journal) {}

    public function index(): View
    {
        return view('administration.utilisateurs', [
            'utilisateurs' => DB::table('utilisateurs as u')
                ->leftJoin('etudiants as e', 'e.id', '=', 'u.etudiant_id')
                ->leftJoin('enseignants as ens', 'ens.id', '=', 'u.enseignant_id')
                ->leftJoin('facultes as fac', 'fac.id', '=', 'u.faculte_id')
                ->where('u.role', '!=', 'etudiant')
                ->orderBy('u.role')->orderBy('u.nom')
                ->get(['u.id', 'u.email', 'u.nom', 'u.role', 'u.faculte_id', 'u.departement_id', 'u.etudiant_id', 'u.enseignant_id',
                    'e.nom as et_nom', 'e.prenoms as et_pren', 'ens.nom as ens_nom', 'ens.prenoms as ens_pren',
                    'fac.code as fac_code', 'fac.couleur as fac_couleur']),
            'facultes' => DB::table('facultes')->where('actif', 1)->orderBy('libelle')->get(['id', 'code', 'libelle']),
            'departements' => DB::table('departements as d')->leftJoin('facultes as f', 'f.id', '=', 'd.faculte_id')
                ->orderBy('f.libelle')->orderBy('d.libelle')->get(['d.id', 'd.faculte_id', 'd.code', 'd.libelle', 'f.code as faculte_code']),
            'etudiants' => DB::table('etudiants')->orderBy('nom')->orderBy('prenoms')->get(['id', 'nom', 'prenoms', 'numero']),
            'enseignants' => DB::table('enseignants')->orderBy('nom')->get(['id', 'nom', 'prenoms']),
        ]);
    }

    public function creer(Request $request): RedirectResponse
    {
        $v = Validator::make($request->all(), $this->regles() + [
            'email' => ['required', 'string', 'email', 'max:150', 'unique:utilisateurs,email'],
            'motdepasse' => ['required', 'string', 'max:255', Password::min(8)->letters()->numbers()],
        ], $this->messages());
        if ($v->fails()) {
            return $this->retour('erreur', $v->errors()->first());
        }
        $donnees = $this->donnees($request);
        if (is_string($donnees)) {
            return $this->retour('erreur', $donnees);
        }

        $id = DB::table('utilisateurs')->insertGetId($donnees + [
            'email' => trim((string) $request->input('email')),
            'prenoms' => '',
            'mot_de_passe' => Hash::make((string) $request->input('motdepasse')),
        ]);
        $this->journal->action('creer', 'utilisateurs', "Compte {$request->input('email')} créé (rôle {$donnees['role']})", $id);

        return $this->retour('succes', "Compte «{$request->input('email')}» créé avec les permissions du rôle {$donnees['role']}.");
    }

    public function modifier(Request $request): RedirectResponse
    {
        $compte = DB::table('utilisateurs')->where('id', (int) $request->input('id'))->first();
        if (! $compte) {
            return $this->retour('erreur', 'Compte introuvable.');
        }
        $email = trim((string) $request->input('email'));
        $v = Validator::make($request->all(), $this->regles() + [
            // Un ancien identifiant qui n'a pas la forme d'un email reste accepté tel quel.
            'email' => array_merge(['required', 'string', 'max:150', 'unique:utilisateurs,email,'.$compte->id], $email === $compte->email ? [] : ['email']),
            'motdepasse' => ['nullable', 'string', 'max:255', Password::min(8)->letters()->numbers()],
        ], $this->messages());
        if ($v->fails()) {
            return $this->retour('erreur', $v->errors()->first());
        }
        $donnees = $this->donnees($request);
        if (is_string($donnees)) {
            return $this->retour('erreur', $donnees);
        }
        if ($compte->role !== $donnees['role']) {
            if ((int) $compte->id === (int) $request->user()->id) {
                return $this->retour('erreur', 'Vous ne pouvez pas changer votre propre rôle.');
            }
            if ($compte->role === 'admin' && $this->dernierAdmin((int) $compte->id)) {
                return $this->retour('erreur', 'Impossible : c\'est le dernier administrateur actif.');
            }
        }

        $maj = $donnees + ['email' => $email];
        if ($request->filled('motdepasse')) {
            $maj['mot_de_passe'] = Hash::make((string) $request->input('motdepasse'));
        }
        DB::table('utilisateurs')->where('id', $compte->id)->update($maj);
        $this->journal->action('modifier', 'utilisateurs', "Compte $email modifié".($request->filled('motdepasse') ? ' (mot de passe changé)' : ''), (int) $compte->id);

        return $this->retour('succes', 'Compte mis à jour.');
    }

    public function supprimer(Request $request): RedirectResponse
    {
        $compte = DB::table('utilisateurs')->where('id', (int) $request->input('id'))->first();
        if (! $compte) {
            return $this->retour('erreur', 'Compte introuvable.');
        }
        if ((int) $compte->id === (int) $request->user()->id) {
            return $this->retour('erreur', 'Impossible de supprimer votre propre compte.');
        }
        if ($compte->role === 'admin' && $this->dernierAdmin((int) $compte->id)) {
            return $this->retour('erreur', 'Impossible : c\'est le dernier administrateur actif.');
        }
        try {
            DB::table('utilisateurs')->where('id', $compte->id)->delete();
        } catch (QueryException) {
            return $this->retour('erreur', 'Ce compte est lié à des données (saisies, documents…) et ne peut pas être supprimé.');
        }
        $this->journal->action('supprimer', 'utilisateurs', "Compte {$compte->email} supprimé", (int) $compte->id);

        return $this->retour('succes', 'Compte supprimé.');
    }

    private function regles(): array
    {
        return [
            'nom' => ['nullable', 'string', 'max:100'],
            'role' => ['required', 'in:'.implode(',', array_keys(Roles::HIERARCHIE))],
            'faculte_id' => ['nullable', 'integer', 'exists:facultes,id'],
            'departement_id' => ['nullable', 'integer', 'exists:departements,id'],
            'etudiant_id' => ['nullable', 'integer', 'exists:etudiants,id'],
            'enseignant_id' => ['nullable', 'integer', 'exists:enseignants,id'],
        ];
    }

    private function messages(): array
    {
        return [
            'email.required' => 'L\'email est requis.', 'email.email' => 'Email invalide.',
            'email.unique' => 'Cet email est déjà utilisé par un autre compte.',
            'motdepasse.required' => 'Le mot de passe est requis.',
            'motdepasse.min' => 'Mot de passe : 8 caractères minimum, avec des lettres et des chiffres.',
            'motdepasse.letters' => 'Mot de passe : 8 caractères minimum, avec des lettres et des chiffres.',
            'motdepasse.numbers' => 'Mot de passe : 8 caractères minimum, avec des lettres et des chiffres.',
            'role.*' => 'Rôle invalide.', 'faculte_id.*' => 'Faculté invalide.', 'departement_id.*' => 'Département invalide.',
            'etudiant_id.*' => 'Étudiant invalide.', 'enseignant_id.*' => 'Enseignant invalide.',
        ];
    }

    /** Champs communs, avec les règles de cohérence rôle ↔ faculté ↔ département. */
    private function donnees(Request $request): array|string
    {
        $role = (string) $request->input('role');
        $faculte = (int) $request->input('faculte_id') ?: null;
        $departement = (int) $request->input('departement_id') ?: null;

        if (in_array($role, self::AVEC_FACULTE, true) && ! $faculte) {
            return 'La faculté est obligatoire pour le rôle « '.self::LIBELLES[$role].' ».';
        }
        if ($role === 'chef_departement' && ! $departement) {
            return 'Le département est obligatoire pour un chef de département.';
        }
        if ($departement && $faculte && (int) DB::table('departements')->where('id', $departement)->value('faculte_id') !== $faculte) {
            return 'Ce département n\'appartient pas à la faculté choisie.';
        }

        return [
            'nom' => trim((string) $request->input('nom')),
            'role' => $role,
            'faculte_id' => $faculte,
            'departement_id' => $departement,
            'etudiant_id' => (int) $request->input('etudiant_id') ?: null,
            'enseignant_id' => (int) $request->input('enseignant_id') ?: null,
        ];
    }

    private function dernierAdmin(int $id): bool
    {
        return ! DB::table('utilisateurs')->where('role', 'admin')->where('actif', 1)->where('id', '!=', $id)->exists();
    }

    private function retour(string $type, string $message): RedirectResponse
    {
        return redirect()->route('utilisateurs')->with($type, $message);
    }
}
