<?php

namespace App\Http\Controllers\Etudiants;

use App\Http\Controllers\Controller;
use App\Services\Journal;
use App\Services\Inscriptions;
use App\Services\Matricules;
use App\Support\Format;
use App\Support\ModeleImportEtudiants;
use App\Support\Tableur;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\View\View;
use Symfony\Component\HttpFoundation\BinaryFileResponse;
use Throwable;

/**
 * Import en masse avec inscription et paiement — portage de
 * pages/etudiants/import_etudiants.php. Chaque ligne crée l'étudiant (avec
 * matricule), son inscription dans la promotion choisie (créée au besoin,
 * maquette copiée) et le paiement des frais.
 *
 * Différences voulues :
 *  - matricules attribués par le service commun, sous verrou, en tenant
 *    compte des matricules fournis dans le fichier (l'ancien calcul unique
 *    au départ pouvait redonner un matricule fourni plus haut) ;
 *  - la ligne d'en-têtes est repérée par sa colonne « Nom » : un CSV dont
 *    l'en-tête commençait par « Nom » prenait la 1re ligne d'étudiant pour
 *    l'en-tête, et toutes les lignes tombaient en « Nom manquant » ;
 *  - sexe absent ou invalide : laissé vide (l'ancien code mettait « M ») ;
 *    année du bac et e-mail invalides : laissés vides au lieu de faire
 *    échouer la ligne ;
 *  - mode de paiement : « MyNITA » (absent de la base, la ligne échouait)
 *    est enregistré comme Mobile Money ; un mode inconnu prend le mode
 *    par défaut ;
 *  - année, filière et niveau doivent exister ; le paiement est rattaché à
 *    l'année ; l'agent qui importe est enregistré ;
 *  - le message technique d'une erreur n'est plus affiché.
 */
class ImportEtudiantsController extends Controller
{
    /** En-têtes reconnus (après normalisation) pour chaque champ. */
    private const COLONNES = [
        'matricule' => ['matricule', 'numero', 'numéro', 'numero_etudiant', 'nie'],
        'nom' => ['nom'],
        'prenoms' => ['prenoms', 'prénom', 'prenom', 'prénoms', 'prenoms_m/f'],
        'sexe' => ['sexe'],
        'date_naissance' => ['date_naissance', 'datenaissance', 'date_naissance_jj_mm_aaaa_'],
        'lieu_naissance' => ['lieu_naissance'],
        'nationalite' => ['nationalite', 'nationalité'],
        'telephone' => ['telephone', 'téléphone'],
        'email' => ['email'],
        'adresse' => ['adresse'],
        'nom_pere' => ['nom_pere', 'nom_père'],
        'nom_mere' => ['nom_mere', 'nom_mère'],
        'tel_urgence' => ['tel_urgence'],
        'bac_serie' => ['bac_serie', 'bac_série'],
        'bac_annee' => ['bac_annee', 'bac_année'],
        'bac_mention' => ['bac_mention'],
        'etablissement_origine' => ['etablissement_origine'],
        'numero_dossier' => ['numero_dossier', 'n°_dossier'],
        'montant_paye' => ['montant_paye', 'montant'],
        'mode_paiement' => ['mode_paiement'],
    ];

    public function __construct(
        private readonly Matricules $matricules,
        private readonly Inscriptions $inscriptions,
        private readonly Journal $journal,
    ) {}

    public function formulaire(): View
    {
        return view('etudiants.import_masse', [
            'annees' => DB::table('annees_academiques')->orderByDesc('libelle')->get(),
            'filieres' => DB::table('filieres')->where('actif', 1)->orderBy('libelle')->get(['id', 'libelle']),
            'niveaux' => DB::table('niveaux')->orderBy('code')->get(['id', 'code', 'libelle']),
            'anneeActive' => (int) DB::table('annees_academiques')->where('active', 1)->value('id'),
            'colonnes' => ['Nom' => true, 'Prenoms' => true] + array_fill_keys(['Sexe (M/F)', 'Date_Naissance', 'Lieu_Naissance', 'Nationalite', 'Telephone', 'Email', 'Adresse', 'Nom_Pere', 'Nom_Mere', 'Tel_Urgence', 'BAC_Serie', 'BAC_Annee', 'BAC_Mention', 'Etablissement_Origine', 'Numero_Dossier', 'Montant_Paye', 'Mode_Paiement'], false),
        ]);
    }

    public function modele(): BinaryFileResponse
    {
        return response()->download(ModeleImportEtudiants::creer(), 'template_import_etudiants.xlsx', [
            'Content-Type' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
            'Cache-Control' => 'no-cache',
        ])->deleteFileAfterSend();
    }

