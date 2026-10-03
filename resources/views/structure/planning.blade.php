{{-- Planning des examens — portage de pages/structure/planning.php --}}
@extends('layouts.app', ['titre' => 'Planning des examens', 'fil' => 'Administratif › Planning'])

@section('contenu')
<div class="card mb-20"><div class="card-body" style="display:flex;gap:12px;align-items:flex-end;flex-wrap:wrap">
  <div class="form-group" style="margin:0;flex:1;min-width:240px"><label class="form-label" for="choix-pv">Sélectionner un PV</label>
    <select id="choix-pv" class="form-control" data-navigate="{{ route('planning') }}?pv_id=">
      <option value="">— Choisir un PV —</option>
      @foreach ($pvs as $p)<option value="{{ $p->id }}" @selected($p->id == $pvId)>{{ $p->libelle }} — {{ \App\Services\Pv::SESSIONS[$p->type_session] ?? $p->type_session }} — {{ $p->annee }}</option>@endforeach
    </select></div>
  @if ($pv)<a href="{{ route('planning', ['pv_id' => $pv->id, 'imprimer' => 1]) }}" target="_blank" rel="noopener" class="btn btn-secondary">🖨️ Imprimer</a>@endif
</div></div>

@if ($pv)
<form method="post" action="{{ route('planning.sauvegarder') }}" id="form-planning" data-url-disponibilite="{{ route('planning.disponibilite') }}">
  @csrf
  <input type="hidden" name="pv_id" value="{{ $pv->id }}">
  <div class="card">
    <div class="card-header"><span class="card-title">📅 Planning — {{ $pv->libelle }}</span><button type="submit" class="btn btn-primary btn-sm">💾 Sauvegarder</button></div>
    <div class="card-body" style="padding:0;overflow-x:auto">
      <table class="table" style="font-size:12px">
        <thead><tr><th style="min-width:200px">Matière</th><th style="min-width:130px">Date</th><th style="min-width:90px">Début</th><th style="min-width:90px">Fin</th><th style="min-width:150px">Salle</th><th style="min-width:160px">Surveillant 1</th><th style="min-width:160px">Surveillant 2</th></tr></thead>
        <tbody>
        @foreach ($ues as $ue)
        <tr style="background:var(--blue-700,#1A3A5C)"><td colspan="7" style="color:#fff;font-weight:700;padding:4px 12px;font-size:11px">{{ $ue->libelle }}</td></tr>
        @foreach ($ue->matieres as $m)
        @php $pl = $planning[$m->id] ?? null; $n = "planning[{$m->id}]"; @endphp
        <tr data-ligne-planning data-exclure="{{ $pl->id ?? 0 }}">
          <td style="font-weight:600">{{ $m->libelle }}</td>
          <td><input type="date" name="{{ $n }}[date]" class="form-control form-control-sm" data-champ="date" value="{{ $pl->date_examen ?? '' }}" aria-label="Date — {{ $m->libelle }}"></td>
          <td><input type="time" name="{{ $n }}[heure_debut]" class="form-control form-control-sm" data-champ="debut" value="{{ substr($pl->heure_debut ?? '08:00', 0, 5) }}" aria-label="Début"></td>
          <td><input type="time" name="{{ $n }}[heure_fin]" class="form-control form-control-sm" data-champ="fin" value="{{ substr($pl->heure_fin ?? '10:00', 0, 5) }}" aria-label="Fin"></td>
          <td>
            <select name="{{ $n }}[salle_id]" class="form-control form-control-sm" data-champ="salle" aria-label="Salle">
              <option value="">— Aucune —</option>
              @foreach ($salles->groupBy('batiment') as $bat => $liste)<optgroup label="{{ $bat ?: 'Sans bâtiment' }}">@foreach ($liste as $sa)<option value="{{ $sa->id }}" @selected(($pl->salle_id ?? null) == $sa->id)>{{ $sa->code }} — {{ $sa->nom }} ({{ $sa->capacite }})</option>@endforeach</optgroup>@endforeach
            </select>
            <div data-avertissement style="display:none;font-size:10px;color:#b45309;margin-top:2px">⚠️ Occupée à cette date/heure</div>
          </td>
          @foreach (['surv1' => 'surveillant_1', 'surv2' => 'surveillant_2'] as $champ => $col)
          <td><select name="{{ $n }}[{{ $champ }}]" class="form-control form-control-sm" aria-label="Surveillant"><option value="">—</option>
            @foreach ($enseignants as $e)<option value="{{ $e->id }}" @selected(($pl->$col ?? null) == $e->id)>{{ $e->nom }} {{ $e->prenoms }}</option>@endforeach</select></td>
          @endforeach
        </tr>
        @endforeach
        @endforeach
        </tbody>
      </table>
    </div>
  </div>
  <p style="font-size:11px;color:var(--text-muted);margin-top:8px">Videz la date d'une matière pour la retirer du planning.</p>
  <div style="text-align:right;margin-top:12px"><button type="submit" class="btn btn-primary btn-lg">💾 Sauvegarder le planning</button></div>
</form>
@else
<div class="card"><div class="empty-state" style="padding:60px"><div class="icon" style="font-size:48px">📅</div><h3>Planning des examens</h3><p>Sélectionnez un PV pour gérer le planning.</p></div></div>
@endif
@endsection

@push('scripts')
<script src="{{ asset('assets/js/salles-disponibilite.js') }}"></script>
@endpush
