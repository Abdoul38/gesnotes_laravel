{{-- Liste imprimable A4 — portage de liste_inscrits_pdf.php (page autonome) --}}
<!DOCTYPE html>
<html lang="fr">
<head>
<meta charset="UTF-8">
<title>Liste inscrits — {{ $annee }}</title>
<style>
* { -webkit-print-color-adjust: exact !important; print-color-adjust: exact !important; color-adjust: exact !important; }
* { margin:0; padding:0; box-sizing:border-box; }
body { font-family: Arial, sans-serif; font-size:11px; color:#2C2C2C; background:#fff; }
@page { size: A4 portrait; margin: 12mm 10mm 12mm 10mm; }
@media print { .no-print { display:none !important; } tr { page-break-inside: avoid; } }
@media screen {
    body { background:#e8e5df; }
    .page { background:#fff; width:210mm; min-height:297mm; margin:10mm auto; padding:12mm 10mm; box-shadow:0 2px 10px rgba(0,0,0,.3); }
}
.page-header { border-bottom:1.5px solid #B8860B; padding-bottom:6px; margin-bottom:8px; display:flex; justify-content:space-between; align-items:flex-start; }
.univ-name { font-size:12px; font-weight:bold; text-transform:uppercase; color:#0F2942; }
.univ-sub  { font-size:9px; color:#8B6F1F; font-style:italic; margin-top:2px; }
.doc-title { font-size:13px; font-weight:bold; text-align:center; text-transform:uppercase; background:#fff; color:#0F2942; border:1.5px solid #0F2942; padding:5px; margin:6px 0; letter-spacing:.5px; }
.doc-meta  { display:flex; justify-content:space-between; font-size:9px; color:#8a8a8a; margin-bottom:5px; font-style:italic; }
.doc-stats { display:flex; gap:20px; font-size:10px; background:#fff; padding:4px 8px; margin-bottom:8px; border:0.5px solid #d8cfb8; }
.doc-stats span strong { color:#0F2942; }
.group-header { background:#fff; color:#0F2942; font-weight:bold; font-size:10px; border-bottom:1.5px solid #B8860B; padding:3px 8px; margin:8px 0 0; display:flex; justify-content:space-between; }
table { width:100%; border-collapse:collapse; font-size:9px; }
thead th { background:#fff; color:#0F2942; font-weight:bold; padding:3px 5px; border:1px solid #0F2942; text-align:left; }
tbody td { padding:2px 5px; border:0.5px solid #d8cfb8; vertical-align:middle; }
.mat-col { font-family:monospace; font-size:8px; color:#0F2942; font-weight:bold; }
.sexe-m { background:#dbeafe; color:#1e40af; padding:0 4px; border-radius:2px; font-size:8px; font-weight:bold; }
.sexe-f { background:#fce7f3; color:#9d174d; padding:0 4px; border-radius:2px; font-size:8px; font-weight:bold; }
.niv { background:#f2ecdd; color:#8B6F1F; padding:0 5px; border-radius:2px; font-size:8px; font-weight:bold; }
.sous-total { text-align:right; font-size:9px; font-weight:bold; color:#0F2942; background:#fff; padding:2px 5px; border:0.5px solid #d8cfb8; }
.total-bar { background:#fff; color:#0F2942; font-size:10px; font-weight:bold; border:1.5px solid #0F2942; display:flex; justify-content:space-between; padding:4px 8px; margin-top:8px; }
.sig-zone { display:grid; grid-template-columns:1fr; gap:30px; margin-top:18px; }
.sig-box  { border-top:0.5px solid #B8860B; padding-top:3px; text-align:center; font-size:9px; color:#8B6F1F; font-style:italic; }
.footer   { border-top:0.5px solid #d8cfb8; margin-top:10px; padding-top:4px; display:flex; justify-content:space-between; font-size:8px; color:#8a8a8a; font-style:italic; }
.toolbar  { position:fixed; top:8px; right:8px; display:flex; gap:8px; z-index:999; }
.btn-print{ background:#0F2942; color:#fff; border:none; padding:7px 16px; border-radius:5px; cursor:pointer; font-size:12px; font-weight:bold; }
.btn-back { background:#eee; color:#333; border:none; padding:7px 12px; border-radius:5px; cursor:pointer; font-size:12px; text-decoration:none; }
</style>
</head>
<body>
@php($nbCols = 4 + count(array_filter($colonnes)))
<div class="toolbar no-print">
  <a href="{{ $retour }}" class="btn-back">← Retour</a>
  <button type="button" class="btn-print" data-print="1">🖨️ Imprimer / PDF</button>
</div>

<div class="page">
  <div class="page-header">
    <div style="display:flex;align-items:center;gap:10px">
      @if ($logos['gauche'])<img src="{{ $logos['gauche'] }}" alt="" style="height:75px;width:auto" data-onerror-hide="1">@endif
      <div>
        <div class="univ-name">{{ $institution }}</div>
        <div class="univ-sub">{{ $service }} — {{ $bp }}</div>
      </div>
    </div>
    <div style="display:flex;align-items:center;gap:10px">
      <div style="text-align:right;font-size:9px;color:#555">Édité le {{ date('d/m/Y à H:i') }}</div>
      @if ($logos['droite'] ?? null)<img src="{{ $logos['droite'] }}" alt="" style="height:75px;width:auto" data-onerror-hide="1">@endif
    </div>
  </div>

  <div class="doc-title">Liste des étudiants — {{ $c['pedagogique'] ? 'Inscription Pédagogique' : 'Inscription Scolarité' }}</div>
  <div class="doc-meta"><span><strong>Année :</strong> {{ $annee }}</span><span><strong>Critère :</strong> {{ $titre }}</span></div>
  <div class="doc-stats">
    <span>Total : <strong>{{ $total }}</strong></span><span>Hommes : <strong>{{ $hommes }}</strong></span>
    <span>Femmes : <strong>{{ $femmes }}</strong></span><span>Groupes : <strong>{{ count($groupes) }}</strong></span>
  </div>

  @foreach ($groupes as $groupe => $ets)
  @php($h = collect($ets)->where('sexe', 'M')->count())
  @php($f = collect($ets)->where('sexe', 'F')->count())
  <div class="group-header"><span>{{ $groupe }}</span><span>{{ count($ets) }} étudiant(s) — {{ $h }}H / {{ $f }}F</span></div>
  <table>
    <thead><tr>
      <th style="width:20px">#</th><th style="width:65px">Matricule</th><th>Nom & Prénoms</th><th style="width:28px;text-align:center">Sexe</th>
      @if ($colonnes['filiere'])<th>Filière</th>@endif
      @if ($colonnes['niveau'])<th style="width:40px;text-align:center">Niv.</th>@endif
      @if ($colonnes['semestres'])<th>Semestres</th>@endif
    </tr></thead>
    <tbody>
    @foreach ($ets as $et)
    <tr>
      <td style="text-align:center;color:#999">{{ $loop->iteration }}</td>
      <td class="mat-col">{{ $et->matricule ?? '—' }}</td>
      <td><strong>{{ $et->nom }}</strong> {{ $et->prenoms }}</td>
      <td style="text-align:center">@if ($et->sexe === 'M')<span class="sexe-m">M</span>@elseif ($et->sexe === 'F')<span class="sexe-f">F</span>@else—@endif</td>
      @if ($colonnes['filiere'])<td style="font-size:9px">{{ $et->filiere }}</td>@endif
      @if ($colonnes['niveau'])<td style="text-align:center"><span class="niv">{{ $et->niveau }}</span></td>@endif
      @if ($colonnes['semestres'])<td style="font-size:8px;color:#059669">{{ $et->semestres ?? '—' }}</td>@endif
    </tr>
    @endforeach
    <tr><td colspan="{{ $nbCols }}" class="sous-total">Sous-total : {{ count($ets) }} étudiant(s) — {{ $h }} Homme(s) — {{ $f }} Femme(s)</td></tr>
    </tbody>
  </table>
  @endforeach

  <div class="total-bar"><span>TOTAL GÉNÉRAL</span><span>{{ $total }} étudiant(s) — {{ $hommes }} Homme(s) — {{ $femmes }} Femme(s)</span></div>
  <div class="sig-zone"><div class="sig-box"><div>Le Chef de Service Scolarité</div><div style="height:25px"></div></div></div>
  <div class="footer"><span>{{ $institution }} — {{ $service }}</span><span>Document généré le {{ date('d/m/Y') }} — Confidentiel</span></div>
</div>
<script src="{{ asset('assets/js/impression.js') }}"></script>
</body>
</html>
