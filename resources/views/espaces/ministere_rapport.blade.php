{{-- Rapport national imprimable (remplace l'export html2pdf de ministere.php) --}}
@php $pctTaux = fn ($t) => $t === null ? '—' : $t.'%'; @endphp
@php $t = $totaux; $fmt = fn ($n) => number_format((float) $n, 0, '', ' '); @endphp
<!DOCTYPE html>
<html lang="fr">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Rapport national — {{ $annee }}</title>
<style>
* { box-sizing: border-box; margin: 0; padding: 0; -webkit-print-color-adjust: exact; print-color-adjust: exact; }
body { font-family: Arial, sans-serif; color: #111; background: #e5e7eb; }
.no-print { background: #1A3A5C; padding: 12px 20px; display: flex; gap: 12px; align-items: center; }
.no-print button { background: #fff; color: #1A3A5C; border: none; padding: 8px 18px; border-radius: 6px; font-weight: 700; cursor: pointer; }
.no-print a { color: #fff; font-size: 13px; text-decoration: none; }
.page { width: 210mm; min-height: 297mm; margin: 20px auto; background: #fff; padding: 18mm; }
.kpi { display: grid; grid-template-columns: repeat(5, 1fr); gap: 8px; margin: 18px 0; }
.kpi div { background: #f0f4ff; border-radius: 6px; padding: 8px; text-align: center; font-size: 10px; color: #6b7280; }
.kpi strong { display: block; font-size: 18px; color: #1A3A5C; }
table { width: 100%; border-collapse: collapse; font-size: 10px; }
th { background: #1A3A5C; color: #fff; border: 1px solid #999; padding: 5px; }
td { border: 1px solid #999; padding: 4px; text-align: center; }
td:first-child { text-align: left; }
@media print { body { background: #fff; } .no-print { display: none; } .page { margin: 0; } @page { size: A4 portrait; margin: 0; } }
</style>
</head>
<body>
<div class="no-print">
  <button type="button" data-print="1">🖨️ Imprimer / Enregistrer PDF</button>
  <a href="#" data-window-close="1">✕ Fermer</a>
</div>
<div class="page">
  <div style="text-align:center">
    <div style="font-weight:700;font-size:13px">MINISTÈRE DE L'ENSEIGNEMENT SUPÉRIEUR, DE LA RECHERCHE ET DE L'INNOVATION</div>
    <div style="font-size:11px;margin-top:2px">République du Niger</div>
    <div style="font-weight:700;font-size:16px;margin-top:14px;text-decoration:underline">RAPPORT NATIONAL — ANNÉE {{ $annee }}</div>
  </div>
  <div class="kpi">
    <div><strong>{{ $t['universites'] }}</strong>Universités</div>
    <div><strong>{{ $fmt($t['inscrits']) }}</strong>Inscrits</div>
    <div><strong>{{ $pctTaux($t['taux_passage']) }}</strong>Taux de passage</div>
    <div><strong>{{ $t['taux_programme'] }}%</strong>Programme</div>
    <div><strong>{{ $fmt($t['pv_publies']) }}</strong>PV publiés</div>
  </div>
  <table>
    <thead><tr><th>Université</th><th>Inscrits</th><th>Passage</th><th>Redoublants</th><th>Taux passage</th><th>Programme</th><th>PV publiés</th></tr></thead>
    <tbody>
    @foreach ($stats as $s)
    <tr><td>{{ $s['uni']->nom }}</td>
      @if ($s['erreur'])<td colspan="6" style="color:#991b1b">Connexion impossible</td>
      @else<td>{{ $fmt($s['inscrits']) }}</td><td>{{ $s['passages'] }}</td><td>{{ $s['redoublants'] }}</td><td>{{ $pctTaux($s['taux_passage']) }}</td><td>{{ $s['taux_programme'] }}%</td><td>{{ $s['pv_publies'] }}</td>@endif
    </tr>
    @endforeach
    <tr style="font-weight:700;background:#f0f4ff"><td>TOTAL NATIONAL</td><td>{{ $fmt($t['inscrits']) }}</td><td>{{ $t['passages'] }}</td><td>{{ $t['redoublants'] }}</td><td>{{ $pctTaux($t['taux_passage']) }}</td><td>{{ $t['taux_programme'] }}%</td><td>{{ $fmt($t['pv_publies']) }}</td></tr>
    </tbody>
  </table>
  <div style="margin-top:24px;font-size:9px;color:#6b7280;text-align:center">Généré depuis le tableau de bord national MESR — {{ now()->format('d/m/Y H:i') }}</div>
</div>
<script src="{{ asset('assets/js/impression.js') }}"></script>
</body>
</html>
