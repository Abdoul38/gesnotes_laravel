{{-- Listes des inscrits — portage de liste_inscrits.php --}}
@extends('layouts.app', ['titre' => 'Listes des inscrits', 'fil' => 'Scolarité › Listes'])

@php
    $scolarite = array_diff_key($parametres, ['pedagogique' => 1]);
    $export = $parametres + ($c['pedagogique'] ? ['pedagogique' => 1] : []);
@endphp

@section('contenu')
<div class="card mb-16 no-print">
  <div class="card-body">
    <form method="get" action="{{ route('liste_inscrits') }}" style="display:flex;gap:10px;flex-wrap:wrap;align-items:flex-end">
      @if ($c['pedagogique'] && $c['type'] !== 'semestre')<input type="hidden" name="pedagogique" value="1">@endif

      <div class="form-group" style="margin:0;min-width:150px">
        <label class="form-label" for="annee_id">Année</label>
        <select name="annee_id" id="annee_id" class="form-control" data-autosubmit="1">
          <option value="">— Sélectionner —</option>
          @foreach ($annees as $a)<option value="{{ $a->id }}" @selected($a->id == $c['annee_id'])>{{ $a->libelle }}</option>@endforeach
        </select>
      </div>

      <div class="form-group" style="margin:0;min-width:210px">
        <label class="form-label" for="type">Regrouper par</label>
        <select name="type" id="type" class="form-control" data-autosubmit="1">
          @foreach (['tous' => 'Tous', 'faculte' => 'Par Faculté', 'departement' => 'Par Département', 'filiere' => 'Par Filière', 'niveau' => 'Par Niveau (ex: L1 Droit)', 'semestre' => 'Par Semestre (pédagogique)'] as $v => $l)
          <option value="{{ $v }}" @selected($c['type'] === $v)>{{ $l }}</option>
          @endforeach
        </select>
      </div>

      @if ($c['type'] === 'faculte' || $c['type'] === 'departement')
      <div class="form-group" style="margin:0;min-width:180px">
        <label class="form-label" for="filtre_id">{{ $c['type'] === 'faculte' ? 'Faculté' : 'Département' }}</label>
        <select name="filtre_id" id="filtre_id" class="form-control" data-autosubmit="1">
          <option value="">{{ $c['type'] === 'faculte' ? 'Toutes' : 'Tous' }}</option>
          @foreach ($c['type'] === 'faculte' ? $facultes : $departements as $v)<option value="{{ $v }}" @selected($v === $c['filtre'])>{{ $v }}</option>@endforeach
        </select>
      </div>
      @elseif ($c['type'] === 'filiere')
      <div class="form-group" style="margin:0;min-width:220px">
        <label class="form-label" for="filtre_id">Filière</label>
        <select name="filtre_id" id="filtre_id" class="form-control" data-autosubmit="1">
          <option value="0">Toutes les filières</option>
          @foreach ($filieres as $f)<option value="{{ $f->id }}" @selected($f->id == $c['filtre_id'])>{{ $f->libelle }}</option>@endforeach
        </select>
      </div>
      @elseif ($c['type'] === 'niveau' || $c['type'] === 'semestre')
      <div class="form-group" style="margin:0;min-width:200px">
        <label class="form-label" for="filiere_id">Filière</label>
        <select name="filiere_id" id="filiere_id" class="form-control" data-autosubmit="1">
          <option value="0">Toutes les filières</option>
          @foreach ($filieres as $f)<option value="{{ $f->id }}" @selected($f->id == $c['filiere_id'])>{{ $f->libelle }}</option>@endforeach
        </select>
      </div>
      <div class="form-group" style="margin:0;min-width:100px">
        <label class="form-label" for="niveau_id">Niveau</label>
        <select name="niveau_id" id="niveau_id" class="form-control" data-autosubmit="1">
          <option value="0">Tous</option>
          @foreach ($niveaux as $n)<option value="{{ $n->id }}" @selected($n->id == $c['niveau_id'])>{{ $n->code }}</option>@endforeach
        </select>
      </div>
      @if ($c['type'] === 'semestre')
      <div class="form-group" style="margin:0;min-width:130px">
        <label class="form-label" for="semestre_id">Semestre</label>
        <select name="semestre_id" id="semestre_id" class="form-control" data-autosubmit="1">
          <option value="0">Tous les semestres</option>
          @foreach ($semestres as $s)<option value="{{ $s->id }}" @selected($s->id == $c['semestre_id'])>{{ $s->libelle }}</option>@endforeach
        </select>
      </div>
      @endif
      @endif

      @if ($c['type'] !== 'semestre')
      <div class="form-group" style="margin:0">
        <span class="form-label">Inscription</span>
        <div style="display:flex;gap:6px">
          <a href="{{ route('liste_inscrits', $scolarite) }}" class="btn {{ ! $c['pedagogique'] ? 'btn-primary' : 'btn-secondary' }}">💳 Scolarité</a>
          <a href="{{ route('liste_inscrits', $scolarite + ['pedagogique' => 1]) }}" class="btn {{ $c['pedagogique'] ? 'btn-primary' : 'btn-secondary' }}">🎓 Pédagogique</a>
        </div>
      </div>
      @else
      <div class="form-group" style="margin:0">
        <span class="form-label">Mode</span>
        <span class="badge badge-blue" style="padding:8px 12px">🎓 Inscription pédagogique</span>
      </div>
      @endif

      <div class="form-group" style="margin:0;margin-left:auto">
        <span class="form-label">&nbsp;</span>
        <div style="display:flex;gap:6px">
          <a href="{{ route('liste_inscrits.pdf', $export) }}" target="_blank" rel="noopener" class="btn btn-primary">📄 PDF A4</a>
          <a href="{{ route('liste_inscrits.excel', $export) }}" class="btn btn-secondary">📊 Excel</a>
          <button type="button" data-print="1" class="btn btn-secondary">🖨️ Imprimer</button>
        </div>
      </div>
    </form>
  </div>
