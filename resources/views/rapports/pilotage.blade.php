{{-- Tableau de bord de pilotage : réussite, notes non finalisées, retards de saisie, présence --}}
@extends('layouts.app', ['titre' => 'Tableau de pilotage', 'fil' => ($rectorat ? 'Rectorat' : 'Rapports').' › Pilotage'])

@php
    $pct = fn ($v) => $v === null ? '—' : number_format((float) $v, 1, ',', ' ').' %';
    $couleur = fn ($v, $bon = 70, $moyen = 50) => $v === null ? '#9ca3af' : ($v >= $bon ? '#16a34a' : ($v >= $moyen ? '#d97706' : '#dc2626'));
    $barre = fn ($v, $c) => '<div style="background:#eef2f7;border-radius:4px;height:8px;min-width:80px"><div style="width:'.min(100, max(0, (float) $v)).'%;background:'.$c.';height:8px;border-radius:4px"></div></div>';
@endphp

@push('head')
<style>@media (max-width: 1100px) { .grille-pilotage { grid-template-columns: 1fr !important; } }</style>
@endpush

@section('contenu')
<form method="get" action="{{ route($route) }}" class="card mb-16">
  <div class="card-body" style="display:flex;gap:10px;align-items:flex-end;flex-wrap:wrap">
    <div class="form-group" style="margin:0"><label class="form-label" for="p-annee">Année académique</label>
      <select name="annee_id" id="p-annee" class="form-control">
        @foreach ($annees as $a)<option value="{{ $a->id }}" @selected($a->id == $anneeId)>{{ $a->libelle }}</option>@endforeach
      </select></div>
    @if ($facultes->isNotEmpty())
    <div class="form-group" style="margin:0"><label class="form-label" for="p-fac">Faculté</label>
      <select name="faculte_id" id="p-fac" class="form-control"><option value="">Toute l'université</option>
        @foreach ($facultes as $f)<option value="{{ $f->id }}" @selected($f->id == $faculteId)>{{ $f->libelle }}</option>@endforeach
      </select></div>
    @endif
    <button type="submit" class="btn btn-secondary">🔍 Afficher</button>
    <button type="button" class="btn btn-secondary" data-print>🖨️ Imprimer</button>
    <span style="margin-left:auto;font-size:12px;color:var(--text-muted)">Périmètre : <strong>{{ $perimetre }}</strong></span>
  </div>
</form>

<div style="display:grid;grid-template-columns:repeat(auto-fit,minmax(200px,1fr));gap:12px;margin-bottom:16px">
  @foreach ([
      ['🎓 Taux de réussite', $pct($resume['taux_reussite']), $resume['effectif'].' résultat(s) de semestre', $couleur($resume['taux_reussite'])],
      ['📝 Notes non finalisées', $resume['non_finalisees'], 'matière(s) / PV dont '.$resume['en_retard'].' en retard', $resume['non_finalisees'] ? '#d97706' : '#16a34a'],
      ['⏰ Enseignants en retard', $resume['enseignants_en_retard'], 'examen passé, notes non finalisées', $resume['enseignants_en_retard'] ? '#dc2626' : '#16a34a'],
      ['📋 Taux de présence', $pct($resume['taux_presence']), 'aux séances enregistrées', $couleur($resume['taux_presence'], 80, 65)],
  ] as [$titre, $valeur, $detail, $c])
  <div class="card"><div class="card-body" style="padding:14px 16px;border-left:4px solid {{ $c }}">
    <div style="font-size:12px;color:var(--text-muted)">{{ $titre }}</div>
    <div style="font-size:26px;font-weight:800;color:{{ $c }}">{{ $valeur }}</div>
    <div style="font-size:11px;color:var(--text-muted)">{{ $detail }}</div>
  </div></div>
  @endforeach
</div>

<div class="card mb-16">
  <div class="card-header"><span class="card-title">⏰ Retards de saisie par enseignant</span></div>
  <div class="card-body" style="padding:0;overflow-x:auto">
    @if ($retards->isEmpty())
    <div class="empty-state" style="padding:24px"><p>Aucun retard : toutes les notes des examens passés sont finalisées.</p></div>
    @else
    <table class="table">
      <thead><tr><th>Enseignant</th><th style="text-align:center">Matières en retard</th><th style="text-align:center">Retard maximal</th><th>Matières</th></tr></thead>
      <tbody>
      @foreach ($retards as $r)
      <tr>
        <td style="font-weight:700">{{ $r->nom }}</td>
        <td style="text-align:center">{{ count($r->matieres) }}</td>
        <td style="text-align:center;font-weight:700;color:{{ $r->retard_max > 14 ? '#dc2626' : '#d97706' }}">{{ $r->retard_max }} jour(s)</td>
        <td style="font-size:12px">{{ implode(' · ', $r->matieres) }}</td>
      </tr>
      @endforeach
      </tbody>
    </table>
    @endif
  </div>
</div>

