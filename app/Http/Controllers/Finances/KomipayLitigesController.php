<?php

namespace App\Http\Controllers\Finances;

use App\Http\Controllers\Controller;
use App\Services\InscriptionEnLigne;
use App\Services\Journal;
use App\Services\Paiement\KomiPay;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\View\View;

/**
 * Litiges KomiPay (administrateur) — portage de pages/finances/
 * komipay_litiges.php : paiements en ligne restés sans confirmation,
 * revérification, confirmation ou rejet manuels.
 *
 * Différence voulue : la page porte sur les inscriptions payées en ligne
 * (inscriptions_en_ligne), où se trouvent les transactions KomiPay ; elle
 * cherchait dans la table des paiements un mode « komipay » et une colonne
 * reference_komipay qui n'existent pas, et ne trouvait donc jamais rien.
 * Une confirmation (vérifiée ou manuelle) finalise l'inscription
 * (matricule, inscription, reçu) ; chaque action est journalisée.
 */
class KomipayLitigesController extends Controller
{
    public function __construct(private readonly InscriptionEnLigne $service, private readonly Journal $journal) {}

    public function index(): View
    {
        $litiges = DB::table('inscriptions_en_ligne as il')->leftJoin('etudiants as e', 'e.id', '=', 'il.etudiant_id')
            ->whereNotNull('il.reference_paiement')
            ->where(fn ($w) => $w->where(fn ($a) => $a->whereIn('il.statut_paiement', ['en_attente', 'echec'])->where('il.updated_at', '<', now()->subMinutes(30)))
                ->orWhere(fn ($b) => $b->where('il.statut_paiement', 'valide')->where('il.statut', '!=', 'complete')))
            ->where('il.statut', '!=', 'annule')
            ->orderByDesc('il.created_at')->limit(200)
            ->get(['il.*', 'e.nom', 'e.prenoms', 'e.numero', DB::raw('TIMESTAMPDIFF(MINUTE, il.created_at, NOW()) AS age_minutes')]);

        return view('finances.komipay_litiges', ['litiges' => $litiges,
            'montant' => $litiges->sum(fn ($l) => $this->service->montant((float) $l->montant)['total'])]);
    }

    public function reverifier(Request $request, KomiPay $komipay): RedirectResponse
    {
        $ins = $this->litige($request);
        if (! $ins) {
            return $this->retour()->with('erreur', 'Paiement introuvable.');
        }
        $verif = $komipay->verifier($ins->reference_paiement);
        $this->journal->action('verifier', 'komipay', "Revérification KomiPay de l'inscription en ligne #{$ins->id} : {$verif['statut_transaction']}", (int) $ins->id);
        if ($verif['statut_transaction'] === 'Success') {
            DB::table('inscriptions_en_ligne')->where('id', $ins->id)->update(['statut_paiement' => 'valide', 'date_paiement' => $ins->date_paiement ?? now()]);
            $matricule = $this->service->finaliser((int) $ins->id);

            return $this->retour()->with('succes', "Paiement confirmé par KomiPay : inscription finalisée (matricule $matricule).");
        }

        return $this->retour()->with('info', 'Statut KomiPay : '.$verif['statut_transaction'].($verif['message'] ? ' — '.$verif['message'] : ''));
    }

    public function confirmer(Request $request): RedirectResponse
    {
        $ins = $this->litige($request);
        if (! $ins) {
            return $this->retour()->with('erreur', 'Paiement introuvable.');
        }
        DB::table('inscriptions_en_ligne')->where('id', $ins->id)->update(['statut_paiement' => 'valide', 'date_paiement' => $ins->date_paiement ?? now()]);
        $matricule = $this->service->finaliser((int) $ins->id);
        $this->journal->action('confirmer', 'komipay', "Paiement en ligne #{$ins->id} (réf. {$ins->reference_paiement}) confirmé manuellement après litige", (int) $ins->id);

        return $this->retour()->with('succes', "Paiement confirmé manuellement : inscription finalisée (matricule $matricule).");
    }

    public function rejeter(Request $request): RedirectResponse
    {
        $ins = $this->litige($request);
        if (! $ins || $ins->statut === 'complete') {
            return $this->retour()->with('erreur', $ins ? 'Inscription déjà finalisée : elle ne peut plus être rejetée ici.' : 'Paiement introuvable.');
        }
        DB::table('inscriptions_en_ligne')->where('id', $ins->id)->update(['statut_paiement' => 'rembourse', 'statut' => 'annule']);
        $this->journal->action('rejeter', 'komipay', "Paiement en ligne #{$ins->id} (réf. {$ins->reference_paiement}) rejeté manuellement", (int) $ins->id);

        return $this->retour()->with('succes', 'Paiement rejeté (marqué remboursé).');
    }

    private function litige(Request $request): ?object
    {
        return DB::table('inscriptions_en_ligne')->where('id', (int) $request->input('paiement_id'))->whereNotNull('reference_paiement')
            ->where('statut', '!=', 'annule')->first();
    }

    private function retour(): RedirectResponse
    {
        return redirect()->route('komipay_litiges');
    }
}
