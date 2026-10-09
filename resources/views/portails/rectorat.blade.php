{{-- Tableau de bord du rectorat — portage de pages/rectorat.php --}}
@extends('layouts.app', ['titre' => 'Tableau de bord Rectorat', 'fil' => 'Rectorat'])

@php
  $s = $stats;
  $fmt = fn ($n) => number_format((float) $n, 0, '', ' ');
  $couleurTaux = fn ($t) => $t === null ? '#6b7280' : ($t >= 50 ? '#166534' : '#dc2626');
  // Taux vide (aucune décision de passage encore) : « — » plutôt que 0 %
  $pctTaux = fn ($t) => $t === null ? '—' : $t.'%';
  $couleurProg = fn ($t) => $t >= 80 ? '#166534' : ($t >= 50 ? '#C8860A' : '#dc2626');
  $badgeProg = fn ($t) => $t >= 80 ? 'badge-green' : ($t >= 50 ? 'badge-amber' : 'badge-red');
  $lien = fn (array $p = []) => route('rectorat', $p + ['onglet' => $onglet, 'annee_id' => $annee?->id]);
  $graphiques = [
    'facultes' => array_column($s['facultes'], 'faculte'),
    'inscrits' => array_column($s['facultes'], 'inscrits'),
    'taux' => array_column($s['facultes'], 'taux'),
    'hommes' => array_column($s['facultes'], 'hommes'),
    'femmes' => array_column($s['facultes'], 'femmes'),
    'annees' => array_column($s['evolution'], 'annee'),
    'evInscrits' => array_column($s['evolution'], 'inscrits'),
    'evTaux' => array_column($s['evolution'], 'taux'),
  ];
@endphp

@section('contenu')
<div class="card mb-16">
  <div class="card-body" style="display:flex;gap:10px;align-items:center;flex-wrap:wrap">
    <label for="annee-rectorat" style="font-weight:700;font-size:13px;color:#1A3A5C">📅 Année</label>
    <select id="annee-rectorat" class="form-control" style="max-width:180px" data-navigate="{{ route('rectorat', ['onglet' => $onglet]) }}&annee_id=">
      @foreach ($annees as $a)<option value="{{ $a->id }}" @selected($a->id == $annee?->id)>{{ $a->libelle }}</option>@endforeach
    </select>
    <div style="margin-left:auto;display:flex;gap:8px;flex-wrap:wrap">
      <a href="{{ route('rectorat.excel', ['annee_id' => $annee?->id]) }}" class="btn btn-sm btn-primary" title="Synthèse, facultés, filières, évolution">📊 Excel détaillé</a>
      <a href="{{ route('rectorat.rapport', ['annee_id' => $annee?->id]) }}" class="btn btn-sm btn-secondary" target="_blank" rel="noopener">📥 Rapport PDF</a>
      <button type="button" class="btn btn-sm btn-secondary" data-toggle-display="zone-mdp">🔒 Mot de passe</button>
    </div>
  </div>
  <div id="zone-mdp" style="display:none;border-top:1px solid var(--border);padding:14px 16px">
    <form method="post" action="{{ route('rectorat.mot_de_passe') }}" style="display:grid;grid-template-columns:repeat(auto-fit,minmax(200px,1fr));gap:10px;align-items:end" autocomplete="off">
      @csrf
      <input type="hidden" name="onglet" value="{{ $onglet }}">
      <div class="form-group" style="margin:0"><label class="form-label" for="ancien_mdp">Mot de passe actuel</label><input type="password" name="ancien_mdp" id="ancien_mdp" class="form-control" required autocomplete="current-password"></div>
      <div class="form-group" style="margin:0"><label class="form-label" for="nouveau_mdp">Nouveau (8 caractères, lettres et chiffres)</label><input type="password" name="nouveau_mdp" id="nouveau_mdp" class="form-control" required minlength="8" autocomplete="new-password"></div>
      <div class="form-group" style="margin:0"><label class="form-label" for="confirmer_mdp">Confirmation</label><input type="password" name="confirmer_mdp" id="confirmer_mdp" class="form-control" required minlength="8" autocomplete="new-password"></div>
      <button type="submit" class="btn btn-primary">Valider</button>
    </form>
  </div>
</div>

<div class="tabs mb-16" role="tablist" style="display:flex;gap:4px;flex-wrap:wrap">
  @foreach (\App\Http\Controllers\Portails\RectoratController::ONGLETS as $cle => $libelle)
  <a href="{{ route('rectorat', ['onglet' => $cle, 'annee_id' => $annee?->id]) }}" class="btn btn-sm {{ $onglet === $cle ? 'btn-primary' : 'btn-secondary' }}" role="tab" @if ($onglet === $cle) aria-selected="true" @endif>{{ $libelle }}</a>
  @endforeach
</div>

