{{-- Détail d'un PV et grand tableau de saisie — portage de pv_detail.php --}}
@extends('layouts.app', ['titre' => $pv->libelle, 'fil' => 'PV › Détail'])
@inject('navigation', 'App\Navigation\Menu')

@php
    $sessionLib = $pv->type_session === 'rattrapage' ? '2ème Session' : '1ère Session';
    $nbCols = fn ($m) => (float) $m->note_max_cc > 0 ? 5 : 3;
    $couleurObs = fn ($o) => ['AV' => 'var(--green-700)', 'AC' => 'var(--blue-600)', 'NV' => 'var(--amber-600)', 'Déf.' => 'var(--red-600)'][$o] ?? 'var(--text-muted)';
    $creditsTotal = $ues->sum('credits_total');
    $f2 = fn ($v) => $v === null ? '—' : number_format((float) $v, 2);
@endphp

@section('contenu')
<div class="card mb-16 no-print">
  <div class="card-body" style="padding:14px 20px">
    <div style="display:grid;grid-template-columns:1fr 1fr 1fr auto;gap:16px;align-items:center">
      <div><div class="text-muted text-xs">Filière</div><div class="fw-600">{{ $pv->filiere }}</div></div>
      <div><div class="text-muted text-xs">Niveau · Semestre</div><div class="fw-600">{{ $pv->niveau }} · {{ $pv->sem_libelle }}</div></div>
      <div><div class="text-muted text-xs">Année · Session</div><div class="fw-600">{{ $pv->annee }} · {{ $sessionLib }}</div></div>
      <div class="flex gap-8" style="flex-wrap:wrap">
        @if ($peutModifier)
        <a href="{{ $navigation->lien('notes_importer', ['pv_id' => $pv->id]) }}" class="btn btn-secondary btn-sm">📥 Importer notes</a>
        @foreach (['reporter_notes' => ['↩ Reporter notes', 'Reporter les notes validées des années précédentes'], 'recalculer' => ['🔄 Recalculer', 'Recalcul complet (peut être lent)']] as $act => [$lib, $titre])
        <form method="post" action="{{ route('pv_detail.action', ['id' => $pv->id]) }}" style="display:inline">
          @csrf <input type="hidden" name="action_pv" value="{{ $act }}">
          <button type="submit" class="btn btn-secondary btn-sm" title="{{ $titre }}">{{ $lib }}</button>
        </form>
        @endforeach
        <button type="button" class="btn btn-secondary btn-sm" id="btn-recalc-async" data-url="{{ route('pv_detail.file', ['id' => $pv->id]) }}" title="Recalcul progressif — ne bloque pas le navigateur">⚡ Recalcul rapide</button>
        <div id="recalc-progress" style="display:none;align-items:center;gap:8px;min-width:200px">
          <div style="flex:1;background:#e5e7eb;border-radius:4px;height:8px;overflow:hidden"><div id="recalc-bar" style="height:100%;background:var(--blue-600);width:0%;transition:width .3s"></div></div>
          <span id="recalc-label" style="font-size:11px;color:var(--text-muted);white-space:nowrap">0 / 0</span>
          <button type="button" id="recalc-annuler" style="background:none;border:none;cursor:pointer;font-size:14px;color:var(--red-500)" title="Annuler">✕</button>
        </div>
        @if ($admin)
        <div style="position:relative;display:inline-block">
          <button type="button" class="btn btn-sm" style="background:#dc2626;color:#fff;border:none" data-toggle-display="dropdown-vider">🗑️ Vider notes ▾</button>
          <div id="dropdown-vider" style="display:none;position:absolute;top:100%;left:0;z-index:999;background:#fff;border:1px solid #e5e7eb;border-radius:6px;box-shadow:0 4px 16px rgba(0,0,0,.15);min-width:260px;max-height:320px;overflow-y:auto;padding:4px 0">
            @foreach ($ues as $ue)
            <div style="padding:4px 10px;font-size:10px;font-weight:700;color:#6b7280;text-transform:uppercase;background:#f9fafb;border-bottom:1px solid #f3f4f6">{{ $ue->code }} — {{ $ue->libelle }}</div>
            @foreach ($ue->matieres as $m)
            <form method="post" action="{{ route('pv_detail.action', ['id' => $pv->id]) }}" style="margin:0" data-confirm="Supprimer toutes les notes de « {{ $m->libelle }} » pour tous les étudiants ?">
              @csrf <input type="hidden" name="action_pv" value="vider_matiere"><input type="hidden" name="matiere_id" value="{{ $m->id }}">
              <button type="submit" class="btn-vider-matiere" style="width:100%;text-align:left;padding:7px 14px;border:none;background:none;cursor:pointer;font-size:12px;color:#374151">🗑️ {{ $m->libelle }}</button>
            </form>
            @endforeach
            @endforeach
          </div>
        </div>
        @endif
        @foreach (['finaliser' => ['✅ Finaliser', 'btn-primary'], 'verrouiller' => ['🔒 Verrouiller', 'btn-secondary']] as $act => [$lib, $classe])
        <form method="post" action="{{ route('pv_detail.action', ['id' => $pv->id]) }}" style="display:inline">
          @csrf <input type="hidden" name="action_pv" value="{{ $act }}">
          <button type="submit" class="btn {{ $classe }} btn-sm">{{ $lib }}</button>
        </form>
        @endforeach
        @endif
        @if ($gestionnaire && $pv->verrouille)
        <form method="post" action="{{ route('pv_detail.action', ['id' => $pv->id]) }}" style="display:inline">
          @csrf <input type="hidden" name="action_pv" value="deverrouiller">
          <button type="submit" class="btn btn-secondary btn-sm">🔓 Déverrouiller</button>
        </form>
        @endif
        <a href="{{ $navigation->lien('pv_imprimer', ['id' => $pv->id]) }}" target="_blank" rel="noopener" class="btn btn-secondary btn-sm">🖨️ Imprimer</a>
        <a href="{{ $navigation->lien('pv_exporter', ['id' => $pv->id]) }}" class="btn btn-secondary btn-sm">📊 Exporter Excel</a>
        <a href="{{ $navigation->lien('liste_valides', ['pv_id' => $pv->id]) }}" target="_blank" rel="noopener" class="btn btn-secondary btn-sm">✅ Liste validés</a>
        <a href="{{ $navigation->lien('attestation', ['pv_id' => $pv->id, 'masse' => 1]) }}" target="_blank" rel="noopener" class="btn btn-secondary btn-sm">🏆 Attestations</a>
        <a href="{{ $navigation->lien('deliberation', ['pv_id' => $pv->id]) }}" target="_blank" rel="noopener" class="btn btn-secondary btn-sm">⚖️ Délibération</a>
        <a href="{{ $navigation->lien('planning', ['pv_id' => $pv->id]) }}" class="btn btn-secondary btn-sm">📅 Planning</a>
        <a href="{{ $navigation->lien('convocations', ['pv_id' => $pv->id, 'masse' => 1]) }}" target="_blank" rel="noopener" class="btn btn-secondary btn-sm">📨 Convocations</a>
      </div>
    </div>
  </div>
</div>

@if ($stats['total'] > 0)
<div class="stats-grid mb-16 no-print">
  <div class="stat-card"><div class="stat-value" style="color:var(--blue-600)">{{ $stats['total'] }}</div><div class="stat-label">👥 Inscrits</div></div>
  <div class="stat-card"><div class="stat-value" style="color:var(--green-600)">{{ $stats['admis'] }}</div><div class="stat-label">✅ Admis</div></div>
  <div class="stat-card"><div class="stat-value" style="color:var(--amber-600)">{{ $stats['ajournes'] }}</div><div class="stat-label">⏳ Ajournés</div></div>
  <div class="stat-card"><div class="stat-value" style="color:var(--red-600)">{{ $stats['defaillants'] }}</div><div class="stat-label">❌ Défaillants</div></div>
  <div class="stat-card"><div class="stat-value" style="color:var(--blue-600)">{{ round($stats['admis'] / $stats['total'] * 100, 1) }}%</div><div class="stat-label">📈 Taux réussite</div></div>
</div>
@if ($stats['non_calcules'])
<div class="alert alert-info no-print">ℹ️ {{ $stats['non_calcules'] }} étudiant(s) sans résultat calculé : enregistrez les notes ou lancez « Recalculer ».</div>
@endif
@endif

@if (! $peutModifier && ! $pv->verrouille)
<div class="alert alert-info no-print">ℹ️ Vous êtes en lecture seule. Seul un secrétaire ou admin peut modifier les notes.</div>
@endif
@if ($pv->verrouille)
<div class="alert alert-warning no-print">🔒 Ce PV est verrouillé. Les notes ne peuvent plus être modifiées.</div>
@endif

<form method="get" action="{{ route('pv_detail', ['id' => $pv->id]) }}" class="no-print" style="display:inline-flex;align-items:center;margin-bottom:10px">
  <input type="text" name="q" value="{{ $recherche }}" placeholder="🔍 Nom ou matricule..." autocomplete="off" aria-label="Rechercher un étudiant"
    style="width:200px;height:30px;padding:4px 10px;border:1px solid #ccc;border-radius:6px 0 0 6px;font-size:12px">
  <button type="submit" style="background:var(--blue-600);color:#fff;border:none;padding:0 12px;height:30px;cursor:pointer;font-size:12px;border-radius:0 6px 6px 0;font-weight:600">Rechercher</button>
  @if ($recherche !== '')
  <a href="{{ route('pv_detail', ['id' => $pv->id]) }}" style="margin-left:8px;font-size:11px;color:var(--text-muted);text-decoration:none">✕</a>
  <span style="font-size:11px;color:var(--blue-600);margin-left:4px;font-weight:600">{{ $total }} résultat(s) pour « {{ $recherche }} »</span>
  @endif
</form>

@if ($peutModifier)
<form method="post" action="{{ route('pv_detail.notes', ['id' => $pv->id]) }}" id="form-notes" data-simulation="{{ route('pv_detail.simuler', ['id' => $pv->id]) }}">
  @csrf
  <input type="hidden" name="ppage" value="{{ $page }}">
@endif
<div class="card">
  <div class="card-header">
    <span class="card-title">Tableau des résultats — {{ $total }} étudiant(s)</span>
    @if ($pages > 1)
    <div class="flex gap-8 no-print" style="align-items:center">
      <span style="font-size:11px;color:var(--text-muted)">Page {{ $page }} / {{ $pages }}</span>
      @if ($page > 1)<a class="btn btn-secondary btn-sm" href="{{ route('pv_detail', ['id' => $pv->id, 'ppage' => $page - 1, 'q' => $recherche ?: null]) }}">◀ Préc.</a>@endif
      @if ($page < $pages)<a class="btn btn-secondary btn-sm" href="{{ route('pv_detail', ['id' => $pv->id, 'ppage' => $page + 1, 'q' => $recherche ?: null]) }}">Suiv. ▶</a>@endif
    </div>
    @endif
    <div class="flex gap-8 no-print" style="flex-wrap:wrap;align-items:center">
      <span style="font-size:11px;color:var(--text-muted)">Moy.</span>
      <input type="number" id="filter-moy-min" class="form-control form-control-sm" placeholder="min" min="0" max="20" step="0.01" style="width:64px" aria-label="Moyenne minimale">
      <span style="font-size:11px;color:var(--text-muted)">–</span>
      <input type="number" id="filter-moy-max" class="form-control form-control-sm" placeholder="max" min="0" max="20" step="0.01" style="width:64px" aria-label="Moyenne maximale">
      <span style="font-size:11px;color:var(--text-muted)">Crédits ≥</span>
      <input type="number" id="filter-credits" class="form-control form-control-sm" placeholder="—" min="0" step="1" style="width:60px" aria-label="Crédits minimum">
      <select id="filter-decision" class="form-control form-control-sm" style="width:120px" aria-label="Décision">
        <option value="">Décision…</option><option>Admis</option><option>Ajourné</option><option>Défaillant</option>
      </select>
      <select id="filter-mention" class="form-control form-control-sm" style="width:130px" aria-label="Mention">
        <option value="">Mention…</option><option>Passable</option><option>Assez bien</option><option>Bien</option><option>Très bien</option><option>Défaillant</option>
      </select>
      <button type="button" id="filter-reset" class="btn btn-secondary btn-sm" title="Réinitialiser les filtres">✕</button>
      <span id="filter-count" style="font-size:11px;color:var(--text-muted)"></span>
      @if ($peutModifier)<button type="submit" class="btn btn-primary">💾 Enregistrer les notes</button>@endif
    </div>
  </div>
  <div class="table-wrap">
    <table class="pv-table">
      <thead>
        <tr>
          <th rowspan="3" style="writing-mode:vertical-rl;transform:rotate(180deg);min-width:28px">N°</th>
          <th rowspan="3" style="writing-mode:vertical-rl;transform:rotate(180deg);min-width:38px">N° Étud.</th>
          <th rowspan="3" style="min-width:150px;text-align:left">NOM & PRÉNOMS</th>
          @foreach ($ues as $ue)
          <th colspan="{{ $ue->matieres->sum($nbCols) + 5 }}" style="font-size:10px;font-weight:700;background:var(--blue-800);text-transform:uppercase">{{ $ue->code }} — {{ $ue->libelle }}</th>
          @endforeach
          <th colspan="6" style="font-size:10px;font-weight:700;background:var(--blue-900)">RÉSULTAT SEMESTRE</th>
        </tr>
        <tr>
          @foreach ($ues as $ue)
          @foreach ($ue->matieres as $m)
          <th colspan="{{ $nbCols($m) }}" style="font-size:8.5px;white-space:normal;line-height:1.3;max-width:150px;background:var(--blue-600);color:#fff">{{ $m->libelle }}</th>
          @endforeach
          @foreach (['TOTAL/'.(float) $ue->matieres->sum('note_max_totale'), 'MOYENNE /20', 'CRÉDITS UE ('.$ue->credits_total.')', 'VALID. UE', 'OBS. UE'] as $titre)
          <th rowspan="2" style="font-size:8px;writing-mode:vertical-rl;transform:rotate(180deg);background:var(--blue-100);color:var(--blue-900)">{{ $titre }}</th>
          @endforeach
          @endforeach
          @foreach (['TOTAL CRÉDITS', 'MOY. GÉN./20', 'DÉCISION', 'MENTION', 'RANG', 'RELEVÉ'] as $titre)
          <th rowspan="2" style="font-size:8px;writing-mode:vertical-rl;transform:rotate(180deg);background:var(--gray-50)">{{ $titre }}</th>
          @endforeach
        </tr>
        <tr>
          @foreach ($ues as $ue)
          @foreach ($ue->matieres as $m)
          <th style="font-size:8px;background:var(--blue-50);color:var(--blue-900)">Exam.<br>/{{ (float) $m->note_max_examen }}</th>
          @if ((float) $m->note_max_cc > 0)
          <th style="font-size:8px;background:var(--blue-50);color:var(--blue-900)">TD<br>/{{ (float) $m->note_max_cc }}</th>
          <th style="font-size:8px;background:var(--blue-50);color:var(--blue-900)">Note<br>/{{ (float) $m->note_max_totale }}</th>
          @endif
          <th style="font-size:8px;background:var(--blue-50);color:var(--blue-900)">Crédits<br>({{ $m->credits }})</th>
          <th style="font-size:8px;background:var(--blue-50);color:var(--blue-900)">Obs.</th>
          @endforeach
          @endforeach
        </tr>
      </thead>
      <tbody>
      @forelse ($etudiants as $i => $et)
        @php
            $res = $resultats[$et->id] ?? null;
            $classe = ! $res ? '' : ($res->defaillant ? 'cell-defaillant' : ($res->valide ? 'cell-admit' : 'cell-ajourne'));
        @endphp
        <tr class="table-row-searchable {{ $classe }}" data-etudiant-id="{{ $et->id }}"
            data-moy="{{ $res ? number_format((float) $res->moyenne_generale, 2, '.', '') : '' }}" data-credits="{{ $res->credits_obtenus ?? '' }}"
            data-decision="{{ $res->decision ?? '' }}" data-mention="{{ $res->mention ?? '' }}">
          <td style="font-weight:600;color:var(--blue-800)">{{ $decalage + $i + 1 }}</td>
          <td style="font-size:10px;color:var(--text-muted)">{{ $et->numero }}</td>
          <td class="td-name">{{ $et->nom }}<br><span style="font-weight:400;font-size:10px;color:var(--text-muted)">{{ $et->prenoms }}</span></td>
          @foreach ($ues as $ue)
            @foreach ($ue->matieres as $m)
              @php
                  ['note' => $note, 'etat' => $etat] = $cellules[$et->id][$m->id];
                  $avecTd = (float) $m->note_max_cc > 0;
                  $saisie = $peutModifier && $etat === 'saisie';
                  $finale = $note?->note_finale ?? (($note && ($note->note_examen !== null || $note->note_cc !== null)) ? (float) $note->note_examen + (float) $note->note_cc : null);
                  $obs = $note?->observation;
                  $marque = ['session1' => '<sup style="font-size:8px" title="Validé en 1ère session">🔒</sup>', 'reportee' => '<sup style="font-size:8px" title="Note reportée d\'une année précédente">📥</sup>'][$etat] ?? '';
              @endphp
              @if ($etat === 'nc')
              <td colspan="{{ $nbCols($m) }}" style="text-align:center;background:#f9fafb;color:#9ca3af;font-size:11px;border-left:3px solid #fbbf24">— NC —</td>
              @else
              <td style="text-align:center">
                @if ($saisie)
                <input type="number" class="note-input" name="notes[{{ $et->id }}][{{ $m->id }}][exam]" value="{{ $note?->note_examen }}"
                  min="0" max="{{ (float) $m->note_max_examen }}" step="0.01" data-decimales="2" placeholder="—" aria-label="Examen {{ $m->libelle }} — {{ $et->nom }}">
                @else
                <span style="font-size:11px;font-weight:600;color:{{ $etat !== 'saisie' ? 'var(--green-600)' : 'inherit' }}">{{ $f2($note?->note_examen) }}{!! $marque !!}</span>
                @endif
              </td>
              @if ($avecTd)
              <td style="text-align:center">
                @if ($saisie)
                <input type="number" class="note-input cc-input" name="notes[{{ $et->id }}][{{ $m->id }}][cc]" value="{{ $note?->note_cc }}"
                  min="0" max="{{ (float) $m->note_max_cc }}" step="0.01" data-decimales="2" placeholder="TD" style="width:56px" aria-label="TD {{ $m->libelle }} — {{ $et->nom }}">
                @else
                <span style="font-size:11px;font-weight:600;color:{{ $etat !== 'saisie' ? 'var(--green-600)' : 'inherit' }}">{{ $f2($note?->note_cc) }}{!! $marque !!}</span>
                @endif
              </td>
              <td class="mat-finale-{{ $m->id }}" style="text-align:center;font-size:11px;font-weight:600">{{ $f2($finale) }}</td>
              @endif
              <td class="mat-cred-{{ $m->id }}" style="text-align:center;font-size:11px;font-weight:700;color:{{ in_array($obs, ['AV', 'AC'], true) ? 'var(--green-700)' : 'var(--text-muted)' }}">{{ in_array($obs, ['AV', 'AC'], true) ? $m->credits : 0 }}</td>
              <td class="mat-obs-{{ $m->id }}" style="text-align:center;font-weight:700;font-size:11px;color:{{ $couleurObs($obs) }}">{{ $obs ?: '—' }}</td>
              @endif
            @endforeach
            @php
                $ru = $resultatsUe[$et->id][$ue->id] ?? null;
                [$val, $obsUe] = ! $ru ? ['—', '—'] : ($ru->defaillant ? ['NON', 'Déf.'] : (! $ru->valide ? ['NON', 'NV'] : ['OUI', (float) $ru->moyenne_20 < $seuil ? 'AC' : 'AV']));
                $fond = $ru && $ru->valide ? 'var(--green-50)' : 'var(--amber-50)';
            @endphp
            <td class="ue-total-{{ $ue->id }}" style="text-align:center;font-weight:600;font-size:11px;background:{{ $fond }}">{{ $f2($ru?->total_points) }}</td>
            <td class="ue-moy-{{ $ue->id }}" style="text-align:center;font-weight:700;font-size:12px;background:{{ $fond }};color:{{ $ru && $ru->valide ? 'var(--green-700)' : 'var(--amber-600)' }}">{{ $f2($ru?->moyenne_20) }}</td>
            <td class="ue-cred-{{ $ue->id }}" style="text-align:center;font-weight:700;font-size:12px;color:{{ $ru && $ru->credits_obtenus >= $ue->credits_total ? 'var(--green-700)' : 'var(--red-600)' }}">{{ $ru ? $ru->credits_obtenus : 0 }}/{{ $ue->credits_total }}</td>
            <td class="ue-val-{{ $ue->id }}" style="text-align:center;font-weight:700;font-size:12px;{{ ['OUI' => 'background:#dcfce7;color:#166534', 'NON' => $obsUe === 'Déf.' ? 'background:#fee2e2;color:#991b1b' : 'background:#fef3c7;color:#92400e'][$val] ?? '' }}">{{ $val }}</td>
            <td class="ue-obs-{{ $ue->id }}" style="text-align:center;font-weight:700;font-size:11px;color:{{ $couleurObs($obsUe) }}">{{ $obsUe }}</td>
          @endforeach
          <td class="sem-cred" style="text-align:center;font-weight:700;font-size:12px;color:{{ $res && $res->credits_obtenus >= $creditsTotal ? 'var(--green-700)' : 'var(--red-600)' }}">{{ $res->credits_obtenus ?? 0 }}/{{ $creditsTotal }}</td>
          <td class="sem-moy" style="text-align:center;font-weight:700;font-size:13px;color:{{ $res?->valide ? 'var(--green-700)' : 'var(--amber-600)' }};background:{{ $res?->valide ? 'var(--green-50)' : 'var(--amber-50)' }}">{{ $f2($res?->moyenne_generale) }}</td>
          <td class="sem-decision" style="text-align:center;font-size:11px;font-weight:700;color:{{ $res?->valide ? 'var(--green-700)' : 'var(--amber-600)' }}">{{ $res->decision ?? '—' }}</td>
          <td class="sem-mention" style="text-align:center;font-size:11px;font-weight:600">{{ $res->mention ?? '—' }}</td>
          <td style="text-align:center;font-size:12px;font-weight:700;color:var(--blue-600)">{{ $res->rang ?? '—' }}</td>
          <td style="text-align:center" class="no-print">
            @if ($res?->valide)
            <a href="{{ $navigation->lien('releve_notes', ['pv_id' => $pv->id, 'et_id' => $et->id]) }}" target="_blank" rel="noopener" class="btn btn-icon btn-sm btn-secondary" title="Relevé de notes">📄</a>
            @else<span style="color:var(--text-muted)" title="Semestre non validé">—</span>@endif
          </td>
        </tr>
      @empty
        <tr><td colspan="50"><div class="empty-state"><div class="icon">👥</div>Aucun étudiant inscrit pédagogiquement à ce semestre.</div></td></tr>
      @endforelse
      </tbody>
    </table>
  </div>
  <div class="card-footer no-print">
    <span class="text-muted text-xs">AV = Acquis par validation · AC = Acquis par compensation · NV = Non validé · Déf. = Défaillant · NC = option non choisie &nbsp;|&nbsp; 📥 = Note reportée · 🔒 = Validée en 1ère session · <em>en italique : résultat provisoire pendant la saisie</em></span>
    @if ($peutModifier)<button type="submit" class="btn btn-primary btn-sm" style="margin-left:auto">💾 Enregistrer les notes</button>@endif
  </div>
</div>

@if ($peutModifier)
<div style="position:fixed;bottom:0;left:var(--sidebar-w);right:0;background:var(--blue-900);color:#fff;padding:10px 24px;display:flex;align-items:center;justify-content:space-between;z-index:200;box-shadow:0 -2px 12px rgba(0,0,0,.25)" class="no-print">
  <span id="save-status-bar" style="font-size:13px;color:rgba(255,255,255,.8)">✏️ Mode saisie — les résultats se mettent à jour pendant la saisie ; cliquez sur « Tout enregistrer » pour les valider</span>
  <button type="submit" class="btn btn-lg" style="background:#fff;color:var(--blue-900);font-weight:700;padding:10px 28px">💾 Tout enregistrer</button>
</div>
<div style="height:64px"></div>
</form>
@endif
@endsection

@push('head')
<style>.btn-vider-matiere:hover{background:#fee2e2 !important;color:#dc2626 !important} tr.provisoire td{font-style:italic}</style>
@endpush

@push('scripts')
<script src="{{ asset('assets/js/pv-detail.js') }}"></script>
@endpush
