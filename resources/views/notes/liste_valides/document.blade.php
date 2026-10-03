{{-- Liste des admis — portage de liste_valides.php (page autonome A4) --}}
<!DOCTYPE html>
<html lang="fr">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width,initial-scale=1">
<title>Liste des validés — {{ $pv->libelle }}</title>
<style>
*{box-sizing:border-box;margin:0;padding:0}
body{font-family:'Times New Roman',Times,serif;font-size:11pt;background:#e0e0e0;color:#000}
.page{
  width:210mm;
  background:#fff;
  margin:8mm auto;
  padding:12mm 15mm;
}
@media print{
  html,body{background:#fff;margin:0;padding:0}
  .no-print{display:none!important}
  .page{
    margin:0;
    width:210mm;
    padding:10mm 14mm;
  }
  @page{size:A4 portrait;margin:0}
}

.rep{font-weight:bold;font-size:11pt;text-transform:uppercase;margin-bottom:6px}
.inst-name{font-weight:bold;font-size:12pt;text-transform:uppercase;margin-bottom:3px}
.inst-sub{font-weight:bold;font-size:11pt;text-transform:uppercase;margin-bottom:3px}
.inst-dept{font-weight:bold;font-size:11pt;text-transform:uppercase;margin-bottom:3px}
.inst-bp{font-size:10pt;margin-bottom:12px}

.intro-text{font-size:11pt;line-height:1.7;margin:15px 0}

table.valides{width:100%;border-collapse:collapse;margin:15px 0;font-size:10pt}
table.valides th,table.valides td{border:1px solid #000;padding:5px 8px;vertical-align:middle}
table.valides th{font-weight:bold;text-align:center;background:#f0f0f0}
table.valides td.center{text-align:center}
table.valides tr:nth-child(even) td{background:#fafafa}

.nb-box{margin-top:20px;font-size:10pt;line-height:1.8}
.signature-area{display:flex;justify-content:space-between;margin-top:30px;font-size:10pt}
.sig-left{font-style:italic}
.sig-right{text-align:right;font-style:italic}
.sig-name{font-weight:bold;font-style:normal;margin-top:50px}
</style>
</head>
<body>

<div class="no-print" style="position:fixed;top:10px;right:10px;z-index:999">
  <button type="button" data-print="1" style="background:#185FA5;color:#fff;border:none;padding:8px 18px;border-radius:6px;cursor:pointer;font-size:13px;font-weight:600">🖨️ Imprimer</button>
  <a href="#" data-history-back="1" aria-label="Fermer" style="background:rgba(0,0,0,.1);color:#333;border:none;padding:8px 14px;border-radius:6px;cursor:pointer;font-size:13px;text-decoration:none;margin-left:6px">✕</a>
</div>
<div class="page">

<div style="display:flex;align-items:center;justify-content:space-between;margin-bottom:8px">
  <img src="{{ $logos['gauche'] }}" alt="" style="height:70px;width:auto" data-onerror-hide="1">
  <div style="flex:1;text-align:center;padding:0 10px">
    <div class="rep">{{ $republique }}</div>
    <div class="inst-name">{{ $institution }}</div>
    <div class="inst-sub">{{ $faculte }}</div>
    <div class="inst-dept">{{ $departement }}</div>
    <div class="inst-bp">{{ $bp }}</div>
  </div>
  <img src="{{ $logos['droite'] }}" alt="" style="height:70px;width:auto" data-onerror-hide="1">
</div>

<div style="border-bottom:1.5px solid #000;margin-bottom:12px"></div>

<div class="intro-text">
  Ont définitivement validé le {{ mb_strtolower($pv->sem_libelle) }}
  à l'issue de la {{ \App\Support\Format::session($pv->type_session) }}
  de l'année académique {{ $pv->annee }},
  les étudiants de {{ $pv->niveau_libelle }} {{ $pv->filiere }}
  dont les noms suivent :
</div>

@if ($valides->isEmpty())
<p style="text-align:center;padding:20px;font-style:italic">Aucun étudiant validé pour ce PV.</p>
@else
<table class="valides">
  <thead>
    <tr>
      <th style="width:6%">N°</th>
      <th style="width:28%;text-align:left">NOM</th>
      <th style="width:25%;text-align:left">PRÉNOMS</th>
      <th style="width:14%">DATE NAISS.</th>
      <th style="width:18%">LIEU NAISS.</th>
      <th style="width:9%">Mention</th>
    </tr>
  </thead>
  <tbody>
  @foreach ($valides as $v)
  <tr>
    <td class="center">{{ $loop->iteration }}</td>
    <td>{{ $v->nom }}</td>
    <td>{{ $v->prenoms }}</td>
    <td class="center">{{ $v->date_naissance ? date('d/m/y', strtotime($v->date_naissance)) : '—' }}</td>
    <td>{{ $v->lieu_naissance ?? '—' }}</td>
    <td class="center">{{ $v->mention ?? '—' }}</td>
  </tr>
  @endforeach
  </tbody>
</table>
@endif

<div class="nb-box">
  <strong>NB :</strong> Les réclamations doivent être adressées au Président du Jury et déposées
  dans un délai de <strong>72 heures</strong> après la délibération.
  <br>
  Toute demande d'indulgence du jury est irrecevable.
</div>

<div class="signature-area">
  <div class="sig-left">
    <div>Le Président du Jury</div>
    <div class="sig-name" style="margin-top:50px"></div>
  </div>
  <div class="sig-right">
    <div>Fait à {{ $ville }}, le {{ $pv->date_pv ? \App\Support\Format::dateFR($pv->date_pv) : '......................' }}</div>
  </div>
</div>

</div>
<script src="{{ asset('assets/js/impression.js') }}"></script>
</body>
</html>
