{{-- Présidents de jury : un enseignant par semestre de chaque promotion --}}
@extends('layouts.app', ['titre' => 'Présidents de jury', 'fil' => 'Délibérations › Présidents de jury'])

@section('contenu')
<form method="get" action="{{ route('presidents_jury') }}" class="card mb-16">
  <div class="card-body" style="display:flex;gap:10px;align-items:flex-end;flex-wrap:wrap">
    <div class="form-group" style="margin:0"><label class="form-label" for="f-annee">Année académique</label>
      <select name="annee_id" id="f-annee" class="form-control">
        @foreach ($annees as $a)<option value="{{ $a->id }}" @selected($a->id == $anneeId)>{{ $a->libelle }}</option>@endforeach
      </select></div>
    <div class="form-group" style="margin:0"><label class="form-label" for="f-filiere">Filière</label>
      <select name="filiere_id" id="f-filiere" class="form-control"><option value="">Toutes</option>
        @foreach ($filieres as $f)<option value="{{ $f->id }}" @selected($f->id == $filiereId)>{{ $f->libelle }}</option>@endforeach
      </select></div>
    <button type="submit" class="btn btn-secondary">🔍 Afficher</button>
  </div>
</form>

<form method="post" action="{{ route('presidents_jury.designer') }}">
  @csrf
  <input type="hidden" name="annee_id" value="{{ $anneeId }}">
  <input type="hidden" name="filiere_id" value="{{ $filiereId ?: '' }}">
  <div class="card">
    <div class="card-header" style="display:flex;gap:10px;align-items:center;flex-wrap:wrap">
      <span class="card-title">🧑‍⚖️ Présidents de jury</span>
      <div style="margin-left:auto;display:flex;gap:8px;align-items:center;flex-wrap:wrap">
        <label for="d-ens" style="font-size:12px;color:var(--text-muted)">Désigner</label>
        <select name="enseignant_id" id="d-ens" class="form-control form-control-sm" style="min-width:240px" required>
          <option value="">— Enseignant —</option>
          @foreach ($enseignants as $e)<option value="{{ $e->id }}">{{ $e->nom }} {{ $e->prenoms }}{{ $e->grade ? ' ('.$e->grade.')' : '' }}</option>@endforeach
        </select>
        <button type="submit" class="btn btn-primary btn-sm">✅ Président des semestres cochés</button>
      </div>
    </div>
    <div class="card-body" style="padding:0;overflow-x:auto">
      @if ($lignes->isEmpty())
      <div class="empty-state" style="padding:40px"><p>Aucun semestre pour cette année (ni UE, ni PV).</p></div>
      @else
      <table class="table">
        <thead><tr><th style="width:40px"></th><th>Filière</th><th>Niveau</th><th>Semestre</th><th>Président du jury</th><th style="width:60px"></th></tr></thead>
        <tbody>
        @foreach ($lignes as $l)
        @php $cle = $l->promotion_id.'-'.$l->semestre_id; @endphp
        <tr>
          <td style="text-align:center"><input type="checkbox" name="semestres[]" value="{{ $cle }}" id="s-{{ $cle }}" aria-label="{{ $l->filiere }} {{ $l->niveau }} {{ $l->semestre }}"></td>
          <td><label for="s-{{ $cle }}" style="font-weight:600;cursor:pointer">{{ $l->filiere }}</label></td>
          <td>{{ $l->niveau }}</td>
          <td>{{ $l->semestre }}</td>
          <td>@if ($l->president_id)<strong>{{ $l->nom }} {{ $l->prenoms }}</strong>@if ($l->grade)<span style="font-size:11px;color:var(--text-muted)"> — {{ $l->grade }}</span>@endif
              @else<span style="color:#b45309;font-size:12px">⚠️ Non désigné</span>@endif</td>
          <td>@if ($l->president_id)
            <button type="submit" form="retirer-{{ $cle }}" class="btn btn-sm btn-secondary" aria-label="Retirer le président">✖</button>
          @endif</td>
        </tr>
        @endforeach
        </tbody>
      </table>
      @endif
    </div>
  </div>
</form>
@foreach ($lignes->whereNotNull('president_id') as $l)
<form method="post" action="{{ route('presidents_jury.retirer') }}" id="retirer-{{ $l->promotion_id }}-{{ $l->semestre_id }}" data-confirm="Retirer le président du jury de ce semestre ?">
  @csrf
  <input type="hidden" name="annee_id" value="{{ $anneeId }}">
  <input type="hidden" name="filiere_id" value="{{ $filiereId ?: '' }}">
  <input type="hidden" name="semestre" value="{{ $l->promotion_id }}-{{ $l->semestre_id }}">
</form>
@endforeach
<p style="font-size:12px;color:var(--text-muted);margin-top:10px">
  Le président voit les PV de ses semestres dans « Mes jurys » : il les verrouille ou les déverrouille après la délibération et les imprime,
  sans pouvoir y saisir de notes. Son nom figure au bas du PV, du PV de délibération et de la liste des admis.
</p>
@endsection
