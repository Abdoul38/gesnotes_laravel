{{-- PV de délibération — portage de deliberation.php (page autonome A4) --}}
@php $taux = $stats["total"] ? round($stats["admis"] / $stats["total"] * 100, 1) : 0; @endphp
<!DOCTYPE html>
<html lang="fr">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width,initial-scale=1">
<title>PV Délibération — {{ $pv->libelle }}</title>
<style>
/* ── Reset ──────────────────────────────────────────────────── */
*{box-sizing:border-box;margin:0;padding:0}

/* ── Variables ──────────────────────────────────────────────── */
:root{
  --blue-dark:#1A3A5C;
  --blue-mid:#2C5F9E;
  --green:#1b5e20;
  --orange:#e65100;
  --red:#b71c1c;
  --bg-head:#EEF4FB;
  --bg-page:#d4d4d4;
  --font:'Times New Roman',Times,serif;
  --page-w:210mm;
  --page-h:297mm;
  --margin-v:14mm;
  --margin-h:16mm;
}

/* ── Corps écran ────────────────────────────────────────────── */
html{background:var(--bg-page)}
body{
  font-family:var(--font);
  font-size:10pt;
  color:#111;
  background:var(--bg-page);
  min-height:100vh;
}

/* ── Page A4 ────────────────────────────────────────────────── */
.page{
  width:var(--page-w);
  min-height:var(--page-h);
  background:#fff;
  margin:10mm auto;
  padding:var(--margin-v) var(--margin-h);
  box-shadow:0 4px 24px rgba(0,0,0,.25);
  position:relative;
}

/* ── Bouton impression ──────────────────────────────────────── */
.print-btn{
  position:fixed;top:14px;right:14px;z-index:999;
  background:var(--blue-dark);color:#fff;
  border:none;padding:9px 22px;border-radius:6px;
  cursor:pointer;font-size:13px;font-weight:700;
  box-shadow:0 2px 8px rgba(0,0,0,.3);
  display:flex;align-items:center;gap:6px;
}
.print-btn:hover{background:var(--blue-mid)}

