{{-- Notes d'un semestre dans le dossier étudiant (logique d'affichage de l'original) --}}
@php
  // Résultat retenu : normale validée, sinon rattrapage validé, sinon le dernier disponible
  $resSem = ($resS1 && $resS1->valide) ? $resS1 : (($resS2 && $resS2->valide) ? $resS2 : ($resS2 ?? $resS1));
  $avecS2 = $resS2 && $resS2->pv_id !== ($resS1->pv_id ?? null);
  $niveauCode = $lignes[0]->niveau ?? '';
  $cellule = 'padding:6px 10px;text-align:center;border:1px solid #9DC3E6';
@endphp
<div style="padding:10px 16px;font-size:13px;font-weight:700;color:#1A3A5C;border-bottom:1px solid var(--border)">
  {{ $niveauCode }} — {{ $et->filiere ?? '' }}
  @if ($resSem && $resSem->decision)
    &nbsp;—&nbsp;<span style="color:{{ $resSem->valide ? '#16a34a' : '#dc2626' }};font-weight:700">{{ $resSem->decision }}</span>
  @endif
</div>
<table style="width:100%;border-collapse:collapse;font-size:13px">
  <thead>
    <tr><td colspan="6" style="background:#4472C4;color:#fff;padding:8px 14px;font-weight:700;font-size:13px">
      {{ $semestre }}
      @if ($resS1)
        &nbsp;&nbsp;<span style="font-size:11px;font-weight:400;opacity:.85">S1&nbsp;: {{ $resS1->moyenne_generale }}/20 @if ($resS1->mention)({{ $resS1->mention }})@endif &mdash; {{ $resS1->valide ? '✓ Validé' : '✗ Non validé' }}</span>
      @endif
      @if ($avecS2)
        &nbsp;|&nbsp;<span style="font-size:11px;font-weight:400;background:rgba(255,255,255,.2);padding:2px 8px;border-radius:6px">Ratt&nbsp;: {{ $resS2->moyenne_generale }}/20 &mdash; {{ $resS2->valide ? '✓ Validé' : '✗ Non validé' }}</span>
      @endif
    </td></tr>
    <tr style="background:#BDD7EE;color:#1A3A5C">
      <th style="padding:7px 14px;text-align:left;border:1px solid #9DC3E6;font-size:12px">Code</th>
      <th style="padding:7px 14px;text-align:left;border:1px solid #9DC3E6;font-size:12px">Matière</th>
      <th style="{{ $cellule }};font-size:12px;width:50px">Type</th>
      <th style="{{ $cellule }};font-size:12px;width:80px">Session 1</th>
      @if ($avecS2)<th style="{{ $cellule }};font-size:12px;width:80px">Session 2</th>@endif
      <th style="{{ $cellule }};font-size:12px;width:80px">Validation</th>
    </tr>
  </thead>
  <tbody>
  @foreach ($lignes as $i => $n)
    @php
      $note1 = $n->note_finale;
      $note2 = $n->note_finale_s2;
      $noteMax = (float) ($n->note_max_totale ?? 40);
      $seuil = $noteMax / 2;
      $valideS1 = $note1 !== null && $note1 >= $seuil;
      $valideS2 = $note2 !== null && $note2 >= $seuil;
      $valideSemestre = $resSem ? (bool) $resSem->valide : false;
      $parComp = $valideSemestre && ! $valideS1 && ! $valideS2;
      $obs1 = $n->observation ?? '';
      $obs2 = $n->observation_s2 ?? '';
      $noteValide = in_array($obs1, ['AV', 'AC'], true) || in_array($obs2, ['AV', 'AC'], true) || $valideS1 || $valideS2 || $parComp;
      // Meilleure observation entre les deux sessions : AV > AC > NV
      $rang = ['AV' => 3, 'AC' => 2, 'NV' => 1];
      $meilleure = ($rang[$obs1] ?? 0) >= ($rang[$obs2] ?? 0) ? $obs1 : $obs2;
      $obsAff = match (true) {
        in_array($meilleure, ['AV', 'AC', 'NV'], true) => $meilleure,
        $valideS2, $valideS1 => 'AV',
        $parComp => 'AC',
        (bool) $n->defaillant => 'Déf.',
        $note1 !== null || $note2 !== null => 'NV',
        default => '',
      };
      $couleurObs = ['AV' => '#16a34a', 'AC' => '#2563eb', 'NV' => '#dc2626', 'Déf.' => '#854d0e'][$obsAff] ?? '#6b7280';
    @endphp
    <tr style="background:{{ $i % 2 ? '#DEEAF1' : '#fff' }};border-bottom:1px solid #9DC3E6">
      <td style="padding:6px 14px;border:1px solid #9DC3E6;font-size:12px;color:#1A3A5C;font-family:monospace;white-space:nowrap">{{ $n->ue_code }}</td>
      <td style="padding:6px 14px;border:1px solid #9DC3E6;font-size:12px">{{ $n->matiere }}</td>
      <td style="{{ $cellule }};font-size:11px;color:#374151">{{ ($n->type_matiere ?? 'obligatoire') === 'optionnelle' ? 'OPT' : 'OBL' }}</td>
      <td style="{{ $cellule }};font-size:13px;font-weight:700;color:{{ $note1 !== null ? ($noteValide ? '#166534' : '#dc2626') : '#6b7280' }}">
        @if ($note1 !== null){{ number_format($note1, 2) }} <span style="font-size:10px;font-weight:400;color:#6b7280">/{{ number_format($noteMax, 0) }}</span>@endif
      </td>
      @if ($avecS2)
      <td style="{{ $cellule }};font-size:13px;font-weight:700;color:{{ $note2 !== null ? ($noteValide ? '#166534' : '#dc2626') : '#6b7280' }}">
        @if ($note2 !== null){{ number_format($note2, 2) }} <span style="font-size:10px;font-weight:400;color:#6b7280">/{{ number_format($noteMax, 0) }}</span>@endif
      </td>
      @endif
      <td style="{{ $cellule }}">@if ($obsAff)<span style="color:{{ $couleurObs }};font-weight:700;font-size:12px">{{ $obsAff }}</span>@endif</td>
    </tr>
  @endforeach
    <tr style="background:#BDD7EE">
      <td colspan="2" style="padding:6px 14px;border:1px solid #9DC3E6;font-size:12px;font-weight:700;color:#1A3A5C">Validation du semestre :</td>
      <td style="{{ $cellule }};font-size:12px">@if ($resS1)<span style="font-weight:600;color:{{ $resS1->valide ? '#166534' : '#dc2626' }}">S1 : {{ $resS1->moyenne_generale }}/20</span>@endif</td>
      @if ($avecS2)
        <td style="{{ $cellule }};font-size:12px"><span style="font-weight:600;color:{{ $resS2->valide ? '#166534' : '#dc2626' }}">Ratt : {{ $resS2->moyenne_generale }}/20</span></td>
      @endif
      <td colspan="2" style="padding:6px 14px;border:1px solid #9DC3E6;text-align:center">
        @if ($resSem)
          <span style="font-weight:700;font-size:13px;color:{{ $resSem->valide ? '#166534' : '#dc2626' }}">
            {{ $resSem->valide ? '✅ Validé' : '❌ Non validé' }}
            @if ($resSem->credits_obtenus)({{ $resSem->credits_obtenus }}/{{ $resSem->credits_total }} crédits)@endif
          </span>
        @endif
        @php $pvReleve = ($resS1 && $resS1->valide) ? $resS1->pv_id : (($resS2 && $resS2->valide) ? $resS2->pv_id : null); @endphp
        @if ($pvReleve)
          <a href="{{ $navigation->lien('releve_notes', ['pv_id' => $pvReleve, 'et_id' => $et->id]) }}" target="_blank" rel="noopener" style="display:inline-flex;align-items:center;gap:4px;margin-left:8px;background:#1A3A5C;color:#fff;padding:3px 10px;border-radius:6px;font-size:11px;font-weight:600;text-decoration:none;vertical-align:middle" title="Imprimer le relevé de notes">🖨️ Relevé</a>
        @endif
      </td>
    </tr>
  </tbody>
</table>
<div style="height:8px"></div>
