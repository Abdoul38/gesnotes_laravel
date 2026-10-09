{{-- Tableau de bord national du Ministère — portage de pages/ministere.php --}}
@extends('espaces.layout', ['titre' => 'Tableau de bord national'])

@use('App\Http\Controllers\Espaces\MinistereController', 'MC')
@use('App\Services\IndicateursNationaux', 'IN')

@php
  $compte = auth()->guard('mesr')->user();
  $fmt = fn ($n) => number_format((float) $n, 0, '', ' ');
  $couleurTaux = fn ($t) => $t === null ? '#6b7280' : ($t >= 50 ? '#166534' : '#dc2626');
  // Taux vide (aucune décision de passage encore) : « — » plutôt que 0 %
  $pctTaux = fn ($t) => $t === null ? '—' : $t.'%';
  $couleurProg = fn ($t) => $t >= 80 ? '#166534' : ($t >= 50 ? '#C8860A' : '#dc2626');
  $badgeProg = fn ($t) => $t >= 80 ? 'badge-green' : ($t >= 50 ? 'badge-amber' : 'badge-red');
  $couleur = fn ($u) => preg_match('/^#[0-9A-Fa-f]{6}$/', $u->couleur) ? $u->couleur : '#1A3A5C';
  $statuts = ['finalise' => ['Finalisé', 'badge-blue'], 'publie' => ['Publié', 'badge-green']];
  $tendance = function ($delta, $suffixe = '') {
      if ($delta === null) return '';
      if ($delta == 0) return '<div style="font-size:10px;color:#6b7280;margin-top:2px">→ stable vs année préc.</div>';
      return '<div style="font-size:10px;font-weight:700;margin-top:2px;color:'.($delta > 0 ? '#166534' : '#dc2626').'">'.($delta > 0 ? '↑ +' : '↓ ').e($delta.$suffixe).' vs année préc.</div>';
  };
@endphp

@push('nav')
<a href="{{ route('ministere.excel', ['annee' => $annee]) }}">📊 Excel</a>
<a href="{{ route('ministere.rapport', ['annee' => $annee]) }}" target="_blank" rel="noopener">📥 Rapport</a>
@endpush

@section('contenu')
<div class="card mb-16"><div class="card-body" style="display:flex;gap:10px;align-items:center;flex-wrap:wrap;padding:10px 14px">
  <form method="get" action="{{ route('ministere.accueil') }}" style="display:flex;gap:8px;align-items:center;margin:0">
    <input type="hidden" name="onglet" value="{{ $onglet }}">
    <label for="annee" style="font-weight:700;font-size:13px;color:#1A3A5C">📅 Année</label>
    <select name="annee" id="annee" class="form-control" style="max-width:170px" data-autosubmit="1">
      @foreach ($annees as $lib)<option value="{{ $lib }}" @selected($lib === $annee)>{{ $lib }}</option>@endforeach
    </select>
  </form>
  <nav style="display:flex;gap:4px;flex-wrap:wrap;margin-left:auto" aria-label="Onglets">
    @foreach (MC::ONGLETS as $cle => $lib)
    @continue($cle === 'journal' && ! $compte->estAdmin())
    <a href="{{ route('ministere.accueil', ['onglet' => $cle, 'annee' => $annee]) }}" class="btn btn-sm {{ $onglet === $cle ? 'btn-primary' : 'btn-secondary' }}" @if ($onglet === $cle) aria-current="page" @endif>{{ $lib }}</a>
    @endforeach
  </nav>
</div></div>

@if ($onglet === 'national')
@php $t = $totaux; @endphp
<div style="display:grid;grid-template-columns:repeat(auto-fit,minmax(170px,1fr));gap:12px;margin-bottom:16px">
  @foreach ([['🎓', 'Universités', $t['universites'], '#1A3A5C', ''], ['📋', 'Inscrits '.$annee, $fmt($t['inscrits']), '#166534', $tendance($t['delta_inscrits'])],
             ['🏆', 'Taux de passage national', $pctTaux($t['taux_passage']), $couleurTaux($t['taux_passage']), $tendance($t['delta_taux'], ' pt')],
             ['📘', 'Exécution du programme', $t['taux_programme'].'%', $couleurProg($t['taux_programme']), ''], ['📄', 'PV publiés', $fmt($t['pv_publies']), '#7C3AED', '']] as [$ic, $lb, $val, $c, $tr])
  <div class="card" style="padding:14px"><div style="font-size:20px">{{ $ic }}</div><div style="font-size:22px;font-weight:800;color:{{ $c }}">{{ $val }}</div>
    <div style="font-size:10px;color:var(--text-muted);text-transform:uppercase">{{ $lb }}</div>{!! $tr !!}</div>
  @endforeach
