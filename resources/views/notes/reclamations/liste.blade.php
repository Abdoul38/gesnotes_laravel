{{-- Réclamations — portage de pages/reclamations/reclamations.php --}}
@extends('layouts.app', ['titre' => 'Réclamations', 'fil' => 'Notes & PV › Réclamations'])

@section('contenu')
@if ($faculte)
@php
    $cf = preg_match('/^#[0-9A-Fa-f]{6}$/', (string) $faculte->couleur) ? $faculte->couleur : '#1A3A5C';
    $estDoyen = auth()->user()->role === 'doyen';
@endphp
<div style="display:flex;align-items:center;gap:10px;margin-bottom:12px;padding:10px 16px;border-radius:8px;background:{{ $cf }}15;border:1px solid {{ $cf }}30">
  <span style="font-size:18px">{{ $estDoyen ? '🎓' : '🏛️' }}</span>
  <span style="font-weight:700;color:{{ $cf }}">{{ $faculte->libelle }}</span>
  <span style="font-size:11px;color:var(--text-muted)">Vous ne voyez que les réclamations de cette faculté{{ $estDoyen ? ' (tous départements)' : '' }}</span>
</div>
@endif
@if ($departement)
<div style="display:flex;align-items:center;gap:10px;margin-bottom:12px;padding:10px 16px;border-radius:8px;background:#dbeafe;border:1px solid #93c5fd">
  <span style="font-size:18px">📘</span>
  <span style="font-weight:700;color:#1d4ed8">{{ $departement }}</span>
  <span style="font-size:11px;color:var(--text-muted)">Vous ne voyez que les réclamations de ce département</span>
</div>
@endif

<div class="stats-grid" style="grid-template-columns:repeat(4,1fr);gap:12px;margin-bottom:20px">
  @foreach (\App\Services\Reclamations::STATUTS as $cle => [$icone, $classe, $libelle])
  <div class="stat-card" style="cursor:pointer" data-navigate-static="{{ route('reclamations', ['statut' => $cle]) }}">
    <div class="stat-icon">{{ $icone }}</div>
    <div class="stat-value">{{ $compteurs[$cle] ?? 0 }}</div>
    <div class="stat-label">{{ $libelle }}{{ $cle === 'en_attente' ? '' : 's' }}</div>
  </div>
  @endforeach
</div>

<div class="card mb-16">
  <div class="card-body" style="padding:14px 16px">
    <form method="get" action="{{ route('reclamations') }}" class="flex gap-8" style="flex-wrap:wrap;align-items:flex-end">
      <div style="min-width:130px">
        <label class="form-label" for="annee_id" style="font-size:11px;margin-bottom:3px">Année</label>
        <select name="annee_id" id="annee_id" class="form-control form-control-sm">
          <option value="">Toutes</option>
          @foreach ($annees as $a)<option value="{{ $a->id }}" @selected($filtres['annee_id'] == $a->id)>{{ $a->libelle }}</option>@endforeach
        </select>
      </div>
      <div style="min-width:200px">
        <label class="form-label" for="pv_id" style="font-size:11px;margin-bottom:3px">PV</label>
        <select name="pv_id" id="pv_id" class="form-control form-control-sm">
          <option value="">Tous les PV</option>
          @foreach ($pvs as $p)<option value="{{ $p->id }}" @selected($filtres['pv_id'] == $p->id)>{{ mb_strimwidth($p->libelle, 0, 50, '…') }}</option>@endforeach
        </select>
      </div>
      <div style="min-width:130px">
        <label class="form-label" for="statut" style="font-size:11px;margin-bottom:3px">Statut</label>
        <select name="statut" id="statut" class="form-control form-control-sm">
          <option value="">Tous</option>
          @foreach (\App\Services\Reclamations::STATUTS as $cle => [$icone, , $libelle])
          <option value="{{ $cle }}" @selected($filtres['statut'] === $cle)>{{ $icone }} {{ $libelle }}</option>
          @endforeach
        </select>
      </div>
      <button type="submit" class="btn btn-secondary btn-sm">🔍 Filtrer</button>
      <a href="{{ route('reclamations') }}" class="btn btn-secondary btn-sm" aria-label="Réinitialiser">↺</a>
    </form>
  </div>
