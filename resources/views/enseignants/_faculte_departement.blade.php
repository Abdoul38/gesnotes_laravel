{{-- Faculté (verrouillée pour un agent de faculté) puis département en cascade --}}
@php($faculteChoisie = $droits['faculte'] ?: $faculteActuelle)
<div class="form-group"><label class="form-label" for="{{ $prefixe }}-sel-faculte">Faculté</label>
  @if ($droits['faculte'])
  <input type="text" id="{{ $prefixe }}-sel-faculte" class="form-control" value="{{ $facultes->firstWhere('id', $droits['faculte'])->libelle ?? '' }}" disabled>
  <div style="font-size:11px;color:var(--text-muted)">Verrouillée sur votre propre faculté.</div>
  @else
  <select name="faculte_id" id="{{ $prefixe }}-sel-faculte" class="form-control" data-onchange="cascadeDep" data-args='["__thisvalue__","{{ $prefixe }}-sel-departement",0]'>
    <option value="">— Aucune —</option>
    @foreach ($facultes as $f)<option value="{{ $f->id }}" @selected($faculteActuelle == $f->id)>{{ $f->libelle }}</option>@endforeach
  </select>
  @endif
</div>
<div class="form-group"><label class="form-label" for="{{ $prefixe }}-sel-departement">Département</label>
  <select name="departement_id" id="{{ $prefixe }}-sel-departement" class="form-control">
    <option value="">— Aucun département —</option>
    @foreach ($departements->where('faculte_id', $faculteChoisie) as $d)
    <option value="{{ $d->id }}" @selected($departementActuel == $d->id)>{{ $d->libelle }}</option>
    @endforeach
  </select>
</div>
