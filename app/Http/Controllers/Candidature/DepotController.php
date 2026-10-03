<?php

namespace App\Http\Controllers\Candidature;

use App\Http\Controllers\Controller;
use App\Services\Candidatures;
use App\Services\Fichiers;
use App\Services\Identite;
use App\Services\Parametres;
use App\Universites\UniversiteCourante;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;
use Illuminate\View\View;

/**
 * Dépôt du dossier de préinscription en 4 étapes (informations, filières,
 * pièces, résumé) et quitus — portage de candidature/pages/depot.php,
 * succes.php et quitus.php.
 *
 * Différences voulues :
 *  - un dossier soumis, en cours, accepté ou refusé n'est plus modifiable :
 *    le candidat pouvait changer ses choix ou ses pièces après l'acceptation
 *    et le repasser « soumis » ; seuls brouillon et incomplet le sont ;
 *  - portail fermé : plus d'enregistrement ni de soumission (seule la
 *    création d'un dossier était bloquée) ;
 *  - un nouveau dossier se crée en POST, et seulement s'il n'y en a pas déjà
 *    un en cours (chaque visite de la page sans numéro en créait un) ;
 *  - champs contrôlés (listes de choix, date, filières actives et
 *    distinctes) ; soumission refusée sans les pièces obligatoires ;
 *  - pièces vérifiées par leur contenu (l'extension suffisait), photo
 *    d'identité : image seulement, fichier refusé signalé (ignoré en
 *    silence), rangées hors du dossier public, nom aléatoire ;
 *  - quitus : QR code produit dans la page (le nom du candidat partait
 *    vers un service tiers, api.qrserver.com) ; pas de quitus pour un
 *    brouillon ; numéro stable (il changeait à chaque affichage).
 */
class DepotController extends Controller
{
    public function __construct(private readonly Candidatures $candidatures) {}

    public function nouveau(Request $request): RedirectResponse
    {
        $candidat = $request->user('candidat');
        if (! $this->candidatures->ouvert()) {
            return redirect()->route('candidature.tableau')->with('erreur', $this->candidatures->config('message_ferme', 'Le dépôt de dossiers est actuellement fermé.'));
        }
        $enCours = $this->candidatures->dossiersDe($candidat->id)->first(fn ($d) => in_array($d->statut, Candidatures::MODIFIABLES, true));
        if ($enCours) {
            return redirect()->route('candidature.depot', ['id' => $enCours->id, 'etape' => max(1, (int) $enCours->etape_actuelle)]);
        }
        $id = DB::table('candidatures')->insertGetId(['candidat_id' => $candidat->id, 'numero_dossier' => $this->candidatures->numeroDossier(),
            'nom' => $candidat->nom, 'prenom' => $candidat->prenom, 'email' => $candidat->email, 'telephone' => $candidat->telephone,
            'statut' => 'brouillon', 'etape_actuelle' => 1, 'created_at' => now(), 'updated_at' => now()]);

        return redirect()->route('candidature.depot', ['id' => $id, 'etape' => 1]);
    }

    public function etape(Request $request, int $id, int $etape): View|RedirectResponse
    {
        $dossier = $this->modifiable($request, $id);
        if ($dossier instanceof RedirectResponse) {
            return $dossier;
        }
        $etape = max(1, min(4, $etape, max(1, (int) $dossier->etape_actuelle)));

        return view('candidature.depot', ['dossier' => $dossier, 'etape' => $etape, 'filieres' => $this->candidatures->filieres(),
            'candidat' => $request->user('candidat'), 'manquantes' => $etape === 4 ? $this->candidatures->piecesManquantes($dossier) : []]);
    }