    public function importer(Request $request): RedirectResponse
    {
        $anneeId = (int) $request->input('annee_id');
        $filiereId = (int) $request->input('filiere_id');
        $niveauId = (int) $request->input('niveau_id');
        $montantDefaut = max(0, (float) $request->input('montant_def', 10000));
        $modeDefaut = Inscriptions::mode((string) $request->input('mode_def')) ?? 'especes';
        $fichier = $request->file('fichier');

        $erreurs = [];
        if (! $anneeId || ! DB::table('annees_academiques')->where('id', $anneeId)->exists()) {
            $erreurs[] = 'Année académique requise.';
        }
        if (! $filiereId || ! DB::table('filieres')->where('id', $filiereId)->where('actif', 1)->exists()) {
            $erreurs[] = 'Filière requise.';
        }
        if (! $niveauId || ! DB::table('niveaux')->where('id', $niveauId)->exists()) {
            $erreurs[] = 'Niveau requis.';
        }
        if (! $fichier || ! $fichier->isValid()) {
            $erreurs[] = 'Fichier requis.';
        }
        if ($erreurs) {
            return back()->withInput()->with('erreur', implode(' ', $erreurs));
        }

        $lignes = $this->lire($fichier->getRealPath(), strtolower($fichier->getClientOriginalExtension()));
        $enTete = $this->trouverEnTete($lignes);
        if ($enTete === null) {
            return back()->withInput()->with('erreur', $lignes
                ? "Ligne d'en-têtes introuvable : le fichier doit contenir les titres de colonnes (Nom, Prenoms…), comme le template."
                : "Le fichier est vide ou ne contient que l'en-tête.");
        }
        [$numeroEnTete, $index] = $enTete;

        $promoId = $this->inscriptions->promotion($filiereId, $niveauId, $anneeId);
        $utilisateur = $request->user()->id;
        $creees = $doublons = $echecs = 0;
        $details = [];

        $this->matricules->sousVerrou(function () use ($lignes, $numeroEnTete, $index, $promoId, $niveauId, $anneeId, $filiereId, $montantDefaut, $modeDefaut, $utilisateur, &$creees, &$doublons, &$echecs, &$details) {
            foreach ($lignes as $numero => $cols) {
                if ($numero <= $numeroEnTete || ! array_filter($cols, fn ($v) => trim((string) $v) !== '')) {
                    continue;
                }
                $v = fn (string $cle) => isset($index[$cle]) ? trim((string) ($cols[$index[$cle]] ?? '')) : '';
                $ligne = "Ligne $numero";

                $nom = mb_strtoupper($v('nom'));
                $prenoms = mb_convert_case(mb_strtolower($v('prenoms')), MB_CASE_TITLE);
                if ($nom === '') {
                    $details[] = "$ligne : Nom manquant — ignorée";
                    $echecs++;

                    continue;
                }
                $ddn = Format::dateImport($v('date_naissance'));
                if (DB::table('etudiants')->where('nom', $nom)->where('prenoms', $prenoms)
                    ->when($ddn, fn ($q) => $q->where('date_naissance', $ddn))->exists()) {
                    $details[] = "$ligne : $nom $prenoms — étudiant déjà existant, ignoré";
                    $doublons++;

                    continue;
                }

                $matricule = $v('matricule');
                if ($matricule !== '' && DB::table('etudiants')->where('numero', $matricule)->exists()) {
                    $details[] = "$ligne : Matricule $matricule déjà existant — ignoré";
                    $doublons++;

                    continue;
                }
                $matricule = $matricule !== '' ? $matricule : Matricules::formater($this->matricules->dernier() + 1);

                $montant = $v('montant_paye') !== '' ? (float) str_replace([' ', ','], ['', '.'], $v('montant_paye')) : $montantDefaut;
                $mode = Inscriptions::mode($v('mode_paiement')) ?? $modeDefaut;
                $sexe = mb_strtoupper(mb_substr($v('sexe'), 0, 1));
                $email = mb_strtolower($v('email'));

                try {
                    DB::transaction(function () use ($v, $matricule, $nom, $prenoms, $sexe, $ddn, $email, $filiereId, $promoId, $niveauId, $anneeId, $montant, $mode, $utilisateur) {
                        $etId = DB::table('etudiants')->insertGetId([
                            'numero' => $matricule, 'nom' => $nom, 'prenoms' => $prenoms,
                            'sexe' => in_array($sexe, ['M', 'F'], true) ? $sexe : null,
                            'date_naissance' => $ddn, 'lieu_naissance' => $v('lieu_naissance') ?: null,
                            'nationalite' => $v('nationalite') ?: 'Nigérienne',
                            'telephone' => $v('telephone') ?: null,
                            'email' => filter_var($email, FILTER_VALIDATE_EMAIL) ? $email : null,
                            'adresse' => $v('adresse') ?: null, 'nom_pere' => $v('nom_pere') ?: null,
                            'nom_mere' => $v('nom_mere') ?: null, 'tel_urgence' => $v('tel_urgence') ?: null,
                            'bac_serie' => $v('bac_serie') ?: null,
                            'bac_annee' => preg_match('/^(19|20)\d\d$/', $v('bac_annee')) ? $v('bac_annee') : null,
                            'bac_mention' => $v('bac_mention') ?: null,
                            'etablissement_origine' => $v('etablissement_origine') ?: null,
                            'numero_dossier' => $v('numero_dossier') ?: null,
                            'filiere_id' => $filiereId, 'statut_etudiant' => 'actif',
                        ]);
                        $insId = DB::table('inscriptions')->insertGetId([
                            'etudiant_id' => $etId, 'promotion_id' => $promoId, 'niveau_id' => $niveauId, 'annee_id' => $anneeId,
                            'date_inscription' => now()->toDateString(), 'statut' => 'inscrit',
                            'montant_paye' => $montant, 'mode_paiement' => $mode, 'saisie_par' => $utilisateur,
                        ]);
                        if ($montant > 0) {
                            DB::table('paiements')->insert([
                                'etudiant_id' => $etId, 'inscription_id' => $insId, 'type_paiement' => 'inscription',
                                'montant_du' => 10000, 'montant_paye' => $montant, 'date_paiement' => now()->toDateString(),
                                'mode_paiement' => $mode, 'statut' => 'complet', 'saisi_par' => $utilisateur, 'annee_id' => $anneeId,
                            ]);
                        }
                    });
                    $creees++;
                } catch (Throwable $e) {
                    Log::error("Import étudiants, ligne $numero : ".$e->getMessage());
                    $details[] = "$ligne : $nom $prenoms — enregistrement impossible";
                    $echecs++;
                }
            }
        });

        $message = "✅ Import terminé — $creees étudiant(s) créé(s)".($doublons ? ", $doublons doublon(s) ignoré(s)" : '').($echecs ? ", $echecs erreur(s)" : '');
        $this->journal->action('importer', 'etudiant', "Import avec inscription : $creees étudiant(s) — promotion $promoId");

        return redirect()->route('import_etudiants')
            ->with($echecs || $doublons ? 'avertissement' : 'succes', $message)
            ->with('details_import', $details);
    }