@if ($onglet === 'accueil')
<div style="display:grid;grid-template-columns:repeat(auto-fit,minmax(160px,1fr));gap:12px;margin-bottom:16px">
  @foreach ([['🏫', 'Facultés', count($s['facultes']), '#1A3A5C'], ['📋', 'Inscrits '.($annee->libelle ?? ''), $fmt($s['inscrits']), '#166534'],
             ['🏆', 'Taux de passage', $pctTaux($s['taux_passage']), $couleurTaux($s['taux_passage'])],
             ['📘', 'Exécution du programme', $s['taux_programme'].'%', $couleurProg($s['taux_programme'])], ['📄', 'PV publiés', $fmt($s['pv_publies']), '#7C3AED']] as [$ic, $lb, $val, $c])
  <div class="card" style="padding:14px"><div style="font-size:20px">{{ $ic }}</div><div style="font-size:22px;font-weight:800;color:{{ $c }}">{{ $val }}</div><div style="font-size:10px;color:var(--text-muted);text-transform:uppercase">{{ $lb }}</div></div>
  @endforeach
</div>
<div style="display:grid;grid-template-columns:repeat(auto-fit,minmax(260px,1fr));gap:14px;margin-bottom:14px">
  <div class="card" style="padding:14px"><div class="card-title" style="margin-bottom:8px">📋 Inscrits par faculté</div><div class="graphe" style="height:260px"><canvas id="cInscrits" aria-label="Inscrits par faculté"></canvas></div></div>
  <div class="card" style="padding:14px"><div class="card-title" style="margin-bottom:8px">🏆 Taux de passage (%)</div><div class="graphe" style="height:260px"><canvas id="cPassage" aria-label="Taux de passage par faculté"></canvas></div></div>
  <div class="card" style="padding:14px"><div class="card-title" style="margin-bottom:8px">⚧ Répartition H/F</div><div class="graphe" style="height:260px"><canvas id="cGenre" aria-label="Répartition hommes femmes par faculté"></canvas></div></div>
</div>
@if ($s['facultes'])
<div class="card">
  <div class="card-header"><span class="card-title">🏫 Tableau par faculté — {{ $annee->libelle ?? '' }}</span></div>
  <div style="overflow-x:auto">
    <table class="table">
      <thead><tr><th>Faculté</th><th>Inscrits</th><th>Passage</th><th>Redoublants</th><th>Taux passage</th><th>Programme</th><th>PV publiés</th></tr></thead>
      <tbody>
      @foreach ($s['facultes'] as $f)
      <tr>
        <td style="font-weight:700">{{ $f['faculte'] }}</td><td style="font-weight:700">{{ $fmt($f['inscrits']) }}</td>
        <td style="color:#166534;font-weight:700">{{ $f['passages'] }}</td><td style="color:#f59e0b">{{ $f['redoublants'] }}</td>
        <td style="font-weight:700;color:{{ $couleurTaux($f['taux']) }}">{{ $pctTaux($f['taux']) }}</td>
        <td><span class="badge {{ $badgeProg($f['taux_programme']) }}">{{ $f['taux_programme'] }}%</span> <span style="font-size:10px;color:var(--text-muted)">({{ $f['heures_faites'] }}h/{{ $f['heures_prevues'] }}h)</span></td>
        <td style="text-align:center;font-weight:700">{{ $f['pv_publies'] }}</td>
      </tr>
      @endforeach
      <tr style="background:#f0f4ff;font-weight:700"><td>TOTAL ÉTABLISSEMENT</td><td>{{ $fmt($s['inscrits']) }}</td><td>{{ $s['passages'] }}</td><td>{{ $s['redoublants'] }}</td><td>{{ $pctTaux($s['taux_passage']) }}</td><td>{{ $s['taux_programme'] }}%</td><td style="text-align:center">{{ $s['pv_publies'] }}</td></tr>
      </tbody>
    </table>
  </div>
</div>
@endif
<script type="application/json" id="donnees-rectorat">@json($graphiques)</script>

@elseif ($onglet === 'evolution')
@if (! $s['evolution'])
<div class="card"><div class="empty-state" style="padding:24px"><p>Aucune donnée historique disponible.</p></div></div>
@else
<div class="card mb-16"><div style="padding:16px;display:grid;grid-template-columns:repeat(auto-fit,minmax(260px,1fr));gap:20px">
  <div class="graphe" style="height:240px"><canvas id="evChartIns" aria-label="Évolution des inscrits"></canvas></div><div class="graphe" style="height:240px"><canvas id="evChartTaux" aria-label="Évolution du taux de passage"></canvas></div>
</div></div>
<div class="card"><div style="overflow-x:auto"><table class="table">
  <thead><tr><th>Année</th><th style="text-align:center">Inscrits</th><th style="text-align:center">Passages</th><th style="text-align:center">Redoublants</th><th style="text-align:center">Taux de passage</th></tr></thead>
  <tbody>
  @foreach ($s['evolution'] as $e)
  <tr><td style="font-weight:700">{{ $e['annee'] }}</td><td style="text-align:center">{{ $fmt($e['inscrits']) }}</td><td style="text-align:center;color:#166534;font-weight:700">{{ $e['passages'] }}</td>
    <td style="text-align:center;color:#f59e0b">{{ $e['redoublants'] }}</td><td style="text-align:center;font-weight:700;color:{{ $couleurTaux($e['taux']) }}">{{ $pctTaux($e['taux']) }}</td></tr>
  @endforeach
  </tbody>
