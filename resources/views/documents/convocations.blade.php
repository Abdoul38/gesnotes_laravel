{{-- Convocations aux examens — portage de convocations.php --}}
<!DOCTYPE html>
<html lang="fr">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width,initial-scale=1">
<title>Convocations — {{ $masse ? 'Tous' : $etudiants->first()->nom }}</title>
<style>
* { -webkit-print-color-adjust: exact !important; print-color-adjust: exact !important; color-adjust: exact !important; }

*{box-sizing:border-box;margin:0;padding:0}
html,body{background:#e8e5df;font-family:Georgia,'Times New Roman',Times,serif;color:#2C2C2C}
.conv{width:210mm;min-height:297mm;background:#fff;margin:8mm auto;padding:14mm 16mm;page-break-after:always;position:relative;box-shadow:0 4px 20px rgba(0,0,0,.15)}
.conv:last-child{page-break-after:auto}
.conv::before{content:'';position:absolute;inset:5mm;border:0.75pt solid #B8860B;pointer-events:none}
.conv::after{content:'';position:absolute;inset:6.5mm;border:0.5pt solid #0F2942;pointer-events:none}
.conv-inner{position:relative;z-index:1;padding:2mm 3mm}
@media print{html,body{background:#fff}.no-print{display:none!important}
  .conv{margin:0;padding:12mm 14mm;box-shadow:none}@page{size:A4 portrait;margin:0}}
.header{display:flex;align-items:center;justify-content:space-between;margin-bottom:5mm}
.header img{height:75px}.header-center{flex:1;text-align:center;font-size:9pt;font-style:italic;line-height:1.5;color:#555}
.header-center strong{font-size:10.5pt;font-style:normal;font-weight:bold;text-transform:uppercase;font-family:'Palatino Linotype','Book Antiqua',Georgia,serif;color:#0F2942}
.divider{border-top:0.75pt solid #B8860B;margin:3mm 0}
.titre-conv{text-align:center;margin:4mm 0}
.titre-conv h2{font-size:16pt;font-weight:bold;text-transform:uppercase;color:#0F2942;letter-spacing:1.5px;font-family:'Palatino Linotype','Book Antiqua',Georgia,serif}
.titre-conv p{font-size:9.5pt;font-style:italic;color:#8B6F1F;margin-top:1mm}
.num-conv{text-align:right;font-size:8pt;color:#8a8a8a;margin-bottom:2mm;font-style:italic}
.intro{font-size:10.5pt;line-height:1.7;margin:5mm 0}
.intro .nom{font-size:12.5pt;font-weight:bold;text-transform:uppercase;color:#0F2942;border-bottom:1pt solid #B8860B}
.intro .val{font-weight:bold;color:#0F2942}
table.mat{width:100%;border-collapse:collapse;font-size:9pt;margin:4mm 0}
table.mat th{background:#fff;color:#0F2942;padding:5px 8px;text-align:center;border:1pt solid #0F2942;text-transform:uppercase;font-size:8pt;letter-spacing:.2px}
table.mat th.left{text-align:left}
table.mat td{border:0.5pt solid #d8cfb8;padding:4px 8px}
table.mat td.center{text-align:center}
.regles{font-size:8.5pt;margin:4mm 0;padding:8px 12px;background:#fff;border-left:3px solid #B8860B;border-radius:2px}
.regles li{margin-left:16px;line-height:1.8}
.pied-conv{display:flex;justify-content:space-between;margin-top:8mm;font-size:9.5pt}
.pied-conv .gauche{font-style:italic;font-size:9pt;color:#555}
.pied-conv .droite{text-align:right}
.pied-conv .sig{margin-top:20mm;font-style:italic;color:#8B6F1F}
.pied-conv .nom-sig{font-weight:bold;margin-top:12mm;color:#0F2942}
.btn-bar{position:fixed;top:0;left:0;right:0;z-index:999;background:#0F2942;color:#fff;
  padding:8px 20px;display:flex;align-items:center;justify-content:space-between;font-size:13px}
.btn-bar button,.btn-bar a{background:#fff;color:#0F2942;border:none;padding:6px 14px;
  border-radius:5px;cursor:pointer;font-size:12px;font-weight:600;text-decoration:none}
.btn-bar a.sec{background:rgba(255,255,255,.15);color:#fff;border:1px solid rgba(255,255,255,.4)}
.spacer{height:44px}
</style>
</head>
<body>

<div class="btn-bar no-print">
  <span>📨 {{ $masse ? $etudiants->count().' convocation(s)' : 'Convocation individuelle' }}</span>
  <div style="display:flex;gap:8px">
    @if (! $masse)<a href="{{ route('convocations', ['pv_id' => $pv->id, 'masse' => 1]) }}" class="sec">📋 Toutes les convocations</a>@endif
    <button type="button" data-print="1">🖨️ Imprimer{{ $masse ? ' toutes ('.$etudiants->count().')' : '' }}</button>
    <a href="#" data-history-back="1" class="sec" aria-label="Fermer">✕</a>
  </div>
</div>
<div class="spacer no-print"></div>

@forelse ($etudiants as $et)
<div class="conv">
<div class="conv-inner">

  <div class="num-conv">N° {{ str_pad((string) $loop->iteration, 4, '0', STR_PAD_LEFT) }}/{{ date('Y') }}</div>

  <div class="header">
    <img src="{{ $logos['gauche'] }}" alt="" data-onerror-hide="1">
    <div class="header-center">
      <strong>{{ $republique }}</strong><br>
      {{ $institution }}<br>
      {{ $faculte }}<br>
      {{ $departement }}<br>
      {{ $bp }}
    </div>
    <img src="{{ $logos['droite'] }}" alt="" data-onerror-hide="1">
  </div>
  <div class="divider"></div>

  <div class="titre-conv">
    <h2>Convocation aux Examens</h2>
    <p>{{ $pv->niveau_libelle }} {{ $pv->filiere }} — {{ $pv->sem_libelle }} / {{ \App\Support\Format::session($pv->type_session) }} — A.A. {{ $pv->annee }}</p>
  </div>

  <div class="intro">
    <p>Le Doyen de la {{ $faculte }} convoque :</p>
    <p style="text-align:center;margin:4mm 0"><span class="nom">{{ $et->nom }} {{ $et->prenoms }}</span></p>
    <p>
      Né(e) le <span class="val">{{ \App\Support\Format::dateFR($et->date_naissance) }}</span>
      @if ($et->lieu_naissance) à <span class="val">{{ $et->lieu_naissance }}</span>@endif
      — Matricule : <span class="val">{{ $et->numero }}</span>
    </p>
    <p style="margin-top:3mm">À se présenter aux examens du <span class="val">{{ $pv->sem_libelle }}</span> selon le calendrier ci-dessous :</p>
  </div>

  <table class="mat">
    <thead>
      <tr>
        <th class="left" style="width:35%">Matière</th>
        <th style="width:14%">Date</th>
        <th style="width:10%">Début</th>
        <th style="width:10%">Fin</th>
        <th style="width:15%">Salle</th>
        <th class="left" style="width:16%">UE</th>
      </tr>
    </thead>
    <tbody>
    @foreach ($matieres as $m)
    <tr>
      <td>{{ $m->libelle }}</td>
      <td class="center">{{ $m->date_examen ? date('d/m/Y', strtotime($m->date_examen)) : '—' }}</td>
      <td class="center">{{ $m->heure_debut ? substr($m->heure_debut, 0, 5) : '—' }}</td>
      <td class="center">{{ $m->heure_fin ? substr($m->heure_fin, 0, 5) : '—' }}</td>
      <td class="center">{{ $m->salle ?? '—' }}</td>
      <td style="font-size:8pt;color:#555">{{ $m->ue }}</td>
    </tr>
    @endforeach
    </tbody>
  </table>

  <div class="regles">
    <strong>Consignes :</strong>
    <ul>
      <li>Se présenter 15 minutes avant le début de l'épreuve muni de cette convocation et d'une pièce d'identité.</li>
      <li>Tout retard de plus de 30 minutes entraîne l'exclusion de la salle.</li>
      <li>Les téléphones portables doivent être éteints et rangés.</li>
      <li>Tout acte de fraude entraîne l'annulation de l'épreuve et des poursuites disciplinaires.</li>
    </ul>
  </div>

  <div class="pied-conv">
    <div class="gauche"><p>Conservez cette convocation — Elle vous sera demandée à l'entrée de la salle.</p></div>
    <div class="droite">
      <div>Fait à {{ $ville }}, le {{ $pv->date_pv ? \App\Support\Format::dateFR($pv->date_pv) : date('d/m/Y') }}</div>
      <div class="sig">{{ $signataire['titre'] }}</div>
      <div class="nom-sig">{{ $signataire['nom'] }}</div>
    </div>
  </div>

</div>
</div>
@empty
<div class="conv"><div class="conv-inner" style="text-align:center;padding-top:60mm;font-style:italic;color:#888">Aucun étudiant à convoquer pour ce PV.</div></div>
@endforelse
<script src="{{ asset('assets/js/impression.js') }}"></script>
</body>
</html>
