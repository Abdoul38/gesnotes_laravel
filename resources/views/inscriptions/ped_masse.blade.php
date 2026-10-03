{{-- Inscription pédagogique en masse — portage de inscription_ped_masse.php --}}
@extends('layouts.app', ['titre' => 'Inscription Pédagogique en Masse', 'fil' => 'Inscriptions › Pédago en masse'])

@section('contenu')
<div class="card mb-20">
  <div class="card-header"><span class="card-title">🔍 Sélectionner la promotion</span></div>
  <div class="card-body">
    <form method="get" action="{{ route('inscription_ped_masse') }}" style="display:grid;grid-template-columns:1fr 1fr 1fr auto;gap:12px;align-items:flex-end">
      <div class="form-group" style="margin:0">
        <label class="form-label" for="annee_id">Année académique</label>
        <select name="annee_id" id="annee_id" class="form-control" data-autosubmit="1">
          <option value="">— Choisir —</option>
          @foreach ($annees as $a)<option value="{{ $a->id }}" @selected($a->id == $anneeId)>{{ $a->libelle }}{{ $a->active ? ' (En cours)' : '' }}</option>@endforeach
        </select>
      </div>
      <div class="form-group" style="margin:0">
        <label class="form-label" for="filiere_id">Filière</label>
        <select name="filiere_id" id="filiere_id" class="form-control" data-autosubmit="1">
          <option value="">— Choisir —</option>
          @foreach ($filieres as $f)<option value="{{ $f->id }}" @selected($f->id == $filiereId)>{{ $f->libelle }}</option>@endforeach
        </select>
      </div>
      <div class="form-group" style="margin:0">
        <label class="form-label" for="niveau_id">Niveau</label>
        <select name="niveau_id" id="niveau_id" class="form-control" data-autosubmit="1">
          <option value="">— Choisir —</option>
          @foreach ($niveaux as $n)<option value="{{ $n->id }}" @selected($n->id == $niveauId)>{{ $n->code }} — {{ $n->libelle }}</option>@endforeach
        </select>
      </div>
      <button type="submit" class="btn btn-primary">🔍 Afficher</button>
    </form>
  </div>
</div>

@if ($anneeId && $filiereId && $niveauId)
@if ($etudiants->isEmpty())
<div class="card">
  <div class="card-body" style="text-align:center;padding:40px;color:var(--text-muted)">
    <div style="font-size:40px;margin-bottom:12px">📭</div>
    Aucun étudiant trouvé pour cette promotion.
  </div>
</div>
@else
@php($nbDeja = $etudiants->filter(fn ($e) => $e->deja)->count())
<div style="display:grid;grid-template-columns:repeat(3,1fr);gap:16px;margin-bottom:20px">
  <div class="card" style="text-align:center;padding:16px">
    <div style="font-size:28px">👥</div>
    <div style="font-size:24px;font-weight:800;color:var(--primary)">{{ $etudiants->count() }}</div>
    <div style="font-size:11px;color:var(--text-muted);text-transform:uppercase">Total inscrits</div>
  </div>
  <div class="card" style="text-align:center;padding:16px">
    <div style="font-size:28px">✅</div>
    <div style="font-size:24px;font-weight:800;color:var(--green-700)">{{ $nbDeja }}</div>
    <div style="font-size:11px;color:var(--text-muted);text-transform:uppercase">Déjà inscrits pédago</div>
  </div>
  <div class="card" style="text-align:center;padding:16px">
    <div style="font-size:28px">⏳</div>
    <div style="font-size:24px;font-weight:800;color:var(--amber-600)">{{ $etudiants->count() - $nbDeja }}</div>
    <div style="font-size:11px;color:var(--text-muted);text-transform:uppercase">En attente</div>
  </div>
</div>

