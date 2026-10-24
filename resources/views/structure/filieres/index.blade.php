{{-- Filières, départements, UE et matières — portage de pages/structure/filieres.php --}}
@extends('layouts.app', ['titre' => 'Filières, Niveaux & UE', 'fil' => 'Configuration'])
@inject('navigation', 'App\Navigation\Menu')

@section('contenu')
<div style="display:flex;justify-content:flex-end;margin-bottom:12px">
  <a href="{{ $navigation->lien('import_ue_matieres') }}" class="btn btn-secondary btn-sm">📥 Importer UE & Matières depuis Excel</a>
</div>

<div class="flex gap-8 mb-20">
  <a href="{{ route('filieres', ['tab' => 'filieres']) }}" class="btn {{ $tab === 'filieres' ? 'btn-primary' : 'btn-secondary' }}">📚 Filières</a>
  <a href="{{ route('filieres', ['tab' => 'departements']) }}" class="btn {{ $tab === 'departements' ? 'btn-primary' : 'btn-secondary' }}">🏢 Départements</a>
  <a href="{{ route('filieres', ['tab' => 'structure']) }}" class="btn {{ $tab === 'structure' ? 'btn-primary' : 'btn-secondary' }}">🏗️ Structure UE / Matières</a>
  <a href="{{ $navigation->lien('suivi_execution') }}" class="btn btn-secondary">🕐 Suivi d'exécution</a>
</div>

@include('structure.filieres.'.$tab)

{{-- Données pour filieres.js (bloc JSON non exécuté) --}}
@php $donneesJs = ['departements' => $depParFaculte, 'ordres' => $ordres ?? []]; @endphp
<script type="application/json" id="donnees-filieres">@json($donneesJs)</script>
@endsection

@push('scripts')
<script src="{{ \App\Support\Ressource::url('assets/js/filieres.js') }}"></script>
@endpush