</table></div></div>
@endif
<script type="application/json" id="donnees-rectorat">@json($graphiques)</script>

@elseif ($onglet === 'detail')
<input type="text" id="filtre-detail" class="form-control mb-16" placeholder="🔎 Rechercher une faculté ou une filière..." aria-label="Rechercher une faculté ou une filière">
@forelse ($s['facultes'] as $f)
@php $totHF = $f['hommes'] + $f['femmes']; @endphp
<details class="card mb-16" data-recherche="{{ mb_strtolower($f['faculte'].' '.implode(' ', array_column($f['filieres'], 'filiere'))) }}">
  <summary style="padding:12px 16px;cursor:pointer;display:flex;justify-content:space-between;align-items:center;flex-wrap:wrap;gap:8px">
    <span><strong>🏫 {{ $f['faculte'] }}</strong> <span style="font-size:11px;color:var(--text-muted)">{{ count($f['filieres']) }} filière(s)</span></span>
    <span style="display:flex;gap:6px;flex-wrap:wrap">
      <span class="badge badge-blue">👥 {{ $fmt($f['inscrits']) }} inscrits</span>
      <span class="badge {{ $f['taux'] === null ? 'badge-gray' : ($f['taux'] >= 50 ? 'badge-green' : 'badge-red') }}">🏆 {{ $pctTaux($f['taux']) }}</span>
      <span class="badge badge-blue">📄 {{ $f['passages'] }} passages</span>
    </span>
  </summary>
  <div style="padding:0 14px 14px">
    <div style="display:flex;gap:8px;margin-bottom:12px;flex-wrap:wrap;font-size:11px;text-align:center">
      <div style="flex:1;min-width:100px;background:#dbeafe;border-radius:8px;padding:8px"><strong style="font-size:15px;color:#1D4ED8">{{ $fmt($f['hommes']) }}</strong><br>Hommes ({{ $totHF > 0 ? round($f['hommes'] / $totHF * 100) : 0 }}%)</div>
      <div style="flex:1;min-width:100px;background:#fce7f3;border-radius:8px;padding:8px"><strong style="font-size:15px;color:#DB2777">{{ $fmt($f['femmes']) }}</strong><br>Femmes</div>
      <div style="flex:1;min-width:100px;background:#f5f3ff;border-radius:8px;padding:8px"><strong style="font-size:15px;color:#7C3AED">{{ $f['passages'] }}/{{ $f['passages'] + $f['redoublants'] }}</strong><br>Passage / Décisions</div>
    </div>
    @if (! $f['filieres'])
    <p style="text-align:center;color:var(--text-muted);font-size:12px">Aucune filière disponible.</p>
    @else
    <table class="table" style="font-size:12px">
      <thead><tr><th>Filière</th><th>Niv.</th><th>Inscrits</th><th>Passage</th><th>Redoub.</th><th>Taux passage</th></tr></thead>
      <tbody>
      @foreach ($f['filieres'] as $l)
      <tr><td style="font-weight:600">{{ $l['filiere'] }}</td><td><span class="badge badge-blue">{{ $l['niveau'] }}</span></td><td>{{ $l['inscrits'] }}</td>
        <td style="color:#166534;font-weight:700">{{ $l['passages'] }}</td><td style="color:#f59e0b">{{ $l['redoublants'] }}</td>
        <td style="font-weight:700;color:{{ $couleurTaux($l['taux']) }}">{{ $pctTaux($l['taux']) }}</td></tr>
      @endforeach
      </tbody>
    </table>
    @endif
  </div>
</details>
@empty
<div class="card"><div class="empty-state" style="padding:24px"><p>Aucune donnée de passage disponible.</p></div></div>
@endforelse

@elseif ($onglet === 'pv')
@if ($detail)
@php
  $nbAdmis = $resultats->where('valide', 1)->count();
  $tauxAdmis = $resultats->count() > 0 ? round($nbAdmis / $resultats->count() * 100, 1) : 0;
  $nbCompletes = $matieresPv->where('categorie', 'complete')->count();
  $tauxProgPv = $matieresPv->count() > 0 ? round($nbCompletes / $matieresPv->count() * 100, 1) : 0;
  [$stLbl, $stCls] = \App\Http\Controllers\Portails\RectoratController::STATUTS[$detail->statut] ?? [$detail->statut, 'badge-blue'];
@endphp
<div style="margin-bottom:12px;display:flex;justify-content:space-between;flex-wrap:wrap;gap:8px">
  <a href="{{ route('rectorat', array_filter(['onglet' => 'pv', 'annee_id' => $annee?->id] + $filtres)) }}" class="btn btn-sm btn-secondary">← Retour à la liste des PV</a>
  <span style="display:flex;gap:8px">
    <a href="{{ route('rectorat.pv_impression', ['id' => $detail->id, 'type' => 'admis']) }}" class="btn btn-sm btn-primary" target="_blank" rel="noopener">📥 Liste des admis (PDF)</a>
    <a href="{{ route('rectorat.pv_impression', ['id' => $detail->id, 'type' => 'global']) }}" class="btn btn-sm btn-primary" target="_blank" rel="noopener">📥 PV complet (PDF)</a>
  </span>
