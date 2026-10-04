{{-- Import UE & matières — portage de pages/outils/import_ue_matieres.php --}}
@extends('layouts.app', ['titre' => 'Import UE & Matières', 'fil' => 'Filières › Import depuis Excel'])

@use('App\Http\Controllers\Structure\ImportUeMatieresController', 'IUM')

@section('contenu')
@if ($semestres === null)
<div class="card" style="max-width:640px">
  <div class="card-header"><span class="card-title">📥 Importer les UE et matières depuis Excel</span></div>
  <div class="card-body">
    <div class="alert alert-info" style="margin-bottom:16px;font-size:12px">💡 Le fichier doit suivre le format habituel d'ingénierie de formation : une colonne <strong>CODE UE / UE</strong>, une colonne <strong>CODE ECUE / ECUE</strong> (les matières), les <strong>crédits</strong>, organisés par blocs <strong>SEMESTRE 1</strong>, <strong>SEMESTRE 2</strong>, etc. L'aperçu vous permettra de vérifier avant toute création.</div>
    <form method="post" action="{{ route('import_ue_matieres.analyser') }}" enctype="multipart/form-data">
      @csrf
      <div class="form-group"><label class="form-label" for="fichier">Fichier Excel (.xlsx)</label><input type="file" name="fichier" id="fichier" class="form-control" accept=".xlsx" required></div>
      <button type="submit" class="btn btn-primary w-100">📄 Analyser le fichier</button>
    </form>
  </div>
</div>
@else
<div class="alert alert-info" style="margin-bottom:16px;font-size:12px">💡 Pour chaque semestre détecté, choisissez la promotion cible (ou laissez « — Ne pas importer — » pour l'ignorer). Les UE et matières déjà existantes (même code) seront mises à jour, jamais dupliquées.</div>
<form method="post" action="{{ route('import_ue_matieres.confirmer') }}">
  @csrf
  @foreach ($semestres as $codeS => $data)
  <div class="card mb-16">
    <div class="card-header" style="display:flex;justify-content:space-between;align-items:flex-start;flex-wrap:wrap;gap:8px">
      <span class="card-title">📚 {{ $data['libelle_source'] }} ({{ $codeS }})</span>
      <div style="text-align:right">
        <select name="promotion_semestre[{{ $codeS }}]" class="form-control" style="max-width:380px" aria-label="Promotion cible pour {{ $codeS }}">
          <option value="0">— Ne pas importer —</option>
          @foreach ($promotions as $p)<option value="{{ $p->id }}">{{ $p->filiere }} — {{ $p->niveau }} — {{ $p->annee }}</option>@endforeach
        </select>
        <label style="display:block;margin-top:6px;font-size:11.5px;color:var(--text-muted)"><input type="checkbox" name="remplacer[{{ $codeS }}]" value="1" style="vertical-align:middle"> Remplacer — supprimer les UE/matières existantes de cette promotion absentes de ce fichier</label>
      </div>
    </div>
    <div class="card-body" style="padding:0;overflow-x:auto">
      <table class="table" style="font-size:12px">
        <thead><tr><th>Code UE</th><th>UE</th><th style="text-align:center">Crédits UE</th><th>Matières</th></tr></thead>
        <tbody>
        @foreach ($data['ue'] as $ue)
        <tr>
          <td><code>{{ $ue['code'] }}</code></td><td style="font-weight:600">{{ $ue['libelle'] }}</td><td style="text-align:center">{{ $ue['credits_total'] ?? '—' }}</td>
          <td>@foreach ($ue['matieres'] as $m)<div>{{ $m['libelle'] }} <span style="color:var(--text-muted)">({{ $m['credits'] }} créd., coef {{ $m['coefficient'] }} → examen /{{ IUM::baremeExamen($m['coefficient']) }}{{ $m['volume_horaire'] ? ', '.$m['volume_horaire'].'h prévues' : '' }})</span></div>@endforeach</td>
        </tr>
        @endforeach
        </tbody>
      </table>
    </div>
  </div>
  @endforeach
  <div style="display:flex;gap:10px;margin-top:16px">
    <a href="{{ route('import_ue_matieres') }}" class="btn btn-secondary">✕ Annuler</a>
    <button type="submit" class="btn btn-primary">✅ Confirmer l'import</button>
  </div>
</form>
@endif
@endsection
