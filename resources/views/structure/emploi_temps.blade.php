{{-- Emploi du temps — portage de pages/structure/emploi_temps.php --}}
@extends('layouts.app', ['titre' => 'Emploi du temps', 'fil' => 'Administratif › Emploi du temps'])

@use('App\Http\Controllers\Structure\EmploiTempsController', 'EDT')

@section('contenu')
@php $lien = fn (array $p = []) => route('emploi_temps', $p + ['promo_id' => $promo->id ?? null, 'annee_id' => $anneeId, 'sem_id' => $semId]); @endphp
<div class="card mb-16"><div class="card-body">
  <form method="get" action="{{ route('emploi_temps') }}" style="display:flex;gap:12px;align-items:flex-end;flex-wrap:wrap">
    <div class="form-group" style="margin:0;flex:1;min-width:200px"><label class="form-label" for="promo_id">Promotion</label>
      <select name="promo_id" id="promo_id" class="form-control" data-autosubmit="1"><option value="">— Choisir —</option>
        @foreach ($promos as $p)<option value="{{ $p->id }}" @selected($promo && $p->id == $promo->id)>{{ $p->niveau }} {{ $p->filiere }} — {{ $p->annee }}</option>@endforeach</select></div>
    <div class="form-group" style="margin:0;min-width:160px"><label class="form-label" for="annee_id">Année</label>
      <select name="annee_id" id="annee_id" class="form-control" data-autosubmit="1">@foreach ($annees as $a)<option value="{{ $a->id }}" @selected($a->id == $anneeId)>{{ $a->libelle }}</option>@endforeach</select></div>
    <div class="form-group" style="margin:0;min-width:140px"><label class="form-label" for="sem_id">Semestre</label>
      <select name="sem_id" id="sem_id" class="form-control" data-autosubmit="1">@foreach ($semestres as $s)<option value="{{ $s->id }}" @selected($s->id == $semId)>{{ $s->libelle }}</option>@endforeach</select></div>
    @if ($promo)<a href="{{ $lien(['imprimer' => 1]) }}" target="_blank" rel="noopener" class="btn btn-secondary">🖨️ Imprimer</a>@endif
  </form>
</div></div>

@if ($promo)
<div class="card mb-16">
  <div class="card-header"><span class="card-title">📅 Emploi du temps</span>
    @if ($peutModifier)<button type="button" class="btn btn-primary btn-sm" data-edt-ajouter>➕ Ajouter une séance</button>@endif</div>
  <div class="card-body" style="padding:0;overflow-x:auto">
    <table style="width:100%;border-collapse:collapse;font-size:11px;min-width:800px">
      <thead><tr><th style="background:#1A3A5C;color:#fff;padding:8px;width:80px">Horaires</th>@foreach (EDT::JOURS as $j)<th style="background:#1A3A5C;color:#fff;padding:8px">{{ $j }}</th>@endforeach</tr></thead>
      <tbody>
      @for ($h = 0; $h < count(EDT::HEURES) - 1; $h++)
      <tr style="height:50px">
        <td style="background:#f5f5f5;text-align:center;font-weight:600;font-size:10px;border:0.5px solid #ddd;color:#555;white-space:nowrap">{{ EDT::HEURES[$h] }} → {{ EDT::HEURES[$h + 1] }}</td>
        @foreach (EDT::JOURS as $j)
        @php $cell = $grille[$j][$h]; @endphp
        @continue($cell === 'skip')
        @php $s = is_array($cell) ? $cell['seance'] : null; @endphp
        <td rowspan="{{ is_array($cell) ? $cell['rowspan'] : 1 }}" style="border:0.5px solid #e0e0e0;padding:2px;vertical-align:top;background:{{ $s ? '#fff' : '#fafafa' }}">
          @if ($s)
          @php $donnees = ['id' => $s->id, 'jour' => $s->jour, 'heure_debut' => substr($s->heure_debut, 0, 5), 'heure_fin' => substr($s->heure_fin, 0, 5), 'matiere_id' => $s->matiere_id,
            'enseignant_id' => $s->enseignant_id, 'salle_id' => $s->salle_id, 'type_seance' => $s->type_seance, 'couleur' => $s->couleur ?: EDT::TYPES[$s->type_seance][1]]; @endphp
          <div @if ($peutModifier) data-edt-seance="{{ json_encode($donnees) }}" role="button" tabindex="0" @endif
            style="background:{{ preg_match('/^#[0-9a-fA-F]{6}$/', (string) $s->couleur) ? $s->couleur : EDT::TYPES[$s->type_seance][1] }};color:#fff;border-radius:4px;padding:6px 8px;{{ $peutModifier ? 'cursor:pointer;' : '' }}font-size:10px;line-height:1.5">
            <div style="font-weight:700;font-size:11px">{{ $s->matiere ?? (EDT::TYPES[$s->type_seance][0] ?? '—') }}</div>
            <div style="opacity:.85;font-size:9px">{{ substr($s->heure_debut, 0, 5) }}–{{ substr($s->heure_fin, 0, 5) }}@if ($s->salle)<br>📍 {{ $s->salle }}@endif</div>
            @if ($s->ens_nom)<div style="opacity:.8;font-size:9px">👤 {{ $s->ens_nom }} {{ $s->ens_pren }}</div>@endif
            <div style="font-size:8px;opacity:.7;text-transform:uppercase">{{ EDT::TYPES[$s->type_seance][0] ?? '' }}</div>
          </div>
          @endif
        </td>
        @endforeach
      </tr>
      @endfor
      </tbody>
    </table>
  </div>
