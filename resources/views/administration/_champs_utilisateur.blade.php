{{-- Champs rôle / faculté / département / liaisons, communs à la création et à la modification --}}
@use('App\Http\Controllers\Administration\UtilisateursController', 'UC')
<div class="form-group"><label class="form-label" for="{{ $prefixe }}-role">Rôle</label>
  <select name="role" id="{{ $prefixe }}-role" class="form-control" data-champ="role">
    @foreach (UC::LIBELLES as $r => $lib)<option value="{{ $r }}" @selected($r === 'user')>{{ $lib }}</option>@endforeach
  </select></div>
<div class="form-group"><label class="form-label" for="{{ $prefixe }}-faculte">Faculté <span class="req" data-faculte-requise style="display:none">*</span>
  <small style="color:var(--text-muted)" data-faculte-aide>Laisser vide = accès toutes facultés</small></label>
  <select name="faculte_id" id="{{ $prefixe }}-faculte" class="form-control" data-champ="faculte">
    <option value="">— Toutes (admin/secrétaire) —</option>
    @foreach ($facultes as $f)<option value="{{ $f->id }}">{{ $f->code }} — {{ $f->libelle }}</option>@endforeach
  </select></div>
<div class="form-group" data-groupe="departement" style="display:none"><label class="form-label" for="{{ $prefixe }}-dep">Département <span class="req">*</span>
  <small style="color:var(--text-muted)">Ce chef ne verra que les matières de ce département</small></label>
  <select name="departement_id" id="{{ $prefixe }}-dep" class="form-control" data-champ="departement"><option value="">— Sélectionner d'abord une faculté —</option></select></div>
<div class="form-group" data-groupe="etudiant"><label class="form-label" for="{{ $prefixe }}-et">Lier à un étudiant</label>
  <select name="etudiant_id" id="{{ $prefixe }}-et" class="form-control"><option value="">— Aucun —</option>
    @foreach ($etudiants as $e)<option value="{{ $e->id }}">{{ $e->nom }} {{ $e->prenoms }} ({{ $e->numero }})</option>@endforeach</select></div>
<div class="form-group" data-groupe="enseignant"><label class="form-label" for="{{ $prefixe }}-ens">Lier à un enseignant</label>
  <select name="enseignant_id" id="{{ $prefixe }}-ens" class="form-control"><option value="">— Aucun —</option>
    @foreach ($enseignants as $e)<option value="{{ $e->id }}">{{ $e->nom }} {{ $e->prenoms }}</option>@endforeach</select></div>
