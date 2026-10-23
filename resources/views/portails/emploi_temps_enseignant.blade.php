{{-- Mon emploi du temps : séances de l'enseignant, par jour --}}
@extends('layouts.app', ['titre' => 'Mon emploi du temps', 'fil' => 'Portail enseignant › Emploi du temps'])
@use('App\Http\Controllers\Structure\EmploiTempsController', 'EDT')

@section('contenu')
<form method="get" action="{{ route('mon_emploi_temps') }}" class="card mb-16 no-print">
  <div class="card-body" style="display:flex;gap:10px;align-items:flex-end;flex-wrap:wrap">
    <div class="form-group" style="margin:0"><label class="form-label" for="f-annee">Année académique</label>
      <select name="annee_id" id="f-annee" class="form-control">
        @foreach ($annees as $a)<option value="{{ $a->id }}" @selected($a->id == $anneeId)>{{ $a->libelle }}</option>@endforeach
      </select></div>
    <div class="form-group" style="margin:0"><label class="form-label" for="f-sem">Semestre</label>
      <select name="sem_id" id="f-sem" class="form-control"><option value="">Tous</option>
        @foreach ($semestres as $s)<option value="{{ $s->id }}" @selected($s->id == $semId)>{{ $s->libelle }}</option>@endforeach
      </select></div>
    <button type="submit" class="btn btn-secondary">🔍 Afficher</button>
    <button type="button" class="btn btn-secondary" data-print>🖨️ Imprimer</button>
    <span style="margin-left:auto;font-size:12px;color:var(--text-muted)">{{ $nb }} séance(s) par semaine · {{ $heures }} h</span>
  </div>
</form>

@if ($nb === 0)
<div class="card"><div class="empty-state" style="padding:40px"><p>Aucune séance à votre nom dans l'emploi du temps de cette période.</p></div></div>
@else
<div style="display:grid;grid-template-columns:repeat(auto-fill,minmax(260px,1fr));gap:14px">
  @foreach ($jours as $jour => $seances)
  <div class="card">
    <div class="card-header" style="background:#1A3A5C"><span class="card-title" style="color:#fff">{{ $jour }}</span></div>
    <div class="card-body" style="padding:8px">
      @forelse ($seances as $s)
      @php $couleur = preg_match('/^#[0-9a-fA-F]{6}$/', (string) $s->couleur) ? $s->couleur : (EDT::TYPES[$s->type_seance][1] ?? '#1A3A5C'); @endphp
      <div style="border-left:4px solid {{ $couleur }};background:var(--gray-50);border-radius:4px;padding:6px 10px;margin-bottom:6px">
        <div style="display:flex;justify-content:space-between;gap:6px">
          <strong style="font-size:12px">{{ substr($s->heure_debut, 0, 5) }} – {{ substr($s->heure_fin, 0, 5) }}</strong>
          <span style="background:{{ $couleur }};color:#fff;padding:0 6px;border-radius:8px;font-size:10px">{{ EDT::TYPES[$s->type_seance][0] ?? $s->type_seance }}</span>
        </div>
        <div style="font-weight:600;font-size:12px">{{ $s->matiere ?? '—' }}</div>
        <div style="font-size:11px;color:var(--text-muted)">{{ $s->filiere }} {{ $s->niveau }} · {{ $s->semestre }}@if ($s->salle) · 📍 {{ $s->salle }}@endif</div>
        @if ($s->conflit)<div style="font-size:10px;color:#b91c1c;font-weight:700">⚠️ Chevauche une autre séance</div>@endif
      </div>
      @empty
      <div style="font-size:11px;color:var(--text-muted);padding:6px">Aucune séance</div>
      @endforelse
    </div>
  </div>
  @endforeach
</div>
@endif
@endsection
