<?php

namespace App\Http\Controllers\Communication;

use App\Autorisations\Perimetre;
use App\Http\Controllers\Controller;
use App\Services\Journal;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\View\View;

/**
 * Annonces / tableau d'affichage — portage de
 * pages/communication/annonces.php : liste, création, modification,
 * publication, suppression.
 *
 * Différences voulues :
 *  - écriture réservée à la permission « etudiants_gerer » (la lecture
 *    « etudiants_voir » suffisait pour créer, publier ou supprimer) ;
 *  - un compte limité à une faculté ne modifie, ne publie et ne supprime
 *    que ses propres annonces ; filière ciblée de sa faculté ;
 *  - type, public, filière, niveau, dates et longueurs contrôlés ;
 *  - création et modification qui fonctionnent (boutons et script en ligne
 *    bloqués par la CSP) ; suppression confirmée ; actions journalisées.
 */
class AnnoncesController extends Controller
{
    public const TYPES = ['info' => ['#dbeafe', '#1e40af', 'ℹ️ Info'], 'important' => ['#fef9c3', '#854d0e', '⚠️ Important'],
        'urgent' => ['#fee2e2', '#991b1b', '🚨 Urgent'], 'evenement' => ['#dcfce7', '#166534', '🎉 Événement']];

    public const CIBLES = ['tous' => 'Tout le monde', 'etudiants' => 'Étudiants uniquement', 'enseignants' => 'Enseignants uniquement',
        'filiere' => 'Par filière', 'niveau' => 'Par niveau'];

    public function __construct(private readonly Perimetre $perimetre, private readonly Journal $journal) {}

    public function index(Request $request): View
    {
        $u = $request->user();
        $faculte = $this->perimetre->faculte($u);
        $annonces = DB::table('annonces as a')->leftJoin('utilisateurs as u', 'u.id', '=', 'a.auteur_id')
            ->leftJoin('filieres as f', 'f.id', '=', 'a.filiere_id')->leftJoin('niveaux as n', 'n.id', '=', 'a.niveau_id')
            ->orderByDesc('a.epinglee')->orderByDesc('a.created_at')
            ->get(['a.*', 'u.nom as auteur', 'f.libelle as filiere', 'n.code as niveau']);
        $aujourdhui = date('Y-m-d');

        return view('communication.annonces', [
            'annonces' => $annonces,
            'stats' => [
                'total' => $annonces->count(),
                'publiees' => $annonces->where('publiee', 1)->count(),
                'actives' => $annonces->filter(fn ($a) => $a->publiee && (! $a->date_fin || $a->date_fin >= $aujourdhui))->count(),
                'epinglees' => $annonces->where('epinglee', 1)->count(),
            ],
            'filieres' => DB::table('filieres')->where('actif', 1)->when($faculte, fn ($q) => $q->where('faculte_id', $faculte))->orderBy('libelle')->get(['id', 'libelle']),
            'niveaux' => DB::table('niveaux')->orderBy('code')->get(['id', 'code', 'libelle']),
            'peutEcrire' => $u->autorise('etudiants_gerer'),
            'modifiable' => fn ($a) => $this->modifiable($request, $a),
        ]);
    }

    public function enregistrer(Request $request): RedirectResponse
    {
        $modification = $request->input('action') === 'modifier';
        $existante = $modification ? DB::table('annonces')->find((int) $request->input('id')) : null;
        if ($modification && (! $existante || ! $this->modifiable($request, $existante))) {
            return redirect()->route('annonces')->with('erreur', 'Annonce introuvable.');
        }
        $v = Validator::make($request->all(), [
            'titre' => 'required|string|max:200',
            'contenu' => 'required|string|max:10000',
            'type' => 'required|in:'.implode(',', array_keys(self::TYPES)),
            'cible' => 'required|in:'.implode(',', array_keys(self::CIBLES)),
            'filiere_id' => 'nullable|integer|required_if:cible,filiere',
            'niveau_id' => 'nullable|integer|required_if:cible,niveau',
            'date_debut' => 'nullable|date_format:Y-m-d',
            'date_fin' => 'nullable|date_format:Y-m-d|after_or_equal:date_debut',
        ], ['required' => 'Titre et contenu obligatoires.', 'required_if' => 'Choisissez la filière ou le niveau ciblé.',
            'after_or_equal' => 'La date de fin précède la date de début.'], ['cible' => 'destinataires']);
        if ($v->fails()) {
            return redirect()->route('annonces')->with('erreur', $v->errors()->first());
        }
        $d = $v->validated();
        $filiere = $d['cible'] === 'filiere' ? (int) $d['filiere_id'] : null;
        $niveau = $d['cible'] === 'niveau' ? (int) $d['niveau_id'] : null;
        if (($filiere && (! DB::table('filieres')->where('id', $filiere)->exists() || ! $this->perimetre->filiere($request->user(), $filiere)))
            || ($niveau && ! DB::table('niveaux')->where('id', $niveau)->exists())) {
            return redirect()->route('annonces')->with('erreur', 'Filière ou niveau introuvable.');
        }
        $valeurs = [
            'titre' => trim($d['titre']), 'contenu' => trim($d['contenu']), 'type' => $d['type'], 'cible' => $d['cible'],
            'filiere_id' => $filiere, 'niveau_id' => $niveau, 'date_debut' => $d['date_debut'] ?? date('Y-m-d'), 'date_fin' => $d['date_fin'] ?? null,
            'publiee' => $request->boolean('publiee') ? 1 : 0, 'epinglee' => $request->boolean('epinglee') ? 1 : 0,
        ];
        if ($modification) {
            DB::table('annonces')->where('id', $existante->id)->update($valeurs);
            $this->journal->action('modifier', 'annonce', "Annonce modifiée : {$valeurs['titre']}", (int) $existante->id);

            return redirect()->route('annonces')->with('succes', 'Annonce modifiée.');
        }
        $id = DB::table('annonces')->insertGetId($valeurs + ['auteur_id' => $request->user()->id]);
        $this->journal->action('creer', 'annonce', "Annonce créée : {$valeurs['titre']}", $id);

        return redirect()->route('annonces')->with('succes', 'Annonce créée avec succès.');
    }

    public function basculerPublication(Request $request): RedirectResponse
    {
        $a = DB::table('annonces')->find((int) $request->input('id'));
        if (! $a || ! $this->modifiable($request, $a)) {
            return redirect()->route('annonces')->with('erreur', 'Annonce introuvable.');
        }
        DB::table('annonces')->where('id', $a->id)->update(['publiee' => $a->publiee ? 0 : 1]);
        $this->journal->action($a->publiee ? 'depublier' : 'publier', 'annonce', "Annonce : {$a->titre}", (int) $a->id);

        return redirect()->route('annonces');
    }

    public function supprimer(Request $request): RedirectResponse
    {
        $a = DB::table('annonces')->find((int) $request->input('id'));
        if (! $a || ! $this->modifiable($request, $a)) {
            return redirect()->route('annonces')->with('erreur', 'Annonce introuvable.');
        }
        DB::table('annonces')->where('id', $a->id)->delete();
        $this->journal->action('supprimer', 'annonce', "Annonce supprimée : {$a->titre}", (int) $a->id);

        return redirect()->route('annonces')->with('succes', 'Annonce supprimée.');
    }

    /** Écriture : permission « etudiants_gerer » ; un compte limité à une faculté ne touche qu'à ses annonces. */
    private function modifiable(Request $request, object $a): bool
    {
        $u = $request->user();

        return $u->autorise('etudiants_gerer') && ($this->perimetre->faculte($u) === 0 || (int) $a->auteur_id === (int) $u->id);
    }
}