</div>
<div class="card mb-16"><div class="card-body">
  <div class="card-title" style="margin-bottom:10px">📚 Exécution du programme — détail par matière (toutes universités, {{ $annee }})</div>
  <div style="display:grid;grid-template-columns:repeat(auto-fit,minmax(180px,1fr));gap:12px">
    <div style="background:#dcfce7;border-radius:8px;padding:14px;text-align:center"><div style="font-size:26px;font-weight:800;color:#166534">{{ $t['matieres']['completes'] }}</div><div style="font-size:11px;color:#166534">Matières complètes (100% des heures faites)</div></div>
    <div style="background:#fef9c3;border-radius:8px;padding:14px;text-align:center"><div style="font-size:26px;font-weight:800;color:#92400e">{{ $t['matieres']['en_cours'] }}</div><div style="font-size:11px;color:#92400e">Matières en cours (heures partiellement faites)</div></div>
    <div style="background:#fee2e2;border-radius:8px;padding:14px;text-align:center"><div style="font-size:26px;font-weight:800;color:#991b1b">{{ $t['matieres']['sans_toucher'] }}</div><div style="font-size:11px;color:#991b1b">Matières sans aucune heure déclarée</div></div>
  </div>
  @if ($t['matieres']['sans_volume'] > 0)<div style="margin-top:10px;font-size:11px;color:var(--text-muted);text-align:center">+ {{ $t['matieres']['sans_volume'] }} matière(s) sans volume horaire prévu — hors périmètre de ce calcul tant qu'il n'est pas fixé.</div>@endif
</div></div>
<div style="display:grid;grid-template-columns:repeat(auto-fit,minmax(260px,1fr));gap:14px;margin-bottom:14px">
  <div class="card" style="padding:14px"><div class="card-title" style="margin-bottom:8px">📋 Inscrits par université</div><div class="graphe" style="height:260px"><canvas id="cInscrits" aria-label="Inscrits par université"></canvas></div></div>
  <div class="card" style="padding:14px"><div class="card-title" style="margin-bottom:8px">🏆 Taux de passage (%)</div><div class="graphe" style="height:260px"><canvas id="cPassage" aria-label="Taux de passage par université"></canvas></div></div>
  <div class="card" style="padding:14px"><div class="card-title" style="margin-bottom:8px">⚧ Répartition H/F</div><div class="graphe" style="height:260px"><canvas id="cGenre" aria-label="Répartition hommes femmes par université"></canvas></div></div>
</div>
<div class="card">
  <div class="card-header"><span class="card-title">🏛️ Tableau national — {{ $annee }}</span></div>
  <div style="overflow-x:auto"><table class="table">
    <thead><tr><th>Université</th><th>Inscrits</th><th>Passage</th><th>Redoublants</th><th>Taux passage</th><th>Programme</th><th>PV publiés</th><th>Statut</th></tr></thead>
    <tbody>
    @foreach ($stats as $s)
    <tr>
      <td><div style="font-weight:700;color:{{ $couleur($s['uni']) }}">{{ $s['uni']->sigle }}</div><div style="font-size:10px;color:var(--text-muted)">{{ $s['uni']->ville }}</div></td>
      @if ($s['erreur'])
      <td colspan="6" style="color:#dc2626;font-size:11px">⚠️ Connexion impossible</td><td><span class="badge badge-red">Hors ligne</span></td>
      @else
      <td style="font-weight:700">{{ $fmt($s['inscrits']) }}</td><td style="color:#166534;font-weight:700">{{ $s['passages'] }}</td><td style="color:#f59e0b">{{ $s['redoublants'] }}</td>
      <td style="font-weight:700;color:{{ $couleurTaux($s['taux_passage']) }}">{{ $pctTaux($s['taux_passage']) }}</td>
      <td><span class="badge {{ $badgeProg($s['taux_programme']) }}">{{ $s['taux_programme'] }}%</span>
        <div style="font-size:9px;color:var(--text-muted);margin-top:3px;white-space:nowrap">✅{{ $s['matieres']['completes'] }} 🟡{{ $s['matieres']['en_cours'] }} 🔴{{ $s['matieres']['sans_toucher'] }}</div></td>
      <td>{{ $s['pv_publies'] }}@if ($s['pv_attente'] > 0)<span style="font-size:10px;color:#f59e0b"> ({{ $s['pv_attente'] }} att.)</span>@endif</td>
      <td><span class="badge badge-green">✅ En ligne</span></td>
      @endif
    </tr>
    @endforeach
    <tr style="background:#f0f4ff;font-weight:700"><td>TOTAL NATIONAL</td><td>{{ $fmt($t['inscrits']) }}</td><td>{{ $t['passages'] }}</td><td>{{ $t['redoublants'] }}</td><td>{{ $pctTaux($t['taux_passage']) }}</td><td>{{ $t['taux_programme'] }}%</td><td>{{ $fmt($t['pv_publies']) }}</td><td></td></tr>
    </tbody>
  </table></div>
