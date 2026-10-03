{{-- Onglet Structure UE / Matières --}}
@php $selPromo = $promo?->id; @endphp
<div style="display:grid;grid-template-columns:1fr 360px;gap:20px">
  <div>
    <div class="card mb-16">
      <div class="card-body" style="padding:12px 16px">
        <form method="get" action="{{ route('filieres') }}" class="flex gap-10 align-items-center">
          <input type="hidden" name="tab" value="structure">
          <label class="fw-500" for="sel-promo">Promotion :</label>
          <select id="sel-promo" name="promo" class="form-control" data-autosubmit="1" style="max-width:400px">
            @foreach ($promos as $p)
              <option value="{{ $p->id }}" @selected($selPromo == $p->id)>{{ $p->niveau }} — {{ $p->filiere }} ({{ $p->annee }})</option>
            @endforeach
          </select>
        </form>
      </div>
    </div>

    @foreach ($ues->groupBy('sem_code') as $semestre => $uesSemestre)
      <h3 style="font-size:14px;font-weight:600;margin-bottom:12px;color:var(--blue-800)">📅 Semestre {{ $semestre }}</h3>
      <div style="display:grid;gap:12px;margin-bottom:12px">
      @foreach ($uesSemestre as $ue)
      <div class="card">
        <div class="card-header" style="background:var(--blue-50)">
          <span class="badge badge-blue">{{ $ue->code }}</span>
          <span class="card-title">{{ $ue->libelle }}</span>
          <span class="text-muted text-sm">{{ $ue->credits_total }} crédits</span>
          <div class="flex gap-4" style="margin-left:auto">
            <button type="button" data-onclick="openModal" data-arg="modal-ue-{{ $ue->id }}" class="btn btn-sm btn-secondary" title="Modifier l'UE" aria-label="Modifier l'UE {{ $ue->code }}">✏️</button>
            <form method="post" action="{{ route('ue.supprimer') }}" style="display:inline" data-confirm="Supprimer cette UE ? (impossible si elle contient encore des matières)">
              @csrf
              <input type="hidden" name="ue_id_edit" value="{{ $ue->id }}">
              <input type="hidden" name="tab" value="structure">
              <input type="hidden" name="promo_id_redirect" value="{{ $selPromo }}">
              <button type="submit" class="btn btn-icon btn-danger btn-sm" title="Supprimer l'UE" aria-label="Supprimer l'UE {{ $ue->code }}">🗑️</button>
            </form>
          </div>
        </div>
        <div class="table-wrap">
          <table class="table table-sm">
            <thead><tr><th>Code</th><th>Matière</th><th>Enseignant(s)</th><th>Max Exam.</th><th>Max TD</th><th>Max Total</th><th>Crédits</th><th>Coef.</th><th>Vol. horaire</th><th><span class="sr-only">Actions</span></th></tr></thead>
            <tbody>
            @forelse ($ue->matieres as $m)
            <tr>
              <td><code style="font-size:11px">{{ $m->code }}</code></td>
              <td>{{ $m->libelle }}
                @if (($m->type_matiere ?? 'obligatoire') === 'optionnelle')
                  <span style="background:#fef9c3;color:#854d0e;font-size:10px;padding:1px 6px;border-radius:8px;font-weight:600;margin-left:4px">OPT</span>
                @endif
              </td>
              <td>
                @if ($m->enseignants)
                  <span style="font-size:12px">{{ $m->enseignants }}</span>
                @else
                  <span style="background:#fee2e2;color:#991b1b;font-size:10px;padding:2px 8px;border-radius:8px;font-weight:600">Non affectée</span>
                @endif
              </td>
              <td>{{ $m->note_max_examen }}</td>
              <td>{{ $m->note_max_cc > 0 ? $m->note_max_cc : '—' }}</td>
              <td><strong>{{ $m->note_max_totale }}</strong></td>
              <td>{{ $m->credits }} cr.</td>
              <td><span class="badge badge-blue">{{ number_format($m->coefficient ?? 1, 1) }}</span></td>
              <td>
                @if ($m->volume_horaire_prevu !== null){{ $m->volume_horaire_prevu }}h
                @else<span class="text-muted text-xs">non fixé</span>@endif
              </td>
              <td>
                <div class="flex gap-4">
                  <button type="button" data-onclick="openModal" data-arg="modal-mat-{{ $m->id }}" class="btn btn-sm btn-secondary" title="Modifier" aria-label="Modifier {{ $m->code }}">✏️</button>
                  <form method="post" action="{{ route('matieres.supprimer') }}" style="display:inline" data-confirm="Supprimer cette matière ?">
                    @csrf
                    <input type="hidden" name="mat_id" value="{{ $m->id }}">
                    <input type="hidden" name="tab" value="structure">
                    <input type="hidden" name="promo_id_redirect" value="{{ $selPromo }}">
                    <button type="submit" class="btn btn-icon btn-danger btn-sm" title="Supprimer" aria-label="Supprimer {{ $m->code }}">🗑️</button>
                  </form>
                </div>
              </td>
            </tr>
            @empty
            <tr><td colspan="10" class="text-muted text-center text-sm" style="padding:8px">Aucune matière. Ajoutez-en depuis le panneau latéral.</td></tr>
            @endforelse
            </tbody>
          </table>
        </div>
      </div>
      @endforeach
      </div>
    @endforeach

    @if ($promo)
      @php
        $formCopie = function (bool $avecForcer) use ($sources, $selPromo) { return compact('avecForcer', 'sources', 'selPromo'); };
      @endphp
      @if ($ues->isNotEmpty())
        <details style="margin-bottom:16px">
          <summary style="cursor:pointer;font-size:13px;font-weight:600;color:var(--blue-700);padding:8px 12px;background:var(--blue-50);border-radius:6px;border:1px solid var(--blue-200)">
            📋 Copier/Remplacer les UE depuis une autre promotion…
          </summary>
          <div style="margin-top:8px;padding:12px;border:1px solid var(--blue-200);border-radius:6px;background:#fff">
            @include('structure.filieres._copie_ues', $formCopie(true))
          </div>
        </details>
      @else
        <div class="alert alert-info" style="display:flex;flex-direction:column;gap:10px">
          <div style="display:flex;align-items:center;gap:8px"><span>ℹ️</span><span>Aucune UE créée pour cette promotion. Copiez depuis une promotion existante :</span></div>
          @include('structure.filieres._copie_ues', $formCopie(false))
        </div>
      @endif
    @else
      <div class="empty-state"><div class="icon">🎓</div>Aucune promotion. Créez-en d'abord depuis la page Promotions.</div>
    @endif
  </div>

  <div style="display:flex;flex-direction:column;gap:16px">
    <div class="card">
      <div class="card-header"><span class="card-title">➕ Nouvelle UE</span></div>
      <div class="card-body">
        <form method="post" action="{{ route('ue.enregistrer') }}">
          @csrf
          <input type="hidden" name="tab" value="structure">
          <input type="hidden" name="promo_id" value="{{ $selPromo }}">
          @include('structure.filieres._champs_ue', ['ue' => null, 'ordre' => $ues->count() + 1])
          <button type="submit" class="btn btn-primary w-100" @disabled(! $promo)>Ajouter l'UE</button>
        </form>
      </div>
    </div>

    @if ($peutFixerVolume)
    <div class="card">
      <div class="card-header"><span class="card-title">🕐 Volume horaire — en masse</span></div>
      <div class="card-body">
        <p class="form-hint" style="margin-bottom:10px">
          Applique une valeur unique à toutes les matières qui n'ont <strong>encore aucun</strong> volume horaire — celles déjà fixées individuellement ne sont jamais modifiées.
          @if (auth()->user()->faculteId()) Limité aux matières de votre faculté. @endif
        </p>
        <form method="post" action="{{ route('matieres.volume_masse') }}" data-confirm="{{ auth()->user()->faculteId() ? 'Fixer ce volume horaire pour toutes les matières de votre faculté qui n\'en ont pas encore ?' : 'Fixer ce volume horaire pour toutes les matières qui n\'en ont pas encore ? Cette action touche l\'ensemble de l\'université, pas seulement la promotion affichée.' }}">
          @csrf
          <input type="hidden" name="tab" value="structure">
          <input type="hidden" name="promo_retour" value="{{ $selPromo }}">
          <div class="form-group">
            <label class="form-label" for="volume_masse">Volume horaire à appliquer (heures)</label>
            <input type="number" id="volume_masse" name="volume_masse" class="form-control" min="0.5" step="0.5" placeholder="Ex : 30" required>
          </div>
          <button type="submit" class="btn btn-secondary w-100">Appliquer aux matières sans volume</button>
        </form>
      </div>
    </div>
    @endif

    <div class="card">
      <div class="card-header"><span class="card-title">➕ Nouvelle matière</span></div>
      <div class="card-body">
        <form method="post" action="{{ route('matieres.enregistrer') }}">
          @csrf
          <input type="hidden" name="tab" value="structure">
          <input type="hidden" name="promo_id_redirect" value="{{ $selPromo }}">
          <div class="form-group"><label class="form-label" for="sel-ue-mat">UE parente <span class="req">*</span></label>
            <select name="ue_id" id="sel-ue-mat" class="form-control" required data-onchange="updateOrdreMatiere" data-arg="__thisvalue__">
              <option value="">— Sélectionner une UE —</option>
              @foreach ($ues as $ue)<option value="{{ $ue->id }}">{{ $ue->code }} — {{ mb_substr($ue->libelle, 0, 30) }}</option>@endforeach
            </select></div>
          <div class="form-group"><label class="form-label" for="code_mat">Code matière <span class="req">*</span></label>
            <input type="text" id="code_mat" name="code_mat" class="form-control" placeholder="ex: INTRO-ECO" required></div>
          @include('structure.filieres._champs_matiere', ['m' => null])
          <button type="submit" class="btn btn-primary w-100">Ajouter la matière</button>
        </form>
      </div>
    </div>
  </div>
