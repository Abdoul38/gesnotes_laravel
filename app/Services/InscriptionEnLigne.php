<?php

namespace App\Services;

use App\Services\Paiement\KomiPay;
use Illuminate\Support\Facades\DB;

/**
 * Inscription académique en ligne (portail candidats) — règles de
 * candidature/pages/inscription.php : identification par matricule (ancien
 * étudiant, décision de passage obligatoire) ou par n° de dossier / dépôt
 * (nouveau bachelier, L1 ou DUT1), restrictions du portail, tarif du
 * niveau + frais de transaction, puis finalisation après paiement
 * (matricule, promotion, inscription, reçu).
 */
class InscriptionEnLigne
{
    public const MOYENS = ['mobile_money' => 'Mobile Money', 'mynita' => 'MyNITA', 'amana' => 'Amana Microfinance', 'visa_master' => 'Carte Visa/Mastercard'];

    public function __construct(private readonly Candidatures $candidatures, private readonly Matricules $matricules, private readonly Inscriptions $inscriptions,
        private readonly KomiPay $komipay) {}

    /** Moyens de paiement activés (config komipay.moyens) : [code => libellé]. */
    public function moyens(): array
    {
        return array_intersect_key(self::MOYENS, array_flip(config('komipay.moyens')));
    }

    /**
     * Lance un paiement Mobile Money, MyNITA ou Amana (la carte bancaire
     * passe par sa propre page : authentification 3-D Secure). Partagé par
     * le portail et l'application mobile.
     *
     * @return array{ok: bool, message?: string}
     */
    public function lancerPaiement(object $ins, string $moyen, string $telephone): array
    {
        if ($ins->statut_paiement === 'valide' || $ins->statut === 'complete') {
            return ['ok' => false, 'message' => 'Le paiement de cette inscription est déjà validé.'];
        }
        if ($ins->reference_paiement && $ins->statut_paiement === 'en_attente' && ! $this->expire($ins)) {
            return ['ok' => false, 'message' => 'Un paiement est déjà en attente de validation : validez-le sur votre téléphone ou attendez son expiration.'];
        }
        if (! in_array($moyen, config('komipay.moyens'), true) || $moyen === 'visa_master') {
            return ['ok' => false, 'message' => 'Moyen de paiement invalide.'];
        }
        $telephone = trim($telephone);
        if (! preg_match('/^\+?[0-9 ]{8,16}$/', $telephone)) {
            return ['ok' => false, 'message' => 'Le numéro de téléphone est obligatoire (8 chiffres, ou +227…).'];
        }
        $total = $this->montant((float) $ins->montant)['total'];
        $reference = 'INS'.$ins->id.'-'.date('ymdHis');
        if ($moyen === 'mobile_money') {
            $r = $this->komipay->payerMobileMoney('airtel_money', $total, preg_replace('/\D/', '', $telephone), trim($ins->nom.' '.$ins->prenoms) ?: 'Candidat', $reference);
            $statut = 'valide'; // Mobile Money opérateur : réponse synchrone
        } else {
            $intl = str_starts_with($telephone, '+') ? preg_replace('/[^0-9+]/', '', $telephone) : '+227'.preg_replace('/\D/', '', $telephone);
            $r = $this->komipay->payerSTA($moyen === 'mynita' ? 'nita_transfert' : 'amana_transfert', $total, $intl, 'NIGER', $reference);
            $statut = 'en_attente'; // le payeur valide ensuite sur son compte
        }
        if (! $r['ok']) {
            return ['ok' => false, 'message' => 'Paiement refusé : '.$r['message']];
        }
        DB::table('inscriptions_en_ligne')->where('id', $ins->id)->update(['moyen_paiement' => $moyen, 'operateur' => $moyen === 'mobile_money' ? 'airtel' : null,
            'telephone_paiement' => mb_substr($telephone, 0, 30), 'reference_paiement' => $r['reference_transaction'], 'code_achat_komipay' => $r['code_achat'] ?? null,
            'statut_paiement' => $statut, 'date_paiement' => $statut === 'valide' ? now() : null, 'updated_at' => now()]);

        return ['ok' => true];
    }