    public function enregistrer(Request $request, Fichiers $fichiers, int $id, int $etape): RedirectResponse
    {
        $dossier = $this->modifiable($request, $id);
        if ($dossier instanceof RedirectResponse) {
            return $dossier;
        }
        $ici = redirect()->route('candidature.depot', ['id' => $id, 'etape' => $etape]);
        $suivante = redirect()->route('candidature.depot', ['id' => $id, 'etape' => min(4, $etape + 1)]);
        $avancer = fn () => DB::raw('GREATEST(etape_actuelle, '.min(4, $etape + 1).')');

        if ($etape === 1) {
            $v = Validator::make($request->all(), [
                'nom' => 'required|string|max:100', 'prenom' => 'required|string|max:150',
                'date_naissance' => 'required|date|before:today|after:1900-01-01', 'lieu_naissance' => 'required|string|max:200',
                'nationalite' => 'required|string|max:100', 'sexe' => 'required|in:M,F', 'telephone' => 'required|string|max:30',
                'email' => 'required|email|max:150', 'adresse' => 'required|string|max:1000',
                'nom_pere' => 'nullable|string|max:200', 'nom_mere' => 'nullable|string|max:200', 'tel_urgence' => 'nullable|string|max:30',
                'bac_type' => ['required', Rule::in(array_keys(Candidatures::BACS))], 'bac_lieu' => ['required', Rule::in(Candidatures::LIEUX)],
                'bac_annee' => ['required', 'regex:/^\d{4}-\d{4}$/'], 'bac_mention' => ['required', Rule::in(Candidatures::MENTIONS)],
                'bac_etablissement' => 'nullable|string|max:200',
            ], ['required' => 'Tous les champs marqués * sont obligatoires.'], [
                'date_naissance' => 'date de naissance', 'bac_type' => 'type de bac', 'bac_lieu' => "lieu d'obtention", 'bac_annee' => "année d'obtention",
                'bac_mention' => 'mention']);
            if ($v->fails()) {
                return $ici->withInput()->with('erreur', $v->errors()->first());
            }
            $d = $v->validated();
            DB::table('candidatures')->where('id', $id)->update([
                'nom' => mb_strtoupper(trim($d['nom'])), 'prenom' => mb_convert_case(mb_strtolower(trim($d['prenom'])), MB_CASE_TITLE),
                'date_naissance' => $d['date_naissance'], 'lieu_naissance' => trim($d['lieu_naissance']), 'nationalite' => trim($d['nationalite']),
                'sexe' => $d['sexe'], 'telephone' => trim($d['telephone']), 'email' => mb_strtolower(trim($d['email'])), 'adresse' => trim($d['adresse']),
                'nom_pere' => trim($d['nom_pere'] ?? '') ?: null, 'nom_mere' => trim($d['nom_mere'] ?? '') ?: null, 'tel_urgence' => trim($d['tel_urgence'] ?? '') ?: null,
                'bac_type' => $d['bac_type'], 'bac_lieu' => $d['bac_lieu'], 'bac_annee' => $d['bac_annee'], 'bac_mention' => $d['bac_mention'],
                'bac_etablissement' => trim($d['bac_etablissement'] ?? '') ?: null, 'etape_actuelle' => $avancer(), 'updated_at' => now()]);

            return $suivante;
        }

        if ($etape === 2) {
            $choix = array_map(fn ($n) => (int) $request->input("choix$n"), [1, 2, 3]);
            $actives = $this->candidatures->filieres()->pluck('id')->map(fn ($i) => (int) $i)->all();
            if (! $choix[0]) {
                return $ici->with('erreur', 'Le premier choix est obligatoire.');
            }
            $retenus = array_filter($choix);
            if (array_diff($retenus, $actives) || count($retenus) !== count(array_unique($retenus))) {
                return $ici->with('erreur', 'Choisissez des filières différentes dans la liste.');
            }
            DB::table('candidatures')->where('id', $id)->update(['choix1_id' => $choix[0], 'choix2_id' => $choix[1] ?: null, 'choix3_id' => $choix[2] ?: null,
                'etape_actuelle' => $avancer(), 'updated_at' => now()]);

            return $suivante;
        }

        if ($etape === 3) {
            $maj = [];
            $refuses = [];
            foreach (Candidatures::PIECES as $champ => [$colonne, , $libelle]) {
                $fichier = $request->file($champ);
                if (! $fichier) {
                    continue;
                }
                $nom = $fichiers->enregistrerPiece($fichier, $champ, Candidatures::TAILLE_MAX, $champ === 'photo');
                if ($nom) {
                    $maj[$colonne] = $nom;
                } else {
                    $refuses[] = $libelle;
                }
            }
            if ($maj) {
                DB::table('candidatures')->where('id', $id)->update($maj + ['etape_actuelle' => $avancer(), 'updated_at' => now()]);
            }
            if ($refuses) {
                return $ici->with('erreur', 'Fichier refusé ('.implode(', ', $refuses).') : PDF, JPG ou PNG de 3 Mo au plus'
                    .(in_array(Candidatures::PIECES['photo'][2], $refuses, true) ? ", la photo en JPG ou PNG" : '').'.');
            }
            DB::table('candidatures')->where('id', $id)->update(['etape_actuelle' => $avancer()]);

            return $suivante;
        }

        return $ici;
    }

