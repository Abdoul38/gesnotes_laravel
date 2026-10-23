{{-- Portail enseignant — portage de pages/enseignants/portail_enseignant.php --}}
@extends('layouts.app', ['titre' => 'Portail enseignant', 'fil' => 'Saisie des notes'])

@section('contenu')
<div class="card mb-16" style="background:linear-gradient(135deg,#1A3A5C,#2C5F9E);color:#fff">
  <div class="card-body" style="display:flex;align-items:center;gap:16px;padding:16px;flex-wrap:wrap">
    <div style="font-size:40px">{{ $mode === 'enseignant' ? '👨‍🏫' : '🔧' }}</div>
    <div>
      @if ($mode === 'enseignant')
      <div style="font-size:18px;font-weight:800">{{ $enseignant->nom }} {{ $enseignant->prenoms }}</div>
      <div style="opacity:.8;font-size:12px">{{ $enseignant->grade ?? '' }}{{ ! empty($enseignant->specialite) ? ' · '.$enseignant->specialite : '' }}</div>
      @else
      <div style="font-size:18px;font-weight:800">
        @if ($mode === 'chef') Mode Chef de département — Suivi des matières
        @elseif ($mode === 'faculte') {{ auth()->user()->role === 'doyen' ? 'Mode Doyen' : 'Mode Agent de faculté' }} — Suivi des matières
        @else Mode Administration — Saisie des notes
        @endif
      </div>
      <div style="opacity:.8;font-size:12px">{{ $perimetre }} · Rôle : {{ auth()->user()->role }}</div>
      @endif
    </div>
    <div style="margin-left:auto">
      <select class="form-control" aria-label="Année académique" data-navigate="{{ route('portail_enseignant') }}?annee_id=">
        @foreach ($annees as $a)<option value="{{ $a->id }}" @selected($a->id == $anneeId)>{{ $a->libelle }}</option>@endforeach
      </select>
    </div>
  </div>
</div>

