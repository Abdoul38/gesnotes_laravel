{{-- Reçu d'inscription (B5) — portage de recu_inscription.php --}}
<!DOCTYPE html>
<html lang="fr">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width,initial-scale=1">
<title>Reçu d'inscription — {{ $et->nom }}</title>
<style>
* { -webkit-print-color-adjust: exact !important; print-color-adjust: exact !important; color-adjust: exact !important; }

*{box-sizing:border-box;margin:0;padding:0}
body{font-family:Georgia,'Times New Roman',serif;font-size:10pt;background:#e8e5df;color:#2C2C2C}
.page{width:176mm;min-height:250mm;background:#fff;margin:8mm auto;padding:12mm 14mm;box-shadow:0 4px 20px rgba(0,0,0,.15);position:relative}
.page::before{content:'';position:absolute;inset:5mm;border:0.75pt solid #B8860B;pointer-events:none}
.page::after{content:'';position:absolute;inset:6.5mm;border:0.5pt solid #0F2942;pointer-events:none}
.page-inner{position:relative;z-index:1;padding:3mm 4mm}
@media print{body{background:#fff}.no-print{display:none!important}.page{width:176mm;min-height:250mm;margin:0;padding:10mm 13mm;box-shadow:none}@page{size:B5 portrait;margin:0}}
.header{display:flex;align-items:center;justify-content:space-between;margin-bottom:5mm}
.header img{height:75px}
.header-center{flex:1;text-align:center}
.header-center .rep{font-weight:bold;font-size:11pt;text-transform:uppercase;color:#0F2942;letter-spacing:.5px}
.header-center .inst{font-size:9pt;font-style:italic;line-height:1.5;color:#555}
.header-center .inst:nth-of-type(1){font-style:normal;font-weight:bold;font-family:'Palatino Linotype','Book Antiqua',Georgia,serif;font-size:10.5pt;color:#0F2942}
.divider{border-top:0.75pt solid #B8860B;margin:3mm 0}
.titre{text-align:center;margin:4mm 0}
.titre h1{font-size:16pt;font-weight:bold;text-transform:uppercase;color:#0F2942;letter-spacing:1.5px;font-family:'Palatino Linotype','Book Antiqua',Georgia,serif}
.titre p{font-size:9.5pt;font-style:italic;margin-top:2px;color:#8B6F1F}
.num-recu{display:flex;justify-content:space-between;font-size:8.5pt;color:#8a8a8a;margin:3mm 0;font-style:italic}
.section{margin:5mm 0}
.section-title{font-size:10pt;font-weight:bold;text-transform:uppercase;color:#0F2942;
  border-left:3px solid #B8860B;padding-left:8px;margin-bottom:3mm;letter-spacing:.3px}
table.info{width:100%;border-collapse:collapse;font-size:9.5pt}
table.info tr{border-bottom:0.5pt solid #d8cfb8}
table.info td{padding:3.5px 8px}
table.info td:first-child{color:#8B6F1F;font-weight:600;width:40%;font-style:italic}
table.info td strong{color:#0F2942}
.confirm-box{border:1pt solid #B8860B;border-radius:4px;padding:9px 16px;
  background:#fff;margin:5mm 0;display:flex;align-items:center;gap:12px}
.confirm-box .icon{font-size:26px}
.confirm-box .text{font-size:10pt;line-height:1.4}
.confirm-box .text strong{font-size:11pt;color:#0F2942}
.sig-zone{display:flex;justify-content:space-between;margin-top:9mm;font-size:9pt}
.sig-zone>div{flex:1;text-align:center;color:#555}
.sig-line{border-top:0.5pt solid #B8860B;margin-top:16mm;padding-top:3px;font-style:italic;color:#8B6F1F}
.watermark{position:absolute;top:50%;left:50%;transform:translate(-50%,-50%) rotate(-30deg);
  font-size:60pt;font-weight:900;opacity:0.045;color:#0F2942;pointer-events:none;white-space:nowrap;font-family:'Palatino Linotype',serif}
.nb{font-size:8pt;font-style:italic;color:#8a8a8a;border-top:0.5pt solid #d8cfb8;padding-top:4mm;margin-top:4mm;line-height:1.6}
.no-print{position:fixed;top:10px;right:10px;z-index:999;display:flex;gap:8px}
.no-print button,.no-print a{background:#0F2942;color:#fff;border:none;padding:8px 16px;border-radius:6px;cursor:pointer;font-size:13px;font-weight:600;text-decoration:none}
</style>
</head>
<body>
@php $institution = $p('institution_nom', 'Université Djibo Hamani de Tahoua'); @endphp
<div class="no-print">
  <button type="button" data-print="1">🖨️ Imprimer</button>
  <a href="#" data-history-back="1">← Retour</a>
</div>

<div class="page" style="position:relative">
<div class="page-inner">
  <div class="watermark">INSCRIT</div>

  <div class="header">
    <img src="{{ $logos['gauche'] }}" alt="" data-onerror-hide="1">
    <div class="header-center">
      <div class="rep">{{ $p('institution_republique', 'REPUBLIQUE DU NIGER') }}</div>
      <div class="inst">{{ $institution }}</div>
      <div class="inst">{{ $ins->faculte ?: $p('institution_faculte_defaut') }}</div>
      <div class="inst">{{ $p('institution_service', 'Service de la Scolarité') }} — {{ $p('institution_bp', 'BP : 255 Tahoua-Niger') }}</div>
    </div>
    <img src="{{ $logos['droite'] }}" alt="" data-onerror-hide="1">
  </div>
  <div class="divider"></div>

  <div class="titre">
    <h1>Reçu d'Inscription</h1>
    <p>Année académique {{ $ins->annee }}</p>
  </div>

  <div class="num-recu">
    <span>N° {{ $numero }}</span>
    <span>Date d'inscription : {{ date('d/m/Y', strtotime($ins->date_inscription ?: $ins->created_at)) }}</span>
  </div>

  <div class="confirm-box">
    <div class="icon">✅</div>
    <div class="text">
      <strong>{{ $et->nom }} {{ $et->prenoms }}</strong><br>
      est officiellement inscrit(e) à l'{{ $institution }} pour l'année académique <strong>{{ $ins->annee }}</strong>.
    </div>
  </div>

  <div class="section">
    <div class="section-title">Informations de l'étudiant</div>
    <table class="info">
      <tr><td>Matricule</td><td><strong>{{ $et->numero }}</strong></td></tr>
      <tr><td>Nom &amp; Prénoms</td><td><strong>{{ $et->nom }} {{ $et->prenoms }}</strong></td></tr>
      <tr><td>Date de naissance</td><td>{{ \App\Support\Format::dateFR($et->date_naissance) }}</td></tr>
      @if (! empty($et->lieu_naissance))<tr><td>Lieu de naissance</td><td>{{ $et->lieu_naissance }}</td></tr>@endif
      @if (! empty($et->telephone))<tr><td>Téléphone</td><td>{{ $et->telephone }}</td></tr>@endif
      @if (! empty($et->email))<tr><td>Email</td><td>{{ $et->email }}</td></tr>@endif
    </table>
  </div>

  <div class="section">
    <div class="section-title">Détails de l'inscription</div>
    <table class="info">
      <tr><td>Filière</td><td><strong>{{ $ins->filiere }}</strong></td></tr>
      <tr><td>Niveau</td><td>{{ $ins->niveau }}</td></tr>
      <tr><td>Montant</td><td>{{ number_format((float) $ins->montant_paye, 0, '.', ' ') }} FCFA</td></tr>
    </table>
  </div>

  <div class="sig-zone">
    <div><div style="margin-bottom:16mm">L'étudiant(e)</div><div class="sig-line">Signature</div></div>
    <div><div style="margin-bottom:16mm">Le Chef Service de la Scolarité Centrale</div><div class="sig-line">Signature et cachet</div></div>
  </div>

  <div class="nb">
    <strong>NB :</strong>
    <br>• Ce reçu atteste de l'inscription de l'étudiant pour l'année académique en cours.
    <br>• Il doit être conservé précieusement et présenté à toute demande de l'administration.
    <br>• Toute falsification expose son auteur à des poursuites disciplinaires et judiciaires.
  </div>
</div>
</div>
<script src="{{ \App\Support\Ressource::url('assets/js/impression.js') }}"></script>
</body>
</html>
