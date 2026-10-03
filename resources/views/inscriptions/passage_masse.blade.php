{{-- Passage en masse — portage de passage_niveau.php --}}
@extends('layouts.app', ['titre' => 'Passage / Promotion en masse', 'fil' => 'Inscriptions › Passage en masse'])

@section('contenu')
<div class="card mb-20">
  <div class="card-header"><span class="card-title">🔍 Sélectionner la promotion</span></div>
  <div class="card-body">
    <form method="get" action="{{ route('passage_niveau') }}" style="display:grid;grid-template-columns:1fr 1fr 1fr auto;gap:12px;align-items:flex-end">
      <div class="form-group" style="margin:0">
        <label class="form-label" for="annee_id">Année académique</label>
        <select name="annee_id" id="annee_id" class="form-control" data-autosubmit="1">
          <option value="">— Choisir —</option>
          @foreach ($annees as $a)<option value="{{ $a->id }}" @selected($a->id == $anneeId)>{{ $a->libelle }}</option>@endforeach
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
@php
    $nbPassage = $etudiants->where('statut', 'passage')->count();
    $nbRedouble = $etudiants->where('statut', 'redoublement')->count();
    $nbLicence = $etudiants->where('statut', 'licence_validee')->count();
@endphp
<div style="display:grid;grid-template-columns:repeat(4,1fr);gap:16px;margin-bottom:20px">
  <div class="card" style="text-align:center;padding:16px">
    <div style="font-size:28px;margin-bottom:4px">👥</div>
    <div style="font-size:24px;font-weight:800;color:var(--primary)">{{ $etudiants->count() }}</div>
    <div style="font-size:11px;color:var(--text-muted);text-transform:uppercase">Total étudiants</div>
  </div>
  <div class="card" style="text-align:center;padding:16px">
    <div style="font-size:28px;margin-bottom:4px">📈</div>
    <div style="font-size:24px;font-weight:800;color:#166534">{{ $nbPassage }}</div>
    <div style="font-size:11px;color:var(--text-muted);text-transform:uppercase">Passent au niveau sup.</div>
  </div>
  <div class="card" style="text-align:center;padding:16px">
    <div style="font-size:28px;margin-bottom:4px">🎓</div>
    <div style="font-size:24px;font-weight:800;color:#166534">{{ $nbLicence }}</div>
    <div style="font-size:11px;color:var(--text-muted);text-transform:uppercase">Licence validée</div>
  </div>
  <div class="card" style="text-align:center;padding:16px">
    <div style="font-size:28px;margin-bottom:4px">🔄</div>
    <div style="font-size:24px;font-weight:800;color:#92400e">{{ $nbRedouble }}</div>
    <div style="font-size:11px;color:var(--text-muted);text-transform:uppercase">Redoublent</div>
  </div>
</div>

