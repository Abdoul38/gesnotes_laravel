<?php

namespace App\Http\Controllers\Administration;

use App\Autorisations\Perimetre;
use App\Http\Controllers\Controller;
use App\Services\Journal;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\View\View;

/**
 * Signataires par faculté — portage de pages/admin/signataires.php.
 * Correction : un utilisateur cantonné à une faculté ne voyait que ses
 * signataires, mais pouvait ajouter, modifier, activer ou supprimer ceux
 * de n'importe quelle faculté (identifiants non vérifiés) ; chaque action
 * est désormais limitée à son périmètre.
 */
class SignatairesController extends Controller
{
    public function __construct(private readonly Perimetre $perimetre, private readonly Journal $journal) {}

    public function index(Request $request): View
    {
        $f = $this->perimetre->faculte($request->user());
        $facultes = DB::table('facultes')->when($f, fn ($q) => $q->where('id', $f))->orderBy('libelle')->get(['id', 'code', 'libelle', 'couleur']);
        $signataires = DB::table('signataires as s')->leftJoin('facultes as f', 'f.id', '=', 's.faculte_id')
            ->when($f, fn ($q) => $q->where('s.faculte_id', $f))
            ->orderBy('f.libelle')->orderByDesc('s.actif')->orderBy('s.nom')
            ->get(['s.*']);
        $parFaculte = $signataires->groupBy(fn ($s) => (int) $s->faculte_id);

        $groupes = $facultes->filter(fn ($fac) => $parFaculte->has($fac->id))
            ->map(fn ($fac) => ['info' => $fac, 'sigs' => $parFaculte[$fac->id]])->values();
        if ($parFaculte->has(0)) {
            $groupes->push(['info' => (object) ['id' => 0, 'libelle' => 'Sans faculté', 'code' => '—', 'couleur' => '#6b7280'], 'sigs' => $parFaculte[0]]);
        }

        return view('administration.signataires', [
            'facultes' => $facultes, 'groupes' => $groupes, 'parFaculte' => $parFaculte, 'aucun' => $signataires->isEmpty(),
        ]);
    }

    public function ajouter(Request $request): RedirectResponse
    {
        $champs = $this->champs($request);
        if (is_string($champs)) {
            return $this->retour('erreur', $champs);
        }
        $id = DB::table('signataires')->insertGetId($champs + ['actif' => 0]);
        $this->journal->action('creer', 'signataires', "Signataire {$champs['nom']} ajouté", $id);

        return $this->retour('succes', "Signataire «{$champs['nom']}» ajouté.");
    }

    public function modifier(Request $request): RedirectResponse
    {
        $sig = $this->trouver($request);
        if (! $sig) {
            return $this->retour('erreur', 'Signataire introuvable.');
        }
        $champs = $this->champs($request);
        if (is_string($champs)) {
            return $this->retour('erreur', $champs);
        }
        // Un signataire actif qui change de faculté ne doit pas laisser deux actifs dans la nouvelle.
        if ($sig->actif && (int) $sig->faculte_id !== (int) $champs['faculte_id']) {
            $champs['actif'] = 0;
        }
        DB::table('signataires')->where('id', $sig->id)->update($champs);
        $this->journal->action('modifier', 'signataires', "Signataire {$champs['nom']} modifié", (int) $sig->id);

        return $this->retour('succes', 'Signataire mis à jour.');
    }

    public function activer(Request $request): RedirectResponse
    {
        $sig = $this->trouver($request);
        if (! $sig) {
            return $this->retour('erreur', 'Signataire introuvable.');
        }
        DB::transaction(function () use ($sig) {
            // Désactiver uniquement les autres signataires de la MÊME faculté
            DB::table('signataires')->when($sig->faculte_id, fn ($q) => $q->where('faculte_id', $sig->faculte_id), fn ($q) => $q->whereNull('faculte_id'))
                ->update(['actif' => 0]);
            DB::table('signataires')->where('id', $sig->id)->update(['actif' => 1]);
        });
        $this->journal->action('modifier', 'signataires', "Signataire {$sig->nom} activé", (int) $sig->id);

        return $this->retour('succes', 'Signataire activé.');
    }

    public function supprimer(Request $request): RedirectResponse
    {
        $sig = $this->trouver($request);
        if (! $sig) {
            return $this->retour('erreur', 'Signataire introuvable.');
        }
        if ($sig->actif) {
            return $this->retour('erreur', 'Impossible de supprimer le signataire actif. Activez-en un autre d\'abord.');
        }
        DB::table('signataires')->where('id', $sig->id)->delete();
        $this->journal->action('supprimer', 'signataires', "Signataire {$sig->nom} supprimé", (int) $sig->id);

        return $this->retour('succes', 'Signataire supprimé.');
    }

    /** Signataire désigné, s'il relève du périmètre de l'utilisateur. */
    private function trouver(Request $request): ?object
    {
        $sig = DB::table('signataires')->where('id', (int) $request->input('id'))->first();

        return $sig && $this->perimetre->faculteAutorisee($request->user(), $sig->faculte_id) ? $sig : null;
    }

    private function champs(Request $request): array|string
    {
        $nom = mb_substr(trim((string) $request->input('nom')), 0, 150);
        $faculte = (int) $request->input('faculte_id') ?: null;
        if ($nom === '') {
            return 'Le nom du signataire est requis.';
        }
        if ($faculte && ! DB::table('facultes')->where('id', $faculte)->exists()) {
            return 'Faculté introuvable.';
        }
        if (! $this->perimetre->faculteAutorisee($request->user(), $faculte)) {
            return 'Vous ne pouvez gérer que les signataires de votre faculté.';
        }

        return [
            'titre' => mb_substr(trim((string) $request->input('titre')), 0, 100),
            'nom' => $nom,
            'qualite' => mb_substr(trim((string) $request->input('qualite')), 0, 200),
            'faculte_id' => $faculte,
        ];
    }

    private function retour(string $type, string $message): RedirectResponse
    {
        return redirect()->route('signataires')->with($type, $message);
    }
}