    /** @return array<int, list<string>> lignes indexées par numéro (1, 2, …) */
    private function lire(string $chemin, string $extension): array
    {
        if ($extension === 'xlsx') {
            return Tableur::xlsx($chemin);
        }
        $texte = Tableur::texteCsv($chemin);
        $separateur = Tableur::separateur(strtok($texte, "\n") ?: '');
        $lignes = [];
        foreach (array_values(array_filter(explode("\n", $texte), fn ($l) => trim($l) !== '')) as $i => $ligne) {
            $lignes[$i + 1] = str_getcsv($ligne, $separateur, '"', '');
        }

        return $lignes;
    }

    /**
     * Ligne d'en-têtes = première ligne (parmi les 10 premières) dont une
     * colonne s'appelle « Nom ». Retourne [numéro de ligne, index des champs].
     */
    private function trouverEnTete(array $lignes): ?array
    {
        foreach (array_slice($lignes, 0, 10, true) as $numero => $cols) {
            $index = [];
            foreach (self::COLONNES as $cle => $variantes) {
                foreach ($cols as $i => $entete) {
                    // « Sexe* (M/F) » → sexe ; « Date_Naissance (JJ/MM/AAAA) » → date_naissance
                    $propre = preg_replace('/\s*\(.*\)/', '', mb_strtolower(trim((string) $entete)));
                    $propre = preg_replace('/_+/', '_', trim(str_replace(['*', ' ', '/', '-'], ['', '_', '_', '_'], $propre), '_'));
                    foreach ($variantes as $variante) {
                        if ($propre === $variante || str_starts_with($propre, $variante.'_')) {
                            $index[$cle] = $i;
                            break 2;
                        }
                    }
                }
            }
            if (isset($index['nom'])) {
                return [$numero, $index];
            }
        }

        return null;
    }
}
