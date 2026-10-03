{{-- Rapport de synthèse du rectorat, imprimable (remplace l'export html2pdf de rectorat.php) --}}
<!DOCTYPE html>
<html lang="fr">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Rapport Rectorat — {{ $annee->libelle ?? '' }}</title>
<style>
* { box-sizing: border-box; margin: 0; padding: 0; -webkit-print-color-adjust: exact; print-color-adjust: exact; }
body { font-family: Arial, sans-serif; color: #111; background: #e5e7eb; }
.no-print { background: #1A3A5C; padding: 12px 20px; display: flex; gap: 12px; align-items: center; }
.no-print button { background: #fff; color: #1A3A5C; border: none; padding: 8px 18px; border-radius: 6px; font-weight: 700; cursor: pointer; }
.no-print a { color: #fff; font-size: 13px; text-decoration: none; }
.page { width: 210mm; min-height: 297mm; margin: 20px auto; background: #fff; padding: 18mm; }
.kpi { display: grid; grid-template-columns: repeat(4, 1fr); gap: 8px; margin: 18px 0; }
.kpi div { background: #f0f4ff; border-radius: 6px; padding: 8px; text-align: center; font-size: 10px; color: #6b7280; }
.kpi strong { display: block; font-size: 18px; color: #1A3A5C; }
table { width: 100%; border-collapse: collapse; font-size: 10px; }
th { background: #1A3A5C; color: #fff; border: 1px solid #999; padding: 5px; }
td { border: 1px solid #999; padding: 4px; text-align: center; }
td:first-child { text-align: left; }
@media print { body { background: #fff; } .no-print { display: none; } .page { margin: 0; box-shadow: none; } @page { size: A4 portrait; margin: 0; } }
</style>
</head>
<body>
<div class="no-print">
  <button type="button" data-print="1">🖨️ Imprimer / Enregistrer PDF</button>
  <a href="#" data-window-close="1">✕ Fermer</a>
</div>
<div class="page">
  <div style="text-align:center">
    <div style="font-weight:700;font-size:13px">{{ mb_strtoupper($universite) }}</div>
    <div style="font-size:11px;margin-top:2px">République du Niger</div>
    <div style="font-weight:700;font-size:16px;margin-top:14px;text-decoration:underline">RAPPORT — ANNÉE {{ $annee->libelle ?? '' }}</div>
  </div>
  <div class="kpi">
    <div><strong>{{ number_format($stats['inscrits'], 0, '', ' ') }}</strong>Inscrits</div>
    <div><strong>{{ $stats['taux_passage'] }}%</strong>Taux de passage</div>
    <div><strong>{{ $stats['taux_programme'] }}%</strong>Programme</div>
    <div><strong>{{ number_format($stats['pv_publies'], 0, '', ' ') }}</strong>PV publiés</div>
  </div>
  <table>
    <thead><tr><th>Faculté</th><th>Inscrits</th><th>Passages</th><th>Redoublants</th><th>Taux passage</th><th>Programme</th></tr></thead>
    <tbody>
    @foreach ($stats['facultes'] as $f)
    <tr><td>{{ $f['faculte'] }}</td><td>{{ number_format($f['inscrits'], 0, '', ' ') }}</td><td>{{ $f['passages'] }}</td><td>{{ $f['redoublants'] }}</td><td>{{ $f['taux'] }}%</td><td>{{ $f['taux_programme'] }}%</td></tr>
    @endforeach
    <tr style="font-weight:700;background:#f0f4ff"><td>TOTAL</td><td>{{ number_format($stats['inscrits'], 0, '', ' ') }}</td><td>{{ $stats['passages'] }}</td><td>{{ $stats['redoublants'] }}</td><td>{{ $stats['taux_passage'] }}%</td><td>{{ $stats['taux_programme'] }}%</td></tr>
    </tbody>
  </table>
  <div style="margin-top:24px;font-size:9px;color:#6b7280;text-align:center">Généré depuis le tableau de bord du rectorat — {{ now()->format('d/m/Y H:i') }}</div>
</div>
<script src="{{ asset('assets/js/impression.js') }}"></script>
</body>
</html>