</div>

@elseif ($onglet === 'evolution')
@foreach ($stats as $code => $s)
@continue($s['erreur'])
<div class="card mb-16">
  <div class="card-header" style="background:{{ $couleur($s['uni']) }}"><span class="card-title" style="color:#fff">📈 {{ $s['uni']->nom }}</span></div>
  <div class="card-body">
    @if (! $s['evolution'])
    <p style="color:var(--text-muted);font-size:13px">Aucune donnée historique disponible.</p>
    @else
    <div style="display:grid;grid-template-columns:repeat(auto-fit,minmax(260px,1fr));gap:16px;margin-bottom:12px">
      <div class="graphe" style="height:240px"><canvas id="ev_inscrits_{{ $code }}" data-evolution="{{ $code }}" data-serie="inscrits" aria-label="Évolution des inscrits — {{ $s['uni']->sigle }}"></canvas></div>
      <div class="graphe" style="height:240px"><canvas id="ev_taux_{{ $code }}" data-evolution="{{ $code }}" data-serie="taux" aria-label="Évolution du taux de passage — {{ $s['uni']->sigle }}"></canvas></div>
    </div>
    <div style="overflow-x:auto"><table class="table">
      <thead><tr><th>Année</th><th>Inscrits</th><th>Passages</th><th>Redoublants</th><th>Taux passage</th></tr></thead>
      <tbody>
      @foreach ($s['evolution'] as $e)
      <tr><td style="font-weight:700">{{ $e['annee'] }}</td><td>{{ $fmt($e['inscrits']) }}</td><td style="color:#166534;font-weight:700">{{ $e['passages'] }}</td><td style="color:#f59e0b">{{ $e['redoublants'] }}</td><td style="font-weight:700;color:{{ $couleurTaux($e['taux']) }}">{{ $pctTaux($e['taux']) }}</td></tr>
      @endforeach
      </tbody>
    </table></div>
    @endif
  </div>
</div>
@endforeach

@elseif ($onglet === 'comparaison')
@php $ok = array_filter($stats, fn ($s) => ! $s['erreur']); @endphp
<div class="card mb-16" style="padding:14px;max-width:760px"><div class="card-title" style="margin-bottom:8px">📋 Inscrits vs passage</div><div class="graphe" style="height:280px"><canvas id="cCompare" aria-label="Inscrits et passages par université"></canvas></div></div>
<div style="display:grid;grid-template-columns:repeat(auto-fit,minmax(220px,1fr));gap:14px;margin-bottom:16px">
  @foreach ($ok as $s)
  <div class="card">
    <div style="background:{{ $couleur($s['uni']) }};color:#fff;padding:10px 14px;font-weight:700;border-radius:8px 8px 0 0">🏛️ {{ $s['uni']->sigle }}</div>
    <div style="padding:10px 14px;font-size:12px">
      @foreach ([['Inscrits', $fmt($s['inscrits'])], ['Femmes', $fmt($s['femmes'])], ['Passage niveau sup.', $s['passages'].' ('.$pctTaux($s['taux_passage']).')'], ['Redoublants', $s['redoublants']],
                 ['PV publiés', $s['pv_publies']], ['Facultés', $s['nb_facultes']], ['Filières', $s['nb_filieres']]] as [$l, $v])
      <div style="display:flex;justify-content:space-between;padding:4px 0;border-bottom:1px solid var(--border)"><span style="color:var(--text-muted)">{{ $l }}</span><strong>{{ $v }}</strong></div>
      @endforeach
    </div>
  </div>
  @endforeach