</div>

<div class="card">
  <div class="card-header" style="display:flex;justify-content:space-between;align-items:center">
    <span class="card-title">📨 Réclamations reçues</span>
    <div class="flex gap-8" style="align-items:center">
      <span class="text-muted text-sm">{{ $total }} réclamation(s)</span>
      @if ($reclamations->isNotEmpty() && $peutExporter)
      <a href="{{ route('reclamations.export', array_filter($filtres)) }}" class="btn btn-sm btn-secondary" title="Télécharger Excel par matière">📥 Export Excel par matière</a>
      @endif
    </div>
  </div>
  <div class="table-wrap">
    <table class="table">
      <thead><tr>
        <th>Étudiant</th><th>Matière réclamée</th><th>PV concerné</th><th>Note originale</th>
        <th>Source</th><th>Date dépôt</th><th>Statut</th><th>Actions</th>
      </tr></thead>
      <tbody>
      @forelse ($reclamations as $r)
      @php [$icone, $classe, $libelle] = \App\Services\Reclamations::STATUTS[$r->statut] ?? ['', 'badge-gray', $r->statut]; @endphp
      <tr>
        <td>
          <strong>{{ $r->nom }} {{ $r->prenoms }}</strong><br>
          <span class="text-xs text-muted">{{ $r->matricule ?? '—' }} · {{ $r->filiere_code }} {{ $r->niveau }}</span>
        </td>
        <td>
          <strong>{{ $r->matiere }}</strong><br>
          <span class="text-xs text-muted" style="max-width:200px;display:block;white-space:normal">{{ mb_strimwidth($r->motif, 0, 60, '…') }}</span>
        </td>
        <td>
          <span class="text-xs">{{ mb_strimwidth($r->pv_libelle, 0, 40, '…') }}</span><br>
          <span class="badge badge-blue" style="font-size:9px">{{ $r->annee }}</span>
        </td>
        <td style="text-align:center">
          <strong>{{ $r->note_originale !== null ? number_format((float) $r->note_originale, 2) : '—' }}</strong>
          @if ($r->note_corrigee !== null)<br><span style="color:var(--green-600);font-size:11px">→ {{ number_format((float) $r->note_corrigee, 2) }}</span>@endif
        </td>
        <td style="text-align:center">
          @if ($r->source === 'enligne')<span class="badge badge-blue">🌐 En ligne</span>@else<span class="badge badge-gray">📄 Physique</span>@endif
        </td>
        <td><span class="text-xs">{{ \App\Support\Format::dateFR(substr((string) $r->created_at, 0, 10)) }}</span></td>
        <td><span class="badge {{ $classe }}">{{ $icone }} {{ $libelle }}</span></td>
        <td>
          <a href="{{ route('reclamation_traitement', $r->id) }}" class="btn btn-sm btn-primary">{{ $r->statut === 'en_attente' ? '⚖️ Traiter' : '👁 Voir' }}</a>
        </td>
      </tr>
      @empty
      <tr><td colspan="8"><div class="empty-state"><div class="icon">📨</div>Aucune réclamation.</div></td></tr>
      @endforelse
      </tbody>
    </table>
  </div>
  @if ($pagination['totalPages'] > 1)
  <div class="card-footer">
    <div class="pagination">
      @for ($i = 1; $i <= $pagination['totalPages']; $i++)
      <a href="{{ request()->fullUrlWithQuery(['p' => $i]) }}" class="page-btn {{ $i == $pagination['page'] ? 'active' : '' }}">{{ $i }}</a>
      @endfor
    </div>
    <span class="text-muted text-sm" style="margin-left:auto">Page {{ $pagination['page'] }}/{{ $pagination['totalPages'] }}</span>
  </div>
  @endif
</div>
@endsection