<div style="display:grid;grid-template-columns:280px 1fr;gap:20px;align-items:start">
  <div class="card">
    <div class="card-header"><span class="card-title">📚 {{ $mode === 'enseignant' ? 'Mes matières' : 'Matières' }}</span></div>
    <div class="card-body" style="padding:8px">
      @forelse ($affectations as $af)
      @php
        $sel = $choix && $af->mat_id == $choix['mat']->id && $af->pv_id == $choix['pv']->id;
        $ratt = $af->type_session === 'rattrapage';
        $peutFin = $peutFinaliser[$af->mat_id] ?? false;
        $heures = (float) $af->heures_faites;
        $taux = $af->volume_horaire_prevu ? min(100, round($heures / $af->volume_horaire_prevu * 100, 1)) : null;
      @endphp
      <div style="margin-bottom:6px">
        @if ($af->pv_id)
        <a href="{{ route('portail_enseignant', ['annee_id' => $anneeId, 'pv_id' => $af->pv_id, 'mat_id' => $af->mat_id]) }}"
           style="display:block;padding:8px 10px;border-radius:6px 6px 0 0;text-decoration:none;background:{{ $sel ? 'var(--green-50)' : 'transparent' }};border:1px solid {{ $sel ? 'var(--green-300)' : 'var(--border)' }};border-bottom:none">
          <div style="font-weight:700;font-size:12px;color:var(--text-primary)">{{ $af->matiere }}</div>
          <div style="font-size:11px;color:var(--text-muted)">{{ $af->filiere }} {{ $af->niveau }}</div>
          <div style="display:flex;gap:6px;margin-top:3px;flex-wrap:wrap">
            <span style="background:var(--blue-50);color:var(--blue-700);padding:1px 6px;border-radius:8px;font-size:10px">{{ $af->sem_label }}</span>
            <span style="background:{{ $ratt ? '#fef3c7' : 'var(--green-50)' }};color:{{ $ratt ? '#b45309' : 'var(--green-700)' }};padding:1px 6px;border-radius:8px;font-size:10px;font-weight:700">{{ $ratt ? '🔄 Rattrapage' : '📋 Normale' }}</span>
            <span style="background:var(--gray-50);color:var(--text-muted);padding:1px 6px;border-radius:8px;font-size:10px">{{ $af->type_charge }}</span>
          </div>
        </a>
        @else
        <div style="padding:8px 10px;border-radius:6px 6px 0 0;border:1px dashed var(--border);border-bottom:none;opacity:.6">
          <div style="font-weight:600;font-size:12px">{{ $af->matiere }}</div>
          <div style="font-size:10px;color:var(--text-muted)">Pas de PV disponible</div>
        </div>
        @endif
        <div style="padding:5px 10px;border:1px solid var(--border);background:{{ $af->finalisee ? '#f0fdf4' : '#fffbeb' }};display:flex;justify-content:space-between;align-items:center;gap:6px">
          <span style="font-size:10px;font-weight:700;color:{{ $af->finalisee ? 'var(--green-700)' : '#b45309' }}">{{ $af->finalisee ? '✅ Finalisée' : ($af->en_attente ? '📝 '.$af->en_attente.' note(s) à finaliser' : '⏳ En cours') }}</span>
          @if ($af->pv_id && $peutFin)
          <div style="display:flex;gap:4px">
            @foreach (array_filter(['finaliser' => ! $af->finalisee, 'rouvrir' => $af->une_finalisee && $mode !== 'enseignant']) as $action => $_)
            <form method="post" action="{{ route('portail_enseignant.finalisation') }}" style="margin:0" data-confirm="{{ $action === 'rouvrir' ? 'Rouvrir ces notes ? L\'enseignant pourra de nouveau les modifier.' : 'Finaliser ces notes ? Elles seront placées dans le PV et ne pourront plus être modifiées par l\'enseignant.' }}">
              @csrf
              <input type="hidden" name="annee_id" value="{{ $anneeId }}">
              <input type="hidden" name="pv_id" value="{{ $af->pv_id }}">
              <input type="hidden" name="mat_id" value="{{ $af->mat_id }}">
              <input type="hidden" name="action" value="{{ $action }}">
              <button type="submit" class="btn btn-sm" style="font-size:10px;padding:2px 8px;border:none;border-radius:4px;background:{{ $action === 'rouvrir' ? 'var(--gray-100)' : 'var(--green-100)' }};color:{{ $action === 'rouvrir' ? 'var(--text-muted)' : 'var(--green-700)' }}">{{ $action === 'rouvrir' ? '↩️ Rouvrir' : '✅ Finaliser' }}</button>
            </form>
            @endforeach
          </div>
          @endif
        </div>
        <div style="padding:5px 10px;border:1px solid var(--border);border-top:none;border-radius:0 0 6px 6px;background:var(--gray-50);font-size:10px">
          @if ($af->volume_horaire_prevu)
          <div style="display:flex;justify-content:space-between;margin-bottom:3px">
            <span style="color:var(--text-muted)">🕐 {{ $heures }}h / {{ (float) $af->volume_horaire_prevu }}h prévues</span>
            <span style="font-weight:700;color:{{ $taux >= 100 ? 'var(--green-700)' : ($taux >= 50 ? '#b45309' : '#dc2626') }}">{{ $taux }}%</span>
          </div>
          @else
          <div style="color:var(--text-muted);margin-bottom:3px">🕐 Volume horaire non fixé</div>
          @endif
          @if ($peutFin && ! $af->finalisee)
          <form method="post" action="{{ route('portail_enseignant.heures') }}" style="display:flex;gap:4px;margin-bottom:4px">
            @csrf
            <input type="hidden" name="annee_id" value="{{ $anneeId }}">
            <input type="hidden" name="mat_id" value="{{ $af->mat_id }}">
            <input type="number" name="heures_faites" value="{{ $heures }}" min="0" step="0.5" aria-label="Heures effectuées" style="width:60px;padding:2px 4px;font-size:10px;border:1px solid var(--border);border-radius:3px">
            <button type="submit" class="btn btn-sm" style="font-size:9px;padding:2px 6px;background:var(--blue-50);color:var(--blue-700);border:none;border-radius:3px">💾 Heures faites</button>
          </form>
          @endif
          @if ($peutFin && $mode !== 'enseignant')
          <form method="post" action="{{ route('portail_enseignant.volume') }}" style="display:flex;gap:4px">
            @csrf
            <input type="hidden" name="annee_id" value="{{ $anneeId }}">
            <input type="hidden" name="mat_id" value="{{ $af->mat_id }}">
            <input type="number" name="volume_horaire_prevu" value="{{ $af->volume_horaire_prevu !== null ? (float) $af->volume_horaire_prevu : '' }}" min="0.5" step="0.5" aria-label="Volume horaire prévu" style="width:60px;padding:2px 4px;font-size:10px;border:1px solid var(--border);border-radius:3px">
            <button type="submit" class="btn btn-sm" style="font-size:9px;padding:2px 6px;background:var(--gray-100);color:var(--text-muted);border:none;border-radius:3px">📋 Vol. prévu</button>
          </form>
          @endif
        </div>
      </div>
      @empty
      <div class="empty-state" style="padding:24px"><p>Aucune affectation pour cette année.</p></div>
      @endforelse
    </div>
  </div>

  <div>
    @if ($choix && $etudiants->isNotEmpty() && $onglets)
    @php
      $pv = $choix['pv']; $mat = $choix['mat'];
      $ratt = $pv->type_session === 'rattrapage';
      $max = (float) ($tc === 'cc' ? $mat->note_max_cc : $mat->note_max_examen);
      $editable = $peutSaisir && ! $lectureSeule;
      $lien = ['annee_id' => $anneeId, 'pv_id' => $pv->id, 'mat_id' => $mat->id];
    @endphp
    <div class="card">
      <div class="card-header" style="display:flex;justify-content:space-between;align-items:center;flex-wrap:wrap;gap:8px">
        <span class="card-title">{{ $ratt ? '🔄 Rattrapage' : '📝 Saisie' }} — {{ $mat->libelle }}
          @if ($ratt)<span style="background:#fef3c7;color:#b45309;padding:2px 8px;border-radius:6px;font-size:11px;margin-left:8px">{{ $etudiants->count() }} non validé(s) en S1</span>@endif
        </span>
        <div style="display:flex;gap:8px;align-items:center">
          <span style="font-size:11px;color:var(--text-muted)">{{ $etudiants->count() }} étudiants</span>
          <a href="{{ route('portail_enseignant.modele', $lien + ['tc' => $tc]) }}" class="btn btn-sm btn-secondary">⬇️ Template XLSX</a>
          @if ($editable)<button type="button" class="btn btn-sm btn-secondary" data-toggle-display="import-zone">📥 Importer XLSX</button>@endif
        </div>
      </div>
      @if ($editable)
      <div id="import-zone" style="display:none;padding:12px 16px;border-bottom:1px solid var(--border);background:var(--blue-50)">
        <form method="post" action="{{ route('portail_enseignant.importer') }}" enctype="multipart/form-data" style="display:flex;gap:10px;align-items:center;flex-wrap:wrap">
          @csrf
          <input type="hidden" name="annee_id" value="{{ $anneeId }}">
          <input type="hidden" name="pv_id" value="{{ $pv->id }}">
          <input type="hidden" name="mat_id" value="{{ $mat->id }}">
          <input type="hidden" name="type_charge" value="{{ $tc }}">
          <input type="file" name="fichier_import" class="form-control form-control-sm" accept=".xlsx,.csv" required style="max-width:280px" aria-label="Fichier de notes">
          <button type="submit" class="btn btn-primary btn-sm">📥 Importer</button>
          <span style="font-size:11px;color:var(--text-muted)">Colonnes : Matricule | Nom | Prénoms | Note ({{ $onglets[$tc] }} sur {{ $max }})</span>
        </form>
      </div>
      @elseif (! $peutSaisir)
      <div style="padding:10px 16px;border-bottom:1px solid var(--border);background:#fef3c7;font-size:12px;color:#92400e">👁️ Consultation seule — la saisie et l'import de notes sont réservés à l'enseignant affecté.</div>
      @else
      <div style="padding:10px 16px;border-bottom:1px solid var(--border);background:#f3f4f6;font-size:12px;color:var(--text-muted)">🔒 {{ $pv->verrouille ? 'PV verrouillé' : 'Notes finalisées : elles figurent dans le PV' }} — notes en lecture seule.</div>
      @endif
      @if ($editable && $mode !== 'admin')
      <div style="padding:10px 16px;border-bottom:1px solid var(--border);background:#fffbeb;font-size:12px;color:#92400e">
        📝 Les notes enregistrées ne figurent pas dans le PV tant qu'elles ne sont pas finalisées @if ($enAttente)— <strong>{{ $enAttente }} note(s) en attente</strong>@endif.
        Une fois toutes vos notes saisies, cliquez sur <strong>✅ Finaliser</strong> dans la liste des matières : elles seront placées dans le PV et verrouillées.
      </div>
      @endif
      <div class="card-body">
        @if (count($onglets) > 1)
        <div style="display:flex;gap:10px;margin-bottom:16px">
          @foreach ($onglets as $cle => $libelle)
          <a href="{{ route('portail_enseignant', $lien + ['tc' => $cle]) }}" class="btn btn-sm {{ $cle === $tc ? 'btn-primary' : 'btn-secondary' }}">{{ $libelle }}</a>
          @endforeach
        </div>
        @endif
        <form method="post" action="{{ route('portail_enseignant.saisir') }}">
          @csrf
          <input type="hidden" name="annee_id" value="{{ $anneeId }}">
          <input type="hidden" name="pv_id" value="{{ $pv->id }}">
          <input type="hidden" name="mat_id" value="{{ $mat->id }}">
          <input type="hidden" name="type_charge" value="{{ $tc }}">
          <table class="table" style="font-size:12px">
            <thead><tr><th style="width:40px">N°</th><th>Nom &amp; Prénoms</th><th style="width:80px">Matricule</th><th style="width:110px">{{ $onglets[$tc] }} (/{{ $max }})</th></tr></thead>
            <tbody>
            @foreach ($etudiants as $i => $et)
            @php $note = $tc === 'cc' ? $et->note_cc : $et->note_examen; $note = $note !== null ? (float) $note : null; @endphp
            <tr>
              <td style="text-align:center;color:var(--text-muted)">{{ $i + 1 }}</td>
              <td style="font-weight:600">{{ $et->nom }} {{ $et->prenoms }}</td>
              <td style="font-size:11px;color:var(--text-muted)">{{ $et->numero }}</td>
              <td>
                @if ($editable)
                <input type="number" name="notes[{{ $et->id }}]" class="form-control form-control-sm" value="{{ old('notes.'.$et->id, $note) }}" min="0" max="{{ $max }}" step="0.01" data-decimales="2" placeholder="—" aria-label="Note de {{ $et->nom }} {{ $et->prenoms }}">
                @else
                <span style="font-weight:600">{{ $note ?? ($et->defaillant ? 'Abs.' : '—') }}</span>
                @endif
              </td>
            </tr>
            @endforeach
            </tbody>
          </table>
          @if ($editable)
          <div style="text-align:right;margin-top:12px">
            @if ($ratt)<span style="font-size:11px;color:var(--text-muted);margin-right:10px">Case vide = absent au rattrapage.</span>@endif
            <button type="submit" class="btn btn-primary btn-lg">💾 Enregistrer les notes{{ $mode !== 'admin' ? ' (sans finaliser)' : '' }}</button>
          </div>
          @endif
        </form>
      </div>
    </div>
    @elseif ($choix && ! $onglets)
    <div class="card"><div class="empty-state" style="padding:32px"><p>Cette matière n'a pas de TD : aucune note à saisir pour votre affectation.</p></div></div>
    @elseif ($choix)
    <div class="card"><div class="empty-state" style="padding:32px"><p>Aucun étudiant trouvé.</p></div></div>
    @else
    <div class="card"><div class="empty-state" style="padding:60px">
      <div class="icon" style="font-size:48px">📝</div>
      <h3>Saisie des notes</h3>
      <p>Sélectionnez une matière dans la liste pour saisir les notes.</p>
    </div></div>
    @endif
  </div>
</div>
@endsection