</div>
<div class="alert alert-info" style="font-size:12px">ℹ️ Consultation en lecture seule — aucune modification possible depuis le tableau de bord Rectorat.</div>
<div class="card mb-16">
  <div class="card-header"><span class="card-title">📄 {{ $detail->niveau_libelle }} {{ $detail->filiere }}</span></div>
  <div class="card-body" style="display:grid;grid-template-columns:repeat(auto-fit,minmax(160px,1fr));gap:14px;font-size:13px">
    <div><div style="font-size:10px;color:var(--text-muted)">FACULTÉ</div><strong>{{ $detail->faculte ?: '—' }}</strong></div>
    <div><div style="font-size:10px;color:var(--text-muted)">NIVEAU · SEMESTRE</div><strong>{{ $detail->niveau }} · {{ $detail->sem_libelle }}</strong></div>
    <div><div style="font-size:10px;color:var(--text-muted)">ANNÉE · SESSION</div><strong>{{ $detail->annee }} · {{ \App\Http\Controllers\Portails\RectoratController::SESSIONS[$detail->type_session] ?? $detail->type_session }}</strong></div>
    <div><div style="font-size:10px;color:var(--text-muted)">STATUT</div><span class="badge {{ $stCls }}">{{ $stLbl }}</span> @if ($detail->verrouille)<span class="badge badge-blue">🔒 Verrouillé</span>@endif</div>
  </div>
</div>
<div style="display:grid;grid-template-columns:repeat(auto-fit,minmax(140px,1fr));gap:10px;margin-bottom:14px;text-align:center">
  <div class="card" style="padding:10px"><strong style="font-size:18px;color:#1D4ED8">{{ $resultats->count() }}</strong><div style="font-size:10px">Inscrits</div></div>
  <div class="card" style="padding:10px"><strong style="font-size:18px;color:#166534">{{ $nbAdmis }}</strong><div style="font-size:10px">Admis</div></div>
  <div class="card" style="padding:10px"><strong style="font-size:18px;color:{{ $couleurTaux($tauxAdmis) }}">{{ $tauxAdmis }}%</strong><div style="font-size:10px">Taux de réussite</div></div>
  <div class="card" style="padding:10px"><strong style="font-size:18px;color:{{ $couleurProg($tauxProgPv) }}">{{ $tauxProgPv }}%</strong><div style="font-size:10px">Programme ({{ $nbCompletes }}/{{ $matieresPv->count() }})</div></div>
</div>
@if ($matieresPv->isNotEmpty())
<div class="card mb-16">
  <div class="card-header"><span class="card-title">📘 Matières du programme</span></div>
  <div style="overflow-x:auto"><table class="table" style="font-size:12px">
    <thead><tr><th>Code</th><th>Matière</th><th>Crédits</th><th>Heure prévue</th><th>Heure faite</th><th>%</th><th>Statut</th></tr></thead>
    <tbody>
    @foreach ($matieresPv as $m)
    @php [$catLbl, $catCls] = \App\Http\Controllers\Portails\RectoratController::CATEGORIES[$m->categorie]; @endphp
    <tr><td><span class="badge badge-blue">{{ $m->code }}</span></td><td style="font-weight:600">{{ $m->libelle }}</td><td style="text-align:center">{{ $m->credits ?? '—' }}</td>
      <td style="text-align:center">{{ $m->volume_horaire_prevu !== null ? (float) $m->volume_horaire_prevu.'h' : '—' }}</td><td style="text-align:center">{{ (float) $m->heures_faites }}h</td>
      <td style="text-align:center;font-weight:700">{{ $m->pct }}%</td><td style="text-align:center"><span class="badge {{ $catCls }}">{{ $catLbl }}</span></td></tr>
    @endforeach
    </tbody>
  </table></div>
</div>
@endif
<div class="card">
  <div class="card-header"><span class="card-title">👥 Résultats ({{ $resultats->count() }} étudiant(s))</span></div>
  @if ($resultats->isEmpty())
  <div class="empty-state" style="padding:24px"><p>Aucun résultat calculé pour ce PV pour le moment.</p></div>
  @else
  <div style="overflow-x:auto"><table class="table" style="font-size:12px">
    <thead><tr><th>N°</th><th>Nom</th><th>Prénoms</th><th>Crédits</th><th>Moyenne</th><th>Décision</th><th>Mention</th><th>Rang</th></tr></thead>
    <tbody>
    @foreach ($resultats as $r)
    <tr><td>{{ $r->numero }}</td><td style="font-weight:600">{{ $r->nom }}</td><td>{{ $r->prenoms }}</td><td style="text-align:center">{{ $r->credits_obtenus ?? '—' }}</td>
      <td style="text-align:center;font-weight:700">{{ $r->moyenne_generale !== null ? number_format($r->moyenne_generale, 2).'/20' : '—' }}</td>
      <td style="text-align:center;font-weight:700;color:{{ $r->valide ? '#166534' : '#dc2626' }}">{{ $r->decision ?? '—' }}</td>
      <td style="text-align:center">{{ $r->mention ?? '—' }}</td><td style="text-align:center;font-weight:700;color:#1D4ED8">{{ $r->rang ?? '—' }}</td></tr>
    @endforeach
    </tbody>
  </table></div>
  @endif
