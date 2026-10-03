{{-- Reçu de paiement (A5) — portage de recu_paiement.php --}}
@php $reste = (float) $p->montant_du - (float) $p->montant_paye; @endphp
<!DOCTYPE html>
<html lang="fr">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width,initial-scale=1">
<title>Reçu paiement</title>
<style>
* { -webkit-print-color-adjust: exact !important; print-color-adjust: exact !important; color-adjust: exact !important; }

*{box-sizing:border-box;margin:0;padding:0}body{font-family:Georgia,'Times New Roman',serif;font-size:10pt;background:#e8e5df;color:#2C2C2C}
.page{width:148mm;min-height:210mm;background:#fff;margin:8mm auto;padding:10mm 12mm;box-shadow:0 4px 20px rgba(0,0,0,.15);position:relative}
.page::before{content:'';position:absolute;inset:4mm;border:0.75pt solid #B8860B;pointer-events:none}
.page::after{content:'';position:absolute;inset:5.5mm;border:0.5pt solid #0F2942;pointer-events:none}
.page-inner{position:relative;z-index:1;display:flex;flex-direction:column;padding:2mm 3mm}
@media print{body{background:#fff}.no-print{display:none!important}.page{margin:0;padding:8mm 10mm;box-shadow:none}@page{size:A5 portrait;margin:0}}
.header{display:flex;align-items:center;justify-content:space-between;margin-bottom:4mm}
.header img{height:55px}.header-center{flex:1;text-align:center;font-size:8.5pt;font-style:italic;color:#555}
.header-center strong{font-style:normal;font-family:'Palatino Linotype','Book Antiqua',Georgia,serif;color:#0F2942;font-size:9.5pt}
.divider{border-top:0.75pt solid #B8860B;margin:3mm 0}
.titre{text-align:center;font-size:14pt;font-weight:bold;text-transform:uppercase;color:#0F2942;margin:3mm 0;letter-spacing:1px;font-family:'Palatino Linotype','Book Antiqua',Georgia,serif}
.sous-titre{text-align:center;font-size:9pt;margin-bottom:4mm;color:#8B6F1F;font-style:italic}
.info-grid{display:grid;grid-template-columns:1fr 1fr;gap:3mm;margin:3mm 0;font-size:9pt}
.info-box{border:0.5pt solid #d8cfb8;border-radius:3px;padding:4px 8px;background:#fff}
.info-box .label{font-size:7.5pt;color:#8B6F1F;text-transform:uppercase;margin-bottom:1px;font-style:italic}
.info-box .val{font-weight:700;color:#0F2942}
.montant-box{border:1.5pt solid #B8860B;border-radius:6px;padding:8px 12px;text-align:center;margin:4mm 0;background:#fff}
.montant-box .lbl{font-size:8pt;color:#8B6F1F;text-transform:uppercase;font-style:italic}
.montant-box .val{font-size:18pt;font-weight:800;color:#0F2942}
.montant-box .reste{font-size:9pt;color:{{ $reste > 0 ? '#b91c1c' : '#166534' }};margin-top:2px}
.statut-badge{display:inline-block;padding:3px 12px;border-radius:12px;font-size:10pt;font-weight:700}
.sig-zone{display:flex;justify-content:space-between;margin-top:8mm;font-size:8.5pt;color:#555}
.sig-zone>div{text-align:center;flex:1}
.sig-line{border-top:0.5pt solid #B8860B;margin-top:15mm;padding-top:2px;font-style:italic;color:#8B6F1F}
.watermark{position:absolute;top:50%;left:50%;transform:translate(-50%,-50%) rotate(-30deg);font-size:40pt;font-weight:900;opacity:0.05;color:#0F2942;pointer-events:none;text-transform:uppercase;white-space:nowrap;font-family:'Palatino Linotype',serif}
.no-print{position:fixed;top:10px;right:10px;background:#0F2942;color:#fff;border:none;padding:8px 16px;border-radius:6px;cursor:pointer;font-size:13px}
</style>
</head>
<body>
@php
    $styles = ['en_attente' => ['#fff3e0', '#e65100'], 'partiel' => ['#e3f2fd', '#0d47a1'], 'complet' => ['#e8f5e9', '#1b5e20'], 'rembourse' => ['#f5f5f5', '#666']];
    $c = $styles[$p->statut] ?? ['#fff', '#333'];
    $fcfa = fn ($v) => number_format((float) $v, 0, '.', ' ');
@endphp
<button type="button" class="no-print" data-print="1">🖨️ Imprimer</button>
<div class="page" style="position:relative">
<div class="page-inner">
  <div class="watermark">{{ $p->statut === 'complet' ? 'PAYÉ' : 'EN COURS' }}</div>

  <div class="header">
    <img src="{{ $logos['gauche'] }}" alt="" data-onerror-hide="1">
    <div class="header-center"><strong>{{ $institution }}</strong><br>{{ $service }}</div>
    <img src="{{ $logos['droite'] }}" alt="" data-onerror-hide="1">
  </div>
  <div class="divider"></div>
  <div class="titre">Reçu de Paiement</div>
  <div class="sous-titre">N° {{ $p->reference ?? str_pad((string) $p->id, 6, '0', STR_PAD_LEFT) }} — {{ $p->date_paiement ? date('d/m/Y', strtotime($p->date_paiement)) : date('d/m/Y') }}</div>

  <div class="info-grid">
    <div class="info-box"><div class="label">Étudiant</div><div class="val">{{ $p->nom }} {{ $p->prenoms }}</div></div>
    <div class="info-box"><div class="label">Matricule</div><div class="val">{{ $p->numero }}</div></div>
    @if ($ins)
    <div class="info-box"><div class="label">Filière</div><div class="val">{{ $ins->filiere }} {{ $ins->niveau }}</div></div>
    <div class="info-box"><div class="label">Année académique</div><div class="val">{{ $ins->annee }}</div></div>
    @endif
    <div class="info-box"><div class="label">Type de paiement</div><div class="val">{{ ucfirst($p->type_paiement) }}</div></div>
    <div class="info-box"><div class="label">Mode de paiement</div><div class="val">{{ str_replace('_', ' ', ucfirst($p->mode_paiement)) }}</div></div>
  </div>

  <div class="montant-box">
    <div class="lbl">Montant payé</div>
    <div class="val">{{ $fcfa($p->montant_paye) }} FCFA</div>
    <div class="reste">@if ($reste > 0)Reste à payer : {{ $fcfa($reste) }} FCFA @else ✅ Paiement complet @endif</div>
  </div>

  <div style="text-align:center;margin:3mm 0">
    <span class="statut-badge" style="background:{{ $c[0] }};color:{{ $c[1] }};border:1px solid {{ $c[1] }}">{{ mb_strtoupper($p->statut) }}</span>
  </div>

  @if ($p->notes)
  <div style="font-size:8.5pt;font-style:italic;color:#666;border-top:0.5px solid #ddd;padding-top:4px;margin-top:3mm">Note : {{ $p->notes }}</div>
  @endif

  <div class="sig-zone">
    <div><div style="margin-bottom:12mm">L'étudiant(e)</div><div class="sig-line">Signature</div></div>
    <div><div style="margin-bottom:12mm">Le Responsable Scolarité</div><div class="sig-line">Signature et cachet</div></div>
  </div>

  <div style="text-align:center;font-size:7.5pt;color:#8a8a8a;margin-top:5mm;border-top:0.5pt solid #d8cfb8;padding-top:3mm;font-style:italic">
    Ce reçu est un document officiel — {{ $bp }}
  </div>
</div>
</div>
<script src="{{ asset('assets/js/impression.js') }}"></script>
</body>
</html>
