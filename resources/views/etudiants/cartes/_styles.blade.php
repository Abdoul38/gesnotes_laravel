{{-- Styles des cartes : repris tels quels de l'ancien carte_etudiant.php (couleurs de l'université) --}}
*{box-sizing:border-box;margin:0;padding:0}
body{font-family:'Segoe UI','Helvetica Neue',Helvetica,Arial,sans-serif;background:#888;-webkit-print-color-adjust:exact;print-color-adjust:exact;padding:16px;display:flex;flex-direction:column;align-items:center;gap:12px}
.btn-bar{position:fixed;top:10px;right:10px;z-index:999;display:flex;gap:8px}
.btn-bar button,.btn-bar a{background:#1A3A5C;color:#fff;border:none;padding:8px 16px;border-radius:6px;cursor:pointer;font-size:13px;text-decoration:none;font-weight:600}
.spacer{height:52px}
@if ($modele === 'badge')
.carte{width:53.98mm;height:85.6mm;border-radius:3.18mm;overflow:hidden;position:relative;flex-shrink:0;box-shadow:0 4px 18px rgba(0,0,0,.4);display:flex;flex-direction:column;-webkit-print-color-adjust:exact;print-color-adjust:exact;border:.5px solid #ddd}
@else
.carte{width:85.6mm;height:53.98mm;border-radius:3.18mm;overflow:hidden;position:relative;flex-shrink:0;box-shadow:0 4px 18px rgba(0,0,0,.4);display:flex;flex-direction:column;-webkit-print-color-adjust:exact;print-color-adjust:exact}
@endif
/* Sur écran : les conteneurs de planche sont transparents (rendu inchangé) */
.planche,.slot{display:contents}
@media print{
    body{background:#fff;padding:0;display:block}
    .btn-bar,.spacer{display:none!important}
    .carte{box-shadow:none}
    /* Planche A4 : 10 cartes paysage (2×5) ou 9 cartes portrait (3×3), repères de coupe aux angles */
    .planche{display:grid;grid-template-columns:repeat(2,auto);justify-content:center;align-content:start}
    .planche.m-badge{grid-template-columns:repeat(3,auto)}
    .slot{display:block;padding:1.25mm;break-inside:avoid;page-break-inside:avoid;
        background:
        linear-gradient(#555,#555) 0 1.1mm/1.25mm .15mm no-repeat,
        linear-gradient(#555,#555) 100% 1.1mm/1.25mm .15mm no-repeat,
        linear-gradient(#555,#555) 0 calc(100% - 1.1mm)/1.25mm .15mm no-repeat,
        linear-gradient(#555,#555) 100% calc(100% - 1.1mm)/1.25mm .15mm no-repeat,
        linear-gradient(#555,#555) 1.1mm 0/.15mm 1.25mm no-repeat,
        linear-gradient(#555,#555) calc(100% - 1.1mm) 0/.15mm 1.25mm no-repeat,
        linear-gradient(#555,#555) 1.1mm 100%/.15mm 1.25mm no-repeat,
        linear-gradient(#555,#555) calc(100% - 1.1mm) 100%/.15mm 1.25mm no-repeat}
    @page{size:A4;margin:6mm 0}
}

@if ($modele === 'moderne')
/* ══════ MODÈLE MODERNE — CR80 85,6 × 53,98 mm : bleu institutionnel, liseré doré, guilloché ══════ */
.carte{border:.15mm solid #cfd6df;background:#fff}
.c-header{position:relative;overflow:hidden;background:linear-gradient(105deg,{{ $c['plusFonce'] }} 0%,{{ $c['marque'] }} 55%,{{ $c['clair'] }} 100%);padding:1.2mm 3mm;display:flex;align-items:center;gap:2.2mm;flex-shrink:0;z-index:1;border-bottom:.5mm solid #c9a24b}
.c-header::after{content:'';position:absolute;right:-7mm;top:-9mm;width:26mm;height:26mm;border-radius:50%;background:rgba(255,255,255,.05)}
.c-logo-box{width:9mm;height:9mm;background:#fff;border-radius:50%;padding:.5mm;flex-shrink:0;display:flex;align-items:center;justify-content:center;box-shadow:0 0 0 .3mm #c9a24b;position:relative;z-index:1}
.c-logo-box img{width:100%;height:100%;object-fit:contain;border-radius:50%}
.c-header-text{flex:1;color:#fff;line-height:1.2;min-width:0;position:relative;z-index:1}
.c-header-text .h1{font-size:5.6pt;font-weight:800;text-transform:uppercase;letter-spacing:.15px;line-height:1.15}
.c-header-text .h2{font-size:5.2pt;color:#e6d7a8;margin-top:.4mm;white-space:nowrap;overflow:hidden;text-overflow:ellipsis}
.c-badge{position:relative;z-index:1;border:.5px solid #c9a24b;background:rgba(201,162,75,.14);color:#f0d98a;font-size:5pt;font-weight:700;text-transform:uppercase;letter-spacing:.5px;padding:1mm 2.2mm;border-radius:3mm;flex-shrink:0;white-space:nowrap}
.c-body{flex:1;display:flex;padding:1.6mm 3mm;gap:2.2mm;position:relative;z-index:1;min-height:0;overflow:hidden;
  background:
   repeating-radial-gradient(circle at 105% -10%,transparent 0 1.1mm,rgba(26,58,92,.07) 1.1mm 1.22mm),
   repeating-radial-gradient(circle at -8% 110%,transparent 0 1.1mm,rgba(201,162,75,.16) 1.1mm 1.22mm),
   #fff}
.c-watermark{display:none}
@if ($filigrane)
.c-watermark{display:block;position:absolute;left:-3mm;top:50%;width:36mm;height:36mm;transform:translateY(-50%);background:url('{{ $filigrane }}') center/contain no-repeat;opacity:.06;z-index:0}
@endif
.c-infos{flex:1;min-width:0;position:relative;z-index:1;padding-left:2.6mm;display:flex;flex-direction:column;justify-content:space-between}
.c-infos::before{content:'';position:absolute;left:0;top:.3mm;bottom:.3mm;width:.5mm;border-radius:.3mm;background:linear-gradient(#c9a24b,#e6d7a8)}
.c-nom,.c-prenom,.c-ddn{position:relative}
.c-nom::before,.c-prenom::before,.c-ddn::before{display:block;font-size:4.8pt;color:#6b7684;text-transform:uppercase;letter-spacing:.3px;font-weight:400;line-height:1.4}
.c-nom::before{content:'Nom'}
.c-prenom::before{content:'Prénom'}
.c-ddn::before{content:'Né(e) le'}
.c-nom{font-size:9pt;font-weight:800;color:{{ $c['fonce'] }};text-transform:uppercase;letter-spacing:.2px;white-space:nowrap;overflow:hidden;text-overflow:ellipsis;line-height:1.2}
.c-prenom{font-size:7.5pt;font-weight:600;color:{{ $c['marque'] }};white-space:nowrap;overflow:hidden;text-overflow:ellipsis;line-height:1.2}
.c-ddn{font-size:6.3pt;font-weight:600;color:#333;line-height:1.2}
.c-grille{display:grid;grid-template-columns:1fr 1fr;gap:1mm 2mm;border-top:.25mm solid #e3d6ad;padding-top:1mm}
.c-champ{display:block;min-width:0}
.c-champ .lbl{display:block;font-size:4.8pt;color:#6b7684;text-transform:uppercase;letter-spacing:.3px;line-height:1.1}
.c-champ .val{display:block;font-size:7pt;font-weight:800;color:{{ $c['marque'] }};white-space:nowrap;overflow:hidden;text-overflow:ellipsis;line-height:1.25}
.c-champ .val.lg{font-size:7pt}
.c-champ.full{grid-column:1/-1}
.c-sep{display:none}
.c-right{position:relative;z-index:1;display:flex;flex-direction:column;align-items:center;flex-shrink:0;width:20mm;justify-content:flex-start}
.c-photo{width:20mm;height:24mm;border:.4mm solid #fff;outline:.25mm solid #c9a24b;border-radius:.8mm;box-shadow:0 .6mm 1.6mm rgba(11,34,64,.25);overflow:hidden;background:#eef1f5;display:flex;align-items:center;justify-content:center;flex-shrink:0}
.c-photo img{width:100%;height:100%;object-fit:cover}
.c-photo .np{font-size:16px;color:#c3cbd4;font-weight:800}
.c-sig-zone{top:18mm;left:-8.5mm}

@elseif ($modele === 'badge')
/* ══════ MODÈLE BADGE (vertical) — CR80 53,98 × 85,6 mm : même identité que le moderne ══════ */
.carte{background:#fff;flex-direction:column;border:.15mm solid #cfd6df;padding-bottom:1.6mm}
.carte::after{content:'';position:absolute;left:0;right:0;bottom:0;height:1.6mm;background:linear-gradient(90deg,#e05206 0 33.34%,#fff 33.34% 66.67%,#0db02b 66.67% 100%);box-shadow:0 -.2mm 0 #c9a24b;z-index:5}
.c-header{position:relative;overflow:hidden;background:linear-gradient(105deg,{{ $c['plusFonce'] }} 0%,{{ $c['marque'] }} 55%,{{ $c['clair'] }} 100%);padding:2.2mm 3mm 7mm;text-align:center;color:#fff;flex-direction:column;display:flex;align-items:center;gap:0;flex-shrink:0;border-bottom:.5mm solid #c9a24b}
.c-header::after{content:'';position:absolute;right:-8mm;top:-10mm;width:26mm;height:26mm;border-radius:50%;background:rgba(255,255,255,.05)}
.c-logo-box{width:8.4mm;height:8.4mm;background:#fff;border-radius:50%;padding:.4mm;margin-bottom:1mm;display:flex;align-items:center;justify-content:center;box-shadow:0 0 0 .3mm #c9a24b;position:relative;z-index:1}
.c-logo-box img{width:100%;height:100%;object-fit:contain;border-radius:50%}
.c-header-text{position:relative;z-index:1}
.c-header-text .h1{font-size:4.8pt;font-weight:800;text-transform:uppercase;letter-spacing:.25px;line-height:1.15}
.c-header-text .h2{font-size:4.4pt;color:#e6d7a8;margin-top:.3mm}
.c-badge{display:block;position:relative;z-index:1;margin-top:1mm;border:.4px solid #c9a24b;background:rgba(201,162,75,.14);color:#f0d98a;font-size:4.3pt;font-weight:700;text-transform:uppercase;letter-spacing:.5px;padding:.6mm 2mm;border-radius:2mm}
.c-body{flex:1;display:flex;flex-direction:column;padding:0 4mm 1.5mm;gap:0;position:relative;z-index:1;min-height:0;
  background:
   repeating-radial-gradient(circle at 105% -10%,transparent 0 1.1mm,rgba(26,58,92,.05) 1.1mm 1.22mm),
   repeating-radial-gradient(circle at -8% 110%,transparent 0 1.1mm,rgba(201,162,75,.12) 1.1mm 1.22mm),
   #fff}
.c-watermark{display:none}
@if ($filigrane)
.c-watermark{display:block;position:absolute;left:10mm;top:50%;width:34mm;height:34mm;transform:translateY(-50%);background:url('{{ $filigrane }}') center/contain no-repeat;opacity:.05;z-index:0}
@endif
.c-right{order:1;display:flex;justify-content:center;width:100%;margin-top:-6mm;position:relative;z-index:3}
.c-photo{width:17mm;height:21mm;border:.4mm solid #fff;outline:.25mm solid #c9a24b;border-radius:.8mm;box-shadow:0 .6mm 1.6mm rgba(11,34,64,.25);overflow:hidden;background:#e2e8ee;display:flex;align-items:center;justify-content:center}
.c-photo img{width:100%;height:100%;object-fit:cover}
.c-photo .np{font-size:26px;color:#c3cbd4}
.c-infos{order:2;text-align:center;padding-top:1.5mm;flex:1;display:flex;flex-direction:column;justify-content:space-between;position:relative;z-index:1;min-height:0}
.c-infos>*{flex-shrink:0}
.c-nom{font-size:8.5pt;font-weight:800;color:{{ $c['fonce'] }};text-transform:uppercase;letter-spacing:.2px;line-height:1.15;white-space:nowrap;overflow:hidden;text-overflow:ellipsis}
.c-prenom{font-size:6.5pt;font-weight:600;color:{{ $c['marque'] }};margin-top:.3mm;white-space:nowrap;overflow:hidden;text-overflow:ellipsis}
.c-ddn{display:block;font-size:5pt;color:#6b7684;margin-top:.5mm}
.c-grille{display:grid;grid-template-columns:.85fr 1.1fr 1.1fr;gap:1.1mm 1.5mm;margin-top:1.2mm;border-top:.25mm solid #e3d6ad;padding-top:1.2mm;text-align:left}
.c-champ{display:block;min-width:0}
.c-champ .lbl{display:block;font-size:4.4pt;color:#6b7684;text-transform:uppercase;letter-spacing:.35px;line-height:1.1}
.c-champ .val{display:block;font-size:5.6pt;font-weight:800;color:{{ $c['marque'] }};white-space:nowrap;overflow:hidden;text-overflow:ellipsis;line-height:1.25}
.c-champ.full{grid-column:1/-1}
.f-mat{display:none}
.f-niv{order:1}.f-an{order:2}.c-champ.f-nat{order:3;grid-column:auto}.f-fil{order:4}
.c-sep{display:none}
.c-sig-zone{display:none}
.c-footer{background:#f4f6f9;padding:1.2mm 3mm;display:flex;justify-content:center;align-items:center;gap:3mm;border-top:.3mm solid #c9a24b;flex-shrink:0;position:relative;z-index:1}
.c-footer .mat{font-family:'Courier New',monospace;font-size:5.5pt;font-weight:700;color:{{ $c['marque'] }};letter-spacing:.8px}
.c-footer .annee{display:none}
.c-footer .validite{display:none}
.c-footer .c-qr{width:7mm;height:7mm}
.c-footer .c-qr svg{width:100%;height:100%;display:block}
.c-auth{margin-top:1.3mm;text-align:center}
.au-titre{font-size:4.8pt;font-weight:700;color:{{ $c['marque'] }};line-height:1.25;white-space:nowrap}
.au-zone{height:4.6mm;border:.2mm solid #b4bcc8;border-radius:.8mm;position:relative;margin:0 4mm;background:rgba(255,255,255,.7)}
.au-zone img{position:absolute;left:.4mm;top:.4mm;width:calc(100% - .8mm);height:calc(100% - .8mm);object-fit:contain;mix-blend-mode:multiply}

@elseif ($modele === 'administratif')
/* ══════ MODÈLE ADMINISTRATIF (fidèle UAM/Tahoua) — CR80 85,6 × 53,98 mm, même identité visuelle ══════ */
.carte{background:#fff;border-radius:3.18mm;border:.15mm solid #cfd6df}
.a-topband{background:linear-gradient(105deg,{{ $c['plusFonce'] }} 0%,{{ $c['marque'] }} 55%,{{ $c['clair'] }} 100%);padding:.7mm 3mm;text-align:center;flex-shrink:0;border-bottom:.4mm solid #c9a24b}
.a-topband .u1{font-size:6.2pt;font-weight:800;color:#fff;text-transform:uppercase;letter-spacing:.9px}
.c-header{padding:.8mm 3mm .4mm;display:flex;align-items:center;gap:2mm;flex-shrink:0}
.c-logo-box{width:8.5mm;height:8.5mm;flex-shrink:0;display:flex;align-items:center;justify-content:center}
.c-logo-box img{width:100%;height:100%;object-fit:contain}
.c-header-text{flex:1;line-height:1.2}
.c-header-text .h1{font-size:6.2pt;font-weight:800;color:{{ $c['fonce'] }}}
.c-header-text .h2{font-size:5.3pt;font-weight:600;color:#6b7684;margin-top:.2mm}
.c-badge{display:none}
.a-titre{text-align:center;font-size:7pt;font-weight:800;color:{{ $c['fonce'] }};text-transform:uppercase;letter-spacing:.4px;padding:.4mm 0;border-top:.25mm solid #c9a24b;border-bottom:.25mm solid #c9a24b;margin:0 3mm;flex-shrink:0}
.c-body{flex:1;display:flex;padding:1.3mm 3mm 1mm;gap:1.8mm;position:relative;min-height:0;
  background:
   repeating-radial-gradient(circle at 105% -10%,transparent 0 1.1mm,rgba(26,58,92,.07) 1.1mm 1.22mm),
   repeating-radial-gradient(circle at -8% 110%,transparent 0 1.1mm,rgba(201,162,75,.16) 1.1mm 1.22mm),
   #fff}
.c-watermark{display:none}
@if ($filigrane)
.c-watermark{display:block;position:absolute;left:-2mm;top:50%;width:30mm;height:30mm;transform:translateY(-50%);background:url('{{ $filigrane }}') center/contain no-repeat;opacity:.06;z-index:0}
@endif
.c-infos{flex:1;display:flex;flex-direction:column;justify-content:center;gap:.7mm;min-width:0;position:relative;z-index:1}
.c-nom,.c-prenom,.c-ddn{font-size:7.4pt;font-weight:600;color:#111;white-space:nowrap;overflow:hidden;text-overflow:ellipsis}
.c-nom::before{content:'Nom : ';font-weight:800;color:{{ $c['marque'] }}}
.c-prenom::before{content:'Prénom : ';font-weight:800;color:{{ $c['marque'] }}}
.c-ddn::before{content:'Né(e) le : ';font-weight:800;color:{{ $c['marque'] }}}
.c-grille{display:flex;flex-wrap:wrap;gap:.5mm 3mm;margin-top:0}
.c-champ{font-size:7.4pt;color:#111;white-space:nowrap;min-width:0}
.c-champ .lbl{font-weight:800;color:{{ $c['marque'] }}}
.c-champ .lbl::after{content:' : '}
.c-champ .val{font-weight:600}
.c-champ.full{flex-basis:100%;order:-1;overflow:hidden;text-overflow:ellipsis}
.f-an{display:none}
.f-niv{order:1}.f-mat{order:2}
.c-sep{display:none}
.c-right{display:flex;flex-direction:column;align-items:center;flex-shrink:0;width:18mm;position:relative;z-index:1}
.c-photo{width:16mm;height:19.5mm;border:.4mm solid #fff;outline:.25mm solid #c9a24b;box-shadow:0 .5mm 1.4mm rgba(11,34,64,.25);overflow:hidden;background:#eef1f5;display:flex;align-items:center;justify-content:center}
.c-photo img{width:100%;height:100%;object-fit:cover}
.c-photo .np{font-size:16px;color:#ccc}
.c-sig-zone{top:13.5mm;left:-8mm}
.au-zone{height:5.4mm}
@endif
@if ($modele !== 'badge')
/* ══════ Commun moderne + administratif : bande du recto, cachet, verso ══════ */
.c-bande{flex-shrink:0;position:relative;z-index:2;display:flex;justify-content:space-between;align-items:center;padding:1.1mm 3mm;background:linear-gradient(105deg,{{ $c['plusFonce'] }} 0%,{{ $c['marque'] }} 55%,{{ $c['clair'] }} 100%);border-top:.4mm solid #c9a24b;color:#fff;font-size:5.4pt;letter-spacing:.5px}
.cb-num{font-family:'Courier New',monospace;font-weight:700;color:#f0d98a}
.cb-val{text-transform:uppercase;font-weight:600}
.c-sig-zone{position:absolute;left:-5mm;width:auto;display:block}
.c-auth{width:100%;margin-top:.6mm;text-align:center}
.au-titre{font-size:5pt;font-weight:700;color:{{ $c['marque'] }};line-height:1.25;white-space:nowrap}
.au-zone{height:6mm;border:.2mm solid #b4bcc8;border-radius:.8mm;position:relative;background:rgba(255,255,255,.7)}
.au-zone img{position:absolute;left:.4mm;top:.4mm;width:calc(100% - .8mm);height:calc(100% - .8mm);object-fit:contain;mix-blend-mode:multiply}
.c-cachet{width:11mm;height:11mm;opacity:.78;transform:rotate(-8deg)}
.c-cachet img{width:100%;height:100%;object-fit:contain}
.carte.verso{background:#fff}
.v-head{flex-shrink:0;background:linear-gradient(105deg,{{ $c['plusFonce'] }} 0%,{{ $c['marque'] }} 55%,{{ $c['clair'] }} 100%);color:#fff;padding:1.3mm 3mm;border-bottom:.4mm solid #c9a24b;display:flex;justify-content:space-between;align-items:center;gap:2mm}
.v-inst{font-size:5.6pt;font-weight:800;text-transform:uppercase;letter-spacing:.3px;white-space:nowrap;overflow:hidden;text-overflow:ellipsis}
.v-titre{font-size:5pt;color:#f0d98a;font-weight:700;text-transform:uppercase;letter-spacing:.5px;white-space:nowrap;flex-shrink:0}
.v-body{flex:1;display:flex;gap:3mm;padding:2mm 3mm;position:relative;min-height:0;overflow:hidden;
  background:
   repeating-radial-gradient(circle at 105% -10%,transparent 0 1.1mm,rgba(26,58,92,.07) 1.1mm 1.22mm),
   repeating-radial-gradient(circle at -8% 110%,transparent 0 1.1mm,rgba(201,162,75,.16) 1.1mm 1.22mm),
   #fff}
.v-left{flex:1;min-width:0;display:flex;flex-direction:column;justify-content:space-between;position:relative;z-index:1}
.v-mention{font-size:5.8pt;line-height:1.4;color:#333;margin:0}
.v-contact{font-size:5.6pt;line-height:1.35;color:{{ $c['marque'] }};font-weight:700;margin:1.2mm 0 0}
.v-sign{border:.25mm dashed #aeb7c2;border-radius:.8mm;height:11mm;position:relative;background:rgba(255,255,255,.75)}
.v-sign span{position:absolute;left:1.2mm;top:.6mm;font-size:4.8pt;color:#6b7684;text-transform:uppercase;letter-spacing:.4px}
.v-right{width:21mm;flex-shrink:0;display:flex;flex-direction:column;align-items:center;justify-content:center;gap:1mm;position:relative;z-index:1}
.v-qr{width:19mm;height:19mm}
.v-qr svg{width:100%;height:100%;display:block}
.v-cap{font-size:4.8pt;line-height:1.25;color:#6b7684;text-align:center}
.v-foot{flex-shrink:0;background:#fff;border-top:.2mm solid #c9a24b;padding:.6mm 3mm;display:flex;justify-content:space-between;align-items:center;gap:2mm;position:relative;z-index:2}
.c-bc-left{display:flex;flex-direction:column;align-items:center;flex:none}
.c-bc-bars{width:44mm;height:5mm}
.c-bc-bars svg{width:100%;height:100%;display:block}
.c-bc-txt{font-family:'Courier New',monospace;font-size:5pt;font-weight:700;letter-spacing:1.4px;color:{{ $c['fonce'] }};line-height:1;margin-top:.4mm}
.v-valid{font-size:5.4pt;line-height:1.4;color:#333;text-align:right;white-space:nowrap}
.v-valid strong{color:{{ $c['marque'] }}}
@endif
