{{-- Diplôme (A4 paysage) — portage de diplome.php --}}
@php
    $civF = ($et->sexe ?? 'M') === 'F';
@endphp
<!DOCTYPE html>
<html lang="fr">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width,initial-scale=1">
<title>Diplôme de {{ $niveauLabel }} — {{ $et->nom }} {{ $et->prenoms }}</title>
<style>
* { -webkit-print-color-adjust: exact !important; print-color-adjust: exact !important; color-adjust: exact !important; }

* { box-sizing:border-box; margin:0; padding:0; }
html { margin:0; padding:0; }
body { font-family: Georgia, 'Times New Roman', serif; background:#e8e5df; color:#2C2C2C; margin:0; }

.no-print { background:#0F2942; padding:12px 20px; display:flex; gap:12px; align-items:center; }
.no-print button { background:#B8860B; color:#fff; border:none; padding:8px 22px; border-radius:3px; cursor:pointer; font-weight:700; font-family:Georgia,serif; }
.no-print a { color:#fff; text-decoration:none; font-size:13px; opacity:.75; }

/* Format paysage A4 */
.page {
  width:297mm; height:210mm; margin:20px auto;
  background:#FAF6EE;
  padding:12mm 16mm;
  box-shadow:0 6px 24px rgba(0,0,0,.18);
  position:relative;
  display:flex; flex-direction:column;
}
.frame-outer { position:absolute; top:6mm; left:6mm; right:6mm; bottom:6mm; border:1.5pt solid #B8860B; pointer-events:none; }
.frame-mid { position:absolute; top:8mm; left:8mm; right:8mm; bottom:8mm; border:3pt double #8B6F1F; pointer-events:none; }
.frame-inner { position:absolute; top:11mm; left:11mm; right:11mm; bottom:11mm; border:1pt solid #0F2942; pointer-events:none; }

.content { position:relative; z-index:1; flex:1; display:flex; flex-direction:column; padding:6mm 14mm; }
.filigrane {
  position:absolute; top:50%; left:50%; transform:translate(-50%,-50%);
  width:140mm; height:140mm;
  background-repeat:no-repeat; background-position:center; background-size:contain;
  opacity:0.06; pointer-events:none; z-index:0;
}

.entete { text-align:center; margin-bottom:2mm; }
.entete-logos { display:flex; align-items:center; justify-content:center; gap:12mm; }
.entete-logos img { height:22mm; width:auto; object-fit:contain; max-width:30mm; }
.entete-texte { flex-shrink:0; }
.entete .rep { font-size:11pt; letter-spacing:2px; text-transform:uppercase; color:#0F2942; font-weight:bold; }
.entete .ministere { font-size:8.5pt; color:#555; margin-top:1mm; font-style:italic; }
.entete .univ { font-size:13pt; font-weight:bold; color:#0F2942; margin-top:1mm; text-transform:uppercase; letter-spacing:1px; }

.titre-diplome { text-align:center; margin:2mm 0; }
.titre-diplome .t1 { font-family:'Palatino Linotype','Book Antiqua',Georgia,serif; font-size:22pt; font-weight:bold; letter-spacing:4px; color:#0F2942; text-transform:uppercase; }

.visas { font-size:10pt; line-height:1.4; color:#333; max-width:235mm; margin:0 auto; text-align:justify; flex-shrink:0; }
.visas p { margin-bottom:1mm; }
.visas .num { font-weight:bold; }

.confere { text-align:center; margin:2mm 0 1mm; font-size:11pt; }
.confere strong { font-family:'Palatino Linotype','Book Antiqua',Georgia,serif; font-size:14pt; color:#0F2942; letter-spacing:1px; }

.details { text-align:center; font-size:11pt; line-height:1.5; margin-bottom:1.5mm; }
.details .champ-label { color:#8B6F1F; font-style:italic; }
.details .champ-val { font-weight:bold; color:#0F2942; }
.details .mention-forte { font-family:'Palatino Linotype','Book Antiqua',Georgia,serif; font-size:13pt; font-weight:bold; color:#0F2942; }

.avis-final { text-align:center; font-size:9pt; font-style:italic; color:#555; margin:1.5mm 0 0; }

.bas { display:flex; justify-content:space-between; align-items:flex-end; margin-top:3mm; padding:0 6mm; }
.bas .impetrant { text-align:center; width:70mm; }
.bas .impetrant .lbl { font-size:9pt; color:#8B6F1F; font-style:italic; margin-bottom:10mm; }
.bas .impetrant .ligne { border-top:0.75pt solid #999; padding-top:1mm; font-size:8pt; color:#888; }
.bas .signature { text-align:center; width:80mm; }
.bas .signature .lieu { font-size:9.5pt; font-style:italic; color:#555; margin-bottom:10mm; }
.bas .signature .ligne-sig { border-top:0.75pt solid #0F2942; margin-bottom:1.5mm; }
.bas .signature .titre-sig { font-size:9.5pt; font-style:italic; color:#555; }
.bas .signature .nom-sig { font-size:11pt; font-weight:bold; color:#0F2942; margin-top:1mm; }
.bas .sceau { width:28mm; height:28mm; border:1pt dashed #B8860B; border-radius:50%; display:flex; align-items:center; justify-content:center; font-size:7pt; color:#B8860B; text-align:center; font-style:italic; }

.numero-diplome { position:absolute; bottom:16mm; right:20mm; font-size:8.5pt; color:#8B6F1F; font-family:Georgia,serif; z-index:2; text-align:right; }
.qr-auth { position:absolute; bottom:16mm; left:20mm; z-index:2; display:flex; align-items:center; gap:3mm; max-width:60mm; }
.qr-auth .qr-txt { line-height:1.3; }
.qr-auth img { width:16mm; height:16mm; }
.qr-auth .qr-txt { font-size:6.5pt; color:#8B6F1F; font-family:Georgia,serif; line-height:1.3; max-width:40mm; overflow-wrap:anywhere; }

@page { size: A4 landscape; margin: 0; }
@media print {
  html, body { margin:0 !important; padding:0 !important; background:#fff; }
  .no-print { display:none !important; }
  .page { width:297mm; height:210mm; margin:0 !important; box-shadow:none; overflow:hidden; }
}
</style>
</head>
<body>

<div class="no-print">
  <button type="button" data-print="1">🖨️ Imprimer</button>
  <a href="#" data-history-back="1">← Retour</a>
  <span style="margin-left:auto;color:#fff;font-size:11px;opacity:.6">
    ⚠️ Dans la fenêtre d'impression : Orientation « Paysage », Marges « Aucune »
  </span>
</div>

<div class="page">
  <div class="frame-outer"></div>
  <div class="frame-mid"></div>
  <div class="frame-inner"></div>

  <div class="content">
    <div class="filigrane" style="background-image:url('{{ $logos['gauche'] }}')"></div>
    <div class="entete">
      <div class="entete-logos">
        <img src="{{ $logos['gauche'] }}" alt="" data-onerror-invisible="1">
        <div class="entete-texte">
          <div class="rep">République du Niger</div>
          <div class="ministere">Ministère de l'Enseignement Supérieur, de la Recherche et de l'Innovation Technologique</div>
          <div class="univ">{{ $institution }}</div>
        </div>
        <img src="{{ $armoirie }}" alt="" data-onerror-invisible="1">
      </div>
    </div>

    <div class="titre-diplome">
      <div class="t1">Diplôme de {{ $niveauLabel }}</div>
    </div>

    <div class="visas">
      @forelse ($visas as $ligne)
      <p>{{ $ligne }}</p>
      @empty
      <p style="color:#c00;font-style:italic" class="no-print">⚠️ Aucun visa réglementaire configuré pour ce niveau — à renseigner dans Paramètres › Visas des diplômes.</p>
      @endforelse
      <p>Vu les titres initiaux produits par {{ $civF ? 'Madame' : 'Monsieur' }} <strong>{{ mb_strtoupper($et->nom) }} {{ $et->prenoms }}</strong>,
      {{ $civF ? 'Née' : 'Né' }} le {{ $et->date_naissance ? \App\Support\Format::dateFR($et->date_naissance) : '……' }}{{ $et->lieu_naissance ? ' à '.$et->lieu_naissance : '' }}, Pays : Niger ;</p>
      <p>Vu les procès-verbaux d'examen dressés par les jurys de délibération des semestres à {{ $faculteDefaut }} de l'{{ $institution }} constatant que l'intéressé(e) a subi avec succès, dans les formes réglementaires, les épreuves de contrôle des connaissances et qu'il(elle) a capitalisé les <strong>{{ $d['credits'] }}</strong> crédits du parcours de {{ $niveauLabel }} ;</p>
    </div>

    <div class="confere">
      Il est conféré à l'intéressé(e) le <strong>DIPLÔME DE {{ $niveauLabel }}{{ $d['type'] ? ' '.mb_strtoupper($d['type']) : '' }}</strong>
    </div>

    <div class="details">
      <span class="champ-label">Domaine :</span> <span class="champ-val">{{ $d['domaine'] }}</span>
      &nbsp;&nbsp;·&nbsp;&nbsp;
      <span class="champ-label">Mention :</span> <span class="champ-val">{{ $d['mention_academique'] ?: '—' }}</span>
      &nbsp;&nbsp;·&nbsp;&nbsp;
      <span class="champ-label">Spécialité :</span> <span class="champ-val">{{ $d['specialite'] }}</span><br>
      <span class="champ-label">à la session de :</span> <span class="champ-val">{{ $d['session_libelle'] }}</span>
      <br>
      avec la mention <span class="mention-forte">{{ $d['mention_grade'] }}</span>
    </div>

    <div class="avis-final">
      Pour en jouir avec les droits et prérogatives qui y sont attachés.<br>
      Il ne sera délivré aucun duplicata du diplôme.
    </div>

    <div class="bas">
      <div class="impetrant">
        <div class="lbl">L'impétrant(e)</div>
        <div class="ligne">Signature</div>
      </div>
      <div class="sceau">Sceau de<br>l'établissement</div>
      <div class="signature">
        <div class="lieu">Fait à {{ $ville }}, le {{ date('d/m/Y') }}</div>
        <div class="ligne-sig"></div>
        <div class="titre-sig">{{ $signataire['titre'] }}</div>
        <div class="nom-sig">{{ $signataire['nom'] }}</div>
      </div>
    </div>
  </div>

  <div class="qr-auth">
    <div data-qr="{{ $urlVerification }}" data-qr-cellule="2" style="width:16mm;height:16mm"></div>
    <span class="qr-txt">Scanner pour vérifier l'authenticité<br>et comparer les informations affichées.<br>Site officiel : <strong>{{ \App\Services\CleVerification::site($urlVerification) }}</strong></span>
  </div>
  <div class="numero-diplome">Diplôme enregistré sous le N° {{ $numero }}<br>Code de vérification : {{ app(\App\Services\CleVerification::class)->code($numero) }}</div>
</div>
<script src="{{ asset('assets/js/qrcode.js') }}"></script>
<script src="{{ asset('assets/js/impression.js') }}"></script>
</body>
</html>
