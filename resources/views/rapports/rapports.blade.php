{{-- Rapports & exports — portage de pages/admin/rapports.php --}}
@extends('layouts.app', ['titre' => 'Rapports & Exports', 'fil' => 'Reporting › Rapports'])

@section('contenu')
@php $lien = ['rapport' => $rapport, 'annee_id' => $anneeId, 'filiere_id' => $filiereId ?: null, 'niveau_id' => $niveauId ?: null]; @endphp
<div class="card mb-20"><div class="card-body">
  <form method="get" action="{{ route('rapports') }}" style="display:flex;gap:12px;flex-wrap:wrap;align-items:flex-end">
    <div><label class="form-label" for="rapport">Type de rapport</label>
      <select name="rapport" id="rapport" class="form-control" data-autosubmit="1" style="width:230px;font-weight:600">
        @foreach (\App\Http\Controllers\Rapports\RapportsController::RAPPORTS as $cle => $lib)<option value="{{ $cle }}" @selected($rapport === $cle)>{{ $lib }}</option>@endforeach
      </select></div>
    @if ($rapport !== 'comparatif')
    <div><label class="form-label" for="annee_id">Année</label>
      <select name="annee_id" id="annee_id" class="form-control" style="width:160px">@foreach ($annees as $a)<option value="{{ $a->id }}" @selected($a->id == $anneeId)>{{ $a->libelle }}</option>@endforeach</select></div>
    <div><label class="form-label" for="filiere_id">Filière</label>
      <select name="filiere_id" id="filiere_id" class="form-control" style="width:220px"><option value="">Toutes</option>
        @foreach ($filieres->groupBy('faculte') as $fac => $liste)<optgroup label="{{ $fac ?: '—' }}">@foreach ($liste as $f)<option value="{{ $f->id }}" @selected($f->id == $filiereId)>{{ $f->libelle }}</option>@endforeach</optgroup>@endforeach
      </select></div>
    @if ($rapport === 'inscriptions')
    <div><label class="form-label" for="niveau_id">Niveau</label>
      <select name="niveau_id" id="niveau_id" class="form-control" style="width:110px"><option value="">Tous</option>@foreach ($niveaux as $n)<option value="{{ $n->id }}" @selected($n->id == $niveauId)>{{ $n->code }}</option>@endforeach</select></div>
    @endif
    @endif
    <button type="submit" class="btn btn-primary">🔍 Générer</button>
    <a href="{{ route('rapports', $lien + ['print' => 1]) }}" target="_blank" rel="noopener" class="btn btn-secondary">🖨️ Imprimer / PDF</a>
    <a href="{{ route('rapports_export', $lien) }}" class="btn btn-success">📊 Export Excel (CSV)</a>
  </form>
</div></div>

@if ($rapport !== 'comparatif')
<div style="display:grid;grid-template-columns:repeat(auto-fit,minmax(140px,1fr));gap:14px;margin-bottom:20px">
  @foreach ([['Inscrits', '👥', $globaux->nb, '#1e40af', '#dbeafe'], ['Hommes', '👨', $globaux->h, '#0369a1', '#e0f2fe'], ['Femmes', '👩', $globaux->f, '#5b21b6', '#ede9fe'],
             ['Filières', '📚', $globaux->nf, '#166534', '#dcfce7'], ['Total FCFA', '💰', $globaux->tp, '#854d0e', '#fef9c3']] as [$lbl, $ic, $val, $txt, $bg])
  <div style="background:{{ $bg }};border-radius:10px;padding:14px;text-align:center"><div style="font-size:22px">{{ $ic }}</div><div style="font-size:16px;font-weight:800;color:{{ $txt }}">{{ number_format((float) $val, 0, ',', ' ') }}</div><div style="font-size:11px;color:{{ $txt }}">{{ $lbl }}</div></div>
  @endforeach
</div>
@endif

<div class="card">
  <div class="card-header"><span class="card-title">{{ \App\Http\Controllers\Rapports\RapportsController::RAPPORTS[$rapport] }}{{ $rapport !== 'comparatif' ? ' — '.$anneeLib : '' }}</span><span style="font-size:12px;color:var(--text-muted)">{{ $donnees->count() }} ligne(s)</span></div>
  <div class="card-body" style="padding:0;overflow-x:auto">@include('rapports._tableau')</div>
</div>
@endsection
