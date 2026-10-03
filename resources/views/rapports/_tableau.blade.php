{{-- Tableau d'un rapport (écran et impression) --}}
@php $fcfa = fn ($v) => number_format((float) $v, 0, ',', ' '); @endphp
@if ($donnees->isEmpty())
<div style="text-align:center;padding:50px;color:#6b7280"><div style="font-size:48px;margin-bottom:12px">📭</div>Aucune donnée pour ces critères</div>
@elseif ($rapport === 'inscriptions')
<table class="table">
  <thead><tr><th>#</th><th>Matricule</th><th>Nom &amp; Prénoms</th><th>Sexe</th><th>Filière</th><th>Niveau</th><th>Statut</th><th style="text-align:right">Montant payé</th><th>Date</th></tr></thead>
  <tbody>
  @foreach ($donnees as $i => $r)
  <tr><td>{{ $i + 1 }}</td><td style="font-family:monospace">{{ $r->numero ?? '—' }}</td><td style="font-weight:600">{{ $r->prenoms }} {{ $r->nom }}</td><td>{{ $r->sexe }}</td>
    <td>{{ $r->filiere ?? '—' }}</td><td>{{ $r->niveau ?? '—' }}</td><td>{{ $r->statut }}</td><td style="text-align:right">{{ $fcfa($r->montant_paye) }} F</td>
    <td>{{ $r->date_inscription ? date('d/m/Y', strtotime($r->date_inscription)) : '—' }}</td></tr>
  @endforeach
  <tr style="background:#f0fdf4;font-weight:800"><td colspan="7">TOTAL</td><td style="text-align:right">{{ $fcfa($donnees->sum('montant_paye')) }} FCFA</td><td></td></tr>
  </tbody>
</table>
@elseif ($rapport === 'filieres')
<table class="table">
  <thead><tr><th>Filière</th><th>Faculté</th><th>Niveau</th><th style="text-align:center">Inscrits</th><th style="text-align:center">H</th><th style="text-align:center">F</th><th style="text-align:right">Total FCFA</th></tr></thead>
  <tbody>
  @foreach ($donnees as $r)
  <tr><td style="font-weight:600">{{ $r->filiere ?? '—' }}</td><td>{{ $r->faculte ?? '—' }}</td><td>{{ $r->niveau ?? '—' }}</td><td style="text-align:center;font-weight:700">{{ $r->nb_inscrits }}</td>
    <td style="text-align:center">{{ (int) $r->nb_hommes }}</td><td style="text-align:center">{{ (int) $r->nb_femmes }}</td><td style="text-align:right">{{ $fcfa($r->total_paye) }}</td></tr>
  @endforeach
  <tr style="background:#f0fdf4;font-weight:800"><td colspan="3">TOTAL</td><td style="text-align:center">{{ $donnees->sum('nb_inscrits') }}</td><td style="text-align:center">{{ $donnees->sum('nb_hommes') }}</td>
    <td style="text-align:center">{{ $donnees->sum('nb_femmes') }}</td><td style="text-align:right">{{ $fcfa($donnees->sum('total_paye')) }} FCFA</td></tr>
  </tbody>
</table>
@elseif ($rapport === 'comparatif')
@php $lignes = $donnees->values(); @endphp
<table class="table">
  <thead><tr><th>Année</th><th style="text-align:center">Total inscrits</th><th style="text-align:center">Hommes</th><th style="text-align:center">Femmes</th><th style="text-align:center">% Femmes</th><th style="text-align:center">Filières</th><th style="text-align:right">Total FCFA</th><th style="text-align:center">Évolution</th></tr></thead>
  <tbody>
  @foreach ($lignes as $i => $r)
  @php
    $nb = (int) $r->nb_inscrits;
    $suivante = $lignes[$i + 1] ?? null; // année précédente (tri décroissant)
    $evo = $suivante && $suivante->nb_inscrits > 0 ? round(($nb - $suivante->nb_inscrits) / $suivante->nb_inscrits * 100, 1) : null;
  @endphp
  <tr><td style="font-weight:700">{{ $r->annee }}</td><td style="text-align:center;font-weight:800">{{ number_format($nb) }}</td><td style="text-align:center">{{ number_format($r->nb_hommes) }}</td>
    <td style="text-align:center">{{ number_format($r->nb_femmes) }}</td><td style="text-align:center">{{ $nb > 0 ? round($r->nb_femmes / $nb * 100, 1) : 0 }}%</td>
    <td style="text-align:center">{{ $r->nb_filieres }}</td><td style="text-align:right">{{ $fcfa($r->total_paye) }}</td>
    <td style="text-align:center">{{ $evo === null ? '—' : ($evo >= 0 ? '▲' : '▼').abs($evo).'%' }}</td></tr>
  @endforeach
  </tbody>
</table>
@else
<table class="table">
  <thead><tr><th>Filière</th><th>Niveau</th><th style="text-align:center">Inscrits</th><th style="text-align:right">Total FCFA</th><th style="text-align:center">Complets</th><th style="text-align:center">Partiels</th><th>Mode</th></tr></thead>
  <tbody>
  @foreach ($donnees as $r)
  <tr><td style="font-weight:600">{{ $r->filiere ?? '—' }}</td><td>{{ $r->niveau ?? '—' }}</td><td style="text-align:center;font-weight:700">{{ $r->nb_inscrits }}</td><td style="text-align:right">{{ $fcfa($r->total_paye) }}</td>
    <td style="text-align:center">{{ $r->nb_complets }}</td><td style="text-align:center">{{ $r->nb_partiels }}</td><td>{{ \App\Services\Inscriptions::MODES[$r->mode_paiement] ?? $r->mode_paiement }}</td></tr>
  @endforeach
  <tr style="background:#f0fdf4;font-weight:800"><td colspan="2">TOTAL</td><td style="text-align:center">{{ $donnees->sum('nb_inscrits') }}</td><td style="text-align:right">{{ $fcfa($donnees->sum('total_paye')) }} FCFA</td><td colspan="3"></td></tr>
  </tbody>
</table>
@endif
