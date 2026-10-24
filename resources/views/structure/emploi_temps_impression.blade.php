{{-- Emploi du temps imprimable — mode « imprimer » de emploi_temps.php --}}
@use('App\Http\Controllers\Structure\EmploiTempsController', 'EDT')
<!DOCTYPE html>
<html lang="fr">
<head>
<meta charset="UTF-8">
<title>Emploi du temps — {{ $promo->filiere }}</title>
<style>
*{box-sizing:border-box;margin:0;padding:0}body{font-family:'Times New Roman',serif;font-size:9pt}
.no-print{padding:10px}.no-print button{background:#1A3A5C;color:#fff;border:none;padding:8px 16px;border-radius:6px;cursor:pointer;font-weight:700}
.page{width:297mm;margin:8mm auto;padding:10mm 12mm;background:#fff}
.header{display:flex;align-items:center;justify-content:space-between;margin-bottom:4mm}
.header img{height:55px}.header-center{flex:1;text-align:center;font-size:8.5pt}
.divider{border-top:2px solid #1A3A5C;margin:3mm 0}
.titre{text-align:center;font-size:12pt;font-weight:bold;text-transform:uppercase;color:#1A3A5C;margin:3mm 0}
.sous-titre{text-align:center;font-size:9pt;font-style:italic;margin-bottom:4mm}
table.edt{width:100%;border-collapse:collapse;font-size:7.5pt}
table.edt th{background:#1A3A5C;color:#fff;padding:4px 3px;text-align:center;border:0.5px solid #fff}
table.edt td{border:0.5px solid #ccc;padding:2px;vertical-align:top;width:13%}
table.edt td.heure{background:#f5f5f5;font-weight:bold;text-align:center;width:6%;color:#555}
.seance{border-radius:3px;padding:2px 3px;margin:1px;font-size:7pt;line-height:1.3;color:#fff}
@media print{.no-print{display:none}@page{size:A4 landscape;margin:0}.page{margin:0;padding:8mm 10mm}}
</style>
</head>
<body>
<div class="no-print"><button type="button" data-print="1">🖨️ Imprimer / Enregistrer en PDF</button></div>
<div class="page">
  <div class="header"><img src="{{ $logo }}" alt="" data-onerror-hide="1"><div class="header-center"><strong>REPUBLIQUE DU NIGER</strong><br>{{ $institution }}<br>{{ $promo->filiere }}</div><img src="{{ $logo }}" alt="" data-onerror-hide="1"></div>
  <div class="divider"></div>
  <div class="titre">Emploi du Temps</div>
  <div class="sous-titre">{{ $promo->niveau }} {{ $promo->filiere }} — {{ $semestre->libelle ?? '' }} — A.A. {{ $annee->libelle ?? '' }}</div>
  <table class="edt">
    <thead><tr><th>Horaires</th>@foreach (EDT::JOURS as $j)<th>{{ $j }}</th>@endforeach</tr></thead>
    <tbody>
    @for ($h = 0; $h < count(EDT::HEURES) - 1; $h++)
    @php [$debut, $fin] = [EDT::HEURES[$h], EDT::HEURES[$h + 1]]; @endphp
    <tr style="height:24px">
      <td class="heure">{{ $debut }}<br>{{ $fin }}</td>
      @foreach (EDT::JOURS as $j)
      <td>
        @foreach ($seances->where('jour', $j)->filter(fn ($s) => substr($s->heure_debut, 0, 5) < $fin && substr($s->heure_fin, 0, 5) > $debut) as $s)
        <div class="seance" style="background:{{ preg_match('/^#[0-9a-fA-F]{6}$/', (string) $s->couleur) ? $s->couleur : EDT::TYPES[$s->type_seance][1] }}">
          <strong>{{ $s->matiere }}</strong>@if ($s->ens_nom)<br>{{ $s->ens_nom }} {{ $s->ens_pren }}@endif @if ($s->salle)<br><em>📍 {{ $s->salle }}</em>@endif
          <br><span style="font-size:6pt;opacity:.8">{{ substr($s->heure_debut, 0, 5) }}-{{ substr($s->heure_fin, 0, 5) }}</span>
        </div>
        @endforeach
      </td>
      @endforeach
    </tr>
    @endfor
    </tbody>
  </table>
  <div style="margin-top:6mm;display:flex;gap:12px;font-size:8pt">@foreach (EDT::TYPES as [$lib, $col])<span style="background:{{ $col }};color:#fff;padding:2px 8px;border-radius:3px">{{ $lib }}</span>@endforeach</div>
</div>
<script src="{{ \App\Support\Ressource::url('assets/js/impression.js') }}"></script>
</body>
</html>
