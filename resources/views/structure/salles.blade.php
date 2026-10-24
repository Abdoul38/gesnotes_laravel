{{-- Salles — portage de pages/structure/salles.php --}}
@extends('layouts.app', ['titre' => 'Gestion des salles', 'fil' => 'Administration › Salles'])

@section('contenu')
@php $retour = array_filter(['type' => $filtres['type']]); @endphp
<div class="stats-grid" style="grid-template-columns:repeat(3,1fr);gap:12px;margin-bottom:20px">
  <div class="stat-card"><div class="stat-icon">🚪</div><div class="stat-value">{{ count($salles) }}</div><div class="stat-label">Salle(s) {{ $filtre ? '(filtrées)' : '' }}</div></div>
  <div class="stat-card"><div class="stat-icon">✅</div><div class="stat-value">{{ $salles->where('actif', 1)->count() }}</div><div class="stat-label">Actives</div></div>
  <div class="stat-card"><div class="stat-icon">👥</div><div class="stat-value">{{ number_format($salles->sum('capacite'), 0, '', ' ') }}</div><div class="stat-label">Capacité cumulée</div></div>
</div>

<div class="card mb-16">
  <div class="card-body" style="display:flex;gap:12px;flex-wrap:wrap;align-items:flex-end;justify-content:space-between">
    <form method="get" action="{{ route('salles') }}" style="display:flex;gap:8px;flex-wrap:wrap;align-items:flex-end">
      <div>
        <label class="form-label" for="f-q" style="font-size:11px;margin-bottom:3px">Recherche</label>
        <input type="text" id="f-q" name="q" value="{{ $filtres['q'] }}" placeholder="Code ou nom..." class="form-control form-control-sm">
      </div>
      <div>
        <label class="form-label" for="f-filtre-type" style="font-size:11px;margin-bottom:3px">Type</label>
        <select id="f-filtre-type" name="type" class="form-control form-control-sm">
          <option value="">Tous</option>
          @foreach ($types as $cle => [$ic, $lb, $c])
            <option value="{{ $cle }}" @selected($filtres['type'] === $cle)>{{ $ic }} {{ $lb }}</option>
          @endforeach
        </select>
      </div>
      @if (count($batiments))
      <div>
        <label class="form-label" for="f-filtre-bat" style="font-size:11px;margin-bottom:3px">Bâtiment</label>
        <select id="f-filtre-bat" name="batiment" class="form-control form-control-sm">
          <option value="">Tous</option>
          @foreach ($batiments as $b)
            <option value="{{ $b }}" @selected($filtres['batiment'] === $b)>{{ $b }}</option>
          @endforeach
        </select>
      </div>
      @endif
      <button type="submit" class="btn btn-secondary btn-sm">🔍 Filtrer</button>
      <a href="{{ route('salles') }}" class="btn btn-secondary btn-sm" aria-label="Réinitialiser les filtres">↺</a>
    </form>
    <button type="button" class="btn btn-primary" data-onclick="ouvrirModal">➕ Nouvelle salle</button>
  </div>
</div>