    public function soumettre(Request $request, int $id): RedirectResponse
    {
        $dossier = $this->modifiable($request, $id);
        if ($dossier instanceof RedirectResponse) {
            return $dossier;
        }
        $ici = redirect()->route('candidature.depot', ['id' => $id, 'etape' => 4]);
        if (! $request->boolean('certifie')) {
            return $ici->with('erreur', "Vous devez certifier l'exactitude des informations.");
        }
        if (! $dossier->date_naissance || ! $dossier->bac_type || ! $dossier->choix1_id) {
            return $ici->with('erreur', 'Complétez les informations personnelles et le choix des filières avant de soumettre.');
        }
        if ($manquantes = $this->candidatures->piecesManquantes($dossier)) {
            return $ici->with('erreur', 'Pièces obligatoires manquantes : '.implode(', ', $manquantes).'.');
        }
        DB::table('candidatures')->where('id', $id)->update(['statut' => 'soumis', 'etape_actuelle' => 4, 'date_soumission' => now(), 'updated_at' => now()]);

        return redirect()->route('candidature.succes', ['id' => $id]);
    }

    public function succes(Request $request, int $id): View|RedirectResponse
    {
        $dossier = $this->candidatures->dossier($id, $request->user('candidat')->id);
        if (! $dossier || $dossier->statut !== 'soumis') {
            return redirect()->route('candidature.dossiers');
        }

        return view('candidature.succes', ['dossier' => $dossier, 'candidat' => $request->user('candidat')]);
    }

    public function quitus(Request $request, Identite $identite, Parametres $parametres, UniversiteCourante $universite, int $id): View|RedirectResponse
    {
        $dossier = $this->candidatures->dossier($id, $request->user('candidat')->id);
        if (! $dossier || $dossier->statut === 'brouillon') {
            return redirect()->route('candidature.dossiers')->with('erreur', 'Quitus disponible après la soumission du dossier.');
        }

        return view('candidature.quitus', self::donneesQuitus($dossier, $identite, $parametres, $universite, $this->candidatures)
            + ['photo' => $dossier->doc_photo && strtolower(pathinfo($dossier->doc_photo, PATHINFO_EXTENSION)) !== 'pdf'
                ? route('candidature.piece', ['id' => $dossier->id, 'type' => 'photo']) : null]);
    }

    /** Données du quitus (communes au candidat et au personnel). */
    public static function donneesQuitus(object $dossier, Identite $identite, Parametres $parametres, UniversiteCourante $universite, Candidatures $candidatures): array
    {
        $u = $universite->get();

        return ['dossier' => $dossier, 'universite' => $u, 'annee' => $candidatures->annee(),
            'logo' => $identite->logos()['gauche'], 'armoirie' => $identite->imageParametre('armoirie_niger'),
            'telephone' => $parametres->valeur('institution_tel', ''), 'adresse' => $parametres->valeur('institution_bp', trim($u->ville.', Niger', ', ')),
            'email' => $parametres->valeur('institution_email', ''),
            'dateLimite' => $candidatures->config('date_limite', 'du Lundi 28 Juillet au Vendredi 29 Août 2025 inclus'),
            'qr' => strtoupper($u->sigle ?: $u->code).'|'.$dossier->numero_dossier.'|'.trim($dossier->nom.' '.$dossier->prenom),
            'numeroQuitus' => 'QUITUS-'.$dossier->numero_dossier.'-'.date('Ymd', strtotime((string) ($dossier->date_soumission ?? $dossier->updated_at))),
            'pieces' => array_values(array_map(fn ($p) => $p[2], array_filter(Candidatures::PIECES, fn ($p) => ! empty($dossier->{$p[0]})))),
        ];
    }

    /** Dossier du candidat, modifiable (brouillon / incomplet) et portail ouvert. */
    private function modifiable(Request $request, int $id): object
    {
        $dossier = $this->candidatures->dossier($id, $request->user('candidat')->id);
        if (! $dossier) {
            return redirect()->route('candidature.tableau');
        }
        if (! in_array($dossier->statut, Candidatures::MODIFIABLES, true)) {
            return redirect()->route('candidature.dossiers', ['voir' => $id])->with('erreur', 'Ce dossier a été soumis : il ne peut plus être modifié.');
        }
        if (! $this->candidatures->ouvert() && $request->isMethod('post')) {
            return redirect()->route('candidature.tableau')->with('erreur', $this->candidatures->config('message_ferme', 'Le dépôt de dossiers est actuellement fermé.'));
        }

        return $dossier;
    }
}
