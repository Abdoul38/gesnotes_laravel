{{-- Planning des examens imprimable — mode « imprimer » de planning.php --}}
<!DOCTYPE html>
<html lang="fr">
<head>
<meta charset="UTF-8">
<title>Planning — {{ $cible->libelle }}</title>
<style>
*{box-sizing:border-box;margin:0;padding:0}body{font-family:'Times New Roman',serif;font-size:9.5pt}
.no-print{padding:10px}.no-print button{background:#1A3A5C;color:#fff;border:none;padding:8px 16px;border-radius:6px;cursor:pointer;font-weight:700}
.page{width:210mm;margin:8mm auto;padding:12mm 15mm;background:#fff}
.header{display:flex;align-items:center;justify-content:space-between;margin-bottom:5mm}
.header img{height:55px}.header-center{flex:1;text-align:center;font-size:9pt}
.divider{border-top:2px solid #1A3A5C;margin:3mm 0}
.titre{text-align:center;font-size:12pt;font-weight:bold;text-transform:uppercase;margin:4mm 0;color:#1A3A5C}
table{width:100%;border-collapse:collapse;font-size:8.5pt;margin-top:4mm}
th{background:#1A3A5C;color:#fff;padding:4px 6px;text-align:center;border:0.5px solid #fff}
td{border:0.5px solid #aaa;padding:3px 5px;vertical-align:middle}
.left{text-align:left}
@media print{.no-print{display:none}@page{size:A4 portrait;margin:0}.page{margin:0;padding:10mm 14mm}}
</style>
</head>
<body>
<div class="no-print"><button type="button" data-print="1">🖨️ Imprimer / Enregistrer en PDF</button></div>
<div class="page">
  <div class="header">
    <img src="{{ $logos['gauche'] }}" alt="" data-onerror-hide="1">
    <div class="header-center"><strong>REPUBLIQUE DU NIGER</strong><br>{{ $institution }}<br>{{ $cible->faculte }}</div>
    <img src="{{ $logos['droite'] }}" alt="" data-onerror-hide="1">
  </div>
  <div class="divider"></div>
  <div class="titre">Planning des examens{{ $cible->session === 'rattrapage' ? ' — Rattrapage' : '' }}</div>
  <p style="text-align:center;font-size:9pt;font-style:italic;margin-bottom:4mm">{{ $cible->niveau_libelle }} {{ $cible->filiere }} — {{ $cible->sem_libelle }} — {{ $cible->annee }}</p>
  <table>
    <thead><tr><th class="left" style="width:28%">Matière</th><th style="width:12%">Date</th><th style="width:9%">Début</th><th style="width:9%">Fin</th><th style="width:12%">Salle</th><th class="left" style="width:15%">Surveillant 1</th><th class="left" style="width:15%">Surveillant 2</th></tr></thead>
    <tbody>
    @foreach ($ues as $ue)
    <tr><td colspan="7" style="background:#d0d0d0;font-weight:bold;font-size:8pt">{{ $ue->libelle }}</td></tr>
    @foreach ($ue->matieres as $m)
    @php $pl = $planning[$m->id] ?? null; @endphp
    <tr><td class="left">{{ $m->libelle }}</td>
      <td style="text-align:center">{{ $pl ? date('d/m/Y', strtotime($pl->date_examen)) : '—' }}</td><td style="text-align:center">{{ $pl ? substr($pl->heure_debut, 0, 5) : '—' }}</td>
      <td style="text-align:center">{{ $pl ? substr($pl->heure_fin, 0, 5) : '—' }}</td><td style="text-align:center">{{ $pl->salle ?? '—' }}</td>
      <td class="left">{{ $pl && $pl->surv1_nom ? $pl->surv1_nom.' '.$pl->surv1_pren : '—' }}</td><td class="left">{{ $pl && $pl->surv2_nom ? $pl->surv2_nom.' '.$pl->surv2_pren : '—' }}</td></tr>
    @endforeach
    @endforeach
    </tbody>
  </table>
  <div style="display:flex;justify-content:space-between;margin-top:10mm;font-size:9pt">
    <div style="text-align:center;flex:1"><div style="border-top:0.5px solid #000;padding-top:3px;font-style:italic;margin:15mm 20px 0">Le Président du Jury</div></div>
    <div style="text-align:center;flex:1"><div style="border-top:0.5px solid #000;padding-top:3px;font-style:italic;margin:15mm 20px 0">Le Doyen</div></div>
  </div>
</div>
<script src="{{ asset('assets/js/impression.js') }}"></script>
</body>
</html>