<div class="card">
  <div class="card-header"><span class="card-title">🚪 Salles ({{ count($salles) }})</span></div>
  <div class="table-wrap">
    <table class="table">
      <thead><tr><th>Code</th><th>Nom</th><th>Type</th><th>Bâtiment</th><th>Capacité</th><th>Équipements</th><th>Statut</th><th>Actions</th></tr></thead>
      <tbody>
      @forelse ($salles as $s)
        @php [$ic, $lb, $c] = $types[$s->type] ?? $types['autre']; @endphp
        <tr style="{{ $s->actif ? '' : 'opacity:.5' }}">
          <td><span class="badge badge-blue">{{ $s->code }}</span></td>
          <td style="font-weight:600">{{ $s->nom }}</td>
          <td><span style="background:{{ $c }}20;color:{{ $c }};padding:2px 8px;border-radius:8px;font-size:11px;font-weight:700">{{ $ic }} {{ $lb }}</span></td>
          <td class="text-sm text-muted">{{ $s->batiment ?? '—' }}</td>
          <td style="text-align:center;font-weight:600">{{ $s->capacite }}</td>
          <td class="text-xs text-muted" style="max-width:220px">{{ mb_strimwidth($s->equipements ?? '—', 0, 60, '…') }}</td>
          <td>
            @if ($s->actif)
              <span class="badge badge-green">✅ Active</span>
            @else
              <span class="badge badge-gray">⛔ Inactive</span>
            @endif
          </td>
          <td style="white-space:nowrap">
            <button type="button" class="btn btn-sm btn-secondary" data-onclick="ouvrirModal" data-args="{{ json_encode([$s]) }}" title="Modifier" aria-label="Modifier {{ $s->code }}">✏️</button>
            <form method="post" action="{{ route('salles.basculer', $retour) }}" style="display:inline" data-confirm="Basculer le statut de cette salle ?">
              @csrf
              <input type="hidden" name="id" value="{{ $s->id }}">
              <button type="submit" class="btn btn-sm btn-secondary" title="Activer/Désactiver" aria-label="Activer ou désactiver {{ $s->code }}">🔁</button>
            </form>
            @if (auth()->user()->aRole('admin'))
            <form method="post" action="{{ route('salles.supprimer', $retour) }}" style="display:inline" data-confirm="Supprimer définitivement cette salle ?">
              @csrf
              <input type="hidden" name="id" value="{{ $s->id }}">
              <button type="submit" class="btn btn-sm btn-danger" title="Supprimer" aria-label="Supprimer {{ $s->code }}">🗑️</button>
            </form>
            @endif
          </td>
        </tr>
      @empty
        <tr><td colspan="8"><div class="empty-state"><div class="icon">🚪</div>Aucune salle enregistrée.</div></td></tr>
      @endforelse
      </tbody>
    </table>
  </div>
</div>

<div id="modal-salle" style="display:none;position:fixed;inset:0;background:rgba(0,0,0,.5);z-index:200;align-items:center;justify-content:center" role="dialog" aria-labelledby="modal-titre">
  <div style="background:#fff;border-radius:12px;padding:24px;width:100%;max-width:460px;max-height:90vh;overflow-y:auto">
    <div id="modal-titre" style="font-weight:700;font-size:16px;color:var(--dark);margin-bottom:16px">➕ Nouvelle salle</div>
    <form method="post" action="{{ route('salles.enregistrer', $retour) }}">
      @csrf
      <input type="hidden" name="id" id="f-id" value="">
      <div class="form-group">
        <label class="form-label" for="f-code">Code <span style="color:var(--red-600)">*</span> <small style="color:var(--text-muted)">Ex. A101</small></label>
        <input type="text" name="code" id="f-code" class="form-control" required maxlength="20">
      </div>
      <div class="form-group">
        <label class="form-label" for="f-nom">Nom <span style="color:var(--red-600)">*</span></label>
        <input type="text" name="nom" id="f-nom" class="form-control" required maxlength="150">
      </div>
      <div style="display:grid;grid-template-columns:1fr 1fr;gap:12px">
        <div class="form-group">
          <label class="form-label" for="f-type">Type</label>
          <select name="type" id="f-type" class="form-control">
            @foreach ($types as $cle => [$ic, $lb, $c])
              <option value="{{ $cle }}">{{ $ic }} {{ $lb }}</option>
            @endforeach
          </select>
        </div>
        <div class="form-group">
          <label class="form-label" for="f-capacite">Capacité</label>
          <input type="number" name="capacite" id="f-capacite" class="form-control" min="0" value="0">
        </div>
      </div>
      <div class="form-group">
        <label class="form-label" for="f-batiment">Bâtiment / Campus</label>
        <input type="text" name="batiment" id="f-batiment" class="form-control" maxlength="100" placeholder="Ex. Bâtiment principal">
      </div>
      <div class="form-group">
        <label class="form-label" for="f-equipements">Équipements <small style="color:var(--text-muted)">séparés par des virgules</small></label>
        <textarea name="equipements" id="f-equipements" class="form-control" rows="2" placeholder="Vidéoprojecteur, Tableau interactif, Climatisation"></textarea>
      </div>
      <div style="display:flex;gap:8px;margin-top:20px">
        <button type="button" data-onclick="fermerModal" class="btn btn-secondary" style="flex:1">Annuler</button>
        <button type="submit" class="btn btn-primary" style="flex:1">💾 Enregistrer</button>
      </div>
    </form>
  </div>
</div>
@endsection

@push('scripts')
<script src="{{ \App\Support\Ressource::url('assets/js/salles.js') }}"></script>
@endpush
