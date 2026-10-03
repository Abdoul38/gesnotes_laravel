{{-- Rapport imprimable — mode « print » de pages/admin/rapports.php --}}
<!DOCTYPE html>
<html lang="fr">
<head>
<meta charset="UTF-8">
<title>Rapport — {{ $anneeLib }}</title>
<style>
*{margin:0;padding:0;box-sizing:border-box}
body{font-family:Arial,sans-serif;font-size:11px;color:#1a1a1a;padding:10mm}
.no-print{margin-bottom:10px}
.no-print button{background:#1A3A5C;color:#fff;border:none;padding:8px 16px;border-radius:6px;cursor:pointer;font-weight:700}
.hdr{text-align:center;border-bottom:2px solid #1A3A5C;padding-bottom:10px;margin-bottom:14px}
.hdr h1{font-size:14px;font-weight:800;color:#1A3A5C;text-transform:uppercase}
.hdr p{font-size:10px;color:#555;margin-top:3px}
.stats{display:flex;gap:10px;margin-bottom:14px}
.sbox{border:1px solid #e5e7eb;border-radius:4px;padding:8px 14px;text-align:center;flex:1}
.sval{font-size:18px;font-weight:800;color:#1A3A5C}
.slbl{font-size:9px;color:#666}
table{width:100%;border-collapse:collapse;font-size:10px}
thead tr{background:#1A3A5C;color:#fff}
th{padding:6px 8px;text-align:left;font-weight:600}
td{padding:5px 8px;border-bottom:1px solid #e5e7eb}
tr:nth-child(even){background:#f9fafb}
@media print{.no-print{display:none}@page{margin:8mm;size:A4 landscape}}
</style>
</head>
<body>
<div class="no-print"><button type="button" data-print="1">🖨️ Imprimer / Enregistrer en PDF</button></div>
<div class="hdr">
  <h1>{{ $institution }} — Service Central de la Scolarité</h1>
  <p>Rapport {{ mb_strtoupper($rapport) }} — Année {{ $anneeLib }} — Généré le {{ now()->format('d/m/Y à H:i') }}</p>
</div>
<div class="stats">
  @foreach (['Inscrits' => $globaux->nb, 'Hommes' => $globaux->h, 'Femmes' => $globaux->f, 'Filières' => $globaux->nf, 'Total FCFA' => $globaux->tp] as $lbl => $val)
  <div class="sbox"><div class="sval">{{ number_format((float) $val, 0, ',', ' ') }}</div><div class="slbl">{{ $lbl }}</div></div>
  @endforeach
</div>
@include('rapports._tableau')
<script src="{{ asset('assets/js/impression.js') }}"></script>
</body>
</html>
