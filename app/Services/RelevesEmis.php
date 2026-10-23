<?php

namespace App\Services;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use RuntimeException;

/**
 * Registre des relevés émis (QR d'authentification) — ancien bloc de
 * releve_notes.php. Un numéro par (étudiant, PV), conservé d'une impression
 * à l'autre ; nom, mention et moyenne remis à jour.
 *
 * Différence voulue : numéro suivant = plus grand numéro de l'année + 1, sous
 * verrou (l'ancien COUNT + 1, sans verrou, pouvait donner deux fois le même
 * numéro après une suppression ou deux impressions simultanées).
 *
 * Ajout : le contenu du relevé tel qu'imprimé (notes par UE et matière,
 * résultat) est conservé (releves_emis_contenu) ; la vérification par QR code
 * l'affiche, pour comparer le document présenté à l'original.
 */
class RelevesEmis
{
    public function __construct(private readonly Pv $pvs, private readonly Releves $releves) {}

    /** @param  array|null  $lignes  Releves::lignes() du relevé imprimé (contenu conservé pour la vérification) */
    public function enregistrer(object $etudiant, object $pv, ?string $mention, $moyenne, ?array $lignes = null): string
    {
        $verrou = 'gesnotes_releve_'.DB::connection()->getDatabaseName();
        if ((int) DB::scalar('SELECT GET_LOCK(?, 10)', [$verrou]) !== 1) {
            throw new RuntimeException('Registre des relevés occupé, réessayez dans un instant.');
        }
        try {
            $numero = DB::table('releves_emis')->where(['etudiant_id' => $etudiant->id, 'pv_id' => $pv->id])->value('numero');
            if (! $numero) {
                $prefixe = 'R'.date('y').'-';
                $dernier = (int) DB::table('releves_emis')->where('numero', 'like', $prefixe.'%')
                    ->max(DB::raw('CAST(SUBSTRING(numero, '.(strlen($prefixe) + 1).') AS UNSIGNED)'));
                $numero = $prefixe.str_pad((string) ($dernier + 1), 6, '0', STR_PAD_LEFT);
            }
            DB::table('releves_emis')->upsert(
                [['numero' => $numero, 'etudiant_id' => $etudiant->id, 'pv_id' => $pv->id, 'nom' => $etudiant->nom, 'prenoms' => $etudiant->prenoms,
                    'semestre' => $pv->sem_libelle, 'mention_grade' => $mention ?: null, 'moyenne' => $moyenne, 'annee_academique' => $pv->annee]],
                ['numero'],
                ['nom', 'prenoms', 'mention_grade', 'moyenne', 'annee_academique'],
            );
            if ($lignes !== null && Schema::hasTable('releves_emis_contenu')) {
                DB::table('releves_emis_contenu')->upsert([['numero' => $numero, 'enregistre_le' => now(),
                    'contenu' => json_encode(self::contenu($lignes, $etudiant), JSON_UNESCAPED_UNICODE)]], ['numero'], ['contenu', 'enregistre_le']);
            }
        } finally {
            DB::scalar('SELECT RELEASE_LOCK(?)', [$verrou]);
        }

        return $numero;
    }

    /**
     * Notes et résultat d'un relevé émis, pour la vérification : le contenu
     * conservé à l'impression (« conserve » vrai) ; à défaut (relevé imprimé
     * avant cette fonction), les notes actuellement enregistrées.
     *
     * @return array{ues: list<array>, resultat: array, conserve: bool, date: ?string}|null
     */
    public function contenuPourVerification(object $releve): ?array
    {
        if (Schema::hasTable('releves_emis_contenu')
            && ($ligne = DB::table('releves_emis_contenu')->where('numero', $releve->numero)->first())
            && is_array($contenu = json_decode((string) $ligne->contenu, true))) {
            return $contenu + ['conserve' => true, 'date' => $ligne->enregistre_le];
        }
        $pv = $this->pvs->trouver((int) $releve->pv_id);
        $resultat = $pv ? DB::table('resultats_semestre')->where(['pv_id' => $pv->id, 'etudiant_id' => $releve->etudiant_id])->first() : null;
        if (! $pv || ! $resultat) {
            return null;
        }
        $notes = DB::table('notes')->where(['pv_id' => $pv->id, 'etudiant_id' => $releve->etudiant_id])->get()->keyBy('matiere_id');
        $lignes = $this->releves->lignes($this->pvs->ues($pv), $this->pvs->choixOptions($pv), $notes, (int) $releve->etudiant_id);

        return self::contenu($lignes, $resultat) + ['conserve' => false, 'date' => null];
    }

    /** Contenu d'un relevé : UE, matières (note, barème, note sur 20, crédits, observation), résultat du semestre. */
    public static function contenu(array $lignes, object $resultat): array
    {
        $ues = [];
        $creditsTotal = 0;
        foreach ($lignes as $l) {
            $creditsTotal += (int) $l['ue']->credits_total;
            $ues[] = ['code' => $l['ue']->code, 'libelle' => $l['ue']->libelle, 'credits_total' => (int) $l['ue']->credits_total,
                'matieres' => $l['matieres']->map(fn ($m) => ['libelle' => $m['m']->libelle, 'note' => $m['finale'] === null ? null : round((float) $m['finale'], 2),
                    'max' => (float) $m['m']->note_max_totale, 'sur20' => $m['moy20'], 'credits' => $m['credits'], 'credits_max' => (int) $m['m']->credits,
                    'obs' => $m['obs']])->values()->all()];
        }

        return ['ues' => $ues, 'resultat' => [
            'moyenne' => isset($resultat->moyenne_generale) ? round((float) $resultat->moyenne_generale, 2) : null,
            'mention' => $resultat->mention ?? null,
            'credits_obtenus' => isset($resultat->credits_obtenus) ? (int) $resultat->credits_obtenus : null,
            'credits_total' => $creditsTotal,
            'valide' => isset($resultat->valide) ? (bool) $resultat->valide : null,
        ]];
    }
}