</div>
@if ($comparaison)
<h3 style="font-size:15px;color:#1A3A5C;margin:20px 0 6px">📚 Comparaison par filière équivalente</h3>
<p style="font-size:11px;color:var(--text-muted);margin-bottom:10px">Filières de même intitulé présentes dans au moins 2 universités — inscrits, taux de passage et exécution du programme par établissement.</p>
<div class="card"><div style="overflow-x:auto"><table class="table">
  <thead><tr><th>Filière</th>@foreach ($ok as $s)<th style="text-align:center;color:{{ $couleur($s['uni']) }}">{{ $s['uni']->sigle }}</th>@endforeach</tr></thead>
  <tbody>
  @foreach ($comparaison as $filiere => $parUni)
  <tr>
    <td style="font-weight:600">{{ $filiere }}</td>
    @foreach ($ok as $code => $s)
    <td style="text-align:center">
      @if (isset($parUni[$code]))
      @php $c = $parUni[$code]; $dec = $c['passages'] + $c['redoublants']; $tx = $dec > 0 ? round($c['passages'] / $dec * 100, 1) : null; $tp = $c['heures_prevues'] > 0 ? round($c['heures_faites'] / $c['heures_prevues'] * 100, 1) : 0; @endphp
      <div>{{ $fmt($c['inscrits']) }} insc.</div><div style="font-weight:700;color:{{ $couleurTaux($tx) }}">{{ $tx }}% passage</div><div style="font-size:10px;font-weight:700;color:{{ $couleurProg($tp) }}">📘 {{ $tp }}%</div>
      @else<span style="color:#d1d5db">—</span>@endif
    </td>
    @endforeach
  </tr>
  @endforeach
  </tbody>
</table></div></div>
@endif

