{{-- Attestation de réussite (par PV) — portage de attestation.php --}}
<!DOCTYPE html>
<html lang="fr">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width,initial-scale=1.0">
<title>Attestation — {{ $masse ? 'Tous les admis' : $etudiants->first()->nom }}</title>
<style>
* { box-sizing:border-box; margin:0; padding:0; }
body { font-family: Georgia, 'Times New Roman', serif; background:#e8e5df; color:#2C2C2C; }

.no-print { background:#0F2942; padding:12px 20px; display:flex; gap:12px; align-items:center; }
.no-print button { background:#B8860B; color:#fff; border:none; padding:8px 22px; border-radius:3px; cursor:pointer; font-weight:700; font-family:Georgia,serif; letter-spacing:.5px; }
.no-print a { color:#fff; text-decoration:none; font-size:13px; opacity:.75; }

.page {
  width:210mm; min-height:297mm; margin:20px auto;
  background:#FAF6ED;
  padding:14mm 16mm;
  box-shadow:0 6px 24px rgba(0,0,0,.18);
  position:relative;
  display:flex; flex-direction:column;
  page-break-after:always;
}
.page:last-child { page-break-after:auto; }

.frame-outer { position:absolute; top:8mm; left:8mm; right:8mm; bottom:8mm; border:1.5pt solid #B8860B; pointer-events:none; }
.frame-inner { position:absolute; top:10.5mm; left:10.5mm; right:10.5mm; bottom:10.5mm; border:2.5pt solid #0F2942; pointer-events:none; }
.frame-inner2 { position:absolute; top:12.5mm; left:12.5mm; right:12.5mm; bottom:12.5mm; border:0.75pt solid #B8860B; pointer-events:none; }

.corner { position:absolute; width:11mm; height:11mm; pointer-events:none; }
.corner svg { width:100%; height:100%; }
.corner.tl { top:8mm; left:8mm; }
.corner.tr { top:8mm; right:8mm; transform:scaleX(-1); }
.corner.bl { bottom:8mm; left:8mm; transform:scaleY(-1); }
.corner.br { bottom:8mm; right:8mm; transform:scale(-1,-1); }

.watermark {
  position:absolute; top:50%; left:50%; transform:translate(-50%,-50%);
  width:150mm; height:150mm;
  background-repeat:no-repeat; background-position:center; background-size:contain;
  opacity:0.05; pointer-events:none;
}

.content { position:relative; z-index:1; flex:1; display:flex; flex-direction:column; justify-content:space-between; padding:6mm 10mm; }
.content-main { display:flex; flex-direction:column; align-items:center; }

.entete { text-align:center; margin-bottom:5mm; }
.entete-logos { display:flex; align-items:center; justify-content:center; gap:14mm; margin-bottom:4mm; }
.entete-logos img { height:24mm; max-width:38mm; width:auto; object-fit:contain; }
.entete .rep { font-size:9.5pt; letter-spacing:1.5px; text-transform:uppercase; color:#0F2942; font-weight:bold; }
.entete .coord { font-size:8pt; color:#666; margin-top:1mm; }
.entete .univ { font-size:11pt; font-weight:bold; color:#0F2942; margin-top:2.5mm; }

.filet-orne { display:flex; align-items:center; justify-content:center; gap:8px; margin:4mm 0; }
.filet-orne .ligne { height:0.75pt; width:38mm; background:#B8860B; }
.filet-orne .losange { width:5px; height:5px; background:#B8860B; transform:rotate(45deg); }

.titre-bloc { text-align:center; margin-bottom:6mm; }
.titre-principal { font-family:'Palatino Linotype','Book Antiqua',Georgia,serif; font-size:19pt; font-weight:bold; letter-spacing:3px; color:#0F2942; text-transform:uppercase; white-space:nowrap; }
.titre-sous { font-size:11.5pt; letter-spacing:2px; color:#8B6F1F; text-transform:uppercase; margin-top:2.5mm; font-weight:normal; }

.corps { font-size:11.5pt; line-height:1.85; text-align:center; padding:0 4mm; }
.corps .intro { font-size:11pt; color:#2C2C2C; margin-bottom:5mm; }

.identite-nom { font-family:'Palatino Linotype','Book Antiqua',Georgia,serif; font-size:16pt; font-weight:bold; color:#0F2942; margin:4mm 0 2mm; letter-spacing:0.5px; }
.identite-detail { font-size:10pt; color:#555; font-style:italic; margin-bottom:5mm; }

.texte-attest { font-size:11.5pt; line-height:1.9; color:#2C2C2C; max-width:150mm; margin:0 auto; }
.texte-attest .semestre { font-family:'Palatino Linotype','Book Antiqua',Georgia,serif; font-weight:bold; font-size:12.5pt; color:#0F2942; }

/* Encart résultats — 4 indicateurs, même esprit que le ruban de mention */
.resultat-box {
  display:flex; justify-content:space-around; align-items:center;
  margin:5mm auto; padding:4mm 2mm;
  border-top:1pt solid #B8860B; border-bottom:1pt solid #B8860B;
  max-width:160mm;
}
.resultat-item { text-align:center; padding:0 3mm; }
.resultat-item .label { font-size:7.5pt; color:#8B6F1F; text-transform:uppercase; letter-spacing:1px; margin-bottom:1.5mm; }
.resultat-item .val { font-size:13pt; font-weight:bold; color:#0F2942; }
.resultat-item .val.decision { color:#166534; }

.foi-de-quoi { font-size:10pt; font-style:italic; color:#555; margin-top:4mm; line-height:1.6; max-width:130mm; margin-left:auto; margin-right:auto; }

.bas-page { display:flex; justify-content:space-between; align-items:flex-end; margin-top:8mm; padding:0 4mm; }
.sceau-zone { width:30mm; height:30mm; border:1pt dashed #B8860B; border-radius:50%; display:flex; align-items:center; justify-content:center; font-size:7pt; color:#B8860B; text-align:center; line-height:1.4; font-style:italic; }
.signature { text-align:center; width:65mm; }
.signature .lieu { font-size:9pt; font-style:italic; color:#555; margin-bottom:8mm; }
.signature .ligne-sig { border-top:0.75pt solid #0F2942; margin-bottom:2mm; }
.signature .titre-sig { font-size:9pt; font-style:italic; color:#555; }
.signature .nom-sig { font-size:10.5pt; font-weight:bold; color:#0F2942; margin-top:1mm; }

.pied { margin-top:6mm; padding-top:3mm; border-top:0.5pt solid #d8cfb8; display:flex; justify-content:space-between; align-items:flex-start; }
.pied .avis { font-size:6.5pt; color:#8a8a8a; max-width:75%; line-height:1.5; font-style:italic; }
.pied .numero { font-size:7pt; color:#8B6F1F; white-space:nowrap; font-family:Georgia,serif; text-align:right; }
.pied .qr-auth { display:flex; align-items:center; gap:1.5mm; flex-shrink:0; margin-left:3mm; }
.pied .qr-auth .qr-txt { font-size:5.5pt; color:#8B6F1F; line-height:1.25; max-width:30mm; overflow-wrap:anywhere; }

@page { size: A4; margin: 0; }
@media print {
  .no-print { display:none !important; }
  body { background:#fff; margin:0; }
  .page { width:210mm; height:297mm; margin:0; box-shadow:none; overflow:hidden; }
}
</style>
</head>
<body>

<div class="no-print">
  <button type="button" data-print="1">🖨️ Imprimer{{ $masse ? ' toutes ('.$etudiants->count().')' : '' }}</button>
  <a href="#" data-history-back="1">← Retour</a>
  <span style="margin-left:auto;color:#fff;font-size:13px;opacity:.7">
    Attestation de Réussite — {{ $masse ? $etudiants->count().' admis' : $etudiants->first()->nom }}
  </span>
</div>

@forelse ($etudiants as $et)
@php $civF = ($et->sexe ?? 'M') === 'F';
  // Civilité selon le sexe enregistré ; formes neutres s'il ne l'est pas
  [$civ, $ne, $inscrit] = ['F' => ['Mlle', 'Née', 'Inscrite'], 'M' => ['M.', 'Né', 'Inscrit']][$et->sexe ?? ''] ?? ['M. (Mlle)', 'Né(e)', 'Inscrit(e)']; @endphp
<div class="page">
  <div class="frame-outer"></div>
  <div class="frame-inner"></div>
  <div class="frame-inner2"></div>
  @include('documents._coins')
  {{-- Filigrane : logo de l'université, comme les attestations de parcours --}}
  <div class="watermark" style="background-image:url('{{ $logos['gauche'] }}')"></div>

  <div class="content">
    <div class="content-main">

      <div class="entete">
        <div class="entete-logos">
          <img src="{{ $logos['gauche'] }}" alt="" data-onerror-invisible="1">
          <div>
            <div class="rep">{{ $republique }}</div>
            <div class="coord">{{ $bp }} — TEL : {{ $tel }}</div>
          </div>
          <img src="{{ $logos['droite'] }}" alt="" data-onerror-invisible="1">
        </div>
        <div class="univ">{{ $institution }}</div>
      </div>

      <div class="filet-orne"><div class="ligne"></div><div class="losange"></div><div class="ligne"></div></div>

      <div class="titre-bloc">
        <div class="titre-principal">Attestation de Réussite</div>
        <div class="titre-sous">{{ mb_strtoupper($niveauLib) }}</div>
      </div>

      <div class="corps">
        <div class="intro">Le Doyen de {{ $faculte }} de l'{{ $institution }} atteste que :</div>

        <div class="identite-nom">{{ $civ }} {{ $et->nom }} {{ $et->prenoms }}</div>
        <div class="identite-detail">
          @if ($et->date_naissance)
          {{ $ne }} le {{ \App\Support\Format::dateFR($et->date_naissance) }}{{ $et->lieu_naissance ? ' à '.$et->lieu_naissance : '' }}
          &nbsp;·&nbsp;
          @endif
          {{ $inscrit }} sous le n° {{ $et->numero }}
        </div>

        <div class="texte-attest">
          A satisfait aux épreuves du <span class="semestre">{{ $pv->sem_libelle }}</span> de {{ $niveauLib }}<br>
          des études de {{ $pv->filiere }}, lors de la session de <strong>{{ \App\Support\Format::session($pv->type_session) }}</strong>,<br>
          au titre de l'année académique <strong>{{ $pv->annee }}</strong>.
        </div>

        <div class="resultat-box">
          <div class="resultat-item"><div class="label">Décision</div><div class="val decision">Admis{{ $civF ? 'e' : '' }}</div></div>
          <div class="resultat-item"><div class="label">Moyenne</div><div class="val">{{ $et->moyenne_generale !== null ? number_format((float) $et->moyenne_generale, 2) : '—' }}/20</div></div>
          <div class="resultat-item"><div class="label">Mention</div><div class="val">{{ $et->mention ?: '—' }}</div></div>
          <div class="resultat-item"><div class="label">Crédits</div><div class="val">{{ (int) $et->credits_obtenus }}/{{ $totalCredits }}</div></div>
        </div>

        <div class="foi-de-quoi">En foi de quoi, nous lui délivrons la présente attestation pour servir et valoir ce que de droit.</div>
      </div>

    </div>

    <div class="bas-page">
      <div class="sceau-zone">Cachet<br>de l'établissement</div>
      <div class="signature">
        <div class="lieu">{{ $ville }}, le {{ $pv->date_pv ? \App\Support\Format::dateFR($pv->date_pv) : date('d/m/Y') }}</div>
        <div class="ligne-sig"></div>
        <div class="titre-sig">{{ $signataire['titre'] }}</div>
        <div class="nom-sig">{{ $signataire['nom'] }}</div>
        @if (! empty($signataire['qualite']))<div style="font-size:8.5pt;font-style:italic;color:#555">{{ $signataire['qualite'] }}</div>@endif
      </div>
    </div>

    <div class="pied">
      <div class="avis"><strong>Avis important —</strong> Il n'est délivré qu'une seule attestation. Le titulaire devra en faire établir des copies certifiées conformes par les autorités compétentes. Cette attestation ne doit comporter ni rature ni surcharge, sous peine de nullité.</div>
      @php $urlVerification = app(\App\Services\CleVerification::class)->url($numeros[$et->id]); @endphp
      <div class="qr-auth">
        <div data-qr="{{ $urlVerification }}" data-qr-cellule="2" style="width:11mm;height:11mm"></div>
        <span class="qr-txt">Scanner pour vérifier l'authenticité et comparer les informations affichées.<br>Site officiel : <strong>{{ \App\Services\CleVerification::site($urlVerification) }}</strong></span>
      </div>
      <div class="numero">N° {{ $numeros[$et->id] }}<br><span style="font-size:.85em">Code : {{ app(\App\Services\CleVerification::class)->code($numeros[$et->id]) }}</span></div>
    </div>

  </div>
</div>
@empty
<div class="page"><div class="content" style="text-align:center;padding-top:60mm;font-style:italic;color:#888">Aucun étudiant admis dans ce PV.</div></div>
@endforelse
<script src="{{ asset('assets/js/qrcode.js') }}"></script>
<script src="{{ asset('assets/js/impression.js') }}"></script>
</body>
</html>
