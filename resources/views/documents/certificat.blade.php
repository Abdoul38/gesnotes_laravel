{{-- Certificat de scolarité — portage de certificat_scolarite.php --}}
<!DOCTYPE html>
<html lang="fr">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Certificat de Scolarité — {{ $d->nom }} {{ $d->prenoms }}</title>
<style>
* { -webkit-print-color-adjust: exact !important; print-color-adjust: exact !important; color-adjust: exact !important; }

* { box-sizing: border-box; margin: 0; padding: 0; }
body { font-family: Georgia, 'Times New Roman', Times, serif; background: #e8e5df; color: #2C2C2C; }

.no-print { background: #0F2942; padding: 12px 20px; display: flex; gap: 12px; align-items: center; }
.no-print button { background: #fff; color: #0F2942; border: none; padding: 8px 20px; border-radius: 6px; cursor: pointer; font-weight: 700; font-size: 14px; }
.no-print a { color: #fff; text-decoration: none; font-size: 13px; opacity: .8; }
.no-print a:hover { opacity: 1; }

.page {
    width: 210mm;
    min-height: 297mm;
    margin: 20px auto;
    background: #fff;
    padding: 20mm 22mm;
    box-shadow: 0 4px 20px rgba(0,0,0,.15);
    display: flex;
    flex-direction: column;
    position: relative;
}
.page::before { content:''; position:absolute; inset:5mm; border:0.75pt solid #B8860B; pointer-events:none; }
.page::after  { content:''; position:absolute; inset:6.5mm; border:0.5pt solid #0F2942; pointer-events:none; }
.page-inner { position:relative; z-index:1; display:flex; flex-direction:column; flex:1; padding:3mm 4mm; }

/* En-tête */
.entete {
    display: flex;
    align-items: flex-start;
    justify-content: space-between;
    margin-bottom: 4mm;
    gap: 10px;
}
.entete-logo img {
    width: 85px;
    height: 85px;
    object-fit: contain;
}
.entete-texte {
    text-align: center;
    flex: 1;
    line-height: 1.5;
}
.entete-texte .rep { font-size: 11pt; font-weight: bold; text-transform: uppercase; color:#0F2942; letter-spacing:.5px; }
.entete-texte .univ { font-size: 11.5pt; font-weight: bold; font-family:'Palatino Linotype','Book Antiqua',Georgia,serif; color:#0F2942; margin-top:1mm; }
.entete-texte .fac { font-size: 10pt; font-weight: bold; color:#333; }
.entete-texte .serv { font-size: 10pt; font-weight: bold; color:#333; }
.entete-texte .coord { font-size: 8.5pt; margin-top: 4px; color: #8B6F1F; font-style:italic; }

/* Ligne séparatrice */
.hr { border: none; border-top: 0.75pt solid #B8860B; margin: 3mm 0; }
.hr-thin { border: none; border-top: 0.5pt solid #d8cfb8; margin: 4mm 0; }

/* Titre */
.titre {
    text-align: center;
    font-size: 17pt;
    font-weight: bold;
    font-family:'Palatino Linotype','Book Antiqua',Georgia,serif;
    text-transform: uppercase;
    letter-spacing: 1.5px;
    margin: 4mm 0;
    color:#0F2942;
    padding-bottom:2mm;
    border-bottom: 1pt solid #B8860B;
    display:inline-block;
}
.titre-wrap { text-align:center; }

/* Corps */
.corps { font-size: 11pt; line-height: 1.4; margin-bottom: 4mm; }

.corps-intro { text-align: justify; margin-bottom: 5mm; }

.infos { margin: 4mm 0 5mm 10mm; }
.infos p { font-size: 11pt; font-weight: bold; margin-bottom: 2.5mm; color:#0F2942; }

.corps-fin { text-align: justify; }

/* Signature */
.signature-bloc {
    display: flex;
    justify-content: flex-end;
    flex-direction: column;
    align-items: flex-end;
}
.signature-lieu { font-size: 11pt; margin-bottom: 2mm; color:#555; font-style:italic; }
.signature-titre { font-size: 11pt; font-weight: bold; margin-bottom: 12mm; color:#8B6F1F; font-style:italic; }
.signature-nom { font-size: 11pt; font-weight: bold; color:#0F2942; }

/* Pied de page */
.pied {
    margin-top: 5mm;
    padding-top: 3mm;
    border-top: 0.5pt solid #d8cfb8;
    text-align: center;
    font-size: 8pt;
    font-style: italic;
    color: #8a8a8a;
    line-height: 1.6;
}

@media print {
    .no-print { display: none !important; }
    body { background: #fff; }
    .page {
        width: 210mm;
        min-height: 297mm;
        margin: 0;
        padding: 17mm 20mm;
        box-shadow: none;
    }
    @page { size: A4 portrait; margin: 0; }
}
</style>
</head>
<body>
@php
    $f = $d->sexe === 'F';
    $institution = $p('institution_nom', 'Université Djibo Hamani de Tahoua');
    $faculte = $d->faculte ?: $p('institution_faculte_defaut');
@endphp
<div class="no-print">
  <button type="button" data-print="1">🖨️ Imprimer / Enregistrer PDF</button>
  <a href="#" data-history-back="1">← Retour</a>
  <span style="margin-left:auto;color:#fff;font-size:13px;opacity:.7">Certificat de scolarité — {{ $d->nom }} {{ $d->prenoms }}</span>
</div>

<div class="page">
<div class="page-inner">

  <div class="entete">
    <div class="entete-logo"><img src="{{ $logos['gauche'] }}" alt="" data-onerror-hide="1"></div>
    <div class="entete-texte">
      <div class="rep">{{ $p('institution_republique', 'REPUBLIQUE DU NIGER') }}</div>
      <div class="univ">{{ $institution }}</div>
      <div class="fac">{{ $faculte }}</div>
      <div class="serv">{{ $p('institution_service', 'Service de la Scolarité') }}</div>
      <div class="coord">
        {{ $p('institution_bp', 'BP : 255 Tahoua-Niger') }} | Tél : {{ $p('institution_tel', '(+227) 20 610 648') }}<br>
        Email : {{ $p('institution_email', 'univ@udh.edu.ne') }} | Web : {{ $p('institution_site', 'https://udh.edu.ne') }}
      </div>
    </div>
    <div class="entete-logo"><img src="{{ $logos['droite'] }}" alt="" data-onerror-hide="1"></div>
  </div>

  <hr class="hr">
  <div class="titre-wrap"><div class="titre">Certificat de Scolarité</div></div>
  <hr class="hr">

  <div class="corps">
    <div class="corps-intro">
      Je soussigné, {{ $signataire['titre'] }} {{ $signataire['nom'] }}
      de la {{ $faculte }} de l'{{ $institution }},
      certifie que {{ $f ? 'madame' : 'monsieur' }} :
    </div>
    <div class="infos">
      <p>&#9632;&nbsp; Nom &amp; Prénom : {{ $d->nom }} {{ $d->prenoms }}</p>
      <p>&#9632;&nbsp; {{ $f ? 'Née le' : 'Né le' }} : {{ $d->date_naissance ? date('d/m/Y', strtotime($d->date_naissance)) : '—' }}{{ $d->lieu_naissance ? ' à '.$d->lieu_naissance : '' }}</p>
      <p>&#9632;&nbsp; {{ $f ? 'Est inscrite' : 'Est inscrit' }} sous le N° : {{ $d->numero ?? $d->numero_dossier ?? '—' }}</p>
      <p>&#9632;&nbsp; Section : {{ $d->niveau }} {{ $d->filiere }}</p>
      <p>&#9632;&nbsp; Au titre de l'année Académique : {{ $d->annee }}.</p>
    </div>
    <div class="corps-fin">En foi de quoi, le présent certificat lui est délivré pour servir et valoir ce que de droit.</div>
  </div>

  <div style="display:flex;justify-content:space-between;align-items:flex-end;margin-top:4mm;padding-top:2mm">
    <div style="display:flex;align-items:center;gap:2mm">
      <div data-qr="{{ $urlVerification }}" data-qr-cellule="2" style="width:12mm;height:12mm"></div>
      <span style="font-size:7pt;color:#666;line-height:1.2">Scanner pour vérifier l'authenticité<br>et comparer les informations affichées.<br>Site officiel : <strong>{{ \App\Services\CleVerification::site($urlVerification) }}</strong><br>N° {{ $numero }}</span>
    </div>
    <div class="signature-bloc" style="margin-top:0;padding-top:0">
      <div class="signature-lieu">Fait à {{ $p('institution_ville', 'Tahoua') }}, le {{ date('d/m/Y') }}</div>
      <div class="signature-titre">{{ $signataire['titre'] }}</div>
      <div style="height:16mm"></div>
      <div class="signature-nom">{{ $signataire['nom'] }}</div>
      @if (! empty($signataire['qualite']))<div style="font-size:9pt;font-style:italic">{{ $signataire['qualite'] }}</div>@endif
    </div>
  </div>

  <div style="flex-grow:1"></div>

  <div class="pied">
    {{ $institution }} | {{ $p('institution_bp', 'BP : 255 Tahoua - Niger') }}<br>
    Email : {{ $p('institution_email', 'univ@udh.edu.ne') }} | Tél : {{ $p('institution_tel', '(+227) 20 61 06 48') }} | Web : {{ $p('institution_site', 'www.udh.edu.ne') }}
    <br><span style="font-size:7pt">N° {{ $numero }}</span>
  </div>
</div>
</div>
<script src="{{ asset('assets/js/qrcode.js') }}"></script>
<script src="{{ asset('assets/js/impression.js') }}"></script>
</body>
</html>