</div>
@else
<h3 style="font-size:14px;color:#1A3A5C;margin-bottom:10px">📄 Procès-verbaux — {{ $pvAnnee->libelle ?? '' }} ({{ $liste->count() }}/{{ $totalPv }})</h3>
@if ($filtres['pv_annee_id'])
<div class="alert alert-warning" style="font-size:12px">⚠️ Vous consultez les PV de <strong>{{ $pvAnnee->libelle }}</strong>, différente de l'année affichée sur les autres onglets ({{ $annee->libelle ?? '' }}).</div>
@endif
<form method="get" action="{{ route('rectorat') }}" style="display:flex;gap:8px;flex-wrap:wrap;align-items:center;margin-bottom:12px">
  <input type="hidden" name="onglet" value="pv"><input type="hidden" name="annee_id" value="{{ $annee?->id }}">
  <select name="pv_annee_id" class="form-control" style="max-width:160px" data-autosubmit="1" aria-label="Année des PV">
    @foreach ($annees as $a)<option value="{{ $a->id }}" @selected($a->id == $pvAnnee?->id)>{{ $a->libelle }}</option>@endforeach
  </select>
  <select name="pv_statut" class="form-control" style="max-width:160px" data-autosubmit="1" aria-label="Statut">
    <option value="">Tous les statuts</option><option value="finalise" @selected($filtres['pv_statut'] === 'finalise')>Finalisé</option><option value="publie" @selected($filtres['pv_statut'] === 'publie')>Publié</option>
  </select>
  <select name="pv_session" class="form-control" style="max-width:180px" data-autosubmit="1" aria-label="Session">
    <option value="">Toutes les sessions</option>
    @foreach (['normale', 'rattrapage', 'reclamation'] as $ses)<option value="{{ $ses }}" @selected($filtres['pv_session'] === $ses)>{{ \App\Http\Controllers\Portails\RectoratController::SESSIONS[$ses] }}</option>@endforeach
  </select>
  @if (array_filter($filtres))<a href="{{ route('rectorat', ['onglet' => 'pv', 'annee_id' => $annee?->id]) }}" style="font-size:11px;color:#dc2626">✕ Réinitialiser</a>@endif
</form>
@if ($liste->isEmpty())
<div class="card"><div class="empty-state" style="padding:24px"><p>Aucun PV ne correspond à ces critères.</p></div></div>
@else
<div class="card"><div style="overflow-x:auto"><table class="table" style="font-size:12px">
  <thead><tr><th>Filière</th><th>Niv.</th><th>Semestre</th><th>Session</th><th>Admis</th><th>Taux</th><th>Programme</th><th>Statut</th><th></th></tr></thead>
  <tbody>
  @php $facCourante = null; @endphp
  @foreach ($liste as $p)
  @php
    $tauxP = $p->nb_total > 0 ? round($p->nb_admis / $p->nb_total * 100, 1) : 0;
    $progP = $p->matieres_total > 0 ? round($p->matieres_evaluees / $p->matieres_total * 100, 1) : 0;
    [$stLbl, $stCls] = \App\Http\Controllers\Portails\RectoratController::STATUTS[$p->statut] ?? [$p->statut, 'badge-blue'];
  @endphp
  @if (($p->faculte ?: '—') !== $facCourante)
  @php $facCourante = $p->faculte ?: '—'; @endphp
  <tr><td colspan="9" style="background:#1A3A5C;color:#fff;font-weight:700">🏫 {{ $facCourante }}</td></tr>
  @endif
  <tr>
    <td style="font-weight:600">{{ $p->filiere }}</td><td><span class="badge badge-blue">{{ $p->niveau }}</span></td><td>{{ $p->sem_libelle }}</td>
    <td>{{ \App\Http\Controllers\Portails\RectoratController::SESSIONS[$p->type_session] ?? $p->type_session }}{{ $p->verrouille ? ' 🔒' : '' }}</td>
    <td style="text-align:center">{{ $p->nb_total > 0 ? $p->nb_admis.'/'.$p->nb_total : '—' }}</td>
    <td style="text-align:center;font-weight:700;color:{{ $p->nb_total > 0 ? $couleurTaux($tauxP) : 'var(--text-muted)' }}">{{ $p->nb_total > 0 ? $tauxP.'%' : '—' }}</td>
    <td style="text-align:center">@if ($p->matieres_total > 0)<span style="font-size:11px">{{ $p->matieres_evaluees }}/{{ $p->matieres_total }}</span> <span class="badge {{ $badgeProg($progP) }}">{{ $progP }}%</span>@else — @endif</td>
    <td><span class="badge {{ $stCls }}">{{ $stLbl }}</span></td>
    <td><a href="{{ route('rectorat', array_filter(['onglet' => 'pv', 'annee_id' => $annee?->id, 'pv_id' => $p->id] + $filtres)) }}" class="btn btn-sm btn-secondary">👁️ Voir</a></td>
  </tr>
  @endforeach
  </tbody>
