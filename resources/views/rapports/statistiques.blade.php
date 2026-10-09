{{-- Tableau de bord statistiques — portage de pages/admin/stats.php --}}
@extends('layouts.app', ['titre' => 'Tableau de bord statistiques', 'fil' => 'Statistiques'])

@section('contenu')
<div class="card mb-20"><div class="card-body">
  <form method="get" action="{{ route('stats') }}" style="display:flex;gap:12px;align-items:flex-end;flex-wrap:wrap">
    <div class="form-group" style="margin:0;flex:1;min-width:180px"><label class="form-label" for="annee_id">Année académique</label>
      <select name="annee_id" id="annee_id" class="form-control" data-autosubmit="1">@foreach ($annees as $a)<option value="{{ $a->id }}" @selected($a->id == $anneeId)>{{ $a->libelle }}</option>@endforeach</select></div>
    <div class="form-group" style="margin:0;flex:1;min-width:180px"><label class="form-label" for="filiere_id">Filière</label>
      <select name="filiere_id" id="filiere_id" class="form-control" data-autosubmit="1"><option value="">Toutes les filières</option>@foreach ($filieres as $f)<option value="{{ $f->id }}" @selected($f->id == $filiereId)>{{ $f->libelle }}</option>@endforeach</select></div>
  </form>
</div></div>

<div style="display:grid;grid-template-columns:repeat(auto-fit,minmax(180px,1fr));gap:16px;margin-bottom:20px">
  @foreach ([['👥', 'Inscrits', $inscrits, 'var(--blue-600)'], ['✅', 'Admis', $admis, 'var(--green-700)'], ['📈', 'Taux réussite', $taux.'%', 'var(--blue-700)']] as [$ic, $lb, $val, $c])
  <div class="card" style="text-align:center;padding:16px"><div style="font-size:28px">{{ $ic }}</div><div style="font-size:24px;font-weight:800;color:{{ $c }}">{{ $val }}</div><div style="font-size:11px;color:var(--text-muted);text-transform:uppercase">{{ $lb }}</div></div>
  @endforeach
</div>

<div style="display:grid;grid-template-columns:repeat(auto-fit,minmax(320px,1fr));gap:20px;margin-bottom:20px">
  <div class="card"><div class="card-header"><span class="card-title">📊 Répartition des décisions</span></div><div class="card-body">
    <div class="stats-graphe"><canvas id="chartDecisions" aria-label="Répartition des décisions"></canvas></div>
    <p class="stats-note">Par étudiant inscrit ({{ array_sum($graphiques['decisions']) }}) : décision de passage ; à défaut, résultats des semestres après rattrapage.</p></div></div>
  <div class="card"><div class="card-header"><span class="card-title">🏅 Répartition des mentions</span></div><div class="card-body">
    <div class="stats-graphe"><canvas id="chartMentions" aria-label="Répartition des mentions"></canvas></div>
    <p class="stats-note">Semestres validés, après rattrapage.</p></div></div>
</div>

<div class="card mb-20">
  <div class="card-header"><span class="card-title">📈 Évolution pluriannuelle</span></div>
  <div class="card-body">
    @if ($evolution->isEmpty())
    <p style="color:var(--text-muted);font-size:13px;text-align:center;padding:16px 0">Aucune donnée historique disponible.</p>
    @else
    <div style="display:grid;grid-template-columns:repeat(auto-fit,minmax(300px,1fr));gap:20px;margin-bottom:16px">
      <div class="stats-graphe"><canvas id="chartEvolInscrits" aria-label="Évolution des inscrits"></canvas></div>
      <div class="stats-graphe"><canvas id="chartEvolTaux" aria-label="Évolution du taux de passage"></canvas></div>
    </div>
    <div style="overflow-x:auto"><table class="table">
      <thead><tr><th>Année</th><th style="text-align:center">Inscrits</th><th style="text-align:center">Passages</th><th style="text-align:center">Redoublants</th><th style="text-align:center">Taux passage</th></tr></thead>
      <tbody>
      @foreach ($evolution as $e)
      <tr><td style="font-weight:700">{{ $e->annee }}</td><td style="text-align:center;font-weight:600">{{ number_format($e->inscrits, 0, '', ' ') }}</td>
        <td style="text-align:center;color:var(--green-700);font-weight:700">{{ $e->passages }}</td><td style="text-align:center;color:var(--amber-600)">{{ $e->redoublants }}</td>
        <td style="text-align:center;font-weight:700;color:{{ $e->taux >= 50 ? 'var(--green-700)' : 'var(--red-600)' }}">{{ (float) $e->taux }}%</td></tr>
      @endforeach
      </tbody>
    </table></div>
    @endif
  </div>
</div>

<div class="card">
  <div class="card-header"><span class="card-title">🏫 Résultats par filière et niveau</span></div>
  <div class="card-body" style="padding:0;overflow-x:auto"><table class="table">
    <thead><tr><th>Filière</th><th>Niveau</th><th style="text-align:center">Inscrits</th><th style="text-align:center">Passages</th><th style="text-align:center">Redoublants</th><th style="text-align:center">Taux passage</th></tr></thead>
    <tbody>
    @forelse ($lignes as $l)
    @php $c = $l->taux >= 70 ? 'var(--green-700)' : ($l->taux >= 50 ? 'var(--amber-600)' : 'var(--red-600)'); @endphp
    <tr><td style="font-weight:600">{{ $l->filiere }}</td><td><span class="badge badge-blue">{{ $l->niveau }}</span></td><td style="text-align:center;font-weight:600">{{ $l->inscrits }}</td>
      <td style="text-align:center;color:var(--green-700);font-weight:700">{{ $l->passages }}</td><td style="text-align:center;color:var(--amber-600)">{{ $l->redoublants }}</td>
      <td style="text-align:center"><span style="font-weight:700;color:{{ $c }}">{{ $l->taux }}%</span></td></tr>
    @empty
    <tr><td colspan="6" style="text-align:center;color:var(--text-muted);padding:20px">Aucune donnée.</td></tr>
    @endforelse
    </tbody>
  </table></div>
</div>
<script type="application/json" id="donnees-stats">@json($graphiques)</script>
@endsection

@push('head')
{{-- Hauteur fixe autour de chaque graphique : sans elle, Chart.js agrandit le canevas sans fin --}}
<style>.stats-graphe{position:relative;height:260px;width:100%;min-width:0}.stats-note{font-size:11px;color:var(--text-muted);margin:8px 0 0;text-align:center}</style>
@endpush

@push('scripts')
<script src="{{ asset('assets/js/chart.min.js') }}"></script>
<script src="{{ asset('assets/js/statistiques.js') }}"></script>
@endpush