</div>
<div style="display:flex;gap:10px;flex-wrap:wrap;margin-bottom:16px">
  @foreach (EDT::TYPES as [$lib, $col])<span style="background:{{ $col }};color:#fff;padding:4px 12px;border-radius:12px;font-size:12px;font-weight:600">{{ $lib }}</span>@endforeach
</div>

@if ($peutModifier)
<div id="modal-edt" class="modal-overlay" style="display:none">
  <div class="modal">
    <div class="modal-header"><span class="modal-title" id="edt-titre">➕ Ajouter une séance</span><button type="button" data-onclick="closeModal" data-arg="modal-edt" class="btn btn-icon" aria-label="Fermer">✕</button></div>
    <form method="post" action="{{ route('emploi_temps.enregistrer') }}" id="form-edt" data-edt data-url-disponibilite="{{ route('emploi_temps.disponibilite') }}" data-annee="{{ $anneeId }}" data-semestre="{{ $semId }}">
      @csrf
      <input type="hidden" name="id" value="">
      <input type="hidden" name="promotion_id" value="{{ $promo->id }}"><input type="hidden" name="annee_id" value="{{ $anneeId }}"><input type="hidden" name="semestre_id" value="{{ $semId }}">
      <div class="modal-body">
        <div style="display:grid;grid-template-columns:1fr 1fr;gap:10px">
          <div class="form-group"><label class="form-label" for="edt-jour">Jour</label><select name="jour" id="edt-jour" class="form-control" required>@foreach (EDT::JOURS as $j)<option value="{{ $j }}">{{ $j }}</option>@endforeach</select></div>
          <div class="form-group"><label class="form-label" for="edt-type">Type</label><select name="type_seance" id="edt-type" class="form-control">@foreach (EDT::TYPES as $k => [$lib])<option value="{{ $k }}">{{ $lib }}</option>@endforeach</select></div>
          <div class="form-group"><label class="form-label" for="edt-debut">Début</label><input type="time" name="heure_debut" id="edt-debut" class="form-control" value="08:00" required></div>
          <div class="form-group"><label class="form-label" for="edt-fin">Fin</label><input type="time" name="heure_fin" id="edt-fin" class="form-control" value="10:00" required></div>
        </div>
        <div class="form-group"><label class="form-label" for="edt-matiere">Matière</label><select name="matiere_id" id="edt-matiere" class="form-control"><option value="">—</option>@foreach ($matieres as $m)<option value="{{ $m->id }}">{{ $m->code }} — {{ $m->libelle }}</option>@endforeach</select></div>
        <div class="form-group"><label class="form-label" for="edt-ens">Enseignant</label><select name="enseignant_id" id="edt-ens" class="form-control"><option value="">—</option>@foreach ($enseignants as $e)<option value="{{ $e->id }}">{{ $e->nom }} {{ $e->prenoms }}</option>@endforeach</select></div>
        <div style="display:grid;grid-template-columns:2fr 1fr;gap:10px">
          <div class="form-group"><label class="form-label" for="edt-salle">Salle</label>
            <select name="salle_id" id="edt-salle" class="form-control"><option value="">— Aucune —</option>
              @foreach ($salles->groupBy('batiment') as $bat => $liste)<optgroup label="{{ $bat ?: 'Sans bâtiment' }}">@foreach ($liste as $sa)<option value="{{ $sa->id }}">{{ $sa->code }} — {{ $sa->nom }} ({{ $sa->capacite }})</option>@endforeach</optgroup>@endforeach
            </select>
            <div data-avertissement style="display:none;font-size:11px;color:#b45309;margin-top:2px">⚠️ Salle occupée sur ce créneau</div></div>
          <div class="form-group"><label class="form-label" for="edt-couleur">Couleur</label><input type="color" name="couleur" id="edt-couleur" class="form-control" value="#1A3A5C" style="height:38px;padding:2px"></div>
        </div>
      </div>
      <div class="modal-footer"><button type="button" data-onclick="closeModal" data-arg="modal-edt" class="btn btn-secondary">Annuler</button><button type="submit" class="btn btn-primary">💾 Enregistrer</button></div>
    </form>
    <form method="post" action="{{ route('emploi_temps.supprimer') }}" id="form-edt-suppr" style="display:none;padding:0 20px 16px" data-confirm="Supprimer cette séance ?">
      @csrf
      <input type="hidden" name="id" value="">
      <button type="submit" class="btn btn-danger">🗑️ Supprimer cette séance</button>
    </form>
  </div>
</div>
@endif
@else
<div class="card"><div class="empty-state" style="padding:60px"><div class="icon" style="font-size:48px">🗓️</div><h3>Emploi du temps</h3><p>Choisissez une promotion pour afficher son emploi du temps.</p></div></div>
@endif
@endsection

@push('scripts')
<script src="{{ \App\Support\Ressource::url('assets/js/salles-disponibilite.js') }}"></script>
<script src="{{ \App\Support\Ressource::url('assets/js/emploi-temps.js') }}"></script>
@endpush
