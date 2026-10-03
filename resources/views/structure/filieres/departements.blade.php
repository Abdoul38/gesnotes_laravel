{{-- Onglet Départements --}}
<div style="display:grid;grid-template-columns:1fr 380px;gap:20px">
  <div class="card">
    <div class="card-header"><span class="card-title">🏢 Départements</span></div>
    <div class="table-wrap">
      <table class="table">
        <thead><tr><th>Code</th><th>Libellé</th><th>Faculté</th><th>Chef de département</th><th>Filières</th><th>Actions</th></tr></thead>
        <tbody>
        @forelse ($departements as $dep)
          <tr>
            <td><span class="badge badge-blue">{{ $dep->code }}</span></td>
            <td><strong>{{ $dep->libelle }}</strong></td>
            <td><span style="font-size:11px;color:var(--text-muted)">{{ $dep->faculte_lib }}</span></td>
            <td>{{ $dep->chef ?? '—' }}</td>
            <td><span class="badge badge-gray">{{ $nbFilieres[$dep->id] ?? 0 }}</span></td>
            <td>
              <button type="button" data-onclick="openModal" data-arg="modal-dep-{{ $dep->id }}" class="btn btn-sm btn-secondary" aria-label="Modifier {{ $dep->code }}">✏️</button>
              <form method="post" action="{{ route('departements.supprimer') }}" style="display:inline" data-confirm="Supprimer ce département ?">
                @csrf
                <input type="hidden" name="id" value="{{ $dep->id }}">
                <input type="hidden" name="tab" value="departements">
                <button type="submit" class="btn btn-sm btn-danger" aria-label="Supprimer {{ $dep->code }}">🗑️</button>
              </form>
            </td>
          </tr>
        @empty
          <tr><td colspan="6"><div class="empty-state"><div class="icon">🏢</div>Aucun département.</div></td></tr>
        @endforelse
        </tbody>
      </table>
    </div>
  </div>

  <div class="card">
    <div class="card-header"><span class="card-title">➕ Nouveau département</span></div>
    <div class="card-body">
      <form method="post" action="{{ route('departements.ajouter') }}">
        @csrf
        <input type="hidden" name="tab" value="departements">
        <div class="form-group"><label class="form-label" for="dep-fac">Faculté <span class="req">*</span></label>
          <select id="dep-fac" name="faculte_id" class="form-control" required>
            <option value="">— Choisir —</option>
            @foreach ($facultes as $fa)<option value="{{ $fa->id }}">{{ $fa->libelle }}</option>@endforeach
          </select></div>
        <div class="form-group"><label class="form-label" for="dep-code">Code <span class="req">*</span></label>
          <input type="text" id="dep-code" name="code_dep" class="form-control" placeholder="ex: DEP-DROIT" required></div>
        <div class="form-group"><label class="form-label" for="dep-libelle">Libellé <span class="req">*</span></label>
          <input type="text" id="dep-libelle" name="libelle_dep" class="form-control" placeholder="Département de Droit" required></div>
        <div class="form-group"><label class="form-label" for="dep-chef">Chef de département</label>
          <input type="text" id="dep-chef" name="chef_dep" class="form-control" placeholder="Nom du chef de département"></div>
        <button type="submit" class="btn btn-primary w-100">Créer le département</button>
      </form>
    </div>
  </div>
</div>

@foreach ($departements as $dep)
<div id="modal-dep-{{ $dep->id }}" class="modal-overlay" style="display:none">
  <div class="modal">
    <div class="modal-header">
      <span class="modal-title">Modifier «{{ $dep->libelle }}»</span>
      <button type="button" data-onclick="closeModal" data-arg="modal-dep-{{ $dep->id }}" class="btn btn-icon" aria-label="Fermer">✕</button>
    </div>
    <form method="post" action="{{ route('departements.modifier') }}">
      <div class="modal-body">
        @csrf
        <input type="hidden" name="id" value="{{ $dep->id }}">
        <input type="hidden" name="tab" value="departements">
        <div class="form-group"><label class="form-label" for="dep-code-{{ $dep->id }}">Code</label>
          <input type="text" id="dep-code-{{ $dep->id }}" name="code_dep" class="form-control" value="{{ $dep->code }}" required></div>
        <div class="form-group"><label class="form-label" for="dep-lib-{{ $dep->id }}">Libellé <span class="req">*</span></label>
          <input type="text" id="dep-lib-{{ $dep->id }}" name="libelle_dep" class="form-control" value="{{ $dep->libelle }}" required></div>
        <div class="form-group"><label class="form-label" for="dep-chef-{{ $dep->id }}">Chef de département</label>
          <input type="text" id="dep-chef-{{ $dep->id }}" name="chef_dep" class="form-control" value="{{ $dep->chef ?? '' }}" placeholder="Nom complet"></div>
        <div class="form-group"><label class="form-label">Faculté</label>
          <input type="text" class="form-control" value="{{ $dep->faculte_lib }}" disabled aria-label="Faculté">
          <div class="form-hint">La faculté d'un département ne peut pas être modifiée.</div>
        </div>
      </div>
      <div class="modal-footer">
        <button type="button" data-onclick="closeModal" data-arg="modal-dep-{{ $dep->id }}" class="btn btn-secondary">Annuler</button>
        <button type="submit" class="btn btn-primary">Enregistrer</button>
      </div>
    </form>
  </div>
</div>
@endforeach
