{{-- Dossier d'un enseignant et ses affectations — portage de enseignants.php?id= --}}
@extends('layouts.app', ['titre' => 'Gestion des enseignants', 'fil' => 'Administratif › Enseignants'])

@php
    $facLibelle = $facultes->firstWhere('id', $ens->faculte_id)->libelle ?? '';
    $depLibelle = $departements->where('faculte_id', $ens->faculte_id)->firstWhere('id', $ens->departement_id)->libelle ?? '';
    $val = fn ($x) => ($x !== null && trim((string) $x) !== '') ? $x : '—';
@endphp

@section('contenu')
@include('enseignants._donnees')
<div style="display:flex;align-items:center;gap:12px;margin-bottom:16px">
  <a href="{{ route('enseignants') }}" class="btn btn-secondary">← Retour</a>
</div>

@if ($droits['chef'] && ! $droits['departement'])
<div style="background:#fff7ed;border:1px solid #fed7aa;color:#9a3412;border-radius:8px;padding:10px 14px;font-size:12.5px;margin-bottom:16px">
  ⚠️ Votre compte n'est rattaché à aucun département : vous ne pouvez pas encore affecter d'enseignants. Contactez l'administrateur.
</div>
@endif

<div class="card mb-20" style="background:linear-gradient(135deg,#1A3A5C,#2C5F9E);color:#fff">
  <div class="card-body" style="display:flex;align-items:center;gap:20px;padding:20px;flex-wrap:wrap">
    <div style="width:80px;height:80px;border-radius:50%;border:3px solid rgba(255,255,255,.4);overflow:hidden;background:rgba(255,255,255,.2);display:flex;align-items:center;justify-content:center;flex-shrink:0">
      @if ($photo)<img src="{{ $photo }}" alt="Photo" style="width:100%;height:100%;object-fit:cover">@else<span style="font-size:32px">👨‍🏫</span>@endif
    </div>
    <div style="flex:1;min-width:220px">
      <div style="font-size:22px;font-weight:800">{{ $ens->nom }} {{ $ens->prenoms }}</div>
      <div style="opacity:.85;font-size:13px;margin-top:2px">
        @if ($ens->grade)<strong>{{ $ens->grade }}</strong>@endif
        @if ($ens->matricule)&nbsp;·&nbsp; Matricule : <strong>{{ $ens->matricule }}</strong>@endif
      </div>
      @if ($ens->specialite)<div style="opacity:.75;font-size:12.5px;margin-top:4px">🎓 {{ $ens->specialite }}</div>@endif
      <div style="opacity:.75;font-size:12px;margin-top:6px;display:flex;gap:14px;flex-wrap:wrap">
        @if ($ens->email)<span>✉️ {{ $ens->email }}</span>@endif
        @if ($ens->telephone)<span>📞 {{ $ens->telephone }}</span>@endif
      </div>
      @if ($facLibelle || $depLibelle)
      <div style="margin-top:8px">
        @if ($facLibelle)<span style="background:rgba(255,255,255,.2);padding:2px 10px;border-radius:10px;font-size:11px">🏛️ {{ $facLibelle }}</span>@endif
        @if ($depLibelle)<span style="background:rgba(255,255,255,.2);padding:2px 10px;border-radius:10px;font-size:11px;margin-left:6px">📂 {{ $depLibelle }}</span>@endif
      </div>
      @endif
    </div>
    <div style="display:flex;flex-direction:column;gap:10px;align-items:stretch">
      <div style="text-align:center;background:rgba(255,255,255,.15);border-radius:10px;padding:12px 20px">
        <div style="font-size:26px;font-weight:800">{{ $affectations->count() }}</div>
        <div style="font-size:11px;opacity:.8">matière(s) affectée(s)</div>
        <div style="font-size:10px;opacity:.7;margin-top:2px">{{ $filtreLibelle }}</div>
      </div>
      @if ($droits['modifier'])
      <button type="button" id="btn-modifier-dossier" class="btn btn-sm" style="background:rgba(255,255,255,.2);color:#fff;border:1px solid rgba(255,255,255,.4)">✏️ Modifier</button>
      @endif
    </div>
  </div>
</div>

<div style="display:grid;grid-template-columns:1fr 1fr;gap:20px;margin-bottom:20px">
@if ($droits['modifier'])
<form method="post" action="{{ route('enseignants.enregistrer') }}" enctype="multipart/form-data" id="form-dossier" style="display:contents">
  @csrf
  <input type="hidden" name="id" value="{{ $ens->id }}">
@endif
  <div class="card">
    <div class="card-header"><span class="card-title">👤 Identité &amp; contact</span></div>
    <div class="card-body">
      <div class="ens-grid" data-view="1">
        <div><div class="lbl">Nom complet</div><div class="val">{{ $ens->nom }} {{ $ens->prenoms }}</div></div>
        <div><div class="lbl">Matricule</div><div class="val">{{ $val($ens->matricule) }}</div></div>
        <div><div class="lbl">Grade</div><div class="val">{{ $val($ens->grade) }}</div></div>
        <div><div class="lbl">Spécialité</div><div class="val">{{ $val($ens->specialite) }}</div></div>
        <div><div class="lbl">Email</div><div class="val">{{ $val($ens->email) }}</div></div>
        <div><div class="lbl">Téléphone</div><div class="val">{{ $val($ens->telephone) }}</div></div>
        <div><div class="lbl">Faculté</div><div class="val">{{ $val($facLibelle) }}</div></div>
        <div><div class="lbl">Département</div><div class="val">{{ $val($depLibelle) }}</div></div>
      </div>
      @if ($droits['modifier'])
      <div class="form-grid form-grid-2" data-edit="1" style="display:none">
        <div class="form-group"><label class="form-label" for="m-nom">Nom *</label>
          <input type="text" name="nom" id="m-nom" class="form-control" value="{{ $ens->nom }}" required></div>
        <div class="form-group"><label class="form-label" for="m-prenoms">Prénoms *</label>
          <input type="text" name="prenoms" id="m-prenoms" class="form-control" value="{{ $ens->prenoms }}" required></div>
        <div class="form-group"><label class="form-label" for="m-matricule">Matricule</label>
          <input type="text" name="matricule" id="m-matricule" class="form-control" value="{{ $ens->matricule }}"></div>
        <div class="form-group"><label class="form-label" for="m-grade">Grade</label>
          <input type="text" name="grade" id="m-grade" class="form-control" value="{{ $ens->grade }}" placeholder="Ex: Professeur Titulaire"></div>
        <div class="form-group"><label class="form-label" for="m-specialite">Spécialité</label>
          <input type="text" name="specialite" id="m-specialite" class="form-control" value="{{ $ens->specialite }}"></div>
        <div class="form-group"><label class="form-label" for="m-email">Email</label>
          <input type="email" name="email" id="m-email" class="form-control" value="{{ $ens->email }}"></div>
        <div class="form-group"><label class="form-label" for="m-telephone">Téléphone</label>
          <input type="text" name="telephone" id="m-telephone" class="form-control" value="{{ $ens->telephone }}"></div>
        @include('enseignants._faculte_departement', ['prefixe' => 'edit', 'faculteActuelle' => $ens->faculte_id, 'departementActuel' => $ens->departement_id])
      </div>
      @endif
    </div>
  </div>

  <div class="card">
    <div class="card-header"><span class="card-title">📋 Informations complémentaires</span></div>
    <div class="card-body">
      <div class="ens-grid" data-view="1">
        <div><div class="lbl">Date et lieu de naissance</div>
          <div class="val">{{ $ens->date_naissance ? \App\Support\Format::dateFR($ens->date_naissance) : '—' }}{{ $ens->lieu_naissance ? ' à '.$ens->lieu_naissance : '' }}</div></div>
        <div><div class="lbl">Diplôme le plus élevé</div><div class="val">{{ $val($ens->diplome_plus_eleve) }}</div></div>
        <div><div class="lbl">Recruté(e) depuis le</div>
          <div class="val">{{ $ens->date_recrutement ? \App\Support\Format::dateFR($ens->date_recrutement) : '—' }}</div></div>
        <div><div class="lbl">Adresse</div><div class="val">{{ $val($ens->adresse) }}</div></div>
      </div>
      @if ($droits['modifier'])
      <div class="form-grid form-grid-2" data-edit="1" style="display:none">
        <div class="form-group"><label class="form-label" for="m-photo">Photo</label>
          <input type="file" name="photo" id="m-photo" class="form-control" accept=".jpg,.jpeg,.png,.webp">
          <div style="font-size:11px;color:var(--text-muted)">JPG, PNG ou WEBP.</div></div>
        <div class="form-group"><label class="form-label" for="m-ddn">Date de naissance</label>
          <input type="date" name="date_naissance" id="m-ddn" class="form-control" value="{{ $ens->date_naissance }}"></div>
        <div class="form-group"><label class="form-label" for="m-lieu">Lieu de naissance</label>
          <input type="text" name="lieu_naissance" id="m-lieu" class="form-control" value="{{ $ens->lieu_naissance }}"></div>
        <div class="form-group"><label class="form-label" for="m-diplome">Diplôme le plus élevé</label>
          <input type="text" name="diplome_plus_eleve" id="m-diplome" class="form-control" value="{{ $ens->diplome_plus_eleve }}" placeholder="Ex: Doctorat en Droit Public"></div>
        <div class="form-group"><label class="form-label" for="m-recrutement">Date de recrutement</label>
          <input type="date" name="date_recrutement" id="m-recrutement" class="form-control" value="{{ $ens->date_recrutement }}"></div>
        <div class="form-group"><label class="form-label" for="m-adresse">Adresse</label>
          <input type="text" name="adresse" id="m-adresse" class="form-control" value="{{ $ens->adresse }}"></div>
      </div>
      <div data-edit="1" style="display:none;margin-top:14px">
        <button type="submit" class="btn btn-primary">💾 Enregistrer les modifications</button>
        <button type="button" id="btn-annuler-dossier" class="btn btn-secondary">Annuler</button>
      </div>
      @endif
    </div>
  </div>
@if ($droits['modifier'])
</form>
@endif
</div>

@if ($droits['affecter'])
<div class="card mb-20">
  <div class="card-header">
    <span class="card-title">📚 Affecter à une matière @if (! $droits['modifier'])<span style="font-size:11px;font-weight:400;color:var(--text-muted)">— matières de votre département</span>@endif</span>
  </div>
  <div class="card-body">
    <form method="post" action="{{ route('enseignants.affecter') }}" id="form-affecter">
      @csrf
      <input type="hidden" name="enseignant_id" value="{{ $ens->id }}">
      <div style="display:grid;grid-template-columns:repeat(auto-fit,minmax(220px,1fr));gap:12px">
        <div class="form-group">
          <label class="form-label" for="sel-annee">Année académique</label>
          <select name="annee_id" class="form-control" id="sel-annee" data-onchange="filtrerMatieres" required>
            @foreach ($annees as $a)<option value="{{ $a->id }}" @selected($a->id == $anneeId)>{{ $a->libelle }}</option>@endforeach
          </select>
        </div>
        <div class="form-group">
          <label class="form-label" for="sel-filiere">Filière / Niveau</label>
          <select class="form-control" id="sel-filiere" data-onchange="filtrerMatieres">
            <option value="">— Toutes les filières —</option>
            @foreach ($matieres->map(fn ($m) => $m->filiere.'||'.$m->niveau)->unique() as $fn)
            @php([$fl, $nv] = explode('||', $fn))
            <option value="{{ $fn }}">{{ $fl }} — {{ $nv }}</option>
            @endforeach
          </select>
        </div>
        <div class="form-group">
          <label class="form-label" for="sel-semestre">Semestre</label>
          <select class="form-control" id="sel-semestre" data-onchange="filtrerMatieres">
            <option value="">— Tous les semestres —</option>
            @foreach ($matieres->pluck('sem_label', 'semestre_id') as $sid => $slabel)<option value="{{ $sid }}">{{ $slabel }}</option>@endforeach
          </select>
        </div>
        <div class="form-group">
          <label class="form-label" for="sel-matiere">Matière <span style="color:var(--red-600)">*</span></label>
          <select name="matiere_id" class="form-control" id="sel-matiere" required>
            <option value="">— Choisir année/filière/semestre d'abord —</option>
            @foreach ($matieres as $m)
            <option value="{{ $m->id }}" data-annee="{{ $m->annee_id }}" data-fn="{{ $m->filiere.'||'.$m->niveau }}" data-sem="{{ $m->semestre_id }}" style="display:none">{{ $m->libelle }} ({{ $m->code }}) — {{ $m->sem_label }}</option>
            @endforeach
          </select>
        </div>
        <div class="form-group">
          <label class="form-label" for="type_charge">Type de charge</label>
          <select name="type_charge" id="type_charge" class="form-control">
            <option value="cours">📖 Cours</option>
            <option value="td">✏️ TD / CC</option>
            <option value="cours_td">📖✏️ Cours + TD</option>
          </select>
        </div>
      </div>
      <button type="submit" class="btn btn-primary" style="margin-top:6px">➕ Affecter cette matière</button>
    </form>
  </div>
</div>

<div class="card mb-16">
  <div class="card-header"><span class="card-title">🔄 Reconduire les affectations d'une année vers une autre</span></div>
  <div class="card-body">
    <form method="post" action="{{ route('enseignants.reconduire') }}" style="display:flex;gap:12px;align-items:flex-end;flex-wrap:wrap"
      data-confirm="{{ $droits['modifier'] ? "Reconduire TOUTES les affectations vers l'année sélectionnée ?" : "Reconduire les affectations de votre département vers l'année sélectionnée ?" }}">
      @csrf
      <input type="hidden" name="retour_id" value="{{ $ens->id }}">
      <div class="form-group" style="margin:0;min-width:200px">
        <label class="form-label" for="annee_src_id">Année source</label>
        <select name="annee_src_id" id="annee_src_id" class="form-control" required>
          <option value="" @selected(! $reconSource)>— Choisir —</option>
          @foreach ($annees as $a)<option value="{{ $a->id }}" @selected((int) $a->id === $reconSource)>{{ $a->libelle }}</option>@endforeach
        </select>
      </div>
      <div style="font-size:20px;padding-bottom:4px">→</div>
      <div class="form-group" style="margin:0;min-width:200px">
        <label class="form-label" for="annee_dest_id">Année destination</label>
        <select name="annee_dest_id" id="annee_dest_id" class="form-control" required>
          @foreach ($annees as $a)<option value="{{ $a->id }}" @selected((int) $a->id === $reconDestination)>{{ $a->libelle }}</option>@endforeach
        </select>
      </div>
      <button type="submit" class="btn btn-primary">🔄 Reconduire tout</button>
    </form>
    <p class="text-sm text-muted" style="margin-top:8px">
      @if (! $droits['modifier'])
      ⚠️ Copie toutes les affectations des matières de votre département d'une année vers une autre en un seul clic.
      @elseif ($droits['faculte'])
      ⚠️ Copie toutes les affectations des matières de votre faculté d'une année vers une autre en un seul clic.
      @else
      ⚠️ Copie toutes les affectations de tous les enseignants d'une année vers une autre en un seul clic.
      @endif
    </p>
  </div>
</div>
@endif

<div class="card mt-20">
  <div class="card-header" style="display:flex;justify-content:space-between;align-items:center;gap:10px;flex-wrap:wrap">
    <span class="card-title">📋 Affectations ({{ $affectations->count() }}) — {{ $filtreLibelle }}</span>
    <form method="get" action="{{ route('enseignants') }}" style="display:flex;gap:6px;align-items:center;margin:0">
      <input type="hidden" name="id" value="{{ $ens->id }}">
      <label class="form-label" for="aff_annee" style="margin:0;font-size:12px">Année :</label>
      <select name="aff_annee" id="aff_annee" class="form-control" style="width:auto" data-autosubmit="1">@include('enseignants._options_annee')</select>
    </form>
  </div>
  <div class="card-body" style="padding:0">
    @if ($affectations->isEmpty())
    <div class="empty-state" style="padding:24px"><p>Aucune affectation{{ $filtre ? ' pour '.$filtreLibelle : '' }}.</p></div>
    @else
    <table class="table">
      <thead><tr><th>Matière</th><th>UE</th><th>Filière</th><th>Niveau</th><th>Année</th><th>Charge</th><th>Action</th></tr></thead>
      <tbody>
      @foreach ($affectations as $af)
      <tr>
        <td style="font-weight:600">{{ $af->matiere }}</td>
        <td style="font-size:11px">{{ $af->ue_libelle }}</td>
        <td>{{ $af->filiere }}</td>
        <td>{{ $af->niveau }}</td>
        <td>{{ $af->annee }}</td>
        <td><span style="background:var(--blue-50);color:var(--blue-700);padding:2px 8px;border-radius:10px;font-size:11px">{{ $af->type_charge }}</span></td>
        <td>
          @if ($droits['modifier'] || ($droits['affecter'] && (int) $af->dep_id === $droits['departement']))
          <form method="post" action="{{ route('enseignants.desaffecter') }}" style="display:inline" data-confirm="Supprimer ?">
            @csrf
            <input type="hidden" name="retour_id" value="{{ $ens->id }}">
            <input type="hidden" name="aff_annee" value="{{ $filtre }}">
            <input type="hidden" name="id" value="{{ $af->id }}">
            <button type="submit" class="btn btn-sm btn-danger">🗑️</button>
          </form>
          @endif
        </td>
      </tr>
      @endforeach
      </tbody>
    </table>
    @endif
  </div>
</div>
@endsection

@push('head')
<style>
.ens-grid{display:grid;grid-template-columns:repeat(auto-fit,minmax(200px,1fr));gap:14px}
.ens-grid .lbl{font-size:10px;color:var(--text-muted);text-transform:uppercase;letter-spacing:.3px}
.ens-grid .val{font-weight:600;font-size:13px;margin-top:2px}
</style>
@endpush

@push('scripts')
<script src="{{ asset('assets/js/enseignants.js') }}"></script>
@endpush