@elseif ($onglet === 'detail')
<input type="text" id="filtre-detail" class="form-control mb-16" placeholder="🔎 Rechercher une faculté ou une filière..." aria-label="Rechercher une faculté ou une filière">
@foreach ($stats as $code => $s)
<details class="card mb-16">
  <summary style="padding:12px 16px;cursor:pointer;display:flex;justify-content:space-between;align-items:center;flex-wrap:wrap;gap:8px">
    <span style="display:flex;align-items:center;gap:10px"><span class="badge" style="background:{{ $couleur($s['uni']) }};color:#fff">{{ $s['uni']->sigle }}</span>
      <span><strong>{{ $s['uni']->nom }}</strong><br><span style="font-size:11px;color:var(--text-muted)">{{ $s['uni']->ville }} · {{ $s['erreur'] ? 'Hors ligne' : $s['annee'] }}</span></span></span>
    @unless ($s['erreur'])
    <span style="display:flex;gap:6px;flex-wrap:wrap"><span class="badge badge-blue">👥 {{ $fmt($s['inscrits']) }} inscrits</span>
      <span class="badge {{ $s['taux_passage'] === null ? 'badge-gray' : ($s['taux_passage'] >= 50 ? 'badge-green' : 'badge-red') }}">🏆 {{ $pctTaux($s['taux_passage']) }}</span><span class="badge badge-blue">📄 {{ $s['pv_publies'] }} PV</span></span>
    @else<span class="badge badge-red">⚠️ Hors ligne</span>@endunless
  </summary>
  @unless ($s['erreur'])
  <div style="padding:0 14px 14px">
    @php $tot = $s['hommes'] + $s['femmes']; @endphp
    <div style="display:flex;gap:8px;margin-bottom:12px;flex-wrap:wrap;font-size:11px;text-align:center">
      <div style="flex:1;min-width:100px;background:#dbeafe;border-radius:8px;padding:8px"><strong style="font-size:15px;color:#1D4ED8">{{ $fmt($s['hommes']) }}</strong><br>Hommes ({{ $tot > 0 ? round($s['hommes'] / $tot * 100) : 0 }}%)</div>
      <div style="flex:1;min-width:100px;background:#fce7f3;border-radius:8px;padding:8px"><strong style="font-size:15px;color:#DB2777">{{ $fmt($s['femmes']) }}</strong><br>Femmes</div>
      <div style="flex:1;min-width:100px;background:#f5f3ff;border-radius:8px;padding:8px"><strong style="font-size:15px;color:#7C3AED">{{ $s['passages'] }}/{{ $s['passages'] + $s['redoublants'] }}</strong><br>Passage / Décisions</div>
    </div>
    @forelse ($s['facultes'] as $f)
    <details class="mb-16" style="border:1px solid var(--border);border-radius:8px" data-recherche="{{ mb_strtolower($f['faculte'].' '.implode(' ', array_column($f['filieres'], 'filiere'))) }}">
      <summary style="padding:10px 14px;cursor:pointer;display:flex;justify-content:space-between;flex-wrap:wrap;gap:8px">
        <span>🏫 {{ $f['faculte'] }} <span style="font-size:11px;color:var(--text-muted)">{{ $f['inscrits'] }} inscrits · {{ $f['passages'] }} passages</span></span>
        <span style="font-weight:700;color:{{ $couleurTaux($f['taux']) }}">{{ $pctTaux($f['taux']) }}</span>
      </summary>
      @if (! $f['filieres'])
      <p style="padding:10px 14px;color:var(--text-muted);font-size:12px">Aucune filière disponible.</p>
      @else
      <div style="overflow-x:auto"><table class="table" style="font-size:12px">
        <thead><tr><th>Filière</th><th>Niv.</th><th>Inscrits</th><th>Femmes</th><th>Passage</th><th>Redoub.</th><th>Taux passage</th></tr></thead>
        <tbody>
        @foreach ($f['filieres'] as $l)
        <tr><td style="font-weight:600">{{ $l['filiere'] }}</td><td><span class="badge badge-blue">{{ $l['niveau'] }}</span></td><td>{{ $l['inscrits'] }}</td><td style="color:#DB2777">{{ $l['femmes'] }}</td>
          <td style="color:#166534;font-weight:700">{{ $l['passages'] }}</td><td style="color:#f59e0b">{{ $l['redoublants'] }}</td><td style="font-weight:700;color:{{ $couleurTaux($l['taux']) }}">{{ $pctTaux($l['taux']) }}</td></tr>
        @endforeach
        </tbody>
      </table></div>
      @endif
    </details>
    @empty
    <p style="text-align:center;color:var(--text-muted);font-size:12px">Aucune donnée de passage disponible.</p>
    @endforelse
  </div>
  @endunless
</details>
@endforeach

@elseif ($onglet === 'pv')
@if ($detail)
@php
  $pv = $detail['pv']; $res = $detail['resultats']; $mats = $detail['matieres'];
  $nbAdmis = $res->where('valide', 1)->count(); $tauxAdmis = $res->count() > 0 ? round($nbAdmis / $res->count() * 100, 1) : 0;
  $nbEval = $mats->where('nb_notes', '>', 0)->count(); $tauxProg = $mats->count() > 0 ? round($nbEval / $mats->count() * 100, 1) : 0;
  $retour = route('ministere.accueil', ['onglet' => 'pv', 'annee' => $annee] + array_filter($filtres));
@endphp
<div style="margin-bottom:12px;display:flex;justify-content:space-between;align-items:center;flex-wrap:wrap;gap:8px">
  <a href="{{ $retour }}" class="btn btn-secondary btn-sm">← Retour à la liste des PV</a>
  <div style="display:flex;gap:8px">
    <a href="{{ route('ministere.pv.imprimer', ['code' => $detail['uni']->code, 'id' => $pv->id, 'type' => 'admis']) }}" target="_blank" rel="noopener" class="btn btn-primary btn-sm">📥 Liste des admis</a>
    <a href="{{ route('ministere.pv.imprimer', ['code' => $detail['uni']->code, 'id' => $pv->id]) }}" target="_blank" rel="noopener" class="btn btn-primary btn-sm">📥 PV complet</a>
  </div>
