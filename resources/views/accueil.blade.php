{{-- Tableau de bord — portage de pages/accueil.php --}}
@extends('layouts.app', ['titre' => 'Tableau de bord', 'fil' => 'Accueil'])

@section('contenu')
@php
    $pc = fn ($n, $total, $dec = 0) => $total ? round($n / $total * 100, $dec) : null;
    $sansPed = empty($s['aPedParSemestre']) && empty($s['pedParFaculte']) && empty($s['pedParDept']);
@endphp

{{-- Filtre d'affichage — indépendant de l'année active, accessible à tous --}}
<div style="display:flex;align-items:center;gap:12px;margin-bottom:20px;padding:12px 16px;border-radius:10px;background:var(--blue-50);border:1px solid var(--blue-200)">
  <span style="font-size:18px">🔍</span>
  <div style="flex:1">
    <div style="font-size:11px;color:var(--text-muted);font-weight:600;text-transform:uppercase;letter-spacing:.5px">Statistiques affichées pour</div>
    <div style="font-size:13px;color:var(--text-muted)">Ce filtre ne change que l'affichage de cette page — l'année académique active du système n'est pas modifiée.</div>
  </div>
  <form method="get" action="{{ route('accueil') }}" style="display:flex;align-items:center;gap:8px">
    <select name="annee_vue" class="form-control" style="min-width:160px;font-weight:600" data-autosubmit="1" aria-label="Année affichée">
      @foreach ($annees as $an)
        <option value="{{ $an->id }}" @selected($an->id == $anneeVueId)>{{ $an->libelle }}{{ $an->active ? ' (active)' : '' }}</option>
      @endforeach
    </select>
  </form>
</div>

{{-- ══ INSCRIPTION SCOLARITÉ ══ --}}
<div style="font-weight:800;font-size:15px;color:var(--blue-700);margin-bottom:12px;border-left:4px solid var(--blue-600);padding-left:10px">💳 Inscription scolarité</div>

<div style="display:grid;grid-template-columns:repeat(3,1fr);gap:16px;margin-bottom:20px">
  @foreach ([['👥', 'Total inscrits', $s['totalInscrits'], 'var(--blue-700)'], ['👨', 'Hommes', $s['nbH'], 'var(--blue-500)'], ['👩', 'Femmes', $s['nbF'], '#e91e8c']] as [$ic, $lb, $val, $co])
  <div class="card" style="text-align:center;padding:18px">
    <div style="font-size:28px;margin-bottom:6px">{{ $ic }}</div>
    <div style="font-size:28px;font-weight:800;color:{{ $co }}">{{ number_format($val) }}</div>
    @if ($s['totalInscrits'] && $lb !== 'Total inscrits')
      <div style="font-size:12px;color:var(--text-muted)">{{ $pc($val, $s['totalInscrits']) }}%</div>
    @endif
    <div style="font-size:11px;color:var(--text-muted);text-transform:uppercase;letter-spacing:.5px;margin-top:4px">{{ $lb }}</div>
  </div>
  @endforeach
</div>

<div style="display:grid;grid-template-columns:300px 1fr;gap:16px;margin-bottom:16px">
  <div class="card"><div class="card-header"><span class="card-title">👥 Genre</span></div>
    <div class="card-body"><div class="graphe" style="height:260px"><canvas id="chartGenre"></canvas></div>
      <div style="display:flex;justify-content:center;gap:16px;margin-top:10px;font-size:12px">
        <span>👨 <strong style="color:var(--blue-600)">{{ $s['nbH'] }}</strong></span>
        <span>👩 <strong style="color:#e91e8c">{{ $s['nbF'] }}</strong></span>
      </div>
    </div>
  </div>
  <div class="card"><div class="card-header"><span class="card-title">🏛️ Par faculté</span></div>
    <div class="card-body"><div class="graphe" style="height:220px"><canvas id="chartFaculte"></canvas></div></div>
  </div>
</div>

