{{-- Inscription scolarité d'un étudiant — portage de inscription_scolarite.php?id= --}}
@extends('layouts.app', ['titre' => 'Inscription Scolarité', 'fil' => 'Inscriptions › Scolarité'])

@section('contenu')
<div class="card mb-20">
  <div class="card-body" style="display:flex;align-items:center;gap:20px">
    @include('inscriptions.scolarite._photo')
    <div style="flex:1">
      <div style="font-size:18px;font-weight:800;color:var(--dark)">{{ $etudiant->nom }} {{ $etudiant->prenoms }}</div>
      <div style="display:flex;gap:16px;margin-top:6px;flex-wrap:wrap">
        <span style="font-size:13px;color:var(--text-muted)">Matricule : <strong style="color:var(--blue-700)">@if ($nouveau)<em>non encore attribué</em>@else{{ $etudiant->numero }}@endif</strong></span>
        @if ($etudiant->filiere_lib)
        <span style="font-size:13px;color:var(--text-muted)">Filière : <strong style="color:#5b21b6">{{ $filiereAffichee }}</strong></span>
        @endif
        @if ($etudiant->sexe)<span style="font-size:13px;color:var(--text-muted)">{{ $etudiant->sexe === 'M' ? 'Masculin' : 'Féminin' }}</span>@endif
        <span style="font-size:13px;color:var(--text-muted)">Inscriptions :
          <strong style="background:var(--blue-50);color:var(--blue-700);padding:1px 8px;border-radius:10px;font-size:12px;font-family:monospace">{{ $inscriptions->count() }}</strong>
        </span>
      </div>
    </div>
    <a href="{{ route('dossier_etudiant', ['id' => $etudiant->id]) }}" class="btn btn-secondary btn-sm">📁 Dossier</a>
  </div>
</div>

@if (! $etudiant->filiere_id)
<div class="alert alert-warning" style="margin-bottom:20px">
  ⚠️ <strong>Cet étudiant n'a pas de filière assignée.</strong>
  Vous devez d'abord lui assigner une filière depuis son dossier avant de pouvoir l'inscrire.
  <a href="{{ route('dossier_etudiant', ['id' => $etudiant->id]) }}" class="btn btn-sm btn-primary" style="margin-left:12px">→ Assigner une filière</a>
</div>
@else
<div style="display:grid;grid-template-columns:1fr 1fr;gap:20px">
  @if ($peutInscrire)
  <div class="card">
    <div class="card-header"><span class="card-title">📝 Nouvelle inscription</span></div>
    <div class="card-body">
      <form method="post" action="{{ route('inscription_scolarite.inscrire') }}">
        @csrf
        <input type="hidden" name="etudiant_id" value="{{ $etudiant->id }}">

        <div class="form-group">
          <span class="form-label">Filière</span>
          <div style="background:var(--gray-50);border:1.5px solid var(--border);border-radius:8px;padding:10px 14px;display:flex;align-items:center;gap:8px">
            <span style="font-size:18px">📚</span>
            <div>
              <div style="font-weight:700;color:#5b21b6">{{ $filiereAffichee }}</div>
              <div style="font-size:11px;color:var(--text-muted)">{{ $faculteAffichee }}</div>
            </div>
          </div>
          <div class="form-hint">Filière de l'étudiant — non modifiable</div>
        </div>

        @if ($passage)
        <div style="background:#dcfce7;border:1px solid #86efac;border-radius:8px;padding:10px 14px;margin-bottom:12px;font-size:13px;color:#166534">
          ✅ <strong>Décision de passage enregistrée</strong> — Niveau autorisé : <strong>{{ $passage->niveau_lib }}</strong>
          ({{ $passage->decision === 'passage' ? 'Passage' : 'Redoublement' }})
        </div>
        @elseif ($nouveau)
        <div style="background:#eff6ff;border:1px solid #bfdbfe;border-radius:8px;padding:10px 14px;margin-bottom:12px;font-size:13px;color:#1e40af">
          🎓 <strong>Nouveau bachelier</strong> — Aucune décision de passage requise. Inscription directe autorisée.
        </div>
        @else
        <div style="background:#fef2f2;border:1px solid #fca5a5;border-radius:8px;padding:12px 14px;margin-bottom:12px;font-size:13px;color:#991b1b">
          ⛔ <strong>Aucune décision de passage enregistrée</strong> pour cet étudiant au titre de l'année {{ $anneeActiveLibelle }}.<br>
          <span style="font-size:12px;color:#b91c1c;margin-top:4px;display:block">
            Cet étudiant n'est pas autorisé à s'inscrire. Veuillez d'abord enregistrer une décision de passage via le module <strong>Passage de niveau</strong>.
          </span>
        </div>
        @endif

        <div class="form-group">
          <label class="form-label" for="niveau_id">Niveau <span class="req">*</span></label>
          <select name="niveau_id" id="niveau_id" class="form-control" required>
            <option value="">— Sélectionner le niveau —</option>
            @foreach ($niveaux as $n)
            <option value="{{ $n->id }}" @selected($passage && $n->id == $passage->niveau_suivant_id)>{{ $n->code }} — {{ $n->libelle }}{{ $passage && $n->id == $passage->niveau_suivant_id ? ' ✦ Suggéré' : '' }}</option>
            @endforeach
          </select>
          @if ($passage)
          <div style="font-size:12px;color:var(--primary);margin-top:4px">💡 Niveau suggéré automatiquement selon les résultats</div>
          @endif
        </div>

        <div class="form-group">
          <label class="form-label" for="annee_id">Année académique <span class="req">*</span></label>
          <select name="annee_id" id="annee_id" class="form-control" required>
            <option value="">— Sélectionner l'année —</option>
            @foreach ($annees as $a)
            <option value="{{ $a->id }}" @selected($a->id == $anneeActive)>{{ $a->libelle }} {{ $a->id == $anneeActive ? '(en cours)' : '' }}</option>
            @endforeach
          </select>
        </div>

        <div style="display:grid;grid-template-columns:1fr 1fr;gap:12px">
          <div class="form-group">
            <label class="form-label" for="montant">Montant payé (FCFA)</label>
            <input type="number" name="montant" id="montant" class="form-control" value="10000" min="0" step="500">
          </div>
          <div class="form-group">
            <label class="form-label" for="mode_paiement">Mode de paiement</label>
            <select name="mode_paiement" id="mode_paiement" class="form-control">
              @foreach (['especes' => 'Espèces', 'mobile_money' => 'Mobile Money', 'mynita' => 'MyNITA', 'amana' => 'Amana', 'virement' => 'Virement'] as $v => $l)
              <option value="{{ $v }}">{{ $l }}</option>
              @endforeach
            </select>
          </div>
        </div>

        <button type="submit" class="btn btn-primary w-100" style="margin-top:8px;padding:12px;font-size:15px"
          @if (! $passage && ! $nouveau) disabled title="Aucune décision de passage enregistrée" @endif>✅ Inscrire l'étudiant</button>
      </form>
    </div>
  </div>
  @else
  <div class="card">
    <div class="card-body" style="text-align:center;padding:40px 20px;color:var(--text-muted)">
      <div style="font-size:32px;margin-bottom:10px">🔒</div>
      La création d'une inscription est réservée à l'admin et à la scolarité centrale.
    </div>
  </div>
  @endif

  <div class="card">
    <div class="card-header"><span class="card-title">📋 Historique des inscriptions</span></div>
    <div class="card-body" style="padding:0">
      @if ($inscriptions->isEmpty())
      <div style="text-align:center;padding:30px;color:var(--text-muted)">
        <div style="font-size:32px;margin-bottom:8px">📭</div>
        Aucune inscription antérieure
      </div>
      @else
      <table style="width:100%;border-collapse:collapse;font-size:13px">
        <thead>
          <tr style="background:var(--gray-50);border-bottom:1px solid var(--border)">
            <th style="padding:10px 14px;text-align:left;font-weight:600">Année</th>
            <th style="padding:10px 14px;text-align:left;font-weight:600">Niveau</th>
            <th style="padding:10px 14px;text-align:left;font-weight:600">Filière</th>
            <th style="padding:10px 14px;text-align:left;font-weight:600">Montant</th>
            <th style="padding:10px 14px;text-align:left;font-weight:600">Statut</th>
            <th style="padding:10px 14px;text-align:center;font-weight:600">Actions</th>
          </tr>
        </thead>
        <tbody>
        @foreach ($inscriptions as $ins)
        <tr style="border-bottom:1px solid var(--border)">
          <td style="padding:10px 14px;font-weight:600">{{ $ins->annee_lib ?? '—' }}</td>
          <td style="padding:10px 14px">{{ $ins->niveau_code ?? '—' }}</td>
          <td style="padding:10px 14px;color:#5b21b6">{{ $ins->ins_filiere_lib ?? '—' }}</td>
          <td style="padding:10px 14px">{{ number_format((float) $ins->montant_paye, 0, ',', ' ') }} FCFA</td>
          <td style="padding:10px 14px">
            <span style="background:{{ $ins->statut === 'inscrit' ? '#dcfce7' : '#fee2e2' }};color:{{ $ins->statut === 'inscrit' ? '#166534' : '#991b1b' }};padding:2px 10px;border-radius:12px;font-size:11px;font-weight:600">{{ ucfirst($ins->statut) }}</span>
          </td>
          <td style="padding:10px 14px;text-align:center">
            @php($donnees = ['id' => $ins->id, 'annee_id' => $ins->annee_id, 'niveau_id' => $ins->niveau_id, 'filiere_id' => $ins->ins_filiere_id, 'montant_paye' => $ins->montant_paye, 'mode_paiement' => $ins->mode_paiement, 'annee_lib' => $ins->annee_lib, 'niveau_code' => $ins->niveau_code])
            <button type="button" class="btn btn-sm btn-secondary" data-onclick="ouvrirModifInscription" data-args="{{ json_encode([$donnees]) }}">🔧 Modifier</button>
          </td>
        </tr>
        @endforeach
        </tbody>
      </table>
      @endif
    </div>
  </div>
</div>
@endif

<div id="modal-modif-inscription" style="display:none;position:fixed;inset:0;background:rgba(0,0,0,.5);z-index:200;align-items:center;justify-content:center">
  <div style="background:#fff;border-radius:12px;padding:24px;width:100%;max-width:440px;max-height:90vh;overflow-y:auto">
    <div style="font-weight:700;font-size:16px;color:var(--dark);margin-bottom:4px">🔧 Modifier l'inscription</div>
    <div id="modif-ins-souslabel" style="font-size:12px;color:var(--text-muted);margin-bottom:16px"></div>
    <form method="post" action="{{ route('inscription_scolarite.modifier') }}">
      @csrf
      <input type="hidden" name="inscription_id" id="modif-ins-id">
      <input type="hidden" name="etudiant_id_redirect" value="{{ $etudiant->id }}">
      <div class="form-group">
        <label class="form-label" for="modif-ins-filiere">Filière</label>
        <select name="filiere_id" id="modif-ins-filiere" class="form-control" required>
          @foreach ($filieres as $f)<option value="{{ $f->id }}">{{ ($f->faculte ? $f->faculte.' — ' : '').$f->libelle }}</option>@endforeach
        </select>
      </div>
      <div class="form-group">
        <label class="form-label" for="modif-ins-niveau">Niveau</label>
        <select name="niveau_id" id="modif-ins-niveau" class="form-control" required>
          @foreach ($niveaux as $n)<option value="{{ $n->id }}">{{ $n->code }} — {{ $n->libelle }}</option>@endforeach
        </select>
      </div>
      <div class="form-group">
        <label class="form-label" for="modif-ins-annee">Année académique</label>
        <select name="annee_id" id="modif-ins-annee" class="form-control" required>
          @foreach ($annees as $a)<option value="{{ $a->id }}">{{ $a->libelle }}</option>@endforeach
        </select>
      </div>
      <div style="display:grid;grid-template-columns:1fr 1fr;gap:12px">
        <div class="form-group">
          <label class="form-label" for="modif-ins-montant">Montant payé (FCFA)</label>
          <input type="number" name="montant_paye" id="modif-ins-montant" class="form-control" min="0">
        </div>
        <div class="form-group">
          <label class="form-label" for="modif-ins-mode">Mode de paiement</label>
          <select name="mode_paiement" id="modif-ins-mode" class="form-control">
            @foreach (\App\Services\Inscriptions::MODES as $v => $l)<option value="{{ $v }}">{{ $l }}</option>@endforeach
          </select>
        </div>
      </div>
      <div style="display:flex;gap:8px;margin-top:20px">
        <button type="button" data-hide="modal-modif-inscription" class="btn btn-secondary" style="flex:1">Annuler</button>
        <button type="submit" class="btn btn-primary" style="flex:1">💾 Enregistrer</button>
      </div>
    </form>
  </div>
</div>
@endsection

@push('scripts')
<script src="{{ \App\Support\Ressource::url('assets/js/inscription-scolarite.js') }}"></script>
@endpush
