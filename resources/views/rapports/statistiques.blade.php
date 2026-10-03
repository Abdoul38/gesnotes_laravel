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
  <div class="card"><div class="card-header"><span class="card-title">📊 Répartition des décisions</span></div><div class="card-body"><canvas id="chartDecisions" height="220" aria-label="Répartition des décisions"></canvas></div></div>
  <div class="card"><div class="card-header"><span class="card-title">🏅 Répartition des mentions</span></div><div class="card-body"><canvas id="chartMentions" height="220" aria-label="Répartition des mentions"></canvas></div></div>
</div>

<div class="card mb-20">
  <div class="card-header"><span class="card-title">📈 Évolution pluriannuelle</span></div>
  <div class="card-body">
    @if ($evolution->isEmpty())
    <p style="color:var(--text-muted);font-size:13px;text-align:center;padding:16px 0">Aucune donnée historique disponible.</p>
    @else
    <div style="display:grid;grid-template-columns:repeat(auto-fit,minmax(300px,1fr));gap:20px;margin-bottom:16px">
      <canvas id="chartEvolInscrits" height="180" aria-label="Évolution des inscrits"></canvas><canvas id="chartEvolTaux" height="180" aria-label="Évolution du taux de passage"></canvas>
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

@push('scripts')
<script src="{{ asset('assets/js/chart.min.js') }}"></script>
<script src="{{ asset('assets/js/statistiques.js') }}"></script>
@endpush