</div>
<div class="alert alert-info" style="font-size:12px">ℹ️ Consultation en lecture seule — aucune modification possible depuis le tableau de bord Ministère.</div>
<div class="card mb-16">
  <div class="card-header" style="background:{{ $couleur($detail['uni']) }}"><span class="card-title" style="color:#fff">📄 {{ $detail['uni']->sigle }} — {{ $pv->niveau_libelle }} {{ $pv->filiere }}</span></div>
  <div style="padding:14px 18px;display:grid;grid-template-columns:repeat(auto-fit,minmax(160px,1fr));gap:14px;font-size:13px">
    <div><div style="font-size:10px;color:var(--text-muted);text-transform:uppercase">Faculté</div><strong>{{ $pv->faculte ?: '—' }}</strong></div>
    <div><div style="font-size:10px;color:var(--text-muted);text-transform:uppercase">Niveau · Semestre</div><strong>{{ $pv->niveau }} · {{ $pv->sem_libelle }}</strong></div>
    <div><div style="font-size:10px;color:var(--text-muted);text-transform:uppercase">Année · Session</div><strong>{{ $pv->annee }} · {{ IN::SESSIONS[$pv->type_session] ?? $pv->type_session }}</strong></div>
    <div><div style="font-size:10px;color:var(--text-muted);text-transform:uppercase">Statut</div><span class="badge {{ $statuts[$pv->statut][1] }}">{{ $statuts[$pv->statut][0] }}</span>@if ($pv->verrouille) <span class="badge badge-blue">🔒 Verrouillé</span>@endif</div>
  </div>
</div>
<div style="display:grid;grid-template-columns:repeat(auto-fit,minmax(140px,1fr));gap:10px;margin-bottom:14px;text-align:center">
  <div style="background:#dbeafe;border-radius:8px;padding:10px"><div style="font-weight:700;font-size:18px;color:#1D4ED8">{{ $res->count() }}</div><div style="font-size:10px;color:var(--text-muted)">Inscrits</div></div>
  <div style="background:#dcfce7;border-radius:8px;padding:10px"><div style="font-weight:700;font-size:18px;color:#166534">{{ $nbAdmis }}</div><div style="font-size:10px;color:var(--text-muted)">Admis</div></div>
  <div style="background:{{ $tauxAdmis >= 50 ? '#dcfce7' : '#fee2e2' }};border-radius:8px;padding:10px"><div style="font-weight:700;font-size:18px;color:{{ $couleurTaux($tauxAdmis) }}">{{ $tauxAdmis }}%</div><div style="font-size:10px;color:var(--text-muted)">Taux de réussite</div></div>
  <div style="background:#f5f3ff;border-radius:8px;padding:10px"><div style="font-weight:700;font-size:18px;color:{{ $couleurProg($tauxProg) }}">{{ $tauxProg }}%</div><div style="font-size:10px;color:var(--text-muted)">Programme ({{ $nbEval }}/{{ $mats->count() }})</div></div>
</div>
@if ($mats->isNotEmpty())
<div class="card mb-16">
  <div class="card-header"><span class="card-title">📘 Matières du programme</span></div>
  <div style="overflow-x:auto"><table class="table">
    <thead><tr><th>Code</th><th>Matière</th><th>Crédits</th><th>Évaluée</th><th>Finalisée</th></tr></thead>
    <tbody>
    @foreach ($mats as $m)
    @php $evaluee = $m->nb_notes > 0; $anomalie = $m->finalisee && ! $evaluee; @endphp
    <tr @if ($anomalie) style="background:#fef2f2" @endif>
      <td><span class="badge badge-blue">{{ $m->code }}</span></td><td style="font-weight:600">{{ $m->libelle }}</td><td style="text-align:center">{{ $m->credits ?? '—' }}</td>
      <td style="text-align:center">{!! $evaluee ? '<span class="badge badge-green">✅ Évaluée</span>' : '<span class="badge badge-amber">⏳ Non évaluée</span>' !!}</td>
      <td style="text-align:center">@if ($m->finalisee)<span class="badge {{ $anomalie ? 'badge-red' : 'badge-blue' }}">{{ $anomalie ? '⚠️ Finalisée' : '🔒 Finalisée' }}</span>@else<span class="badge badge-amber">🔓 Non finalisée</span>@endif</td>
    </tr>
    @endforeach
    </tbody>
  </table></div>
