{{-- Portail ANAB — portage de pages/anab.php --}}
@extends('espaces.layout', ['titre' => 'Consultation des dossiers'])

@use('App\Http\Controllers\Espaces\AnabController', 'AC')

@php
  $semestres = ['S1', 'S2', 'S3', 'S4', 'S5', 'S6'];
  $note = fn ($v) => number_format((float) $v, 2);
  $retour = route('anab.accueil', array_filter($filtres));
@endphp

@push('head')
<style>
.anab-table th{background:#7C3AED;color:#fff;white-space:nowrap}
.anab-table td{white-space:nowrap}
.anab-ligne{cursor:pointer}
.anab-ligne:hover td{background:#f5f3ff}
@media print{.espace-nav,.no-print{display:none!important}body{background:#fff}}
</style>
@endpush

@section('contenu')
@if ($dossier)
@php $e = $dossier['etudiant']; $d = $dossier['derniere']; $m = $dossier['moy6'] ?? $dossier['moy4']; @endphp
<div class="no-print" style="display:flex;justify-content:space-between;align-items:center;margin-bottom:12px;flex-wrap:wrap;gap:8px">
  <a href="{{ $retour }}" class="btn btn-secondary btn-sm">← Retour à la liste</a>
  <button type="button" class="btn btn-primary btn-sm" data-print="1">🖨️ Imprimer le dossier</button>
</div>
<div class="card mb-16" style="background:#7C3AED;color:#fff;padding:18px;display:flex;gap:16px;align-items:center;flex-wrap:wrap">
  <div style="font-size:44px">👤</div>
  <div>
    <div style="font-size:20px;font-weight:800">{{ $e->prenoms }} {{ $e->nom }}</div>
    <div style="font-size:13px;opacity:.85">Matricule : <strong>{{ $e->numero ?: '—' }}</strong> · {{ $uni->nom }}</div>
    <div style="display:flex;gap:8px;flex-wrap:wrap;margin-top:6px;font-size:12px">
      @if ($d)<span>📚 {{ $d->filiere }}</span><span>{{ $d->niveau }}</span><span>📅 {{ $d->annee }}</span>@endif
      <span>{{ $e->sexe === 'F' ? '♀ Femme' : '♂ Homme' }}</span>
    </div>
  </div>
</div>
<div style="display:grid;grid-template-columns:repeat(auto-fit,minmax(280px,1fr));gap:14px;margin-bottom:16px">
  <div class="card"><div class="card-header"><span class="card-title">État civil</span></div><div class="card-body" style="font-size:13px">
    @foreach ([['Nom', $e->nom], ['Prénoms', $e->prenoms], ['Sexe', $e->sexe === 'F' ? 'Féminin' : 'Masculin'],
               ['Date naissance', $e->date_naissance ? \Illuminate\Support\Carbon::parse($e->date_naissance)->format('d/m/Y') : '—'],
               ['Lieu naissance', $e->lieu_naissance ?? '—'], ['Nationalité', $e->nationalite ?? '—']] as [$l, $v])
    <div style="display:flex;justify-content:space-between;padding:4px 0;border-bottom:1px solid var(--border)"><span style="color:var(--text-muted)">{{ $l }}</span><strong>{{ $v ?: '—' }}</strong></div>
    @endforeach
  </div></div>
  <div class="card"><div class="card-header"><span class="card-title">Scolarité</span></div><div class="card-body" style="font-size:13px">
    @foreach ([['Université', $uni->nom], ['Filière', $d->filiere ?? '—'], ['Niveau', $d->niveau ?? '—'], ['Année', $d->annee ?? '—'],
               ['Matricule', $e->numero ?: '—'], ['Statut', ucfirst((string) ($e->statut_etudiant ?? '—'))]] as [$l, $v])
    <div style="display:flex;justify-content:space-between;padding:4px 0;border-bottom:1px solid var(--border)"><span style="color:var(--text-muted)">{{ $l }}</span><strong>{{ $v }}</strong></div>
    @endforeach
  </div></div>
</div>

<h3 style="font-size:14px;color:#7C3AED;margin-bottom:8px">🎓 Résultats académiques — Synthèse par semestre</h3>
<div class="card mb-16"><div style="overflow-x:auto"><table class="table anab-table" style="font-size:11.5px">
  <thead><tr>@foreach ($semestres as $sc)<th style="text-align:center">{{ $sc }}</th>@endforeach<th style="text-align:center">Moy. 4 sem.</th><th style="text-align:center">Moy. 6 sem.</th><th style="text-align:center">Mention</th></tr></thead>
  <tbody><tr>
    @foreach ($semestres as $sc)
    <td style="text-align:center">@if (isset($dossier['sems'][$sc]))<strong style="color:{{ $dossier['sems'][$sc]['moyenne'] >= 10 ? '#166534' : '#dc2626' }}">{{ $note($dossier['sems'][$sc]['moyenne']) }}</strong><div style="font-size:9px;color:var(--text-muted)">{{ $dossier['sems'][$sc]['annee'] }}</div>@else<span style="color:#d1d5db">—</span>@endif</td>
    @endforeach
    <td style="text-align:center;font-weight:800;background:#fef3c7">{{ $dossier['moy4'] !== null ? $note($dossier['moy4']).'/20' : '—' }}</td>
    <td style="text-align:center;font-weight:800;background:#dbeafe">{{ $dossier['moy6'] !== null ? $note($dossier['moy6']).'/20' : '—' }}</td>
    <td style="text-align:center"><span class="badge {{ $m === null ? '' : ($m >= $seuils['bien'] ? 'badge-green' : ($m >= $seuils['assez_bien'] ? 'badge-blue' : 'badge-gray')) }}">{{ AC::mention($m, $seuils) }}</span></td>
  </tr></tbody>
</table></div></div>

<div style="display:grid;grid-template-columns:repeat(auto-fit,minmax(240px,1fr));gap:14px;margin-bottom:16px">
  @foreach ([['L2 (4 semestres)', $dossier['moy4'], 'Éligible bourse L2', 'Non éligible L2', '✅'], ['Licence (6 sem.)', $dossier['moy6'], 'Éligible bourse Licence', 'Non éligible Licence', '🎓']] as [$titre, $moy, $oui, $non, $ic])
  @php $eligible = $moy !== null && $moy >= 12; @endphp
  <div class="card" style="padding:16px;text-align:center;background:{{ $eligible ? '#dcfce7' : '#f9fafb' }};border-color:{{ $eligible ? '#16a34a' : 'var(--border)' }}">
    <div style="font-size:28px">{{ $eligible ? $ic : '⏳' }}</div>
    <div style="font-weight:700;font-size:13px;color:{{ $eligible ? '#166534' : '#6b7280' }}">{{ $eligible ? $oui : $non }}</div>
    <div style="font-size:11px;color:var(--text-muted);margin-top:3px">
      @if ($moy !== null){{ $titre }} — {{ $note($moy) }}/20 — {{ AC::mention($moy, $seuils) }}@unless ($eligible)<br><span style="color:#dc2626">Sous le seuil de 12/20</span>@endunless
      @else{{ $titre }} non disponible
      @endif
    </div>
  </div>
  @endforeach
</div>

<h3 style="font-size:14px;color:#7C3AED;margin-bottom:8px">📂 Détail par semestre</h3>
@forelse ($dossier['resultats'] as $anneeLib => $lignes)
<div style="font-weight:700;color:#7C3AED;margin:10px 0 6px">📅 {{ $anneeLib }}</div>
<div class="card mb-16"><div style="overflow-x:auto"><table class="table" style="font-size:11.5px">
  <thead><tr><th>Filière</th><th>Niv.</th><th>Sem.</th><th>Session</th><th style="text-align:center">Moyenne</th><th style="text-align:center">Crédits</th><th>Mention</th><th style="text-align:center">Statut</th></tr></thead>
  <tbody>
  @foreach ($lignes as $r)
  <tr>
    <td style="font-weight:600">{{ $r->filiere }}</td><td><span class="badge badge-blue">{{ $r->niveau }}</span></td><td style="font-weight:700;color:#7C3AED">{{ $r->sem_code }}</td>
    <td>{{ \App\Services\IndicateursNationaux::SESSIONS[$r->type_session] ?? $r->type_session }}</td>
    <td style="text-align:center">@if ($r->defaillant)<span style="color:#dc2626;font-weight:700">{{ $r->decision === 'Elim' ? 'Elim' : 'Défaillant' }}</span>@elseif ($r->moyenne_generale !== null)<strong style="color:{{ $r->valide ? '#166534' : '#dc2626' }}">{{ $note($r->moyenne_generale) }}</strong>/20 @else — @endif</td>
    <td style="text-align:center">{{ $r->defaillant ? '—' : $r->credits_obtenus.'/'.$r->credits_total }}</td>
    <td>{{ $r->mention ?? '—' }}</td>
    <td style="text-align:center">@if ($r->defaillant)<span class="badge badge-amber">{{ $r->decision === 'Elim' ? 'Elim' : 'Déf.' }}</span>@elseif ($r->valide)<span class="badge badge-green">✅ Validé</span>@else<span class="badge badge-red">❌ Non validé</span>@endif</td>
  </tr>
  @endforeach
  </tbody>
</table></div></div>
@empty
<p style="color:var(--text-muted);font-size:13px;margin-bottom:16px">Aucun résultat publié.</p>
@endforelse

@if ($dossier['passages']->isNotEmpty())
<h3 style="font-size:14px;color:#7C3AED;margin-bottom:8px">🏆 Décisions de passage de niveau</h3>
<div class="card"><div style="overflow-x:auto"><table class="table">
  <thead><tr><th>Année</th><th>Filière</th><th>Décision</th></tr></thead>
  <tbody>
  @foreach ($dossier['passages'] as $p)
  <tr><td style="font-weight:700">{{ $p->annee }}</td><td>{{ $p->filiere }}</td>
    <td>@if ($p->decision === 'passage')<span class="badge badge-green">✅ Passage</span>@elseif ($p->decision === 'redoublement')<span class="badge badge-amber">🔄 Redoublement</span>@elseif ($p->decision === 'licence_validee')<span class="badge badge-green">🎓 Licence validée</span>@else<span class="badge">{{ $p->decision }}</span>@endif</td></tr>
  @endforeach
  </tbody>
</table></div></div>
@endif

@else
<div class="card mb-16"><div class="card-body">
  <div style="font-size:16px;font-weight:800;color:#7C3AED">🎓 Consultation des dossiers étudiants</div>
  <div style="font-size:12px;color:var(--text-muted);margin-bottom:14px">Étudiants ayant validé au moins 4 semestres — filtrables par université, filière et année</div>
  <form method="get" action="{{ route('anab.accueil') }}" style="display:flex;gap:10px;flex-wrap:wrap;align-items:flex-end">
    <div class="form-group" style="margin:0;flex:1.5;min-width:180px"><label class="form-label" for="univ">Université</label>
      <select name="univ" id="univ" class="form-control" data-autosubmit="1"><option value="">— Sélectionner —</option>
        @foreach ($universites as $code => $u)<option value="{{ $code }}" @selected($code === $filtres['univ'])>{{ $u->sigle }} — {{ $u->nom }}</option>@endforeach</select></div>
    @if ($uni)
    <div class="form-group" style="margin:0;min-width:180px"><label class="form-label" for="filiere_id">Filière</label>
      <select name="filiere_id" id="filiere_id" class="form-control"><option value="0">Toutes les filières</option>@foreach ($filieres as $f)<option value="{{ $f->id }}" @selected($filtres['filiere_id'] == $f->id)>{{ $f->libelle }}</option>@endforeach</select></div>
    <div class="form-group" style="margin:0;min-width:150px"><label class="form-label" for="annee_id">Année académique</label>
      <select name="annee_id" id="annee_id" class="form-control"><option value="0">Toutes les années</option>@foreach ($annees as $a)<option value="{{ $a->id }}" @selected($filtres['annee_id'] == $a->id)>{{ $a->libelle }}</option>@endforeach</select></div>
    <div class="form-group" style="margin:0;min-width:130px"><label class="form-label" for="niveau_code">Niveau</label>
      <select name="niveau_code" id="niveau_code" class="form-control"><option value="">Tous les niveaux</option>@foreach ($niveaux as $n)<option value="{{ $n->code }}" @selected($filtres['niveau_code'] === $n->code)>{{ $n->libelle ?: $n->code }}</option>@endforeach</select></div>
    <div class="form-group" style="margin:0;min-width:160px"><label class="form-label" for="q">Nom / Matricule</label><input type="text" name="q" id="q" class="form-control" value="{{ $filtres['q'] }}" maxlength="100" placeholder="Rechercher..."></div>
    <button type="submit" class="btn btn-primary">🔍 Filtrer</button>
    @if ($filtres['filiere_id'] || $filtres['annee_id'] || $filtres['niveau_code'] !== '' || $filtres['q'] !== '')<a href="{{ route('anab.accueil', ['univ' => $filtres['univ']]) }}" class="btn btn-secondary">✕ Effacer</a>@endif
    @endif
  </form>
</div></div>

@if (! $uni)
<div style="display:grid;grid-template-columns:repeat(auto-fit,minmax(220px,1fr));gap:14px">
  @foreach ([['🏛️', "Sélectionner l'université", "Choisissez l'université dans le menu déroulant ci-dessus."], ['🎓', 'Filtrer les étudiants', 'Filtrez par filière, année ou recherchez par nom/matricule.'],
             ['📂', 'Consulter le dossier', "Cliquez sur un étudiant pour voir son dossier complet et l'éligibilité bourse."]] as [$ic, $t, $d])
  <div class="card" style="padding:20px;text-align:center"><div style="font-size:32px;margin-bottom:10px">{{ $ic }}</div><div style="font-weight:700;font-size:13px;color:#7C3AED;margin-bottom:6px">{{ $t }}</div><div style="font-size:12px;color:var(--text-muted)">{{ $d }}</div></div>
  @endforeach
</div>
@elseif ($erreur)
<div class="alert alert-error">⚠️ Les données de cette université sont momentanément indisponibles.</div>
@else
<div class="card">
  <div class="card-header"><span class="card-title" style="color:#7C3AED">🎓 {{ $uni->nom }} — Étudiants éligibles ({{ count($eligibles) }})</span></div>
  @if (! $eligibles)
  <div style="padding:32px;text-align:center;color:var(--text-muted)"><div style="font-size:40px;margin-bottom:10px">🎓</div><div style="font-weight:600">Aucun étudiant éligible</div><div style="font-size:12px;margin-top:4px">Modifiez les filtres ou vérifiez que des résultats sont publiés</div></div>
  @else
  <div style="overflow-x:auto"><table class="table anab-table" style="font-size:11.5px">
    <thead><tr><th>Étudiant</th><th>Matricule</th><th>Filière</th>@foreach ($semestres as $sc)<th style="text-align:center">{{ $sc }}</th>@endforeach
      <th style="text-align:center">Moy. 4 sem.</th><th style="text-align:center">Moy. 6 sem.</th><th style="text-align:center">Attestations</th></tr></thead>
    <tbody>
    @foreach ($eligibles as $r)
    @php $lien = route('anab.accueil', ['etudiant' => $r->id] + array_filter($filtres)); @endphp
    <tr class="anab-ligne" data-navigate-static="{{ $lien }}">
      <td style="font-weight:600"><a href="{{ $lien }}" style="color:inherit;text-decoration:none">{{ $r->nom }} {{ $r->prenoms }}</a></td><td>{{ $r->numero ?: '—' }}</td><td>{{ $r->filiere ?? '—' }}</td>
      @foreach ($semestres as $sc)
      <td style="text-align:center">@if (isset($r->sems[$sc]))<span style="color:{{ $r->sems[$sc]['moyenne'] >= 10 ? '#166534' : '#dc2626' }}">{{ $note($r->sems[$sc]['moyenne']) }}</span><div style="font-size:9px;color:#9ca3af">{{ $r->sems[$sc]['annee'] }}</div>@else<span style="color:#d1d5db">—</span>@endif</td>
      @endforeach
      <td style="text-align:center;font-weight:700;background:#fef3c7">{{ $r->moy4 !== null ? $note($r->moy4).'/20' : '—' }}@if ($r->annee_s4)<div style="font-size:9px;color:var(--text-muted)">{{ $r->annee_s4 }}</div>@endif</td>
      <td style="text-align:center;font-weight:700;background:#dbeafe">{{ $r->moy6 !== null ? $note($r->moy6).'/20' : '—' }}@if ($r->annee_s6)<div style="font-size:9px;color:var(--text-muted)">{{ $r->annee_s6 }}</div>@endif</td>
      <td style="text-align:center">
        @if ($r->moy4 !== null)<span class="badge badge-amber">📋 L2 · {{ AC::mention($r->moy4, $seuils) }}</span>@endif
        @if ($r->moy6 !== null)<span class="badge badge-green">🎓 Licence · {{ AC::mention($r->moy6, $seuils) }}</span>@endif
      </td>
    </tr>
    @endforeach
    </tbody>
  </table></div>
  @endif
</div>
@endif
@endif
@endsection