<div style="display:grid;grid-template-columns:1fr 340px;gap:16px;margin-bottom:28px">
  <div class="card"><div class="card-header"><span class="card-title">📚 Par filière</span></div>
    <div class="card-body"><div class="graphe" style="height:{{ max(140, count($s['parFiliere']) * 30) + 60 }}px"><canvas id="chartFiliere"></canvas></div></div>
  </div>
  <div class="card"><div class="card-header"><span class="card-title">🎓 Par niveau</span></div>
    <div class="card-body" style="padding:0">
      <div class="graphe" style="height:240px;padding:16px"><canvas id="chartNiveau"></canvas></div>
      <table style="width:100%;font-size:12px;border-collapse:collapse">
        <thead><tr style="background:var(--gray-50)">
          <th style="padding:4px 12px;text-align:left">Niveau</th><th style="padding:4px 12px;text-align:right">Nb</th><th style="padding:4px 12px;text-align:right">%</th>
        </tr></thead>
        <tbody>
        @foreach ($s['parNiveau'] as $n)
        <tr style="border-bottom:.5px solid var(--border)">
          <td style="padding:4px 12px;font-weight:600">{{ $n['niveau'] }} <span style="color:var(--text-muted);font-weight:400;font-size:11px">{{ $n['libelle'] }}</span></td>
          <td style="padding:4px 12px;text-align:right;font-weight:700;color:var(--blue-700)">{{ $n['nb'] }}</td>
          <td style="padding:4px 12px;text-align:right;color:var(--text-muted)">{{ $s['totalInscrits'] ? $pc($n['nb'], $s['totalInscrits'], 1).'%' : '—' }}</td>
        </tr>
        @endforeach
        </tbody>
      </table>
    </div>
  </div>
</div>

{{-- ══ INSCRIPTION PÉDAGOGIQUE ══ --}}
<div style="font-weight:800;font-size:15px;color:var(--green-700);margin-bottom:12px;border-left:4px solid var(--green-600);padding-left:10px">🎓 Inscription pédagogique</div>

<div style="display:grid;grid-template-columns:repeat(4,1fr);gap:16px;margin-bottom:20px">
  @foreach ([['🎓', 'Inscrits péda.', $s['totalPed'], 'var(--green-700)'], ['👨', 'Hommes', $s['pedH'], 'var(--green-600)'], ['👩', 'Femmes', $s['pedF'], '#e91e8c'], ['⚠️', 'Sans péda.', $s['nonPed'], 'var(--amber-600)']] as [$ic, $lb, $val, $co])
  <div class="card" style="text-align:center;padding:16px">
    <div style="font-size:24px;margin-bottom:6px">{{ $ic }}</div>
    <div style="font-size:26px;font-weight:800;color:{{ $co }}">{{ number_format($val) }}</div>
    @if ($lb === 'Sans péda.' && $s['totalInscrits'])
      <div style="font-size:11px;color:var(--amber-600)">{{ $pc($val, $s['totalInscrits']) }}% non inscrits</div>
    @elseif ($s['totalPed'] && $lb !== 'Inscrits péda.' && $lb !== 'Sans péda.')
      <div style="font-size:11px;color:var(--text-muted)">{{ $pc($val, $s['totalPed']) }}%</div>
    @endif
    <div style="font-size:11px;color:var(--text-muted);text-transform:uppercase;letter-spacing:.5px;margin-top:4px">{{ $lb }}</div>
  </div>
  @endforeach
</div>

