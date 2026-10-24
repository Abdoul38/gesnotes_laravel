{{-- PV imprimable — portage de pv_imprimer.php (page autonome, A4 ou A3 paysage ; tableau réduit pour tenir dans la largeur du papier) --}}
@php
    use App\Support\Format;
    $f2 = fn ($v) => $v === null ? '—' : number_format((float) $v, 2);
    $classeObs = fn ($o) => ['AV' => 'obs-av', 'AC' => 'obs-ac', 'NV' => 'obs-nv', 'Déf.' => 'obs-nv', 'Elim' => 'obs-nv'][$o] ?? '';
    $creditsTotal = $ues->sum('credits_total');
    // Largeur utile du papier en paysage : largeur − marges de page (2 × 8 mm) − marges du contenu (2 × 6 mm)
    $largeurPapierMm = $format === 'a3' ? 420 - 16 - 12 : 297 - 16 - 12;
@endphp
<!DOCTYPE html>
<html lang="fr">
<head>
<meta charset="UTF-8">
<title>PV — {{ $pv->libelle }}</title>
<style>
*{box-sizing:border-box;margin:0;padding:0}
body{font-family:'Times New Roman',Times,serif;font-size:10px;background:#fff;color:#000}
.page{width:100%;padding:8mm 6mm}
.header-2logos{display:flex;align-items:center;justify-content:space-between;margin-bottom:5px;min-height:28mm}
.header-2logos img{height:26mm;width:26mm;object-fit:contain;flex-shrink:0;display:block}
table.pv{width:100%;border-collapse:collapse;margin-top:8px;font-size:8px}
table.pv th,table.pv td{border:0.5px solid #666;padding:1.5px 3px;text-align:center;vertical-align:middle}
table.pv th{font-weight:600}
.th-ue{background:#1a3a5c;color:#fff;font-size:7.5px}
.th-mat{background:#2c5f9e;color:#fff;font-size:7px}
.pv-zone{max-width:none}
.pv-zone table.pv + table.pv{margin-top:6mm}
/* Tailles de l'impression, appliquées aussi pendant la mesure qui précède l'impression (pv-impression.js) */
body.mesure-impression table.pv th,body.mesure-impression table.pv td{font-size:7px !important;padding:1px 2px !important}
body.mesure-impression .td-name{min-width:90px !important}
.th-sub{background:#dce9f7;color:#000;font-size:7px}
.th-resume{background:#0c3360;color:#fff;font-size:7px}
.td-name{text-align:left !important;font-weight:bold;min-width:120px;white-space:nowrap}
.td-num{font-size:7px;color:#555}
.obs-av{color:#1b5e20;font-weight:bold}
.obs-ac{color:#0d47a1;font-weight:bold}
.obs-nv{color:#e65100}
.tr-admit td{background:#e8f5e9 !important}
.tr-ajourne td{background:#fff8e1 !important}
.tr-defaillant td{background:#ffebee !important}
.moy-ok{color:#1b5e20;font-weight:bold}
.moy-ko{color:#e65100}
.pied-page{display:flex;justify-content:space-between;border-top:1px solid #000;padding:6px 8mm;margin-top:10px;font-size:8px;font-family:'Times New Roman',Times,serif}
.pied-page > div{flex:1;text-align:center;padding:0 8px}
.pied-page > div + div{border-left:0.5px solid #ccc}
@media print{
  .no-print{display:none !important}
  .header-2logos{min-height:28mm !important;page-break-inside:avoid}
  .header-2logos img{height:26mm !important;width:26mm !important;max-height:26mm !important;max-width:26mm !important;object-fit:contain !important;display:block !important;flex-shrink:0 !important;-webkit-print-color-adjust:exact;print-color-adjust:exact}
  table.pv th,table.pv td{font-size:7px !important;padding:1px 2px !important}
  .td-name{min-width:90px !important}
  table.pv thead{display:table-header-group}
  .pied-page{position:fixed;bottom:0;left:0;right:0;background:#fff}
  .page{padding-bottom:25mm}
  @page{size:{{ $format === 'a3' ? 'A3' : 'A4' }} landscape;margin:8mm}
}
</style>
</head>
<body>
<div class="page">

<div class="no-print" style="padding:6px 10px;background:#185FA5;color:#fff;margin-bottom:8px;display:flex;gap:8px;justify-content:flex-end;align-items:center">
  <form method="get" action="{{ route('pv_imprimer') }}" style="display:flex;gap:6px;align-items:center;margin-right:auto">
    <input type="hidden" name="id" value="{{ $pv->id }}">
    <label for="et_id" style="font-size:13px">👤 Étudiant :</label>
    <select name="et_id" id="et_id" data-autosubmit="1" style="padding:4px 8px;border-radius:4px;border:none;font-size:13px">
      <option value="0">— Tous les étudiants —</option>
      @foreach ($tous as $e)<option value="{{ $e->id }}" @selected($filtre === (int) $e->id)>{{ $e->nom }} {{ $e->prenoms }}</option>@endforeach
    </select>
    <label for="format" style="font-size:13px;margin-left:10px">📄 Papier :</label>
    <select name="format" id="format" data-autosubmit="1" style="padding:4px 8px;border-radius:4px;border:none;font-size:13px">
      <option value="a4" @selected($format === 'a4')>A4 paysage</option>
      <option value="a3" @selected($format === 'a3')>A3 paysage</option>
    </select>
    <span style="font-size:12px;opacity:.9;margin-left:6px" title="Le tableau est réduit pour tenir dans la largeur du papier">Échelle : <strong id="pv-echelle">100 %</strong></span>
  </form>
  <button type="button" data-print="1" style="background:#fff;color:#185FA5;border:none;padding:5px 14px;border-radius:4px;font-weight:600;cursor:pointer">🖨️ Imprimer</button>
  <button type="button" data-window-close="1" style="background:transparent;color:#fff;border:1px solid rgba(255,255,255,.5);padding:5px 12px;border-radius:4px;cursor:pointer">✕ Fermer</button>
</div>

<div class="header-2logos">
  <img src="{{ $logos['gauche'] }}" alt="" data-onerror-hide="1">
  <div style="flex:1;text-align:center;padding:0 10px">
    <div style="font-weight:bold;font-size:17px;text-transform:uppercase;letter-spacing:1px">RÉPUBLIQUE DU NIGER</div>
    <div style="font-size:15px;margin:1px 0">{{ $institution }}</div>
    <div style="font-size:15px;margin:1px 0">{{ $faculte }}</div>
    <div style="font-size:15px;margin:1px 0">{{ $departement }}</div>
    <div style="font-size:18px;font-weight:bold;margin:4px 0 2px;text-transform:uppercase;border-bottom:1.5px solid #000;padding-bottom:2px">RÉSULTATS DES EXAMENS — {{ mb_strtoupper($pv->sem_libelle) }}</div>
    <div style="font-size:14px">
      {{ $pv->niveau_libelle }} — {{ $pv->filiere }} · {{ Format::session($pv->type_session) }} · A.A. {{ $pv->annee }}
      @if ($pv->date_pv) · {{ $ville }}, le {{ Format::dateFR($pv->date_pv) }}@endif
    </div>
  </div>
  <img src="{{ $logos['droite'] }}" alt="" data-onerror-hide="1">
</div>

<div style="margin:4px 0;font-size:8px;border:0.5px solid #ccc;padding:3px 6px;background:#f9f9f9">
  <strong>AV</strong> = Acquis/Validé &nbsp;|&nbsp; <strong>AC</strong> = Acquis par Compensation &nbsp;|&nbsp; <strong>NV</strong> = Non Validé &nbsp;|&nbsp; <strong>Déf.</strong> = Défaillant (note manquante) &nbsp;|&nbsp; <strong>Elim</strong> = Note éliminatoire
</div>

<div class="pv-cadre">
<div class="pv-zone" data-largeur-papier-mm="{{ $largeurPapierMm }}">
<table class="pv">
<thead>
  <tr>
    <th rowspan="3" data-g="f" class="th-ue" style="writing-mode:vertical-rl;transform:rotate(180deg);min-width:14px">N°</th>
    <th rowspan="3" data-g="f" class="th-ue" style="writing-mode:vertical-rl;transform:rotate(180deg);min-width:28px">N° Étud.</th>
    <th rowspan="3" data-g="f" class="th-ue" style="text-align:left;min-width:110px">NOM &amp; PRÉNOMS</th>
    @foreach ($ues as $ue)
    <th colspan="{{ $ue->matieres->sum(fn ($m) => (float) $m->note_max_cc > 0 ? 5 : 3) + 5 }}" data-g="{{ $loop->index }}" class="th-ue">{{ $ue->code }} — {{ $ue->libelle }}</th>
    @endforeach
    <th colspan="4" data-g="r" class="th-resume">RÉSULTAT SEMESTRE</th>
  </tr>
  <tr>
    @foreach ($ues as $ue)
    @php
        $g = $loop->index;
        $options = $ue->nb_options_a_choisir > 0;
        $maxUe = $ue->matieres->reject(fn ($m) => $options && $m->type_matiere === 'optionnelle')->sum('note_max_totale')
            + ($options ? (float) ($ue->matieres->firstWhere('type_matiere', 'optionnelle')->note_max_totale ?? 0) * $ue->nb_options_a_choisir : 0);
    @endphp
    @foreach ($ue->matieres as $m)<th colspan="{{ (float) $m->note_max_cc > 0 ? 5 : 3 }}" data-g="{{ $g }}" class="th-mat">{{ $m->libelle }}</th>@endforeach
    @foreach (['TOTAL/'.(int) $maxUe, 'MOY./20', 'CRÉDITS ('.$ue->credits_total.')', 'VALID.', 'OBS.'] as $t)
    <th rowspan="2" data-g="{{ $g }}" class="th-sub" style="writing-mode:vertical-rl;transform:rotate(180deg)">{{ $t }}</th>
    @endforeach
    @endforeach
    {{-- Pas de rang sur le PV imprimé (décision de l'université) --}}
    @foreach (['CR. TOT.', 'MOY. GÉN.', 'DÉCISION', 'MENTION'] as $t)
    <th rowspan="2" data-g="r" class="th-sub" style="writing-mode:vertical-rl;transform:rotate(180deg)">{{ $t }}</th>
    @endforeach
  </tr>
  <tr>
    @foreach ($ues as $ue)
    @php $g = $loop->index; @endphp
    @foreach ($ue->matieres as $m)
    <th data-g="{{ $g }}" class="th-sub">Exam<br>/{{ (float) $m->note_max_examen }}</th>
    @if ((float) $m->note_max_cc > 0)
    <th data-g="{{ $g }}" class="th-sub">TD<br>/{{ (float) $m->note_max_cc }}</th>
    <th data-g="{{ $g }}" class="th-sub">Note<br>/{{ (float) $m->note_max_totale }}</th>
    @endif
    <th data-g="{{ $g }}" class="th-sub">Cr<br>({{ $m->credits }})</th>
    <th data-g="{{ $g }}" class="th-sub">Obs.</th>
    @endforeach
    @endforeach
  </tr>
</thead>
<tbody>
@foreach ($etudiants as $i => $et)
  @php $r = $resSem[$et->id] ?? null; @endphp
  <tr class="{{ ! $r ? '' : ($r->defaillant ? 'tr-defaillant' : ($r->valide ? 'tr-admit' : 'tr-ajourne')) }}">
    <td data-g="f">{{ $i + 1 }}</td>
    <td data-g="f" class="td-num">{{ $et->numero }}</td>
    <td data-g="f" class="td-name">{{ $et->nom }} {{ $et->prenoms }}</td>
    @foreach ($ues as $ue)
      @php $g = $loop->index; @endphp
      @foreach ($ue->matieres as $m)
        @if ($ue->nb_options_a_choisir > 0 && $m->type_matiere === 'optionnelle' && ! in_array((int) $m->id, $choix[$et->id][$ue->id] ?? [], true))
        <td data-g="{{ $g }}" colspan="{{ (float) $m->note_max_cc > 0 ? 5 : 3 }}" style="text-align:center;color:#9ca3af;font-size:10px;background:#f9fafb">— NC —</td>
        @else
        @php
            $n = $notes[$et->id][$m->id] ?? null;
            $obs = $n?->observation;
            $finale = $n?->note_finale ?? (($n && ($n->note_examen !== null || $n->note_cc !== null)) ? (float) $n->note_examen + (float) $n->note_cc : null);
            $reportee = (bool) $n?->reportee;
        @endphp
        <td data-g="{{ $g }}" class="{{ $obs === 'Déf.' && ! $reportee ? 'obs-nv' : '' }}" style="{{ $reportee ? 'color:#1e40af;font-style:italic' : '' }}">{{ $f2($n?->note_examen) }}{{ $reportee ? ' ↩' : '' }}</td>
        @if ((float) $m->note_max_cc > 0)
        <td data-g="{{ $g }}">{{ $f2($n?->note_cc) }}</td>
        <td data-g="{{ $g }}" class="{{ $obs === 'Déf.' ? 'obs-nv' : '' }}">{{ $f2($finale) }}</td>
        @endif
        @php $cr = in_array($obs, ['AV', 'AC'], true) ? $m->credits : 0; @endphp
        <td data-g="{{ $g }}" style="color:{{ $cr ? '#1b5e20' : '#999' }};font-weight:{{ $cr ? 'bold' : 'normal' }}">{{ $cr }}</td>
        <td data-g="{{ $g }}" class="{{ $classeObs($obs) }}">{{ $obs ?: '—' }}</td>
        @endif
      @endforeach
      @php
          $ru = $resUe[$et->id][$ue->id] ?? null;
          [$val, $obsUe, $fond, $couleur] = ! $ru ? ['—', '—', '#f3f4f6', '#6b7280']
              : ($ru->defaillant ? ['NON', $ue->matieres->contains(fn ($m) => \App\Services\Resultats::caseVide($notes[$et->id][$m->id] ?? null, $m)) ? 'Déf.' : 'Elim', '#fee2e2', '#991b1b']
              : (! $ru->valide ? ['NON', 'NV', '#fef3c7', '#92400e']
              : ['OUI', (float) $ru->moyenne_20 < $seuil ? 'AC' : 'AV', '#dcfce7', '#166534']));
      @endphp
      <td data-g="{{ $g }}">{{ $f2($ru?->total_points) }}</td>
      <td data-g="{{ $g }}" class="{{ $ru?->valide ? 'moy-ok' : 'moy-ko' }}">{{ $f2($ru?->moyenne_20) }}</td>
      <td data-g="{{ $g }}" style="font-weight:bold;color:{{ $ru && $ru->credits_obtenus >= $ue->credits_total ? '#1b5e20' : '#b71c1c' }}">{{ $ru->credits_obtenus ?? 0 }}/{{ $ue->credits_total }}</td>
      <td data-g="{{ $g }}" style="text-align:center;font-weight:bold;background:{{ $fond }};color:{{ $couleur }}">{{ $val }}</td>
      <td data-g="{{ $g }}" style="text-align:center;font-weight:bold;background:{{ $obsUe === 'AC' ? '#dbeafe' : $fond }};color:{{ $obsUe === 'AC' ? '#1e40af' : $couleur }}">{{ $obsUe }}</td>
    @endforeach
    <td data-g="r" style="font-weight:bold;color:{{ $r && $r->credits_obtenus >= $creditsTotal ? '#1b5e20' : '#b71c1c' }}">{{ $r->credits_obtenus ?? 0 }}/{{ $creditsTotal }}</td>
    <td data-g="r" class="{{ $r?->valide ? 'moy-ok' : 'moy-ko' }}" style="font-size:9px;font-weight:bold">{{ $f2($r?->moyenne_generale) }}</td>
    <td data-g="r" class="{{ $r?->valide ? 'moy-ok' : 'moy-ko' }}" style="font-size:7.5px;font-weight:bold">{{ $r->decision ?? '—' }}</td>
    <td data-g="r" style="font-size:7.5px;font-weight:bold">{{ $r->mention ?? '—' }}</td>
  </tr>
@endforeach
</tbody>
</table>
</div>
</div>

@if ($repechages->isNotEmpty())
<div style="margin-top:8px;font-size:8px;page-break-inside:avoid">
  <strong>Repêchages accordés par le jury</strong> (points ajoutés à la note d'examen) :
  @foreach ($repechages as $r)
  {{ $r->nom }} {{ $r->prenoms }} ({{ $r->numero }}) — {{ $r->matiere }} : {{ number_format((float) $r->note_examen_avant, 2) }} → {{ number_format((float) $r->note_examen_apres, 2) }} (+{{ rtrim(rtrim(number_format((float) $r->points, 2, '.', ''), '0'), '.') }}){{ $loop->last ? '.' : ' ;' }}
  @endforeach
</div>
@endif

<div class="pied-page">
  <div>
    <div style="text-align:center;font-style:italic;margin-bottom:2px">Fait à {{ $ville }}, le {{ $pv->date_pv ? Format::dateFR($pv->date_pv) : '........................' }}</div>
    <div style="height:14px"></div>
    <div style="border-top:0.5px solid #000;padding-top:3px;text-align:center;font-style:italic">Le Président du Jury</div>
    @if ($president)<div style="text-align:center;font-size:9pt;font-weight:bold">{{ $president }}</div>@endif
  </div>
  <div>
    <div style="height:14px"></div>
    <div style="border-top:0.5px solid #000;padding-top:3px;text-align:center;font-style:italic">Membres du Jury</div>
  </div>
  <div>
    <div style="height:14px"></div>
    <div style="border-top:0.5px solid #000;padding-top:3px;text-align:center;font-style:italic">{{ $signataire['titre'] }}</div>
    <div style="text-align:center;font-size:9pt;font-weight:bold">{{ $signataire['nom'] }}</div>
  </div>
</div>

</div>
<script src="{{ \App\Support\Ressource::url('assets/js/impression.js') }}"></script>
<script src="{{ \App\Support\Ressource::url('assets/js/pv-impression.js') }}"></script>
</body>
</html>