</table></div></div>
@endif
@endif

@elseif ($onglet === 'etudiants')
@if ($etudiant)
<div style="margin-bottom:12px"><a href="{{ route('rectorat', array_filter(['onglet' => 'etudiants', 'annee_id' => $annee?->id, 'etu_q' => $etuQ])) }}" class="btn btn-sm btn-secondary">← Retour à la recherche</a></div>
<div class="card mb-16"><div class="card-body" style="display:flex;align-items:center;gap:16px;flex-wrap:wrap">
  <div style="font-size:30px">👤</div>
  <div style="flex:1">
    <div style="font-size:16px;font-weight:700;color:#1A3A5C">{{ $etudiant->nom }} {{ $etudiant->prenoms }}</div>
    <div style="font-size:12px;color:var(--text-muted)">Matricule : {{ $etudiant->numero }}@if ($etudiant->date_naissance) · Né(e) le {{ \Illuminate\Support\Carbon::parse($etudiant->date_naissance)->format('d/m/Y') }}@if ($etudiant->lieu_naissance) à {{ $etudiant->lieu_naissance }}@endif @endif</div>
  </div>
  <div style="font-size:12px;color:var(--text-muted)">{{ $nbSemestres }} semestre(s) au total</div>
</div></div>
@forelse ($parcours as $bloc)
<div class="card mb-16">
  <div class="card-header" style="background:#1A3A5C;color:#fff;flex-wrap:wrap;gap:10px">
    <span class="card-title" style="color:#fff">📅 Année académique {{ $bloc['annee'] }}</span>
    @if ($bloc['decision'])
    <span style="font-size:11px;font-weight:700;background:rgba(255,255,255,.18);padding:2px 10px;border-radius:10px">Décision de passage : {{ ['licence_validee' => '🎓 Licence validée', 'passage' => 'Passage', 'redoublement' => 'Redoublement'][$bloc['decision']] ?? $bloc['decision'] }}</span>
    @endif
  </div>
  <div style="overflow-x:auto"><table class="table" style="font-size:12px">
    <thead><tr><th>Semestre</th><th>Filière / Niveau</th><th>Session</th><th style="text-align:center">Moy./20</th><th style="text-align:center">Crédits</th><th style="text-align:center">Mention</th><th style="text-align:center">Décision</th></tr></thead>
    <tbody>
    @foreach ($bloc['semestres'] as $sm)
    <tr>
      <td style="font-weight:600">{{ $sm->sem_label }}</td>
      <td><div style="font-weight:600">{{ $sm->filiere }}</div><div style="font-size:11px;color:var(--text-muted)">{{ $sm->niv_label ?? $sm->niveau }}</div></td>
      <td><span class="badge badge-blue">{{ \App\Services\Pv::SESSIONS[$sm->type_session] ?? $sm->type_session }}</span></td>
      <td style="text-align:center;font-weight:700;color:{{ ($sm->moyenne_generale ?? 0) >= 10 ? '#166534' : '#d97706' }}">{{ $sm->moyenne_generale !== null ? number_format($sm->moyenne_generale, 2) : '—' }}</td>
      <td style="text-align:center"><strong style="color:{{ (int) $sm->credits_obtenus >= (int) $sm->credits_total ? '#166534' : '#d97706' }}">{{ (int) $sm->credits_obtenus }}</strong>/{{ (int) $sm->credits_total }}</td>
      <td style="text-align:center">{{ $sm->mention ?? '—' }}</td>
      <td style="text-align:center;font-weight:700;color:{{ $sm->decision === 'Admis' ? '#166534' : ($sm->decision === 'Défaillant' ? '#dc2626' : '#d97706') }}">{{ $sm->decision ?? '—' }}</td>
    </tr>
    @endforeach
    @foreach ($bloc['sans_resultat'] as $ins)
    <tr><td>—</td><td><div style="font-weight:600">{{ $ins->filiere }}</div><div style="font-size:11px;color:var(--text-muted)">{{ $ins->niv_label ?? $ins->niveau }}</div></td>
      <td colspan="5" style="color:#9ca3af;font-size:11px">Inscription enregistrée — résultats non disponibles @if ($ins->statut_inscription && $ins->statut_inscription !== 'inscrit')<span style="color:#9a3412;font-weight:600"> · Statut : {{ $ins->statut_inscription }}</span>@endif</td></tr>
    @endforeach
    </tbody>
  </table></div>
