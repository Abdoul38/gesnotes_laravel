{{-- Formulaire filière : création ($f null) ou modification --}}
@php
    $suffixe = $f ? 'edit-'.$f->id : 'ajout';
    $depActuel = (int) ($f->departement_id ?? 0);
@endphp
<form method="post" action="{{ route('filieres.enregistrer') }}">
  @csrf
  <input type="hidden" name="tab" value="filieres">
  @if ($f)<input type="hidden" name="id" value="{{ $f->id }}">@endif
  <div class="form-group"><label class="form-label" for="code-{{ $suffixe }}">Code <span class="req">*</span></label>
    <input type="text" id="code-{{ $suffixe }}" name="code" class="form-control" value="{{ $f->code ?? '' }}" placeholder="ex: ECO-GES" required></div>
  <div class="form-group"><label class="form-label" for="libelle-{{ $suffixe }}">Libellé <span class="req">*</span></label>
    <input type="text" id="libelle-{{ $suffixe }}" name="libelle" class="form-control" value="{{ $f->libelle ?? '' }}" placeholder="Économie et Gestion" required></div>
  <div class="form-group"><label class="form-label" for="fac-{{ $suffixe }}">Faculté <span class="req">*</span></label>
    <select id="fac-{{ $suffixe }}" name="faculte_id" class="form-control" required
            data-onchange="cascadeDep" data-args='["__thisvalue__","dep-{{ $suffixe }}",{{ $depActuel }}]'>
      <option value="">— Choisir une faculté —</option>
      @foreach ($facultes as $fa)
        <option value="{{ $fa->id }}" @selected($f && (int) $f->faculte_id === (int) $fa->id)>{{ $fa->libelle }}</option>
      @endforeach
    </select></div>
  <div class="form-group"><label class="form-label" for="dep-{{ $suffixe }}">Département</label>
    <select name="departement_id" id="dep-{{ $suffixe }}" class="form-control">
      <option value="">— Sélectionner une faculté d'abord —</option>
      @if ($f)
        @foreach ($departements->where('faculte_id', $f->faculte_id) as $dep)
          <option value="{{ $dep->id }}" @selected($depActuel === (int) $dep->id)>{{ $dep->libelle }}</option>
        @endforeach
      @endif
    </select></div>
  <div class="form-group"><label class="form-label" for="parente-{{ $suffixe }}">Filière de spécialité ?</label>
    <select id="parente-{{ $suffixe }}" name="filiere_parente_id" class="form-control">
      <option value="">— Non (filière principale) —</option>
      @foreach ($filieresMeres as $fm)
        @continue($f && $fm->id === $f->id)
        <option value="{{ $fm->id }}" @selected($f && (int) $f->filiere_parente_id === (int) $fm->id)>{{ $fm->libelle }} ({{ $fm->code }})</option>
      @endforeach
    </select>
    @unless ($f)
      <div style="font-size:11px;color:var(--text-muted);margin-top:4px">Si cette filière est une spécialisation (ex: Droit Privé est une spécialité de Droit), choisir la filière mère.</div>
    @endunless
  </div>
  <div class="form-group"><label class="form-label" for="domaine-{{ $suffixe }}">Domaine</label>
    <input type="text" id="domaine-{{ $suffixe }}" name="domaine" class="form-control" value="{{ $f->domaine ?? '' }}" placeholder="Ex: Droit et Gestion"></div>
  <div class="form-group"><label class="form-label" for="mention-{{ $suffixe }}">Mention académique</label>
    <input type="text" id="mention-{{ $suffixe }}" name="mention_academique" class="form-control" value="{{ $f->mention_academique ?? '' }}" placeholder="Ex: Droit"></div>
  <div class="form-group"><label class="form-label" for="type-{{ $suffixe }}">Type de formation</label>
    <select id="type-{{ $suffixe }}" name="type_formation" class="form-control">
      <option value="classique" @selected(($f->type_formation ?? 'classique') === 'classique')>Classique</option>
      <option value="professionnelle" @selected(($f->type_formation ?? '') === 'professionnelle')>Professionnelle</option>
    </select></div>
  @if ($f)
    <div class="modal-footer" style="padding:0;border:none">
      <button type="button" data-onclick="closeModal" data-arg="modal-edit-{{ $f->id }}" class="btn btn-secondary">Annuler</button>
      <button type="submit" class="btn btn-primary">Enregistrer</button>
    </div>
  @else
    <button type="submit" class="btn btn-primary w-100">Créer la filière</button>
  @endif
</form>
