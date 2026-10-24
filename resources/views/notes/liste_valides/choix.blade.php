{{-- Liste des admis : choix du PV — portage de liste_valides.php (sans pv_id) --}}
@extends('layouts.app', ['titre' => 'Liste des admis', 'fil' => 'Documents › Liste des admis'])

@section('contenu')
<div class="card" style="max-width:650px;margin:0 auto">
  <div class="card-header"><span class="card-title">✅ Sélectionner un PV</span></div>
  <div class="card-body">
    <form method="get" action="{{ route('liste_valides') }}">
      <div class="form-group">
        <label class="form-label" for="srch-pv">Rechercher</label>
        <input type="text" id="srch-pv" class="form-control" placeholder="Filière, niveau, année..." data-filtre-options="sel-pv">
      </div>
      <select name="pv_id" id="sel-pv" class="form-control" size="12" style="height:300px" required aria-label="PV">
        @foreach ($pvs as $annee => $liste)
        <optgroup label="{{ $annee }}">
          @foreach ($liste as $p)
          <option value="{{ $p->id }}">{{ $p->filiere }} — {{ $p->niveau }} — {{ $p->semestre }} ({{ \App\Support\Format::session($p->type_session) }})</option>
          @endforeach
        </optgroup>
        @endforeach
      </select>
      <div style="display:flex;gap:10px;margin-top:12px">
        <button type="submit" class="btn btn-primary flex-1">✅ Générer</button>
      </div>
    </form>
  </div>
</div>
@endsection

@push('scripts')
<script src="{{ \App\Support\Ressource::url('assets/js/filtre-options.js') }}"></script>
@endpush