</div>
@empty
<div class="card"><div class="empty-state" style="padding:40px"><p>Aucune inscription enregistrée pour cet étudiant.</p></div></div>
@endforelse
@else
<form method="get" action="{{ route('rectorat') }}" style="display:flex;gap:8px;margin-bottom:14px">
  <input type="hidden" name="onglet" value="etudiants"><input type="hidden" name="annee_id" value="{{ $annee?->id }}">
  <input type="text" name="etu_q" value="{{ $etuQ }}" class="form-control" placeholder="Nom, prénom ou numéro..." aria-label="Rechercher un étudiant">
  <button type="submit" class="btn btn-primary">🔎 Rechercher</button>
</form>
@if ($etuQ !== '' && mb_strlen($etuQ) < 2)
<p style="text-align:center;color:var(--text-muted);font-size:12px">Entrez au moins 2 caractères.</p>
@elseif ($etuQ !== '' && $trouves->isEmpty())
<p style="text-align:center;color:var(--text-muted);font-size:12px">Aucun étudiant trouvé pour « {{ $etuQ }} ».</p>
@elseif ($trouves->isNotEmpty())
<div class="card"><div style="overflow-x:auto"><table class="table" style="font-size:12px">
  <thead><tr><th>N°</th><th>Nom</th><th>Prénoms</th><th></th></tr></thead>
  <tbody>
  @foreach ($trouves as $t)
  <tr><td>{{ $t->numero }}</td><td style="font-weight:600">{{ $t->nom }}</td><td>{{ $t->prenoms }}</td>
    <td><a href="{{ route('rectorat', ['onglet' => 'etudiants', 'annee_id' => $annee?->id, 'etu_q' => $etuQ, 'etu_id' => $t->id]) }}" class="btn btn-sm btn-secondary">👁️ Voir le parcours</a></td></tr>
  @endforeach
  </tbody>
</table></div></div>
@else
<p style="text-align:center;color:var(--text-muted);font-size:12px">Recherchez un étudiant par nom, prénom ou numéro.</p>
@endif
@endif

@elseif ($onglet === 'programme')
<div style="display:grid;grid-template-columns:repeat(auto-fit,minmax(140px,1fr));gap:10px;margin-bottom:16px;text-align:center">
  <div style="background:#dbeafe;border-radius:8px;padding:12px"><strong style="font-size:20px;color:#1D4ED8">{{ $s['heures_prevues'] }}h</strong><div style="font-size:10px">Heures prévues</div></div>
  <div style="background:#dcfce7;border-radius:8px;padding:12px"><strong style="font-size:20px;color:#166534">{{ $s['heures_faites'] }}h</strong><div style="font-size:10px">Heures faites</div></div>
  <div style="background:#fff7ed;border-radius:8px;padding:12px"><strong style="font-size:20px;color:#C8860A">{{ max(0, $s['heures_prevues'] - $s['heures_faites']) }}h</strong><div style="font-size:10px">Heures restantes</div></div>
  <div style="background:#f3f4f6;border-radius:8px;padding:12px"><strong style="font-size:20px;color:{{ $couleurProg($s['taux_programme']) }}">{{ $s['taux_programme'] }}%</strong><div style="font-size:10px">Taux d'exécution</div></div>
</div>
<div style="display:grid;grid-template-columns:repeat(auto-fit,minmax(140px,1fr));gap:10px;margin-bottom:16px;text-align:center">
  <div style="background:#dcfce7;border-radius:8px;padding:12px;color:#166534"><strong style="font-size:18px">✅ {{ $s['matieres']['completes'] }}</strong><div style="font-size:10px">Matières complètes</div></div>
  <div style="background:#fef9c3;border-radius:8px;padding:12px;color:#92400e"><strong style="font-size:18px">🟡 {{ $s['matieres']['en_cours'] }}</strong><div style="font-size:10px">Matières en cours</div></div>
  <div style="background:#fee2e2;border-radius:8px;padding:12px;color:#991b1b"><strong style="font-size:18px">🔴 {{ $s['matieres']['sans_toucher'] }}</strong><div style="font-size:10px">Sans aucune heure</div></div>
  @if ($s['matieres']['sans_volume'] > 0)<div style="background:#f3f4f6;border-radius:8px;padding:12px;color:#6b7280"><strong style="font-size:18px">{{ $s['matieres']['sans_volume'] }}</strong><div style="font-size:10px">Sans volume fixé (hors calcul)</div></div>@endif
</div>
<form method="get" action="{{ route('rectorat') }}" style="display:flex;gap:8px;margin-bottom:14px;flex-wrap:wrap;align-items:center">
  <input type="hidden" name="onglet" value="programme"><input type="hidden" name="annee_id" value="{{ $annee?->id }}">
  <select name="prog_fac" class="form-control" style="max-width:260px" data-autosubmit="1" aria-label="Faculté">
    <option value="">Toutes les facultés</option>
    @foreach ($s['facultes'] as $f)<option value="{{ $f['faculte'] }}" @selected($progFac === $f['faculte'])>{{ $f['faculte'] }}</option>@endforeach
  </select>
  <select name="prog_statut" class="form-control" style="max-width:220px" data-autosubmit="1" aria-label="Statut des matières">
    <option value="">Toutes les matières</option>
    @foreach (\App\Http\Controllers\Portails\RectoratController::CATEGORIES as $cle => [$lib])<option value="{{ $cle }}" @selected($progStatut === $cle)>{{ $lib }}</option>@endforeach
  </select>
  @if ($progFac !== '' || $progStatut !== '')<a href="{{ route('rectorat', ['onglet' => 'programme', 'annee_id' => $annee?->id]) }}" style="font-size:11px;color:#dc2626">✕ Réinitialiser</a>@endif