</div>
@endif
<div class="card">
  <div class="card-header"><span class="card-title">👥 Résultats ({{ $res->count() }} étudiant(s))</span></div>
  <div style="overflow-x:auto">
    @if ($res->isEmpty())
    <p style="text-align:center;color:var(--text-muted);padding:24px;font-size:12px">Aucun résultat calculé pour ce PV pour le moment.</p>
    @else
    <table class="table">
      <thead><tr><th>N°</th><th>Nom</th><th>Prénoms</th><th>Crédits</th><th>Moyenne</th><th>Décision</th><th>Mention</th><th>Rang</th></tr></thead>
      <tbody>
      @foreach ($res as $r)
      <tr><td>{{ $r->numero }}</td><td style="font-weight:600">{{ $r->nom }}</td><td>{{ $r->prenoms }}</td><td style="text-align:center">{{ $r->credits_obtenus ?? '—' }}</td>
        <td style="text-align:center;font-weight:700">{{ $r->moyenne_generale !== null ? number_format($r->moyenne_generale, 2).'/20' : '—' }}</td>
        <td style="text-align:center;font-weight:700;color:{{ $r->valide ? '#166534' : '#dc2626' }}">{{ $r->decision ?? '—' }}</td><td style="text-align:center">{{ $r->mention ?? '—' }}</td><td style="text-align:center;font-weight:700;color:#1D4ED8">{{ $r->rang ?? '—' }}</td></tr>
      @endforeach
      </tbody>
    </table>
    @endif
  </div>
</div>
@else
<h3 style="font-size:15px;color:#1A3A5C;margin-bottom:8px">📄 Procès-verbaux — {{ $filtres['pv_annee'] }} ({{ count($pvs) }})</h3>
@if ($filtres['pv_annee'] !== $annee)
<div style="font-size:11px;color:#92400e;background:#fef3c7;border-radius:6px;padding:6px 10px;margin-bottom:10px;display:inline-block">⚠️ Vous consultez les PV de <strong>{{ $filtres['pv_annee'] }}</strong>, différente de l'année affichée sur les autres onglets ({{ $annee }}).</div>
@endif
<form method="get" action="{{ route('ministere.accueil') }}" style="display:flex;gap:8px;flex-wrap:wrap;align-items:center;margin-bottom:12px">
  <input type="hidden" name="onglet" value="pv"><input type="hidden" name="annee" value="{{ $annee }}">
  <select name="pv_annee" class="form-control" style="max-width:150px" data-autosubmit="1" aria-label="Année des PV">@foreach ($annees as $lib)<option value="{{ $lib }}" @selected($lib === $filtres['pv_annee'])>{{ $lib }}</option>@endforeach</select>
  <select name="pv_uni" class="form-control" style="max-width:200px" data-autosubmit="1" aria-label="Université"><option value="">Toutes les universités</option>@foreach ($universites as $code => $u)<option value="{{ $code }}" @selected($filtres['pv_uni'] === $code)>{{ $u->sigle }}</option>@endforeach</select>
  <select name="pv_statut" class="form-control" style="max-width:160px" data-autosubmit="1" aria-label="Statut"><option value="">Tous les statuts</option>@foreach ($statuts as $k => [$lib])<option value="{{ $k }}" @selected($filtres['pv_statut'] === $k)>{{ $lib }}</option>@endforeach</select>
  <select name="pv_session" class="form-control" style="max-width:180px" data-autosubmit="1" aria-label="Session"><option value="">Toutes les sessions</option>@foreach (['normale', 'rattrapage', 'reclamation'] as $k)<option value="{{ $k }}" @selected($filtres['pv_session'] === $k)>{{ IN::SESSIONS[$k] }}</option>@endforeach</select>
  @if ($filtres['pv_uni'] !== '' || $filtres['pv_statut'] !== '' || $filtres['pv_session'] !== '' || $filtres['pv_annee'] !== $annee)
  <a href="{{ route('ministere.accueil', ['onglet' => 'pv', 'annee' => $annee]) }}" style="font-size:11px;color:#dc2626">✕ Réinitialiser</a>
  @endif
