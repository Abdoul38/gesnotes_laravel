{{-- Relevé de notes — portage de releve_notes.php (page autonome, une page A4 par étudiant) --}}
<!DOCTYPE html>
<html lang="fr">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width,initial-scale=1">
<title>Relevé — {{ $masse ? 'Tous les validés' : $releves->first()['et']->nom }}</title>
<style>
* { -webkit-print-color-adjust: exact !important; print-color-adjust: exact !important; color-adjust: exact !important; }

*{box-sizing:border-box;margin:0;padding:0}
html,body{background:#e8e5df;font-family:Georgia,'Times New Roman',Times,serif}

/* Une page = un relevé */
.releve{
  width:210mm;
  min-height:297mm;
  background:#fff;
  margin:8mm auto;
  padding:10mm 14mm;
  font-size:9.5pt;
  color:#2C2C2C;
  page-break-after:always;
  display:flex;
  flex-direction:column;
  position:relative;
  box-shadow:0 4px 18px rgba(0,0,0,.15);
}
.releve:last-child{page-break-after:auto}

/* Cadre fin, cohérent avec les autres documents officiels */
.releve::before{
  content:'';
  position:absolute; inset:4mm;
  border:0.75pt solid #B8860B;
  pointer-events:none;
}
.releve::after{
  content:'';
  position:absolute; inset:5.5mm;
  border:0.5pt solid #0F2942;
  pointer-events:none;
}
.releve-inner{position:relative; z-index:1; display:flex; flex-direction:column; flex:1; padding:2mm 3mm}

@media print{
  html,body{background:#fff;margin:0;padding:0}
  .no-print{display:none!important}
  .releve{
    margin:0;
    width:210mm;
    min-height:297mm;
    page-break-after:always;
    box-shadow:none;
    padding:9mm 13mm;
    display:flex;
    flex-direction:column;
  }
  @page{size:A4 portrait;margin:0}
}

.divider{border-top:0.75pt solid #B8860B; margin:2.5mm 0}
.titre{
  border:0.75pt solid #0F2942;
  padding:2.5mm 4mm; text-align:center; margin:2.5mm 0; line-height:1.4;
  border-radius:2px;
}
.titre .t1{font-family:'Palatino Linotype','Book Antiqua',Georgia,serif; font-weight:bold; font-size:11.5pt; text-transform:uppercase; letter-spacing:.5px; color:#0F2942}
.titre .t2{font-size:9pt; font-style:italic; color:#8B6F1F; margin-top:.5mm}
.titre .t3{font-size:8.5pt; color:#0F2942; margin-top:.8mm; letter-spacing:.3px}
.et-info{font-size:9.5pt; line-height:1.6; margin:2mm 0}
.et-info strong{font-weight:bold; color:#0F2942}

table.tn{width:100%; border-collapse:collapse; font-size:8.8pt; margin:2mm 0}
table.tn th{
  background:#0F2942; color:#fff; font-weight:bold;
  border:1pt solid #0F2942; padding:2.2mm 1.5mm; text-align:center;
  font-size:8pt; text-transform:uppercase; letter-spacing:.2px;
}
table.tn th.left{text-align:left}
table.tn td{border:0.5pt solid #d8cfb8; padding:1.8mm 1.5mm; vertical-align:middle}
table.tn td.c{text-align:center}
table.tn .ue-row td{
  background:#d0d0d0; color:#1a1a1a; font-weight:bold; font-size:8.5pt;
  padding:1.8mm 1.5mm; letter-spacing:.2px;
}
table.tn .av{color:#166534; font-weight:bold; text-align:center}
table.tn .ac{color:#1e40af; font-weight:bold; text-align:center}
table.tn .nv{color:#b91c1c; font-weight:bold; text-align:center}

.resultat{
  font-size:9.5pt; font-weight:bold; line-height:1.5; margin:2.5mm 0;
  border-top:0.75pt solid #B8860B; padding-top:2mm; color:#0F2942;
}
.footer{margin-top:3mm}
.footer-main{display:flex; justify-content:space-between; align-items:flex-start; font-size:9pt}
.legende ul{margin:0; padding:0; list-style:none; line-height:1.6}
.legende li::before{content:"● "; font-size:6pt; color:#B8860B}
.sig-zone{text-align:right; font-size:9pt}
.sig-zone .lieu{font-style:italic; color:#555; margin-bottom:2mm}
.sig-zone .titre-sig{font-style:italic; color:#8B6F1F}
.sig-zone .nom-sig{font-weight:bold; font-size:9.5pt; margin-top:22mm; color:#0F2942}
.nb{font-size:7.3pt; border-top:0.5pt solid #d8cfb8; padding-top:2mm; margin-top:2.5mm; line-height:1.4; font-style:italic; color:#8a8a8a}

/* ── Compacité adaptée au nombre de matières (voir $nbLignesTableau) ──
   Chaque palier réduit proportionnellement tout ce qui prend de la place
   verticale — pas seulement le tableau — pour que le relevé tienne
   toujours sur une seule page A4, même pour une filière chargée. */
.releve.compact .titre{padding:1.8mm 4mm; margin:1.5mm 0}
.releve.compact .titre .t1{font-size:10pt}
.releve.compact .titre .t2,.releve.compact .titre .t3{font-size:8pt}
.releve.compact .et-info{font-size:8.5pt; line-height:1.4; margin:1.3mm 0}
.releve.compact table.tn{font-size:7.6pt; margin:1.3mm 0}
.releve.compact table.tn th{padding:1.3mm 1.2mm; font-size:7pt}
.releve.compact table.tn td{padding:1mm 1.2mm}
.releve.compact table.tn .ue-row td{padding:1mm 1.2mm; font-size:7.4pt}
.releve.compact .resultat{font-size:8.5pt; margin:1.5mm 0; padding-top:1.3mm}
.releve.compact .footer-main{font-size:8pt}
.releve.compact .sig-zone .nom-sig{margin-top:14mm}
.releve.compact .nb{font-size:6.5pt; padding-top:1.3mm; margin-top:1.5mm}

.releve.tres-compact .titre{padding:1.2mm 3mm; margin:1mm 0}
.releve.tres-compact .titre .t1{font-size:9pt}
.releve.tres-compact .titre .t2,.releve.tres-compact .titre .t3{font-size:7pt}
.releve.tres-compact .et-info{font-size:7.5pt; line-height:1.25; margin:1mm 0}
.releve.tres-compact table.tn{font-size:6.6pt; margin:1mm 0}
.releve.tres-compact table.tn th{padding:0.9mm 1mm; font-size:6.2pt}
.releve.tres-compact table.tn td{padding:0.6mm 1mm}
.releve.tres-compact table.tn .ue-row td{padding:0.6mm 1mm; font-size:6.4pt}
.releve.tres-compact .resultat{font-size:7.5pt; margin:1mm 0; padding-top:1mm}
.releve.tres-compact .footer-main{font-size:7pt}
.releve.tres-compact .sig-zone .nom-sig{margin-top:9mm}
.releve.tres-compact .nb{font-size:5.8pt; padding-top:1mm; margin-top:1mm}

/* Barre d'actions */
.actions-bar{
  position:fixed;top:0;left:0;right:0;z-index:999;
  background:#0F2942;color:#fff;
  padding:8px 20px;
  display:flex;align-items:center;justify-content:space-between;
  gap:12px;font-size:13px;
}
.actions-bar button,.actions-bar a{
  background:#fff;color:#0F2942;border:none;
  padding:6px 16px;border-radius:5px;cursor:pointer;
  font-size:12px;font-weight:600;text-decoration:none;
  display:inline-flex;align-items:center;gap:5px
}
.actions-bar a{background:rgba(255,255,255,.15);color:#fff;border:1px solid rgba(255,255,255,.4)}
.spacer{height:44px}
</style>
</head>
<body>

<div class="actions-bar no-print">
  <span>
    @if ($masse)
      📋 {{ $releves->count() }} relevés — validés du PV
    @else
      📄 Relevé de notes — {{ $releves->first()['et']->nom }} {{ $releves->first()['et']->prenoms }}
    @endif
  </span>
  <div style="display:flex;gap:8px">
    @if (! $masse && auth()->user()->role !== 'etudiant')
    <a href="{{ route('releve_notes', ['pv_id' => $pv->id, 'masse' => 1]) }}">📋 Générer tous les validés</a>
    @endif
    <button type="button" data-print="1">🖨️ Imprimer{{ $masse ? ' tous ('.$releves->count().')' : '' }}</button>
    <a href="#" data-history-back="1">✕ Fermer</a>
  </div>
</div>
<div class="spacer no-print"></div>

@if ($releves->isEmpty())
<div class="releve"><div class="releve-inner" style="text-align:center;padding-top:40mm;color:#888;font-style:italic">Aucun étudiant validé dans ce PV.</div></div>
@endif

@foreach ($releves as $r)
@php $et = $r['et']; @endphp
<div class="releve {{ $r['taille'] }}">
<div class="releve-inner">

  <div class="releve-body">
  <div style="display:flex;align-items:center;justify-content:space-between;margin-bottom:3px">
    <img src="{{ $logos['gauche'] }}" alt="" style="height:80px;width:auto" data-onerror-hide="1">
    <div style="flex:1;text-align:center;padding:0 8px">
      <div style="font-weight:bold;font-size:9pt;text-transform:uppercase">{{ $republique }}</div>
      <div style="font-size:8.5pt;font-style:italic;line-height:1.5">{{ $institution }}</div>
      <div style="font-size:8.5pt;font-style:italic;line-height:1.5">{{ $faculte }}</div>
      <div style="font-size:8.5pt;font-style:italic;line-height:1.5">{{ $departement }}</div>
      <div style="font-size:8.5pt;font-style:italic;line-height:1.5">{{ $bp }}</div>
    </div>
    <img src="{{ $logos['droite'] }}" alt="" style="height:80px;width:auto" data-onerror-hide="1">
  </div>

  <div class="divider"></div>

  <div class="titre">
    <div class="t1">ANNEE ACADEMIQUE {{ $pv->annee }}</div>
    <div class="t1">RELEVE DE NOTES</div>
    <div class="t2">{{ $pv->niveau_libelle }} {{ $pv->filiere }} — {{ $pv->sem_libelle }} / {{ \App\Support\Format::session($pv->type_session) }}</div>
    <div class="t3">Relevé enregistré sous le N° <strong>{{ $r['numero'] }}</strong> · Code de vérification : <strong>{{ app(\App\Services\CleVerification::class)->code($r['numero']) }}</strong></div>
  </div>

  <div class="et-info">
    <strong>MATRICULE :</strong> {{ $et->numero }} &nbsp;&nbsp;
    <strong>NOM :</strong> {{ $et->nom }} &nbsp;&nbsp;
    <strong>PRENOMS :</strong> {{ $et->prenoms }}<br>
    <strong>Né(e) le :</strong> {{ \App\Support\Format::dateFR($et->date_naissance) }}
    @if (! empty($et->lieu_naissance)) à {{ $et->lieu_naissance }} @endif
  </div>

  <table class="tn">
    <thead>
      <tr>
        <th class="left" style="width:40%">Matière</th>
        <th style="width:7%">Coéf.</th>
        <th style="width:11%">Examen</th>
        <th style="width:9%">TD</th>
        <th style="width:11%">N.Finale</th>
        <th style="width:9%">Crédits</th>
        <th style="width:13%">Validation</th>
      </tr>
    </thead>
    <tbody>
    @foreach ($r['lignes'] as $l)
      <tr class="ue-row"><td colspan="7">{{ $l['ue']->code }} : {{ $l['ue']->libelle }}</td></tr>
      @foreach ($l['matieres'] as $ligne)
      @php $m = $ligne['m']; $n = $ligne['n']; @endphp
      <tr>
        <td>{{ $m->libelle }}</td>
        <td class="c">{{ number_format((float) ($m->coefficient ?? 1), 0) }}</td>
        <td class="c">{{ $n && $n->note_examen !== null ? number_format((float) $n->note_examen, 2) : '—' }}</td>
        <td class="c">{{ $m->note_max_cc > 0 ? ($n && $n->note_cc !== null ? number_format((float) $n->note_cc, 2) : '—') : '-' }}</td>
        <td class="c">{{ $ligne['finale'] !== null ? number_format((float) $ligne['finale'], 2) : '—' }}</td>
        <td class="c">{{ $ligne['credits'] }}</td>
        <td class="{{ $ligne['obs'] === 'AV' ? 'av' : ($ligne['obs'] === 'AC' ? 'ac' : 'nv') }}">{{ $ligne['obs'] }}</td>
      </tr>
      @endforeach
    @endforeach
    </tbody>
  </table>

  <div class="resultat">
    MOYENNE GENERALE : <span style="font-size:10pt">{{ $et->moyenne_generale !== null ? number_format((float) $et->moyenne_generale, 2) : '—' }}/20</span>
    &nbsp;&nbsp;&nbsp; MENTION : {{ $et->mention ?: '—' }}<br>
    RESULTAT DU SEMESTRE (VALIDATION) : {{ $et->valide ? 'OUI' : 'NON' }}<br>
    Nombre de crédits obtenus au {{ $pv->semestre }} : {{ (int) $et->credits_obtenus }}
  </div>

  </div>

  <div class="footer-main" style="margin-top:10px">
    <div class="legende">
      <ul>
        <li><strong>AV</strong> : Acquis par Validation</li>
        <li><strong>AC</strong> : Acquis par Compensation</li>
        <li><strong>NV</strong> : Non Validé</li>
      </ul>
      <div style="display:flex;align-items:center;gap:2mm;margin-top:2mm">
        <div data-qr="{{ $r['urlVerification'] }}" data-qr-cellule="2" style="width:11mm;height:11mm"></div>
        <span style="font-size:6.5pt;color:#8B6F1F;line-height:1.2">Scanner pour vérifier l'authenticité<br>et comparer les notes affichées.<br>Site officiel : <strong>{{ \App\Services\CleVerification::site($r['urlVerification']) }}</strong><br>N° {{ $r['numero'] }} · Code {{ app(\App\Services\CleVerification::class)->code($r['numero']) }}</span>
      </div>
    </div>
    <div class="sig-zone">
      <div class="lieu">Fait à {{ $ville }}, le {{ $pv->date_pv ? \App\Support\Format::dateFR($pv->date_pv) : date('d/m/Y') }}</div>
      <div class="titre-sig">{{ $signataire['titre'] }}</div>
      <div class="nom-sig">{{ $signataire['nom'] }}</div>
      @if (! empty($signataire['qualite']))<div style="font-size:8pt">{{ $signataire['qualite'] }}</div>@endif
    </div>
  </div>

  <div style="flex:1"></div>

  <div class="nb">
    NB : Il n'est délivré qu'un seul relevé de notes : il appartient au titulaire d'en faire établir des copies certifiées conformes.
    Ce relevé ne doit comporter ni rature, ni surcharge sous peine de nullité.
  </div>

</div>
</div>
@endforeach

<script src="{{ \App\Support\Ressource::url('assets/js/qrcode.js') }}"></script>
<script src="{{ \App\Support\Ressource::url('assets/js/impression.js') }}"></script>
</body>
</html>