</div>

{{-- Fenêtres de modification --}}
@foreach ($ues as $ue)
<div id="modal-ue-{{ $ue->id }}" class="modal-overlay" style="display:none">
  <div class="modal">
    <div class="modal-header">
      <span class="modal-title">✏️ Modifier — {{ $ue->code }} : {{ $ue->libelle }}</span>
      <button type="button" data-onclick="closeModal" data-arg="modal-ue-{{ $ue->id }}" class="btn btn-icon" aria-label="Fermer">✕</button>
    </div>
    <form method="post" action="{{ route('ue.enregistrer') }}">
      <div class="modal-body">
        @csrf
        <input type="hidden" name="ue_id_edit" value="{{ $ue->id }}">
        <input type="hidden" name="tab" value="structure">
        <input type="hidden" name="promo_id_redirect" value="{{ $selPromo }}">
        @include('structure.filieres._champs_ue', ['ue' => $ue, 'ordre' => $ue->ordre])
      </div>
      <div class="modal-footer">
        <button type="button" data-onclick="closeModal" data-arg="modal-ue-{{ $ue->id }}" class="btn btn-secondary">Annuler</button>
        <button type="submit" class="btn btn-primary">💾 Enregistrer</button>
      </div>
    </form>
  </div>
</div>
  @foreach ($ue->matieres as $m)
  <div id="modal-mat-{{ $m->id }}" class="modal-overlay" style="display:none">
    <div class="modal">
      <div class="modal-header">
        <span class="modal-title">✏️ Modifier — {{ $m->code }} : {{ $m->libelle }}</span>
        <button type="button" data-onclick="closeModal" data-arg="modal-mat-{{ $m->id }}" class="btn btn-icon" aria-label="Fermer">✕</button>
      </div>
      <form method="post" action="{{ route('matieres.enregistrer') }}">
        <div class="modal-body">
          @csrf
          <input type="hidden" name="mat_id" value="{{ $m->id }}">
          <input type="hidden" name="tab" value="structure">
          <input type="hidden" name="promo_id_redirect" value="{{ $selPromo }}">
          @include('structure.filieres._champs_matiere', ['m' => $m])
        </div>
        <div class="modal-footer">
          <button type="button" data-onclick="closeModal" data-arg="modal-mat-{{ $m->id }}" class="btn btn-secondary">Annuler</button>
          <button type="submit" class="btn btn-primary">💾 Enregistrer</button>
        </div>
      </form>
    </div>
  </div>
  @endforeach
@endforeach