    /** Suivi du paiement : « valide », « echec », « expire » ou « en_attente » (vérifié auprès de KomiPay). */
    public function suivre(object $ins): string
    {
        if (in_array($ins->statut_paiement, ['valide', 'echec'], true)) {
            return $ins->statut_paiement;
        }
        if ($this->expire($ins)) {
            DB::table('inscriptions_en_ligne')->where('id', $ins->id)->update(['statut_paiement' => 'echec']);

            return 'expire';
        }
        if (! $ins->reference_paiement) {
            return 'en_attente';
        }
        $verif = $this->komipay->verifier($ins->reference_paiement, 15);
        if ($verif['statut_transaction'] === 'Echec') {
            DB::table('inscriptions_en_ligne')->where('id', $ins->id)->update(['statut_paiement' => 'echec']);
        }

        return ['Success' => 'valide', 'Echec' => 'echec'][$verif['statut_transaction']] ?? 'en_attente';
    }

    /**
     * Confirmation : paiement vérifié auprès de KomiPay (jamais sur la seule
     * parole du client), puis finalisation.
     *
     * @return array{ok: bool, message?: string, recommencer?: bool, matricule?: string}
     */
    public function confirmer(object $ins): array
    {
        if ($ins->statut_paiement !== 'valide') {
            if ($this->expire($ins)) {
                DB::table('inscriptions_en_ligne')->where('id', $ins->id)->update(['statut_paiement' => 'echec']);

                return ['ok' => false, 'recommencer' => true, 'message' => 'Le délai de paiement ('.config('komipay.expiration_minutes').' minutes) a expiré. Veuillez recommencer le paiement.'];
            }
            if (! $ins->reference_paiement) {
                return ['ok' => false, 'recommencer' => true, 'message' => 'Aucune transaction de paiement trouvée. Veuillez recommencer.'];
            }
            $verif = $this->komipay->verifier($ins->reference_paiement);
            if ($verif['statut_transaction'] !== 'Success') {
                if ($verif['statut_transaction'] === 'Echec') {
                    DB::table('inscriptions_en_ligne')->where('id', $ins->id)->update(['statut_paiement' => 'echec']);
                }

                return ['ok' => false, 'message' => 'Paiement non confirmé pour le moment : '.($verif['message'] ?: 'transaction en attente ou échouée').'. Réessayez après avoir validé sur votre téléphone.'];
            }
            DB::table('inscriptions_en_ligne')->where('id', $ins->id)->update(['statut_paiement' => 'valide', 'date_paiement' => now()]);
        }

        return ['ok' => true, 'matricule' => $this->finaliser((int) $ins->id)];
    }

    public function ouverte(): bool
    {
        return $this->candidatures->config('inscription_ouverte', '1') !== '0';
    }

    /** [base, frais, total] : les frais de transaction sont à la charge de l'étudiant. */
    public function montant(float $base): array
    {
        $frais = round($base * (float) config('komipay.taux_frais'));

        return ['base' => $base, 'frais' => $frais, 'total' => $base + $frais];
    }

    public function anneeActive(): ?object
    {
        return DB::table('annees_academiques')->where('active', 1)->first(['id', 'libelle']);
    }