/* ── En-tête ────────────────────────────────────────────────── */
.entete{
  display:flex;align-items:center;
  justify-content:space-between;
  gap:8px;
  padding-bottom:4mm;
  border-bottom:2.5px solid var(--blue-dark);
  margin-bottom:4mm;
}
.entete img{height:52px;max-width:52px;width:auto;object-fit:contain;flex-shrink:0}
.entete-center{flex:1;text-align:center;line-height:1.55;min-width:0;overflow:hidden}
.entete-rep{font-size:9pt;font-weight:bold;text-transform:uppercase;letter-spacing:.04em}
.entete-inst{font-size:8.5pt;font-style:italic}
.entete-unite{font-size:8pt;color:#333}

/* ── Titre PV ───────────────────────────────────────────────── */
.titre-pv{
  border:2px solid var(--blue-dark);
  border-radius:3px;
  background:var(--bg-head);
  text-align:center;
  padding:5mm 8mm;
  margin-bottom:5mm;
}
.titre-pv h1{
  font-size:14pt;font-weight:bold;
  text-transform:uppercase;
  color:var(--blue-dark);
  letter-spacing:.05em;
  margin-bottom:2mm;
}
.titre-pv .sous-titre{font-size:9.5pt;font-style:italic;color:#333;line-height:1.6}
.titre-pv .date-seance{
  display:inline-block;
  margin-top:2mm;
  font-size:9pt;
  border:1px solid #bbb;
  border-radius:2px;
  padding:1px 8px;
  background:#fff;
}

/* ── Titre de section ───────────────────────────────────────── */
.sec{
  font-size:10pt;font-weight:bold;text-transform:uppercase;
  color:var(--blue-dark);
  border-bottom:1.5px solid var(--blue-dark);
  padding-bottom:1.5px;
  margin:5mm 0 3mm;
  letter-spacing:.03em;
}

/* ── Grille stats ───────────────────────────────────────────── */
.stats{
  display:grid;
  grid-template-columns:repeat(6,1fr);
  gap:6px;
  margin-bottom:4mm;
}
.stat{
  border:1px solid #c8d8e8;
  border-radius:4px;
  padding:5px 4px;
  text-align:center;
  background:#fafcff;
}
.stat .v{font-size:17pt;font-weight:bold;line-height:1.1}
.stat .l{font-size:7pt;text-transform:uppercase;color:#666;margin-top:1px;letter-spacing:.03em}

/* ── Tableaux ───────────────────────────────────────────────── */
table.t{
  width:100%;border-collapse:collapse;
  font-size:8.5pt;margin-bottom:4mm;
}
table.t thead tr{background:var(--blue-dark)}
table.t th{
  color:#fff;padding:3px 5px;
  text-align:center;font-weight:600;
  border:0.5px solid rgba(255,255,255,.25);
  font-size:8pt;
}
table.t th.g{text-align:left}
table.t td{
  border:0.5px solid #bbb;
  padding:2.5px 5px;
  vertical-align:middle;
}
table.t tbody tr:nth-child(even){background:#f7f9fc}
table.t td.g{text-align:left}
table.t td.c{text-align:center}

/* Couleurs décisions */
.dec-admis{color:var(--green);font-weight:700}
.dec-ajourne{color:var(--orange);font-weight:700}
.dec-def{color:var(--red);font-weight:700}
.moy-ok{color:var(--green);font-weight:700}

/* ── Bande récapitulative sous tableau répartition ─────────── */
.recap-band{
  display:flex;border:1px solid #c8d8e8;border-radius:3px;
  overflow:hidden;margin-bottom:5mm;font-size:8.5pt;
}
.recap-band>div{
  flex:1;padding:4px 6px;text-align:center;border-right:1px solid #c8d8e8;
}
.recap-band>div:last-child{border-right:none}
.recap-band .rv{font-weight:700;font-size:10pt}
.recap-band .rl{font-size:7pt;text-transform:uppercase;color:#666}

/* ── Zone signature ─────────────────────────────────────────── */
.signatures{
  margin-top:10mm;
  display:flex;
  justify-content:center;
}
.sig-bloc{
  width:80mm;
  padding:5mm 8mm;
  text-align:center;
  border:1px solid #ccc;
  border-radius:3px;
}
.sig-titre{font-size:9.5pt;font-style:italic;color:#333;margin-bottom:18mm;font-weight:600}
.sig-ligne{
  border-top:1px solid #444;padding-top:3px;
  font-size:8pt;font-style:italic;color:#555;
}

/* ── Pied de page ───────────────────────────────────────────── */
.pied{
  margin-top:5mm;
  font-size:8pt;font-style:italic;
  text-align:right;color:#666;
  border-top:0.5px solid #ddd;
  padding-top:2mm;
}

/* ── Numéro de page (print only) ───────────────────────────── */
.page-num{display:none}

/* ═══════════════════════════════════════════════════════════
   IMPRESSION
═══════════════════════════════════════════════════════════ */
@media print{
  :root{--margin-v:12mm;--margin-h:14mm}
  html,body{background:#fff!important}
  .no-print{display:none!important}

  @page{
    size:A4 portrait;
    margin:var(--margin-v) var(--margin-h);
  }

  .page{
    width:100%;min-height:auto;
    margin:0;padding:0;
    box-shadow:none;
  }

  /* Logos : taille fixe, pas de débordement */
  .entete{display:flex!important;width:100%}
  .entete img{height:48px!important;max-width:48px!important;flex-shrink:0!important}
  .entete-center{flex:1;min-width:0;overflow:hidden}

  /* Sauts de page */
  .pb-before{page-break-before:always;padding-top:4mm}
  .no-break{page-break-inside:avoid}

  /* Tables : répéter l'en-tête sur chaque page */
  table.t{page-break-inside:auto}
  table.t thead{display:table-header-group}
  table.t tr{page-break-inside:avoid}

  /* Éviter coupure sur blocs critiques */
  .titre-pv,.signatures,.recap-band,.sec{page-break-inside:avoid}
  .stats{page-break-inside:avoid}
}
</style>
</head>
<body>
<button class="print-btn no-print" data-print="1">🖨️ Imprimer</button>

<div class="page">

  <div class="entete">
    <img src="{{ $logos['gauche'] }}" alt="Logo" data-onerror-hide="1">
    <div class="entete-center">
      <div class="entete-rep">{{ $republique }}</div>
      <div class="entete-inst">{{ $institution }}</div>
      <div class="entete-unite">{{ $faculte }}</div>
      <div class="entete-unite">{{ $departement }}</div>
    </div>
    <img src="{{ $logos['droite'] }}" alt="Logo" data-onerror-hide="1">
  </div>

  <div class="titre-pv no-break">
    <h1>Procès-Verbal de Délibération</h1>
    <div class="sous-titre">
      {{ $pv->niveau_libelle }} — {{ $pv->filiere }}<br>
      {{ $pv->sem_libelle }} · {{ \App\Support\Format::session($pv->type_session) }} · Année académique {{ $pv->annee }}
    </div>
    <div class="date-seance">
      Réuni en séance de délibération le @if ($pv->date_pv){{ \App\Support\Format::dateFR($pv->date_pv) }}@else{!! str_repeat('&nbsp;', 20) !!}@endif
    </div>
  </div>

  <div class="sec no-break">I. Statistiques générales</div>
  <div class="stats no-break">
    <div class="stat"><div class="v" style="color:var(--blue-dark)">{{ $stats['total'] }}</div><div class="l">Inscrits</div></div>
    <div class="stat"><div class="v" style="color:var(--green)">{{ $stats['admis'] }}</div><div class="l">Admis</div></div>
    <div class="stat"><div class="v" style="color:var(--orange)">{{ $stats['ajournes'] }}</div><div class="l">Ajournés</div></div>
    <div class="stat"><div class="v" style="color:var(--red)">{{ $stats['defaillants'] }}</div><div class="l">Défaillants</div></div>
    <div class="stat"><div class="v" style="color:var(--red)">{{ $stats['elimines'] }}</div><div class="l">Éliminés</div></div>
    <div class="stat"><div class="v" style="color:var(--blue-dark)">{{ $taux }}%</div><div class="l">Taux de réussite</div></div>
  </div>

  <div class="sec">II. Liste des étudiants admis
    <span style="font-weight:400;font-size:9pt;text-transform:none"> — {{ $stats['admis'] }} étudiant{{ $stats['admis'] > 1 ? 's' : '' }}</span>
  </div>
  <table class="t">
    <thead>
      <tr>
        <th style="width:5%">N°</th>
        <th class="g" style="width:22%">Nom</th>
        <th class="g" style="width:20%">Prénoms</th>
        <th style="width:11%">Date naiss.</th>
        <th class="g" style="width:14%">Lieu naiss.</th>
        <th style="width:9%">Moyenne</th>
        <th style="width:12%">Mention</th>
        <th style="width:7%">Crédits</th>
      </tr>
    </thead>
    <tbody>
    @forelse ($admisListe as $et)
    <tr class="no-break">
      <td class="c" style="color:#555">{{ $loop->iteration }}</td>
      <td class="g" style="font-weight:700;text-transform:uppercase">{{ $et->nom }}</td>
      <td class="g">{{ $et->prenoms }}</td>
      <td class="c">{{ $et->date_naissance ? date('d/m/Y', strtotime($et->date_naissance)) : '—' }}</td>
      <td class="g">{{ $et->lieu_naissance ?? '—' }}</td>
      <td class="c moy-ok">{{ number_format((float) $et->moyenne_generale, 2) }}</td>
      <td class="c" style="font-size:8pt;font-weight:600">{{ $et->mention }}</td>
      <td class="c">{{ $et->credits_obtenus }}</td>
    </tr>
    @empty
    <tr><td colspan="8" class="c" style="font-style:italic;color:#888;padding:6px">Aucun étudiant admis</td></tr>
    @endforelse
    </tbody>
  </table>

  <div class="sec">III. Signature du jury</div>
  <div class="signatures no-break">
    <div class="sig-bloc">
      <div class="sig-titre">Le Président du Jury</div>
      <div class="sig-ligne">{{ $president ?: 'Nom & Signature' }}</div>
    </div>
  </div>

  <div class="pied">
    Fait à {{ $ville }}, le @if ($pv->date_pv){{ \App\Support\Format::dateFR($pv->date_pv) }}@else{!! str_repeat('&nbsp;', 20) !!}@endif
    &nbsp;&nbsp;·&nbsp;&nbsp; {{ $pv->libelle }}
  </div>

</div>
<script src="{{ \App\Support\Ressource::url('assets/js/impression.js') }}"></script>
</body>
</html>
