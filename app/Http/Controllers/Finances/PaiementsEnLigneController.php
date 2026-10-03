<?php

namespace App\Http\Controllers\Finances;

use App\Autorisations\Perimetre;
use App\Http\Controllers\Controller;
use App\Support\Pagination;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\View\View;

/**
 * Suivi des paiements en ligne (portail candidats, KomiPay) — portage de
 * pages/finances/paiements_en_ligne.php, en lecture seule : synthèse,
 * recherche, filtres, statut « expiré » calculé (en attente depuis plus de
 * 15 minutes).
 *
 * Différences voulues : paiements des filières de la faculté de l'agent
 * (toute l'université avant) ; filtres contrôlés.
 */
class PaiementsEnLigneController extends Controller
{
    public const TAUX_FRAIS = 0.03;

    public const DELAI_EXPIRATION_MIN = 15;

    private const PAR_PAGE = 30;

    public const STATUTS = ['valide' => ['✅ Validé', '#dcfce7', '#166534'], 'en_attente' => ['⏳ En attente', '#fef3c7', '#92400e'],
        'echec' => ['❌ Échec', '#fee2e2', '#991b1b'], 'rembourse' => ['↩️ Remboursé', '#f3f4f6', '#374151'], 'expire' => ['⏰ Expiré', '#e5e7eb', '#4b5563']];

    public const MOYENS = ['mobile_money' => '📱 Mobile Money', 'mynita' => '🏦 MyNITA', 'amana' => '🕌 Amana', 'visa_master' => '💳 Visa/Mastercard', 'especes' => '💵 Espèces'];

    public function __invoke(Request $request, Perimetre $perimetre): View
    {
        $faculte = $perimetre->faculte($request->user());
        $q = trim((string) $request->query('q', ''));
        $statut = array_key_exists((string) $request->query('statut'), self::STATUTS) ? (string) $request->query('statut') : '';
        $moyen = array_key_exists((string) $request->query('moyen'), self::MOYENS) ? (string) $request->query('moyen') : '';
        $date = preg_match('/^\d{4}-\d{2}-\d{2}$/', (string) $request->query('date')) ? (string) $request->query('date') : '';
        $limite = now()->subMinutes(self::DELAI_EXPIRATION_MIN)->format('Y-m-d H:i:s');

        $base = DB::table('inscriptions_en_ligne as iel')->leftJoin('etudiants as e', 'e.id', '=', 'iel.etudiant_id')
            ->leftJoin('filieres as f', 'f.id', '=', 'iel.filiere_id')->leftJoin('niveaux as n', 'n.id', '=', 'iel.niveau_id')
            ->when($faculte, fn ($r) => $r->where('f.faculte_id', $faculte))
            ->when($q !== '', fn ($r) => $r->where(fn ($w) => $w->where('e.nom', 'like', "%$q%")->orWhere('e.prenoms', 'like', "%$q%")
                ->orWhere('e.numero', 'like', "%$q%")->orWhere('iel.numero_dossier', 'like', "%$q%")
                ->orWhere('iel.reference_paiement', 'like', "%$q%")->orWhere('iel.telephone_paiement', 'like', "%$q%")))
            ->when($statut === 'expire', fn ($r) => $r->where('iel.statut_paiement', 'en_attente')->where('iel.created_at', '<', $limite))
            ->when($statut === 'en_attente', fn ($r) => $r->where('iel.statut_paiement', 'en_attente')->where('iel.created_at', '>=', $limite))
            ->when($statut !== '' && ! in_array($statut, ['expire', 'en_attente'], true), fn ($r) => $r->where('iel.statut_paiement', $statut))
            ->when($moyen, fn ($r) => $r->where('iel.moyen_paiement', $moyen))
            ->when($date, fn ($r) => $r->whereDate('iel.date_paiement', $date));

        $totaux = (clone $base)->selectRaw("COALESCE(SUM(CASE WHEN iel.statut_paiement='valide' THEN iel.montant ELSE 0 END),0) AS total_base,
            COUNT(CASE WHEN iel.statut_paiement='en_attente' AND iel.created_at >= ? THEN 1 END) AS nb_attente,
            COUNT(CASE WHEN iel.statut_paiement='echec' THEN 1 END) AS nb_echec,
            COUNT(CASE WHEN iel.statut_paiement='en_attente' AND iel.created_at < ? THEN 1 END) AS nb_expire", [$limite, $limite])->first();
        $total = (clone $base)->count();
        $pagination = Pagination::calculer($total, self::PAR_PAGE, (int) $request->query('p', 1));
        $totalBase = (float) $totaux->total_base;

        return view('finances.paiements_en_ligne', [
            'paiements' => $base->orderByDesc('iel.created_at')->offset($pagination['offset'])->limit(self::PAR_PAGE)
                ->get(['iel.*', 'e.nom', 'e.prenoms', 'e.numero as matricule', 'f.libelle as filiere', 'n.code as niveau']),
            'total' => $total,
            'pagination' => $pagination,
            'totalBase' => $totalBase,
            'totalFrais' => round($totalBase * self::TAUX_FRAIS),
            'nbAttente' => (int) $totaux->nb_attente,
            'nbEchec' => (int) $totaux->nb_echec,
            'nbExpire' => (int) $totaux->nb_expire,
            'limite' => $limite,
            'filtres' => compact('q', 'statut', 'moyen', 'date'),
        ]);
    }
}