    /**
     * Identifie l'étudiant et ouvre (ou retrouve) son inscription en ligne.
     *
     * @return array{erreur?: string, deja?: object, inscription?: int}
     */
    public function identifier(string $identifiant, string $sigle, ?int $candidatId): array
    {
        $identifiant = mb_strtoupper(trim($identifiant));
        $annee = $this->anneeActive();
        if ($identifiant === '' || ! $annee) {
            return ['erreur' => $identifiant === '' ? 'Veuillez saisir votre identifiant.' : 'Aucune année académique active : contactez la scolarité.'];
        }
        $nouveau = str_starts_with($identifiant, 'D') || ($sigle !== '' && str_starts_with($identifiant, mb_strtoupper($sigle)));
        $et = DB::table('etudiants as e')->leftJoin('filieres as f', 'f.id', '=', 'e.filiere_id')
            ->when($nouveau, fn ($q) => $q->where(fn ($w) => $w->whereRaw('UPPER(e.numero_dossier) = ?', [$identifiant])->orWhereRaw('UPPER(e.numero_depot) = ?', [$identifiant])),
                fn ($q) => $q->where('e.numero', $identifiant))
            ->first(['e.id', 'e.nom', 'e.prenoms', 'e.filiere_id', 'f.libelle as filiere', 'f.faculte']);
        if (! $et) {
            return ['erreur' => $nouveau ? 'Numéro introuvable. Vérifiez votre N° dossier ou N° dépôt.' : 'Matricule introuvable. Vérifiez votre numéro.'];
        }

        $deja = DB::table('inscriptions_en_ligne')->where(['etudiant_id' => $et->id, 'annee_id' => $annee->id, 'statut_paiement' => 'valide'])->first();
        if ($deja) {
            return ['deja' => $deja];
        }
        $scolarite = DB::table('inscriptions as i')->join('promotions as p', 'p.id', '=', 'i.promotion_id')
            ->where(['i.etudiant_id' => $et->id, 'p.annee_id' => $annee->id, 'i.statut' => 'inscrit'])->exists();
        if ($scolarite) {
            return ['erreur' => 'Vous êtes déjà inscrit(e) pour cette année à la scolarité. Passez au service de la scolarité pour votre reçu.'];
        }

        if ($nouveau) {
            $anterieure = DB::table('inscriptions as i')->join('promotions as p', 'p.id', '=', 'i.promotion_id')
                ->where('i.etudiant_id', $et->id)->where('p.annee_id', '!=', $annee->id)->where('i.statut', 'inscrit')->exists();
            if ($anterieure) {
                return ['erreur' => "Votre numéro de dossier n'est valable que pour la première inscription. Vous avez déjà été inscrit(e) lors d'une année précédente : utilisez votre matricule."];
            }
            $niveau = DB::table('niveaux')->whereIn('code', ['L1', 'DUT1'])->orderByRaw("FIELD(code, 'L1', 'DUT1')")->first(['id', 'code']);
        } else {
            $niveau = DB::table('passages_niveau as pn')->join('niveaux as n', 'n.id', '=', 'pn.niveau_suivant_id')
                ->where(['pn.etudiant_id' => $et->id, 'pn.annee_arrivee_id' => $annee->id])->first(['n.id', 'n.code']);
            if (! $niveau) {
                return ['erreur' => "Votre dossier ne comporte pas de décision de passage pour l'année en cours. Vous n'êtes pas autorisé(e) à vous inscrire. Veuillez contacter la scolarité."];
            }
        }
        if (! $et->filiere_id || ! $niveau) {
            return ['erreur' => "Votre filière n'a pas encore été attribuée : contactez la scolarité avant de payer."];
        }
        if ($erreur = $this->restriction($nouveau ? 'nouveau' : 'ancien', $et, (int) $niveau->id)) {
            return ['erreur' => $erreur];
        }

        // Une tentative non payée de la même année est reprise plutôt que dupliquée
        $enCours = DB::table('inscriptions_en_ligne')->where(['etudiant_id' => $et->id, 'annee_id' => $annee->id, 'statut' => 'en_cours'])
            ->whereIn('statut_paiement', ['en_attente', 'echec'])->whereNull('reference_paiement')->value('id');
        if ($enCours) {
            return ['inscription' => (int) $enCours];
        }
        $candidature = $nouveau ? DB::table('candidatures')->whereRaw('UPPER(numero_dossier) = ? OR UPPER(numero_depot) = ?', [$identifiant, $identifiant])->first(['id', 'candidat_id', 'numero_depot']) : null;
        $id = DB::table('inscriptions_en_ligne')->insertGetId([
            'candidature_id' => $candidature?->id, 'candidat_id' => $candidature?->candidat_id ?? $candidatId,
            'etudiant_id' => $et->id, 'numero_dossier' => $nouveau ? $identifiant : '', 'numero_depot' => (string) ($candidature?->numero_depot ?? ''),
            'filiere_id' => $et->filiere_id, 'niveau_id' => $niveau->id, 'annee_id' => $annee->id,
            'montant' => (float) (DB::table('tarifs_niveaux')->where('niveau_id', $niveau->id)->value('montant') ?? 10000),
            'moyen_paiement' => 'mobile_money', 'statut' => 'en_cours', 'statut_paiement' => 'en_attente', 'created_at' => now(), 'updated_at' => now(),
        ]);

        return ['inscription' => $id];
    }

    /** Inscription en ligne avec filière, niveau, année et identité de l'étudiant. */
    public function trouver(int $id): ?object
    {
        return DB::table('inscriptions_en_ligne as il')->leftJoin('etudiants as e', 'e.id', '=', 'il.etudiant_id')
            ->leftJoin('filieres as f', 'f.id', '=', 'il.filiere_id')->leftJoin('niveaux as n', 'n.id', '=', 'il.niveau_id')
            ->leftJoin('annees_academiques as a', 'a.id', '=', 'il.annee_id')
            ->where('il.id', $id)
            ->first(['il.*', 'e.nom', 'e.prenoms', 'e.numero', 'e.date_naissance', 'e.email', 'e.telephone', 'f.libelle as filiere_lib', 'f.faculte',
                'n.code as niveau_code', 'n.libelle as niveau_lib', 'a.libelle as annee_lib']);
    }