</form>
@if (! $pvs)
<p style="text-align:center;color:var(--text-muted);padding:24px;font-size:12px">Aucun PV ne correspond à ces critères.</p>
@else
<div class="card"><div style="overflow-x:auto"><table class="table">
  <thead><tr><th>Filière</th><th>Niv.</th><th>Semestre</th><th>Session</th><th>Admis</th><th>Taux</th><th>Statut</th><th></th></tr></thead>
  <tbody>
  @php $uniCourante = null; @endphp
  @foreach ($pvs as $p)
  @if ($p->uni->code !== $uniCourante)
  @php $uniCourante = $p->uni->code; @endphp
  <tr><td colspan="8" style="background:{{ $couleur($p->uni) }};color:#fff;font-weight:700;font-size:12px;padding:6px 10px">🏛️ {{ $p->uni->sigle }}</td></tr>
  @endif
  <tr>
    <td style="font-weight:600">{{ $p->filiere }}</td><td><span class="badge badge-blue">{{ $p->niveau }}</span></td><td>{{ $p->sem_libelle }}</td>
    <td>{{ IN::SESSIONS[$p->type_session] ?? $p->type_session }}@if ($p->verrouille) 🔒@endif</td>
    <td style="text-align:center">{{ $p->nb_total > 0 ? $p->nb_admis.'/'.$p->nb_total : '—' }}</td>
    <td style="text-align:center;font-weight:700;color:{{ $p->nb_total > 0 ? $couleurTaux($p->taux) : '#6b7280' }}">{{ $p->nb_total > 0 ? $p->taux.'%' : '—' }}</td>
    <td><span class="badge {{ $statuts[$p->statut][1] }}">{{ $statuts[$p->statut][0] }}</span></td>
    <td><a href="{{ route('ministere.accueil', ['onglet' => 'pv', 'annee' => $annee, 'pv_code' => $p->uni->code, 'pv_id' => $p->id] + array_filter($filtres)) }}" class="btn btn-secondary btn-sm">👁️ Voir</a></td>
  </tr>
  @endforeach
  </tbody>
</table></div></div>
@endif
@endif

@elseif ($onglet === 'journal')
<div class="alert alert-info" style="font-size:12px">ℹ️ Trace qui, au sein du Ministère, a consulté le tableau de bord, un PV, ou exporté un document — visible uniquement par les comptes administrateurs. 200 dernières entrées.</div>
@if ($journal->isEmpty())
<p style="text-align:center;color:var(--text-muted);padding:24px;font-size:12px">Aucune entrée pour le moment.</p>
@else
<div class="card"><div style="overflow-x:auto"><table class="table">
  <thead><tr><th>Date</th><th>Utilisateur</th><th>Action</th><th>Cible</th><th>Détails</th><th>IP</th></tr></thead>
  <tbody>
  @foreach ($journal as $l)
  @php [$lib, $classe] = MC::ACTIONS[$l->action] ?? [$l->action, 'badge-blue']; @endphp
  <tr><td style="white-space:nowrap;font-size:11px;color:var(--text-muted)">{{ \Illuminate\Support\Carbon::parse($l->created_at)->format('d/m/Y H:i') }}</td>
    <td style="font-weight:600">{{ $l->nom_complet ?? $l->login }}</td><td><span class="badge {{ $classe }}">{{ $lib }}</span></td>
    <td style="font-size:11px">{{ $l->cible ?? '—' }}</td><td style="font-size:11px;color:var(--text-muted)">{{ $l->details ?? '—' }}</td><td style="font-size:10px;color:#9ca3af">{{ $l->ip ?? '—' }}</td></tr>
  @endforeach
  </tbody>
</table></div></div>
@endif
@endif

@isset($stats)
@php
  $ok = array_filter($stats, fn ($s) => ! $s['erreur']);
  $graphiques = [
    'sigles' => array_values(array_map(fn ($s) => $s['uni']->sigle, $ok)),
    'inscrits' => array_values(array_column($ok, 'inscrits')), 'passages' => array_values(array_column($ok, 'passages')),
    'taux' => array_values(array_column($ok, 'taux_passage')),
    'hommes' => array_values(array_column($ok, 'hommes')), 'femmes' => array_values(array_column($ok, 'femmes')),
    'evolution' => array_map(fn ($s) => ['annees' => array_column($s['evolution'], 'annee'), 'inscrits' => array_column($s['evolution'], 'inscrits'), 'taux' => array_column($s['evolution'], 'taux')], $ok),
  ];
@endphp
<script type="application/json" id="donnees-ministere">{!! json_encode($graphiques, JSON_HEX_TAG | JSON_HEX_AMP) !!}</script>
@endisset
@endsection

@push('scripts')
<script src="{{ asset('assets/js/chart.min.js') }}"></script>
<script src="{{ asset('assets/js/ministere.js') }}"></script>
@endpush
