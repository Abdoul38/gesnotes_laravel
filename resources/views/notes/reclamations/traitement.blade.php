{{-- Traitement d'une réclamation — portage de reclamation_traitement.php --}}
@extends('layouts.app', ['titre' => 'Traitement de réclamation', 'fil' => 'Réclamations › Traitement'])

@section('contenu')
@php
    [$icone, $classe, $libelle] = \App\Services\Reclamations::STATUTS[$r->statut] ?? ['', 'badge-gray', $r->statut];
    $maxExam = (float) ($r->note_max_examen ?? 20);
    $maxCc = (float) ($r->note_max_cc ?? 0);
    $fmt = fn ($v) => $v !== null ? number_format((float) $v, 2) : '—';
@endphp
<div style="max-width:860px">

  <div class="card mb-16">
    <div class="card-header" style="background:var(--primary);color:#fff;display:flex;justify-content:space-between;align-items:center">
      <span class="card-title" style="color:#fff">📨 Réclamation #{{ $r->id }}</span>
      <span class="badge {{ $classe }}">{{ $icone }} {{ $libelle }}</span>
    </div>
    <div class="card-body">
      <div class="form-grid form-grid-2" style="gap:16px">
        <div>
          <div class="form-label">Étudiant</div>
          <div><strong>{{ $r->nom }} {{ $r->prenoms }}</strong></div>
          <div class="text-xs text-muted">{{ $r->matricule ?? '—' }} · {{ $r->filiere_code }} {{ $r->niveau }}</div>
        </div>
        <div>
          <div class="form-label">PV concerné</div>
          <div><strong>{{ $r->pv_libelle }}</strong></div>
          <div class="text-xs text-muted">{{ $r->annee }}</div>
        </div>
        <div>
          <div class="form-label">Matière réclamée</div>
          <div><strong>{{ $r->matiere }}</strong></div>
          <div class="text-xs text-muted">
            Note originale : <strong>{{ $fmt($r->note_originale) }}</strong>
            @if ($r->note_corrigee !== null) → Corrigée : <strong style="color:var(--green-600)">{{ $fmt($r->note_corrigee) }}</strong>@endif
          </div>
        </div>
        <div>
          <div class="form-label">Déposée le</div>
          <div>{{ \App\Support\Format::dateFR(substr((string) $r->created_at, 0, 10)) }}</div>
          <div class="text-xs text-muted">{{ $r->source === 'enligne' ? "🌐 Via l'application mobile" : '📄 Dépôt physique' }}</div>
        </div>
      </div>

      <div style="margin-top:14px;padding:12px;background:var(--bg-secondary);border-radius:6px;border-left:4px solid var(--primary)">
        <div class="form-label" style="margin-bottom:4px">Motif de la réclamation</div>
        <p style="font-size:13px;color:var(--text-primary);white-space:pre-line">{{ $r->motif }}</p>
      </div>

      @if ($r->commentaire)
      <div style="margin-top:10px;padding:10px;background:#f0fdf4;border-radius:6px;border-left:4px solid var(--green-600)">
        <div class="form-label" style="margin-bottom:4px">Commentaire secrétariat</div>
        <p style="font-size:13px;white-space:pre-line">{{ $r->commentaire }}</p>
        @if ($r->traitee_par_nom)
        <div class="text-xs text-muted" style="margin-top:4px">Par {{ $r->traitee_par_nom }} · {{ \App\Support\Format::dateFR(substr((string) $r->traitee_le, 0, 10)) }}</div>
        @endif
      </div>
      @endif
    </div>
  </div>

  <div class="form-grid form-grid-2" style="gap:16px;align-items:start">

    <div class="card">
      <div class="card-header"><span class="card-title">📊 Notes dans ce PV</span></div>
      <div class="table-wrap">
        <table class="table" style="font-size:12px">
          <thead><tr><th>Matière</th><th style="text-align:center">Exam</th><th style="text-align:center">CC</th><th style="text-align:center">Moy.</th></tr></thead>
          <tbody>
          @foreach ($toutesNotes as $n)
          @php $reclamee = $n->matiere_id == $r->matiere_id; @endphp
          <tr style="{{ $reclamee ? 'background:var(--amber-50);font-weight:600' : '' }}">
            <td>{{ $n->matiere }}{{ $reclamee ? ' ⚠️' : '' }}</td>
            <td style="text-align:center">{{ $fmt($n->note_examen) }}</td>
            <td style="text-align:center">{{ $fmt($n->note_cc) }}</td>
            <td style="text-align:center">{{ $fmt($n->note_finale) }}</td>
          </tr>
          @endforeach
          </tbody>
        </table>
      </div>
      @if ($resultat)
      <div class="card-footer" style="font-size:12px">
        Moyenne : <strong>{{ $fmt($resultat->moyenne_generale) }}/20</strong>
        &nbsp;·&nbsp; Crédits : <strong>{{ $resultat->credits_obtenus }}</strong>
        &nbsp;·&nbsp; <span class="badge {{ $resultat->valide ? 'badge-green' : 'badge-red' }}">{{ $resultat->valide ? 'Validé' : 'Non validé' }}</span>
      </div>
      @endif
    </div>

    @if ($r->statut === 'en_attente')
    <div class="card">
      <div class="card-header"><span class="card-title">⚖️ Décision</span></div>
      <div class="card-body">
        <form method="post" action="{{ route('reclamation_traitement.traiter', $r->id) }}" id="form-traitement" data-max-exam="{{ $maxExam }}" data-max-cc="{{ $maxCc }}">
          @csrf
          <div id="bloc-notes-corrigees" style="display:none;margin-bottom:16px;padding:14px;background:var(--bg-secondary);border-radius:8px;border:1px solid var(--green-200)">
            <div style="margin-bottom:12px;color:var(--green-700);font-weight:600;font-size:13px">✏️ Corriger les notes pour <strong>{{ $r->matiere }}</strong></div>

            <div style="display:flex;gap:12px;margin-bottom:12px;padding:8px 12px;background:#fff;border-radius:6px;font-size:12px;border:1px solid var(--border)">
              <span>Note originale :</span>
              <strong>Exam : {{ $noteActuelle ? $fmt($noteActuelle->note_examen ?? 0) : '—' }} / {{ $r->note_max_examen ?? 20 }}</strong>
              @if ($maxCc > 0)<strong>CC : {{ $noteActuelle ? $fmt($noteActuelle->note_cc ?? 0) : '—' }} / {{ $r->note_max_cc }}</strong>@endif
              <strong>Finale : {{ $fmt($r->note_originale) }} / 20</strong>
            </div>

            <div class="form-grid form-grid-2" style="gap:10px">
              <div class="form-group" style="margin-bottom:0">
                <label class="form-label" for="inp-exam">Note Examen corrigée <small class="text-muted">(/ {{ $r->note_max_examen ?? 20 }})</small></label>
                <input type="number" name="note_examen" id="inp-exam" class="form-control" min="0" max="{{ $maxExam }}" step="0.01" data-decimales="2"
                  value="{{ old('note_examen', $noteActuelle->note_examen ?? '') }}" placeholder="Ex: 14.50">
              </div>
              @if ($maxCc > 0)
              <div class="form-group" style="margin-bottom:0">
                <label class="form-label" for="inp-cc">Note CC corrigée <small class="text-muted">(/ {{ $r->note_max_cc }})</small></label>
                <input type="number" name="note_cc" id="inp-cc" class="form-control" min="0" max="{{ $maxCc }}" step="0.01" data-decimales="2"
                  value="{{ old('note_cc', $noteActuelle->note_cc ?? '') }}" placeholder="Ex: 12.00">
              </div>
              @endif
            </div>

            <div id="apercu-finale" style="margin-top:12px;padding:10px 14px;background:#fff;border-radius:6px;border:2px solid var(--green-400);display:none">
              <div style="font-size:12px;color:var(--text-muted);margin-bottom:2px">Note finale calculée</div>
              <div style="font-size:20px;font-weight:800;color:var(--green-700)" id="apercu-valeur">—</div>
              <div style="font-size:11px;color:var(--text-muted);margin-top:2px" id="apercu-detail"></div>
            </div>

            <div style="margin-top:12px;padding:10px 12px;background:#fffbeb;border-radius:6px;border:1px dashed var(--amber-400)">
              <label class="form-label" for="inp-finale-override" style="font-size:12px;color:var(--amber-700);margin-bottom:6px">
                ✏️ Ou saisir directement la note finale / {{ $maxExam + $maxCc }} <small style="font-weight:400">(remplace le calcul automatique)</small>
              </label>
              <input type="number" name="note_finale_override" id="inp-finale-override" class="form-control" min="0" max="{{ $maxExam + $maxCc }}" step="0.01" data-decimales="2"
                value="{{ old('note_finale_override') }}" placeholder="Note finale sur {{ $maxExam + $maxCc }}">
            </div>
          </div>

          <div class="form-group">
            <label class="form-label" for="commentaire">Commentaire <span class="text-muted">(facultatif)</span></label>
            <textarea name="commentaire" id="commentaire" class="form-control" rows="3" maxlength="2000" placeholder="Expliquer la décision prise...">{{ old('commentaire') }}</textarea>
          </div>

          <div style="display:flex;flex-direction:column;gap:10px;margin-top:16px">
            @if ($r->verrouille)
            <div class="alert alert-warning" style="margin:0;font-size:12.5px">🔒 Le PV « {{ $r->pv_libelle }} » est verrouillé : pour corriger la note, faites-le d'abord déverrouiller. « Non fondée » et « rejetée » restent possibles.</div>
            @else
            <div id="bloc-action-fondee">
              <button type="button" class="btn btn-success" style="width:100%" id="btn-fondee">✅ Réclamation fondée — Corriger la note</button>
            </div>
            @endif
            <div id="bloc-confirmer-fondee" style="display:none;padding:12px;background:#f0fdf4;border-radius:8px;border:1px solid var(--green-300)">
              <div style="font-size:12px;color:var(--green-700);margin-bottom:8px">✅ Vérifiez la note corrigée ci-dessus puis confirmez</div>
              <button type="button" class="btn btn-success" style="width:100%" id="btn-confirmer-fondee">💾 Confirmer et enregistrer la note corrigée</button>
              <button type="button" class="btn btn-secondary btn-sm" style="margin-top:6px;width:100%" id="btn-annuler-fondee">✕ Annuler</button>
            </div>
            <button type="button" id="btn-non-fondee" class="btn btn-secondary" data-action="non_fondee">❌ Non fondée — Note confirmée</button>
            <button type="button" id="btn-rejeter" class="btn btn-danger" data-action="rejetee">🚫 Rejeter la réclamation</button>
          </div>
          <input type="hidden" name="action" id="inp-action" value="">
        </form>
      </div>
    </div>
    @else
    <div class="card">
      <div class="card-header"><span class="card-title">✅ Réclamation traitée</span></div>
      <div class="card-body">
        <p class="text-muted" style="font-size:13px">Cette réclamation a déjà été traitée.</p>
        @if ($r->traitee_par_nom)
        <p style="font-size:12px;margin-top:8px">Par <strong>{{ $r->traitee_par_nom }}</strong> le {{ \App\Support\Format::dateFR(substr((string) $r->traitee_le, 0, 10)) }}</p>
        @endif
        @if ($r->note_corrigee !== null)
        <div style="margin-top:12px;padding:10px;background:#f0fdf4;border-radius:6px">
          Note originale : <strong>{{ $fmt($r->note_originale) }}</strong> → Note corrigée : <strong style="color:var(--green-600)">{{ $fmt($r->note_corrigee) }}</strong>
        </div>
        @endif
        <a href="{{ route('reclamations') }}" class="btn btn-secondary btn-sm" style="margin-top:14px">← Retour</a>
      </div>
    </div>
    @endif

  </div>

  @if ($r->statut === 'fondee')
  <div class="alert alert-success mt-16" style="display:flex;justify-content:space-between;align-items:center">
    <span>✅ Cette réclamation est fondée et la note a été corrigée.</span>
    <a href="{{ route('pv_creer', ['type' => 'reclamation', 'pv_source' => $r->pv_id]) }}" class="btn btn-primary btn-sm">📋 Générer PV de réclamation</a>
  </div>
  @endif

</div>
@endsection

@push('scripts')
<script src="{{ \App\Support\Ressource::url('assets/js/reclamation-traitement.js') }}"></script>
@endpush
