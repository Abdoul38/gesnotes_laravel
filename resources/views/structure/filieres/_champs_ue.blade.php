{{-- Champs d'une UE : création ($ue null) ou modification --}}
@php $sfx = $ue ? $ue->id : 'nouv'; $nbOpt = (int) ($ue->nb_options_a_choisir ?? 0); @endphp
<div class="form-group"><label class="form-label" for="sem-{{ $sfx }}">Semestre <span class="req">*</span></label>
  <select id="sem-{{ $sfx }}" name="sem_id" class="form-control" required>
    @foreach ($semestres as $s)<option value="{{ $s->id }}" @selected($ue && $s->id == $ue->semestre_id)>{{ $s->libelle }}</option>@endforeach
  </select></div>
<div class="form-group"><label class="form-label" for="code-ue-{{ $sfx }}">Code UE <span class="req">*</span></label>
  <input type="text" id="code-ue-{{ $sfx }}" name="code_ue" class="form-control" value="{{ $ue->code ?? '' }}" placeholder="ex: UE1.1" required></div>
<div class="form-group"><label class="form-label" for="lib-ue-{{ $sfx }}">Intitulé <span class="req">*</span></label>
  <input type="text" id="lib-ue-{{ $sfx }}" name="libelle_ue" class="form-control" value="{{ $ue->libelle ?? '' }}" placeholder="Fondamentaux..." required></div>
<div class="form-grid form-grid-2">
  <div class="form-group"><label class="form-label" for="cr-ue-{{ $sfx }}">Crédits</label>
    <input type="number" id="cr-ue-{{ $sfx }}" name="credits" class="form-control" value="{{ $ue->credits_total ?? 6 }}" min="1" max="30"></div>
  <div class="form-group"><label class="form-label" for="ord-ue-{{ $sfx }}">Ordre</label>
    <input type="number" id="ord-ue-{{ $sfx }}" name="ordre" class="form-control" value="{{ $ordre }}"></div>
</div>
<div class="form-group">
  <label class="form-label" for="opt-ue-{{ $sfx }}">UE à choix optionnel</label>
  <select id="opt-ue-{{ $sfx }}" name="nb_options_a_choisir" class="form-control">
    <option value="0" @selected($nbOpt === 0)>📌 UE classique (toutes les matières obligatoires)</option>
    @foreach ([1, 2, 3] as $n)
      <option value="{{ $n }}" @selected($nbOpt === $n)>🔀 L'étudiant choisit {{ $n }} matière{{ $n > 1 ? 's' : '' }} parmi les optionnelles</option>
    @endforeach
  </select>
  @unless ($ue)<div class="form-hint">Définissez ensuite chaque matière comme "Obligatoire" ou "Optionnelle"</div>@endunless
</div>