<form method="post" action="{{ route('passage_niveau.enregistrer') }}" id="frmPassage">
  @csrf
  <input type="hidden" name="annee_id" value="{{ $anneeId }}">
  <input type="hidden" name="filiere_id" value="{{ $filiereId }}">
  <input type="hidden" name="niveau_id" value="{{ $niveauId }}">

  <div class="card">
    <div class="card-header" style="display:flex;justify-content:space-between;align-items:center">
      <span class="card-title">📋 Étudiants — Passage automatique</span>
      <div style="display:flex;gap:10px;align-items:center">
        <label for="nouvel_annee_id" style="font-size:13px;font-weight:600">Nouvelle année :</label>
        <select name="nouvel_annee_id" id="nouvel_annee_id" class="form-control" style="width:160px" required>
          <option value="">— Choisir —</option>
          @foreach ($annees as $a)
          @continue($a->id == $anneeId)
          <option value="{{ $a->id }}" @selected($a->active)>{{ $a->libelle }}</option>
          @endforeach
        </select>
      </div>
    </div>

    <div style="background:#e8f0f8;border-left:4px solid #1A3A5C;padding:10px 16px;font-size:13px;color:#1A3A5C">
      ℹ️ <strong>Le passage enregistre une décision</strong>, pas une inscription. L'inscription académique se fait ensuite via <strong>Inscription Scolarité</strong>.
    </div>

    <div style="padding:10px 16px;border-bottom:1px solid var(--border);background:var(--gray-50);display:flex;gap:12px;align-items:center;flex-wrap:wrap">
      <label style="display:flex;align-items:center;gap:6px;cursor:pointer;font-size:13px;font-weight:600">
        <input type="checkbox" id="chkAll" style="width:16px;height:16px"> Tout sélectionner
      </label>
      <button type="button" class="btn btn-secondary btn-sm" data-selection="passage">✅ Sélectionner les passages ({{ $nbPassage }})</button>
      <button type="button" class="btn btn-secondary btn-sm" data-selection="licence_validee">🎓 Sélectionner les licences validées ({{ $nbLicence }})</button>
      <button type="button" class="btn btn-secondary btn-sm" data-selection="redoublement">🔄 Sélectionner les redoublants ({{ $nbRedouble }})</button>
      <span style="margin-left:auto;font-size:12px;color:var(--text-muted)" id="nbSelectionnes">0 sélectionné(s)</span>
    </div>

    <div class="card-body" style="padding:0">
      <table style="width:100%;border-collapse:collapse;font-size:13px">
        <thead>
          <tr style="background:var(--primary);color:#fff">
            <th style="padding:10px 14px;width:40px"></th>
            <th style="padding:10px 14px">Étudiant</th>
            <th style="padding:10px 14px">Matricule</th>
            <th style="padding:10px 14px;text-align:center">Semestres validés</th>
            <th style="padding:10px 14px;text-align:center">Niveau actuel</th>
            <th style="padding:10px 14px;text-align:center">→ Niveau suggéré</th>
            <th style="padding:10px 14px;text-align:center">Filière L3</th>
            <th style="padding:10px 14px;text-align:center">Décision</th>
          </tr>
        </thead>
        <tbody>
        @foreach ($etudiants as $et)
        @php [$fond, $couleur, $libelle] = \App\Services\Inscriptions::styleStatut($et->statut); @endphp
        <tr style="border-bottom:1px solid var(--border)" data-type="{{ $et->statut }}">
          <td style="padding:10px 14px;text-align:center">
            <input type="checkbox" name="etudiants[]" value="{{ $et->id }}" class="chk-et" @checked($et->statut === 'passage') aria-label="{{ $et->nom }}" style="width:16px;height:16px;accent-color:var(--primary)">
          </td>
          <td style="padding:10px 14px"><div style="font-weight:700">{{ $et->nom }} {{ $et->prenoms }}</div></td>
          <td style="padding:10px 14px;font-family:monospace;color:var(--blue-700);font-weight:700">{{ $et->numero }}</td>
          <td style="padding:10px 14px;text-align:center">
            <div style="display:flex;gap:4px;justify-content:center;flex-wrap:wrap">
              @forelse (array_intersect(['S1', 'S2', 'S3', 'S4', 'S5', 'S6'], $et->valides) as $sc)
              <span style="background:#dcfce7;color:#166534;padding:1px 6px;border-radius:6px;font-size:11px;font-weight:700">{{ $sc }}</span>
              @empty
              <span style="color:#9ca3af;font-size:11px">Aucun</span>
              @endforelse
            </div>
          </td>
          <td style="padding:10px 14px;text-align:center"><span style="background:var(--blue-50);color:var(--blue-700);padding:2px 10px;border-radius:10px;font-weight:700;font-size:13px">{{ $et->niveau_code }}</span></td>
          <td style="padding:10px 14px;text-align:center"><span style="background:{{ $fond }};color:{{ $couleur }};padding:2px 10px;border-radius:10px;font-weight:700;font-size:13px">{{ $et->suivant }}</span></td>
          <td style="padding:6px 10px;text-align:center">
            @php $choix = $et->suivant === 'L3' ? $filieresL3 : ($et->suivant === 'LP' ? $filieresLP : collect()); @endphp
            @if ($et->statut === 'passage' && $choix->count() > 1)
            <select name="filiere_{{ strtolower($et->suivant) }}[{{ $et->id }}]" class="form-control form-control-sm" style="min-width:140px;font-size:12px" aria-label="Filière {{ $et->suivant }} — {{ $et->nom }}">
              <option value="">— Choisir —</option>
              @foreach ($choix as $fc)<option value="{{ $fc->id }}">{{ $fc->libelle }}</option>@endforeach
            </select>
            @else
            <span style="color:#9ca3af;font-size:11px">—</span>
            @endif
          </td>
          <td style="padding:10px 14px;text-align:center"><span style="background:{{ $fond }};color:{{ $couleur }};padding:3px 10px;border-radius:12px;font-size:11px;font-weight:700">{{ $libelle }}</span></td>
        </tr>
        @endforeach
        </tbody>
      </table>
    </div>

    <div class="card-footer" style="display:flex;justify-content:flex-end;gap:10px;padding:14px 16px">
      <button type="submit" class="btn btn-primary" style="padding:10px 28px;font-size:15px">✅ Enregistrer les décisions de passage</button>
    </div>
  </div>
</form>
@endif
@else
<div class="card">
  <div class="card-body" style="text-align:center;padding:60px;color:var(--text-muted)">
    <div style="font-size:56px;margin-bottom:16px">🎓</div>
    <div style="font-size:16px;font-weight:700;color:var(--primary);margin-bottom:8px">Sélectionnez une promotion</div>
    <div style="font-size:13px">Choisissez l'année, la filière et le niveau pour afficher les étudiants et leurs niveaux suggérés.</div>
  </div>
</div>
@endif
@endsection

@push('scripts')
<script src="{{ asset('assets/js/passage-masse.js') }}"></script>
@endpush