</form>
<h3 style="font-size:14px;color:#1A3A5C;margin-bottom:10px">📋 Matières du programme ({{ $matieresProgramme->count() }})</h3>
@if ($matieresProgramme->isEmpty())
<div class="card"><div class="empty-state" style="padding:24px"><p>Aucune matière ne correspond à ces critères.</p></div></div>
@else
<div class="card"><div style="overflow-x:auto"><table class="table" style="font-size:12px">
  <thead><tr><th>Faculté</th><th>Filière</th><th>Niv.</th><th>Semestre</th><th>Matière</th><th>Enseignant(s)</th><th>Heure prévue</th><th>Heure faite</th><th>%</th><th>Statut</th></tr></thead>
  <tbody>
  @foreach ($matieresProgramme as $m)
  @php [$catLbl, $catCls] = \App\Http\Controllers\Portails\RectoratController::CATEGORIES[$m->categorie]; @endphp
  <tr><td style="font-size:11px;color:var(--text-muted)">{{ $m->faculte ?: '—' }}</td><td style="font-weight:600">{{ $m->filiere }}</td><td><span class="badge badge-blue">{{ $m->niveau }}</span></td>
    <td>{{ $m->semestre }}</td><td>{{ $m->libelle }}</td><td>@if ($m->enseignants){{ $m->enseignants }}@else<span class="badge badge-red">Non affectée</span>@endif</td>
    <td style="text-align:center">{{ $m->volume_horaire_prevu !== null ? (float) $m->volume_horaire_prevu.'h' : '—' }}</td><td style="text-align:center">{{ (float) $m->heures_faites }}h</td>
    <td style="text-align:center;font-weight:700">{{ $m->pct }}%</td><td style="text-align:center"><span class="badge {{ $catCls }}">{{ $catLbl }}</span></td></tr>
  @endforeach
  </tbody>
</table></div></div>
@endif

@elseif ($onglet === 'historique')
@php
  $actions = ['consultation_dashboard' => ['🏠 Dashboard', 'badge-blue'], 'consultation_pv' => ['📄 Consultation PV', 'badge-amber'],
    'consultation_etudiant' => ['🎓 Consultation étudiant', 'badge-amber'], 'export_pdf_admis' => ['📥 Export admis', 'badge-green'],
    'export_pdf_global' => ['📥 Export PV complet', 'badge-green'], 'export_excel_facultes' => ['📥 Export Excel facultés', 'badge-green'],
    'export_excel_rapport' => ['📥 Export Excel détaillé', 'badge-green'], 'export_pdf_rapport_global' => ['📥 Export PDF rapport global', 'badge-green']];
@endphp
<h3 style="font-size:14px;color:#1A3A5C;margin-bottom:10px">🕵️ {{ auth()->user()->role === 'admin' ? 'Historique des consultations' : 'Historique de mes consultations' }} (200 dernières)</h3>
@if ($journal->isEmpty())
<div class="card"><div class="empty-state" style="padding:24px"><p>Aucune entrée pour le moment.</p></div></div>
@else
<div class="card"><div style="overflow-x:auto"><table class="table" style="font-size:12px">
  <thead><tr><th>Date</th>@if (auth()->user()->role === 'admin')<th>Utilisateur</th>@endif<th>Action</th><th>Cible</th><th>Détails</th><th>IP</th></tr></thead>
  <tbody>
  @foreach ($journal as $l)
  @php [$aLbl, $aCls] = $actions[$l->action] ?? [$l->action, 'badge-blue']; @endphp
  <tr><td style="white-space:nowrap;font-size:11px;color:var(--text-muted)">{{ \Illuminate\Support\Carbon::parse($l->created_at)->format('d/m/Y H:i') }}</td>
    @if (auth()->user()->role === 'admin')<td>{{ $l->nom_complet ?? '—' }}</td>@endif
    <td><span class="badge {{ $aCls }}">{{ $aLbl }}</span></td><td style="font-size:11px">{{ $l->cible ?? '—' }}</td>
    <td style="font-size:11px;color:var(--text-muted)">{{ $l->details ?? '—' }}</td><td style="font-size:10px;color:#9ca3af">{{ $l->ip ?? '—' }}</td></tr>
  @endforeach
  </tbody>
</table></div></div>
@endif
@endif
@endsection

@push('scripts')
@if (in_array($onglet, ['accueil', 'evolution'], true))<script src="{{ asset('assets/js/chart.min.js') }}"></script>@endif
<script src="{{ asset('assets/js/rectorat.js') }}"></script>
@endpush
