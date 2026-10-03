{{-- Suivi d'exécution du programme — portage de pages/structure/suivi_execution.php --}}
@extends('layouts.app', ['titre' => "Suivi d'exécution", 'fil' => 'Programme — heures faites vs prévues'])

@section('contenu')
@php
  $badges = ['complete' => ['✅ Complète', '#dcfce7', '#166534'], 'en_cours' => ['🟡 En cours', '#fef9c3', '#92400e'], 'sans_toucher' => ['🔴 Sans heure', '#fee2e2', '#991b1b']];
  $compte = $matieres->countBy('categorie');
@endphp
<div class="card mb-20"><div class="card-body">
  <form method="get" action="{{ route('suivi_execution') }}" style="display:flex;gap:10px;align-items:flex-end;margin-bottom:16px;flex-wrap:wrap">
    <div><label class="form-label" for="annee_id">Année académique</label>
      <select name="annee_id" id="annee_id" class="form-control" style="width:auto" data-autosubmit="1">@foreach ($annees as $a)<option value="{{ $a->id }}" @selected($anneeId == $a->id)>{{ $a->libelle }}{{ $a->active ? ' (active)' : '' }}</option>@endforeach</select></div>
    <div><label class="form-label" for="filiere_id">Filière</label>
      <select name="filiere_id" id="filiere_id" class="form-control" style="width:auto" data-autosubmit="1"><option value="0">Toutes</option>@foreach ($filieres as $f)<option value="{{ $f->id }}" @selected($filiereId == $f->id)>{{ $f->libelle }}</option>@endforeach</select></div>
    <div><label class="form-label" for="semestre_id">Semestre</label>
      <select name="semestre_id" id="semestre_id" class="form-control" style="width:auto" data-autosubmit="1"><option value="0">Tous</option>@foreach ($semestres as $s)<option value="{{ $s->id }}" @selected($semestreId == $s->id)>{{ $s->code }}</option>@endforeach</select></div>
  </form>
  <div style="display:grid;grid-template-columns:repeat(auto-fit,minmax(150px,1fr));gap:12px;margin-bottom:16px">
    <div style="background:{{ $taux >= 80 ? '#dcfce7' : ($taux >= 50 ? '#fff7ed' : '#fee2e2') }};border-radius:8px;padding:14px;text-align:center">
      <div style="font-size:26px;font-weight:800;color:{{ $taux >= 80 ? '#166534' : ($taux >= 50 ? '#C8860A' : '#dc2626') }}">{{ $taux }}%</div>
      <div style="font-size:11px;color:#6b7280">📊 Taux d'exécution ({{ $faites }}h/{{ $prevues }}h)</div>
    </div>
    @foreach ($badges as $cat => [$lib, $bg, $fg])
    <button type="button" data-filtre-exec="{{ $cat }}" style="background:{{ $bg }};border:2px solid transparent;border-radius:8px;padding:14px;text-align:center;cursor:pointer">
      <div style="font-size:26px;font-weight:800;color:{{ $fg }}">{{ $compte[$cat] ?? 0 }}</div><div style="font-size:11px;color:{{ $fg }}">{{ $lib }}</div>
    </button>
    @endforeach
  </div>
  <div style="display:flex;gap:8px;margin-bottom:14px;align-items:center;flex-wrap:wrap">
    <input type="text" id="srch-exec" placeholder="🔍 Rechercher une matière, filière ou enseignant..." class="form-control" style="max-width:340px" aria-label="Rechercher">
    <button type="button" data-filtre-exec="" class="btn btn-secondary btn-sm">↺ Tout afficher</button>
    <span id="nb-exec-affiche" style="font-size:12px;color:var(--text-muted);margin-left:auto">{{ $matieres->count() }} matière(s)</span>
  </div>
  <div style="overflow-x:auto"><table class="table" id="table-exec">
    <thead><tr><th>Matière</th><th>Filière</th><th>Niv.</th><th>Sem.</th><th>Enseignant</th><th>Prévu</th><th>Fait</th><th>%</th><th>Statut</th>@if ($peutEditer)<th>Action</th>@endif</tr></thead>
    <tbody>
    @forelse ($matieres as $m)
    @php [$lib, $bg, $fg] = $badges[$m->categorie]; @endphp
    <tr class="exec-row" data-cat="{{ $m->categorie }}" data-txt="{{ mb_strtolower($m->matiere.' '.$m->filiere.' '.$m->enseignant) }}">
      <td style="font-weight:600">{{ $m->matiere }}</td><td>{{ $m->filiere }}</td><td><span class="badge badge-blue">{{ $m->niveau }}</span></td><td><span class="badge badge-blue">{{ $m->semestre }}</span></td>
      <td>{{ $m->enseignant ?? '—' }}</td><td style="text-align:center">{{ (float) $m->volume_horaire_prevu }}h</td><td style="text-align:center">{{ (float) $m->heures_faites }}h</td>
      <td style="text-align:center;font-weight:700">{{ $m->pct }}%</td><td><span class="badge" style="background:{{ $bg }};color:{{ $fg }}">{{ $lib }}</span></td>
      @if ($peutEditer)
      <td><form method="post" action="{{ route('suivi_execution.heures') }}" style="display:flex;gap:4px;align-items:center">
        @csrf
        <input type="hidden" name="mat_id" value="{{ $m->id }}"><input type="hidden" name="annee_id" value="{{ $anneeId }}"><input type="hidden" name="filiere_id" value="{{ $filiereId }}"><input type="hidden" name="semestre_id" value="{{ $semestreId }}">
        <input type="number" name="heures_faites" value="{{ (float) $m->heures_faites }}" min="0" step="0.5" aria-label="Heures faites" style="width:60px;padding:3px 5px;font-size:11px;border:1px solid var(--border);border-radius:4px">
        <button type="submit" class="btn btn-sm btn-secondary" style="font-size:10px;padding:3px 8px" aria-label="Enregistrer">💾</button>
      </form></td>
      @endif
    </tr>
    @empty
    <tr><td colspan="{{ $peutEditer ? 10 : 9 }}"><div class="empty-state"><div class="icon">🕐</div>Aucune matière ne correspond à ces critères.</div></td></tr>
    @endforelse
    </tbody>
  </table></div>
</div></div>
@endsection

@push('scripts')
<script src="{{ asset('assets/js/suivi-execution.js') }}"></script>
@endpush
