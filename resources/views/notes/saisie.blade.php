{{-- Accès rapide à la saisie des notes — portage de notes.php --}}
@extends('layouts.app', ['titre' => 'Saisie des notes', 'fil' => 'Gestion académique'])

@section('contenu')
@if ($faculte)
<div style="display:flex;align-items:center;gap:10px;margin-bottom:12px;padding:10px 16px;border-radius:8px;background:{{ $faculte->couleur ?? '#1A3A5C' }}15;border:1px solid {{ $faculte->couleur ?? '#1A3A5C' }}30">
  <span style="font-size:18px">🏛️</span><span style="font-weight:700;color:{{ $faculte->couleur ?? '#1A3A5C' }}">{{ $faculte->libelle }}</span>
  <span style="font-size:11px;color:var(--text-muted)">Vous ne voyez que les PV de cette faculté</span>
</div>
@endif
@if ($departement)
<div style="display:flex;align-items:center;gap:10px;margin-bottom:12px;padding:10px 16px;border-radius:8px;background:#dbeafe;border:1px solid #93c5fd">
  <span style="font-size:18px">📘</span><span style="font-weight:700;color:#1d4ed8">{{ $departement }}</span>
  <span style="font-size:11px;color:var(--text-muted)">Vous ne voyez que les PV de ce département</span>
</div>
@endif

<div class="alert alert-info mb-20">✏️ Sélectionnez un PV ci-dessous pour accéder au tableau de saisie des notes.</div>

@if ($pvs->isEmpty())
<div class="card">
  <div class="empty-state" style="padding:48px">
    <div class="icon">📋</div>
    <p>Aucun PV disponible pour la saisie.</p>
    <p class="text-muted text-sm mt-8">Tous les PV sont verrouillés ou publiés, ou aucun PV n'a été créé.</p>
    @if ($gestionnaire)<a href="{{ route('pv_creer') }}" class="btn btn-primary mt-16">➕ Créer un nouveau PV</a>@endif
  </div>
</div>
@else
<div class="card">
  <div class="card-header">
    <span class="card-title">PV disponibles pour saisie ({{ $pvs->count() }})</span>
    @if ($gestionnaire)<a href="{{ route('pv_creer') }}" class="btn btn-primary btn-sm">➕ Nouveau PV</a>@endif
  </div>
  <div class="table-wrap">
    <table class="table">
      <thead><tr><th>Intitulé du PV</th><th>Filière / Niveau</th><th>Semestre</th><th>Année</th><th>Inscrits (pédago.)</th><th>Avancement</th><th>Statut</th><th>Action</th></tr></thead>
      <tbody>
      @foreach ($pvs as $pv)
      @php($attendues = $pv->nb_inscrits * max(1, $pv->nb_matieres))
      <tr>
        <td><strong>{{ $pv->libelle }}</strong><br><span class="text-xs text-muted">{{ $pv->type_session }}</span></td>
        <td><span class="badge badge-blue">{{ $pv->fcode }}</span> <strong style="margin-left:4px">{{ $pv->niveau }}</strong></td>
        <td class="text-sm">{{ $pv->sem_label }}</td>
        <td class="text-sm text-muted">{{ $pv->annee }}</td>
        <td style="font-weight:600;color:var(--blue-600)">{{ $pv->nb_inscrits }}</td>
        <td style="min-width:120px">
          <div class="text-xs text-muted mb-4">{{ $pv->nb_notes }} notes saisies</div>
          <div class="progress" style="width:100px"><div class="progress-bar progress-bar-blue" style="width:{{ $pv->nb_inscrits ? min(100, round($pv->nb_notes / $attendues * 100)) : 0 }}%"></div></div>
        </td>
        <td><span class="badge {{ ['brouillon' => 'badge-amber', 'finalise' => 'badge-blue'][$pv->statut] ?? 'badge-gray' }}">{{ \App\Services\Pv::STATUTS[$pv->statut] ?? $pv->statut }}</span></td>
        <td><a href="{{ route('pv_detail', ['id' => $pv->id]) }}" class="btn btn-primary btn-sm">✏️ Saisir les notes</a></td>
      </tr>
      @endforeach
      </tbody>
    </table>
  </div>
</div>
@endif
@endsection
