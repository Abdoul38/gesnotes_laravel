{{-- Champs d'une matière : création ($m null) ou modification --}}
@php $sfx = $m ? $m->id : 'nouv'; @endphp
@if ($m)
<div class="form-group">
  <label class="form-label" for="type-mat-{{ $sfx }}">Type de matière</label>
  <select id="type-mat-{{ $sfx }}" name="type_matiere" class="form-control">
    <option value="obligatoire" @selected(($m->type_matiere ?? 'obligatoire') === 'obligatoire')>📌 Obligatoire</option>
    <option value="optionnelle" @selected(($m->type_matiere ?? '') === 'optionnelle')>🔀 Optionnelle (au choix)</option>
  </select>
  <div class="form-hint">Optionnelle = l'étudiant choisit parmi les matières OPT de cette UE</div>
</div>
@endif
<div class="form-group"><label class="form-label" for="lib-mat-{{ $sfx }}">Intitulé <span class="req">*</span></label>
  <input type="text" id="lib-mat-{{ $sfx }}" name="libelle_mat" class="form-control" value="{{ $m->libelle ?? '' }}" placeholder="Introduction à l'Économie" required></div>
<div class="form-grid form-grid-2">
  <div class="form-group"><label class="form-label" for="exam-{{ $sfx }}">Max Examen</label>
    <input type="number" id="exam-{{ $sfx }}" name="max_exam" class="form-control" value="{{ $m->note_max_examen ?? 40 }}" min="0" step="0.5"></div>
  <div class="form-group"><label class="form-label" for="cc-{{ $sfx }}">Max TD <span class="text-muted text-xs">(0 = sans TD)</span></label>
    <input type="number" id="cc-{{ $sfx }}" name="max_cc" class="form-control" value="{{ $m->note_max_cc ?? 0 }}" min="0" step="0.5"></div>
</div>
<div class="form-grid form-grid-3">
  <div class="form-group"><label class="form-label" for="cr-{{ $sfx }}">Crédits</label>
    <input type="number" id="cr-{{ $sfx }}" name="credits_mat" class="form-control" value="{{ $m->credits ?? 4 }}" min="1">
    <div class="form-hint">Valeur LMD</div></div>
  <div class="form-group"><label class="form-label" for="coef-{{ $sfx }}">Coefficient</label>
    <input type="number" id="coef-{{ $sfx }}" name="coef_mat" class="form-control" value="{{ number_format($m->coefficient ?? 1, 1, '.', '') }}" min="0.5" step="0.5">
    <div class="form-hint">{{ $m ? 'Poids moy.' : 'Poids dans la moy.' }}</div></div>
  <div class="form-group"><label class="form-label" for="{{ $m ? 'ord-'.$sfx : 'inp-ordre-mat' }}">Ordre</label>
    <input type="number" id="{{ $m ? 'ord-'.$sfx : 'inp-ordre-mat' }}" name="ordre_mat" class="form-control" value="{{ $m->ordre ?? 1 }}">
    @unless ($m)<div class="form-hint">Auto-calculé selon l'UE choisie</div>@endunless</div>
</div>
@unless ($m)
<div class="form-group">
  <label class="form-label" for="type-mat-nouv">Type de matière</label>
  <select id="type-mat-nouv" name="type_matiere" class="form-control">
    <option value="obligatoire">📌 Obligatoire (toujours composée)</option>
    <option value="optionnelle">🔀 Optionnelle (l'étudiant choisit parmi les OPT)</option>
  </select>
</div>
@endunless
@if ($peutFixerVolume)
<div class="form-group">
  <label class="form-label" for="vol-{{ $sfx }}">Volume horaire prévu <span class="text-muted text-xs">{{ $m ? '(heures — programme officiel)' : '(heures — optionnel)' }}</span></label>
  <input type="number" id="vol-{{ $sfx }}" name="volume_prevu_mat" class="form-control" value="{{ $m->volume_horaire_prevu ?? '' }}" min="0.5" step="0.5" placeholder="Ex : 30">
  <div class="form-hint">{{ $m ? "Sert au calcul du taux d'exécution du programme, comparé aux heures déclarées par l'enseignant." : 'Peut aussi être fixé plus tard depuis cette page ou le portail enseignant.' }}</div>
</div>
@elseif ($m)
<div class="form-group">
  <span class="form-label">Volume horaire prévu</span>
  <div class="form-control" style="background:#f3f4f6;color:var(--text-muted)">{{ $m->volume_horaire_prevu !== null ? $m->volume_horaire_prevu.'h' : 'Non fixé' }}</div>
  <div class="form-hint">Réservé à l'administration, au chef de département ou au doyen.</div>
</div>
@endif