<div class="card mb-16">
  <div class="card-header"><span class="card-title">📝 Notes non finalisées</span>
    <span style="font-size:11px;color:var(--text-muted)">Notes absentes du PV global tant que l'enseignant ne les a pas finalisées</span></div>
  <div class="card-body" style="padding:0;overflow-x:auto">
    @if ($non_finalisees->isEmpty())
    <div class="empty-state" style="padding:24px"><p>Toutes les notes sont finalisées.</p></div>
    @else
    <table class="table" style="font-size:12.5px">
      <thead><tr><th>Matière</th><th>Filière · Semestre</th><th>Session</th><th>À finaliser</th><th>Enseignant(s)</th><th style="text-align:center">Notes en attente</th><th>Examen</th><th style="text-align:center">Retard</th></tr></thead>
      <tbody>
      @foreach ($non_finalisees as $n)
      <tr>
        <td style="font-weight:600">{{ $n->matiere }}@if ($n->verrouille) <span title="PV verrouillé">🔒</span>@endif</td>
        <td>{{ $n->filiere }} {{ $n->niveau }} · {{ $n->semestre }}</td>
        <td>{{ $n->session === 'rattrapage' ? 'Rattrapage' : 'Normale' }}</td>
        <td>{{ implode(', ', $n->manquantes) }}</td>
        <td>{{ collect($n->enseignants)->pluck('nom')->implode(', ') ?: '— non affectée' }}</td>
        <td style="text-align:center">{{ $n->en_attente ?: '—' }}</td>
        <td>{{ $n->date_examen ? \Illuminate\Support\Carbon::parse($n->date_examen)->format('d/m/Y') : '—' }}</td>
        <td style="text-align:center;font-weight:700;color:{{ $n->retard === null ? '#9ca3af' : ($n->retard > 14 ? '#dc2626' : '#d97706') }}">{{ $n->retard === null ? '—' : $n->retard.' j' }}</td>
      </tr>
      @endforeach
      </tbody>
    </table>
    @endif
  </div>
</div>

<div style="display:grid;grid-template-columns:minmax(0,3fr) minmax(0,2fr);gap:16px" class="grille-pilotage">
  <div class="card">
    <div class="card-header"><span class="card-title">🎓 Réussite par filière</span></div>
    <div class="card-body" style="padding:0;overflow-x:auto">
      @if ($reussite->isEmpty())
      <div class="empty-state" style="padding:24px"><p>Aucun résultat calculé pour cette année.</p></div>
      @else
      <table class="table" style="font-size:12.5px">
        <thead><tr><th>Filière · Niveau</th><th>Semestre</th><th style="text-align:center">Effectif</th><th style="text-align:center">Validés</th><th>Taux</th><th style="text-align:center">Moyenne</th></tr></thead>
        <tbody>
        @foreach ($reussite as $r)
        <tr>
          <td style="font-weight:600">{{ $r->filiere }} {{ $r->niveau }}</td>
          <td style="white-space:nowrap">{{ $r->semestre }}@if ($r->rattrapage) <span style="font-size:10px;color:#b45309">(après rattrapage)</span>@endif</td>
          <td style="text-align:center">{{ $r->effectif }}</td>
          <td style="text-align:center">{{ $r->valides }}</td>
          <td style="min-width:150px"><div style="display:flex;gap:6px;align-items:center;white-space:nowrap">{!! $barre($r->taux, $couleur($r->taux)) !!}<strong style="color:{{ $couleur($r->taux) }}">{{ $pct($r->taux) }}</strong></div></td>
          <td style="text-align:center">{{ $r->moyenne !== null ? number_format($r->moyenne, 2, ',', ' ') : '—' }}</td>
        </tr>
        @endforeach
        </tbody>
      </table>
      @endif
    </div>
  </div>

  <div class="card">
    <div class="card-header"><span class="card-title">📋 Présence aux séances</span></div>
    <div class="card-body" style="padding:0;overflow-x:auto">
      @if ($presence->isEmpty())
      <div class="empty-state" style="padding:24px"><p>Aucune présence enregistrée pour cette année.</p></div>
      @else
      <table class="table" style="font-size:12.5px">
        <thead><tr><th>Filière · Niveau</th><th style="text-align:center">Séances</th><th style="text-align:center">Absences</th><th>Taux de présence</th></tr></thead>
        <tbody>
        @foreach ($presence as $p)
        <tr>
          <td style="font-weight:600">{{ $p->filiere }} {{ $p->niveau }}</td>
          <td style="text-align:center">{{ $p->seances }}</td>
          <td style="text-align:center">{{ $p->absents }}</td>
          <td style="min-width:130px"><div style="display:flex;gap:6px;align-items:center">{!! $barre($p->taux, $couleur($p->taux, 80, 65)) !!}<strong style="color:{{ $couleur($p->taux, 80, 65) }}">{{ $pct($p->taux) }}</strong></div></td>
        </tr>
        @endforeach
        </tbody>
      </table>
      @endif
    </div>
  </div>
</div>
@endsection
