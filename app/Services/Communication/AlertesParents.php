<?php

namespace App\Services\Communication;

use App\Services\Parametres;
use App\Universites\UniversiteCourante;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Alertes envoyées aux parents (SMS, et e-mail s'il est connu), pensées pour
 * ceux qui n'ont pas de smartphone : messages courts qui contiennent
 * l'information elle-même. Chaque type d'alerte s'active ou se coupe dans
 * Paramètres › Notif SMS (actives par défaut ; sans passerelle SMS active, les
 * SMS sont seulement journalisés).
 *
 *  - résultats : moyenne et décision à la publication du PV (Notifications::notifierPv) ;
 *  - absences : à l'enregistrement d'une absence (une fois par séance) ;
 *  - examens : rappel la veille (commande gesnotes:rappels-examens) ;
 *  - retards de paiement : relances (déjà existantes, Communication › Relances).
 */
class AlertesParents
{
    public const REGLAGES = [
        'parents_alerte_resultats' => 'Résultats (moyenne et décision) à la publication du PV',
        'parents_alerte_absences' => 'Absence de l\'enfant à une séance',
        'parents_alerte_examens' => 'Rappel des examens la veille',
    ];

    public function __construct(private readonly Notifications $notifications, private readonly Parametres $parametres) {}

    public function active(string $reglage): bool
    {
        return $this->parametres->valeur($reglage, '1') === '1';
    }

    /** Parents actifs liés à l'étudiant. */
    public function parents(int $etudiant)
    {
        return DB::table('parents_etudiants as pe')->join('parents as p', 'p.id', '=', 'pe.parent_id')
            ->where('pe.etudiant_id', $etudiant)->where('p.actif', 1)->get(['p.id', 'p.email', 'p.telephone']);
    }

    /** Absences nouvellement enregistrées à une séance. Retourne le nombre de parents prévenus. */
    public function absences(object $seance, array $etudiants): int
    {
        if (! $etudiants || ! $this->active('parents_alerte_absences')) {
            return 0;
        }
        $date = Carbon::parse($seance->date_seance)->format('d/m/Y');
        $nb = 0;
        foreach (DB::table('etudiants')->whereIn('id', $etudiants)->get(['id', 'nom', 'prenoms']) as $et) {
            $texte = "{$this->sigle()} : {$et->prenoms} {$et->nom} a été absent(e) au cours de {$seance->matiere}"
                .($seance->type_seance ? " ({$seance->type_seance})" : '')." du $date. Pour toute question, contactez la scolarité.";
            foreach ($this->parents((int) $et->id) as $p) {
                $this->notifications->envoyer($p->email, $p->telephone, 'Absence de '.$et->prenoms, $texte, 'absence', (int) $seance->id);
                $nb++;
            }
            app(Push::class)->parentsDe((int) $et->id, 'Absence de '.$et->prenoms, "Absent(e) au cours de {$seance->matiere} du $date.", ['type' => 'absence']);
        }

        return $nb;
    }

    /**
     * Rappel des examens du lendemain (normale : étudiants inscrits au semestre ;
     * rattrapage : ceux qui n'ont pas acquis la matière). Une seule fois par
     * examen et par parent. Retourne le nombre de messages.
     */
    public function rappelsExamens(?Carbon $jour = null): int
    {
        if (! $this->active('parents_alerte_examens') || ! Schema::hasTable('planning_examens')) {
            return 0;
        }
        $jour ??= now()->addDay();
        $examens = DB::table('planning_examens as pe')->join('promotions as p', 'p.id', '=', 'pe.promotion_id')
            ->join('matieres as m', 'm.id', '=', 'pe.matiere_id')
            ->whereDate('pe.date_examen', $jour->toDateString())
            ->get(['pe.*', 'p.annee_id', 'p.filiere_id', 'p.niveau_id', 'm.libelle as matiere']);
        $nb = 0;
        foreach ($examens as $x) {
            $texte = fn ($et) => "{$this->sigle()} : rappel — {$et->prenoms} {$et->nom} a l'examen de {$x->matiere}"
                .($x->session === 'rattrapage' ? ' (rattrapage)' : '').' demain '.$jour->format('d/m/Y').' à '.substr((string) $x->heure_debut, 0, 5)
                .($x->salle ? ", salle {$x->salle}" : '').'. Bonne chance !';
            foreach ($this->candidats($x) as $et) {
                // Notification sur téléphone : une fois par examen et par étudiant
                if (Cache::add('rappel_push|'.$x->id.'|'.$et->id, 1, now()->addDays(2))) {
                    $resume = "{$x->matiere} demain à ".substr((string) $x->heure_debut, 0, 5).($x->salle ? ", salle {$x->salle}" : '');
                    app(Push::class)->etudiant((int) $et->id, 'Rappel d\'examen', $resume, ['type' => 'examen']);
                    app(Push::class)->parentsDe((int) $et->id, 'Examen de '.$et->prenoms, $resume, ['type' => 'examen']);
                }
                foreach ($this->parents((int) $et->id) as $p) {
                    if ($this->dejaEnvoye('rappel_examen', (int) $x->id, $p)) {
                        continue;
                    }
                    $this->notifications->envoyer($p->email, $p->telephone, 'Rappel d\'examen', $texte($et), 'rappel_examen', (int) $x->id);
                    $nb++;
                }
            }
        }

        return $nb;
    }

    /** Étudiants qui composent l'examen planifié. */
    private function candidats(object $x)
    {
        $q = DB::table('etudiants as e')->whereExists(fn ($s) => $s->from('inscriptions_pedagogiques as ip')->join('inscriptions as i', 'i.id', '=', 'ip.inscription_id')
            ->join('promotions as pi', 'pi.id', '=', 'i.promotion_id')->whereColumn('ip.etudiant_id', 'e.id')
            ->where(['ip.semestre_id' => $x->semestre_id, 'ip.annee_id' => $x->annee_id, 'ip.statut' => 'actif', 'pi.filiere_id' => $x->filiere_id, 'pi.niveau_id' => $x->niveau_id]));
        if ($x->session === 'rattrapage') {
            // Seuls ceux qui n'ont pas acquis la matière en 1ère session
            $q->whereNotExists(fn ($s) => $s->from('notes as n')->join('pv_sessions as ps', 'ps.id', '=', 'n.pv_id')->whereColumn('n.etudiant_id', 'e.id')
                ->where(['n.matiere_id' => $x->matiere_id, 'ps.promotion_id' => $x->promotion_id, 'ps.semestre_id' => $x->semestre_id, 'ps.type_session' => 'normale'])
                ->whereIn('n.observation', ['AV', 'AC']));
        }

        return $q->get(['e.id', 'e.nom', 'e.prenoms']);
    }

    private function dejaEnvoye(string $contexte, int $ref, object $parent): bool
    {
        return ($parent->telephone && DB::table('sms_logs')->where(['contexte' => $contexte, 'reference_id' => $ref, 'numero' => Sms::numero((string) $parent->telephone)])->exists())
            || ($parent->email && DB::table('email_logs')->where(['contexte' => $contexte, 'reference_id' => $ref, 'destinataire' => $parent->email])->exists());
    }

    private function sigle(): string
    {
        $u = app(UniversiteCourante::class)->get();

        return $u->sigle ?: strtoupper($u->code);
    }
}