@if ($s['totalInscrits'] > 0)
@php $prog = $pc($s['totalPed'], $s['totalInscrits']); @endphp
<div class="card mb-16">
  <div class="card-body" style="padding:14px 20px">
    <div style="display:flex;justify-content:space-between;font-size:12px;margin-bottom:6px">
      <span style="font-weight:600">Progression inscription pédagogique</span>
      <span style="font-weight:700;color:var(--green-700)">{{ $s['totalPed'] }}/{{ $s['totalInscrits'] }} — {{ $prog }}%</span>
    </div>
    <div style="background:var(--gray-100);border-radius:6px;height:14px;overflow:hidden">
      <div style="background:linear-gradient(90deg,var(--green-500),var(--green-400));height:100%;width:{{ $prog }}%;border-radius:6px;transition:width .5s"></div>
    </div>
    <div style="display:flex;justify-content:space-between;font-size:11px;color:var(--text-muted);margin-top:4px">
      <span style="color:var(--green-600)">✅ {{ $s['totalPed'] }} inscrits pédagogiquement</span>
      <span style="color:var(--amber-600)">⚠️ {{ $s['nonPed'] }} sans inscription pédagogique</span>
    </div>
  </div>
</div>
@endif

@if ($sansPed)
<div class="card mb-16"><div class="card-body">
  <div class="empty-state" style="padding:24px"><div class="icon">🎓</div><p>Aucune inscription pédagogique pour cette année.</p></div>
</div></div>
@else
<div style="display:grid;grid-template-columns:1fr 1fr;gap:16px;margin-bottom:20px">
  @foreach ([['🏫 Par faculté', 'Faculté', 'faculte', 'pedParFaculte', 'chartPedFac'], ['🏛️ Par département', 'Département', 'departement', 'pedParDept', 'chartPedDept']] as [$titreCarte, $colonne, $champ, $cle, $canvas])
  <div class="card">
    <div class="card-header"><span class="card-title">{{ $titreCarte }}</span></div>
    <div class="card-body" style="padding:0">
      @if (empty($s[$cle]))
        <div style="padding:20px;text-align:center;color:var(--text-muted);font-size:13px">—</div>
      @else
        <div class="graphe" style="height:240px;padding:12px"><canvas id="{{ $canvas }}"></canvas></div>
        <table style="width:100%;font-size:12px;border-collapse:collapse">
          <thead><tr style="background:var(--gray-50)">
            <th style="padding:4px 12px;text-align:left">{{ $colonne }}</th><th style="padding:4px 12px;text-align:right">Nb</th><th style="padding:4px 12px;text-align:right">%</th>
          </tr></thead>
          <tbody>
          @foreach ($s[$cle] as $l)
          <tr style="border-bottom:.5px solid var(--border)">
            <td style="padding:4px 12px;font-size:11px;font-weight:600">{{ $l[$champ] }}</td>
            <td style="padding:4px 12px;text-align:right;font-weight:700;color:var(--green-700)">{{ $l['nb'] }}</td>
            <td style="padding:4px 12px;text-align:right;color:var(--text-muted)">{{ $s['totalPed'] ? $pc($l['nb'], $s['totalPed'], 1).'%' : '—' }}</td>
          </tr>
          @endforeach
          </tbody>
        </table>
      @endif
    </div>
  </div>
  @endforeach
</div>
@endif

{{-- Données des graphiques : bloc JSON non exécuté, lu par accueil.js --}}
@php
$graphiques = [
    'genre' => [$s['nbH'], $s['nbF']],
    'faculte' => ['labels' => array_column($s['parFaculte'], 'faculte'), 'data' => array_column($s['parFaculte'], 'nb')],
    'filiere' => ['labels' => array_column($s['parFiliere'], 'filiere'), 'data' => array_column($s['parFiliere'], 'nb')],
    'niveau' => ['labels' => array_column($s['parNiveau'], 'niveau'), 'data' => array_column($s['parNiveau'], 'nb')],
    'pedFac' => ['labels' => array_column($s['pedParFaculte'], 'faculte'), 'data' => array_column($s['pedParFaculte'], 'nb')],
    'pedDept' => ['labels' => array_column($s['pedParDept'], 'departement'), 'data' => array_column($s['pedParDept'], 'nb')],
];
@endphp
<script type="application/json" id="donnees-accueil">@json($graphiques)</script>
@endsection

@push('scripts')
<script src="{{ asset('assets/js/chart.min.js') }}"></script>
<script src="{{ asset('assets/js/accueil.js') }}"></script>
@endpush