</div>

@if ($c['annee_id'] && $total)
<div style="display:flex;gap:12px;margin-bottom:16px;flex-wrap:wrap">
  <div class="card" style="flex:1;padding:12px;text-align:center"><div style="font-size:20px;font-weight:800;color:var(--blue-700)">{{ $total }}</div><div style="font-size:10px;color:var(--text-muted);text-transform:uppercase">Total</div></div>
  <div class="card" style="flex:1;padding:12px;text-align:center"><div style="font-size:20px;font-weight:800;color:var(--blue-500)">{{ $hommes }}</div><div style="font-size:10px;color:var(--text-muted);text-transform:uppercase">Hommes</div></div>
  <div class="card" style="flex:1;padding:12px;text-align:center"><div style="font-size:20px;font-weight:800;color:#e91e8c">{{ $femmes }}</div><div style="font-size:10px;color:var(--text-muted);text-transform:uppercase">Femmes</div></div>
</div>
@endif

@if (! $c['annee_id'])
<div class="alert alert-info">Sélectionnez une année académique.</div>
@elseif (! $groupes)
<div class="card"><div class="card-body">
  <div class="empty-state" style="padding:40px">
    <div class="icon">👥</div>
    <p>Aucun étudiant trouvé pour ces critères.</p>
    @if ($c['pedagogique'])<p style="font-size:12px;color:var(--text-muted);margin-top:8px">Vérifiez que les inscriptions pédagogiques ont été effectuées.</p>@endif
  </div>
</div></div>
@else
@foreach ($groupes as $groupe => $ets)
<div class="card mb-16">
  <div class="card-header">
    <span class="card-title">{{ ['faculte' => '🏫 ', 'departement' => '🏛️ ', 'filiere' => '📚 ', 'niveau' => '🎓 ', 'semestre' => '📅 '][$c['type']] ?? '👥 ' }}{{ $groupe }}</span>
    <span style="font-size:12px;color:var(--text-muted)">{{ count($ets) }} étudiant(s) — {{ collect($ets)->where('sexe', 'M')->count() }}H / {{ collect($ets)->where('sexe', 'F')->count() }}F</span>
  </div>
  <div class="card-body" style="padding:0">
    <table class="table" style="font-size:12px">
      <thead><tr>
        <th style="width:36px">#</th><th style="width:80px">Matricule</th><th>Nom & Prénoms</th><th style="width:36px;text-align:center">Sexe</th>
        @if ($colonnes['filiere'])<th>Filière</th>@endif
        @if ($colonnes['niveau'])<th style="width:50px;text-align:center">Niveau</th>@endif
        @if ($colonnes['semestres'])<th>Semestres</th>@endif
        <th style="width:40px"></th>
      </tr></thead>
      <tbody>
      @foreach ($ets as $et)
      <tr>
        <td style="color:var(--text-muted)">{{ $loop->iteration }}</td>
        <td style="font-family:monospace;font-weight:700;color:var(--blue-700)">{{ $et->matricule ?? '—' }}</td>
        <td><strong>{{ $et->nom }}</strong> <span style="color:var(--text-muted)">{{ $et->prenoms }}</span></td>
        <td style="text-align:center">@if ($et->sexe === 'M')<span class="badge badge-blue">M</span>@elseif ($et->sexe === 'F')<span class="badge badge-red">F</span>@else—@endif</td>
        @if ($colonnes['filiere'])<td style="font-size:11px">{{ $et->filiere }}</td>@endif
        @if ($colonnes['niveau'])<td style="text-align:center"><span style="background:var(--blue-50);color:var(--blue-700);padding:1px 8px;border-radius:8px;font-size:11px;font-weight:600">{{ $et->niveau }}</span></td>@endif
        @if ($colonnes['semestres'])<td style="font-size:11px;color:var(--green-700)">{{ $et->semestres ?? '—' }}</td>@endif
        <td style="text-align:center"><a href="{{ route('dossier_etudiant', ['id' => $et->id]) }}" class="btn btn-sm btn-secondary" style="padding:2px 8px;font-size:10px" title="Dossier">📁</a></td>
      </tr>
      @endforeach
      </tbody>
    </table>
  </div>
</div>
@endforeach
@endif
@endsection

@push('head')
<style>
@media print {
  .sidebar, .topbar, .no-print, .btn, button, .topbar-breadcrumb, .nav-item { display:none!important }
  .main-wrap { margin:0!important }
  .page-content { padding:0!important }
  table { font-size:11px }
  .card { box-shadow:none!important; border:1px solid #ccc!important }
}
</style>
@endpush