    /**
     * Paiement en attente au-delà du délai, compté depuis le lancement du
     * paiement (dernière écriture de la ligne : celle qui a enregistré la
     * transaction). Différence voulue (décision de l'université) : avant,
     * depuis l'identification — un étudiant lent à choisir son moyen de
     * paiement voyait le sien expirer plus tôt. Sans transaction lancée,
     * rien n'expire.
     */
    public function expire(object $ins): bool
    {
        return in_array($ins->statut_paiement, ['en_attente', 'en_cours'], true) && $ins->reference_paiement
            && strtotime((string) $ins->updated_at) + (int) config('komipay.expiration_minutes') * 60 < time();
    }

    /**
     * Paiement validé : matricule (si besoin), promotion, inscription à la
     * scolarité et reçu, une seule fois même si deux confirmations arrivent
     * ensemble. Retourne le matricule.
     */
    public function finaliser(int $id): string
    {
        return $this->matricules->sousVerrou(fn () => DB::transaction(function () use ($id) {
            $ins = DB::table('inscriptions_en_ligne')->where('id', $id)->lockForUpdate()->first();
            if ($ins->statut === 'complete' && $ins->matricule) {
                return (string) $ins->matricule;
            }
            $et = DB::table('etudiants')->where('id', $ins->etudiant_id)->first(['id', 'numero', 'filiere_id']);
            $matricule = $et->numero && $et->numero !== '0' ? (string) $et->numero : Matricules::formater($this->matricules->dernier() + 1);
            DB::table('etudiants')->where('id', $et->id)->update(['numero' => $matricule]);
            if ($et->filiere_id && $ins->niveau_id && $ins->annee_id) {
                $promotion = $this->inscriptions->promotion((int) $et->filiere_id, (int) $ins->niveau_id, (int) $ins->annee_id);
                $existe = DB::table('inscriptions')->where(['etudiant_id' => $et->id, 'annee_id' => $ins->annee_id])->orWhere(fn ($w) => $w->where(['etudiant_id' => $et->id, 'promotion_id' => $promotion]))->exists();
                if (! $existe) {
                    DB::table('inscriptions')->insert(['etudiant_id' => $et->id, 'promotion_id' => $promotion, 'niveau_id' => $ins->niveau_id, 'annee_id' => $ins->annee_id,
                        'date_inscription' => now()->toDateString(), 'statut' => 'inscrit', 'montant_paye' => (int) $ins->montant,
                        'mode_paiement' => $ins->moyen_paiement === 'visa_master' ? 'virement' : 'mobile_money',
                        'recu_numero' => 'INS-'.date('Y').'-'.str_pad((string) $id, 6, '0', STR_PAD_LEFT),
                        'notes' => 'Inscription en ligne — paiement '.(self::MOYENS[$ins->moyen_paiement] ?? $ins->moyen_paiement).' réf. '.$ins->reference_paiement]);
                }
            }
            DB::table('inscriptions_en_ligne')->where('id', $id)->update(['statut' => 'complete', 'statut_paiement' => 'valide',
                'date_paiement' => $ins->date_paiement ?? now(), 'matricule' => $matricule,
                'recu_numero' => 'INS-'.date('Y').'-'.str_pad((string) $id, 6, '0', STR_PAD_LEFT), 'updated_at' => now()]);

            return $matricule;
        }));
    }

    private function restriction(string $type, object $et, int $niveauId): ?string
    {
        $c = fn (string $k) => $this->candidatures->config($k, '');
        if ($c('ins_type_candidat') !== '' && $c('ins_type_candidat') !== $type) {
            return $c('ins_type_candidat') === 'ancien'
                ? "L'inscription en ligne est réservée aux anciens étudiants (matricule uniquement)."
                : "L'inscription en ligne est réservée aux nouveaux bacheliers (N° dossier ou dépôt uniquement).";
        }
        if ($c('ins_filiere_id') !== '' && (int) $c('ins_filiere_id') !== (int) $et->filiere_id) {
            return "L'inscription en ligne est réservée aux étudiants de la filière ".DB::table('filieres')->where('id', (int) $c('ins_filiere_id'))->value('libelle').'.';
        }
        if ($c('ins_niveau_id') !== '' && (int) $c('ins_niveau_id') !== $niveauId) {
            return "L'inscription en ligne est réservée aux étudiants de niveau ".DB::table('niveaux')->where('id', (int) $c('ins_niveau_id'))->value('code').'.';
        }
        if ($c('ins_faculte') !== '' && $c('ins_faculte') !== (string) $et->faculte) {
            return "L'inscription en ligne est réservée aux étudiants de la faculté {$c('ins_faculte')}.";
        }

        return null;
    }
}
