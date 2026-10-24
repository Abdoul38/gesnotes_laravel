{{-- Cartes étudiantes : choix individuel ou en masse — portage de carte_etudiant.php --}}
@extends('layouts.app', ['titre' => 'Cartes étudiantes', 'fil' => 'Documents › Cartes'])

@section('contenu')
<div style="display:grid;grid-template-columns:1fr 1fr;gap:20px">
  <div class="card">
    <div class="card-header"><span class="card-title">🪪 Carte individuelle</span></div>
    <div class="card-body">
      <div class="form-group">
        <label class="form-label" for="srch-et">Rechercher par matricule ou nom</label>
        <input type="text" id="srch-et" class="form-control" placeholder="Matricule ou nom...">
      </div>
      <select id="sel-et" class="form-control" size="8" style="height:200px" aria-label="Étudiant">
        @foreach ($etudiants as $e)
          <option value="{{ $e->id }}" data-matricule="{{ $e->numero }}" data-nom="{{ mb_strtolower($e->nom.' '.$e->prenoms) }}">{{ $e->numero ? $e->numero.' — ' : '' }}{{ $e->nom }} {{ $e->prenoms }}</option>
        @endforeach
      </select>
      <button type="button" data-navigate-from="sel-et" data-navigate-url="{{ route('carte_etudiant.imprimer') }}?id=" class="btn btn-primary w-100 mt-8">🪪 Générer la carte</button>
    </div>
  </div>
  <div class="card">
    <div class="card-header"><span class="card-title">🪪 Cartes en masse</span></div>
    <div class="card-body">
      <div class="form-group">
        <label class="form-label" for="sel-annee">Année académique</label>
        <select id="sel-annee" class="form-control">
          @foreach ($annees as $a)<option value="{{ $a->id }}" @selected($a->id == $anneeActive)>{{ $a->libelle }}</option>@endforeach
        </select>
      </div>
      <div class="form-group">
        <label class="form-label" for="sel-fac">Filtrer par faculté</label>
        <select id="sel-fac" class="form-control">
          <option value="">— Toutes les facultés —</option>
          @foreach ($facultes as $fac)<option value="{{ $fac }}">{{ $fac }}</option>@endforeach
        </select>
      </div>
      <button type="button" data-onclick="genererMasse" class="btn btn-primary w-100 mt-8" data-url="{{ route('carte_etudiant.imprimer') }}">🪪 Générer les cartes en masse</button>
    </div>
  </div>
</div>
@endsection

@push('scripts')
<script src="{{ \App\Support\Ressource::url('assets/js/cartes-selection.js') }}"></script>
@endpush