<form method="post" action="{{ route('inscription_ped_masse.enregistrer') }}" id="frmPedMasse" data-confirm="Enregistrer les inscriptions pédagogiques pour tous les étudiants cochés ?">
  @csrf
  <input type="hidden" name="annee_id" value="{{ $anneeId }}">
  <input type="hidden" name="filiere_id" value="{{ $filiereId }}">
  <input type="hidden" name="niveau_id" value="{{ $niveauId }}">
  <input type="hidden" name="etudiants_json" id="etudiants_json" value="">

  <div class="card">
    <div class="card-header" style="display:flex;justify-content:space-between;align-items:center">
      <span class="card-title">🎓 Inscription pédagogique — Semestres par étudiant</span>
      <div style="display:flex;gap:8px">
        <button type="button" class="btn btn-secondary btn-sm" id="btn-suggestions">✦ Appliquer toutes les suggestions</button>
        <button type="submit" class="btn btn-primary btn-sm">✅ Enregistrer tout</button>
      </div>
    </div>
    <div style="overflow-x:auto">
    <table style="width:100%;border-collapse:collapse;font-size:13px">
      <thead>
        <tr style="background:var(--primary);color:#fff">
          <th style="padding:10px 14px;text-align:left;min-width:200px">Étudiant</th>
          <th style="padding:10px 14px;text-align:center">Matricule</th>
          <th style="padding:10px 14px;text-align:center">Validés</th>
          @foreach ($semestres as $s)<th style="padding:10px 14px;text-align:center;min-width:60px">{{ $s->code }}</th>@endforeach
          <th style="padding:10px 14px;text-align:center">Statut</th>
        </tr>
      </thead>
      <tbody>
      @foreach ($etudiants as $et)
      <tr style="border-bottom:1px solid var(--border);background:{{ $et->deja ? '#f0fdf4' : '#fff' }}" data-et-id="{{ $et->id }}" data-sugg="{{ json_encode($et->sugg_ids) }}">
        <td style="padding:10px 14px;font-weight:700">{{ $et->nom }} {{ $et->prenoms }}</td>
        <td style="padding:10px 14px;text-align:center;font-family:monospace;color:var(--blue-700);font-weight:700">{{ $et->numero }}</td>
        <td style="padding:10px 14px;text-align:center">
          <div style="display:flex;gap:3px;justify-content:center;flex-wrap:wrap">
            @forelse ($et->valides as $v)
            <span style="background:#dcfce7;color:#166534;padding:1px 5px;border-radius:6px;font-size:10px;font-weight:700">{{ $v }}</span>
            @empty
            <span style="color:#9ca3af;font-size:11px">—</span>
            @endforelse
          </div>
        </td>
        @foreach ($semestres as $s)
        @php($sugg = in_array($s->code, $et->suggeres, true))
        @php($deja = in_array((int) $s->id, $et->deja, true))
        <td style="padding:6px;text-align:center">
          <label style="display:flex;flex-direction:column;align-items:center;gap:2px;cursor:pointer">
            <input type="checkbox" value="{{ $s->id }}" class="chk-sem" @checked($sugg || $deja) aria-label="{{ $s->code }} — {{ $et->nom }}" style="width:16px;height:16px;accent-color:var(--primary)">
            @if ($sugg && ! $deja)<span style="font-size:8px;color:var(--primary)">✦</span>@elseif ($deja)<span style="font-size:8px;color:#16a34a">✓</span>@endif
          </label>
        </td>
        @endforeach
        <td style="padding:10px 14px;text-align:center">
          @if ($et->deja)
          <span style="background:#dcfce7;color:#166534;padding:2px 8px;border-radius:10px;font-size:11px;font-weight:700">{{ implode(' + ', $et->deja_codes) }}</span>
          @else
          <span style="background:#fef3c7;color:#92400e;padding:2px 8px;border-radius:10px;font-size:11px;font-weight:700">En attente</span>
          @endif
        </td>
      </tr>
      @endforeach
      </tbody>
    </table>
    </div>
    <div class="card-footer" style="display:flex;justify-content:space-between;align-items:center;padding:14px 16px">
      <div style="font-size:13px;color:var(--text-muted)">
        <span style="color:var(--primary);font-weight:700">✦</span> = Suggéré automatiquement &nbsp;|&nbsp;
        <span style="color:#16a34a;font-weight:700">✓</span> = Déjà inscrit
      </div>
      <button type="submit" class="btn btn-primary" style="padding:10px 28px">✅ Enregistrer tout</button>
    </div>
  </div>
</form>
@endif
@else
<div class="card">
  <div class="card-body" style="text-align:center;padding:60px;color:var(--text-muted)">
    <div style="font-size:56px;margin-bottom:16px">🎓</div>
    <div style="font-size:16px;font-weight:700;color:var(--primary);margin-bottom:8px">Sélectionnez une promotion</div>
    <div style="font-size:13px">Choisissez l'année, la filière et le niveau pour afficher les étudiants.</div>
  </div>
</div>
@endif
@endsection

@push('scripts')
<script src="{{ asset('assets/js/ped-masse.js') }}"></script>
@endpush
