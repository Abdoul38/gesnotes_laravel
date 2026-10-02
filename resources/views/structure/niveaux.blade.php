{{-- Niveaux — portage de pages/structure/niveaux.php --}}
@extends('layouts.app', ['titre' => 'Gestion des niveaux', 'fil' => 'Administration › Niveaux'])

@section('contenu')
<div class="alert alert-info" style="margin-bottom:16px;font-size:12px">
  💡 Un niveau représente une année d'études (L1, DUT1, M2...). Les codes existants (L1, L2, L3, M1, M2, DUT1, DUT2, LP) sont déjà reconnus par les documents et le passage de niveau — un nouveau code personnalisé devra être ajouté à la logique de passage automatique séparément si besoin.
</div>

<div style="display:grid;grid-template-columns:1fr 380px;gap:20px">

  <div class="card">
    <div class="card-header"><span class="card-title">📚 Niveaux ({{ count($niveaux) }})</span></div>
    <div class="card-body" style="padding:0">
      @if (empty($niveaux))
        <div class="empty-state" style="padding:32px"><p>Aucun niveau. Ajoutez-en un.</p></div>
      @else
      <table class="table">
        <thead><tr>
          <th>Code</th><th>Libellé</th>
          <th style="text-align:center">Promotions liées</th>
          <th style="text-align:center">Actions</th>
        </tr></thead>
        <tbody>
        @foreach ($niveaux as $n)
        <tr>
          <td><code style="background:var(--gray-100);padding:2px 6px;border-radius:4px;font-weight:700">{{ $n->code }}</code></td>
          <td style="font-weight:600">{{ $n->libelle }}</td>
          <td style="text-align:center">
            <span style="background:var(--blue-50);color:var(--blue-700);padding:2px 10px;border-radius:10px;font-weight:600">{{ $n->nb_promotions }}</span>
          </td>
          <td>
            <div style="display:flex;gap:4px;justify-content:center">
              <button type="button" data-onclick="openModal" data-arg="modal-edit-{{ $n->id }}" class="btn btn-sm btn-secondary" aria-label="Modifier {{ $n->code }}">✏️</button>
              <form method="post" action="{{ route('niveaux.supprimer') }}" style="display:inline" data-confirm="Supprimer ce niveau ?">
                @csrf
                <input type="hidden" name="id" value="{{ $n->id }}">
                <button type="submit" class="btn btn-sm btn-danger" aria-label="Supprimer {{ $n->code }}">🗑️</button>
              </form>
            </div>
          </td>
        </tr>
        @endforeach
        </tbody>
      </table>

      @foreach ($niveaux as $n)
      <div id="modal-edit-{{ $n->id }}" class="modal-overlay" style="display:none">
        <div class="modal">
          <div class="modal-header">
            <span class="modal-title">✏️ Modifier — {{ $n->libelle }}</span>
            <button type="button" data-onclick="closeModal" data-arg="modal-edit-{{ $n->id }}" class="btn btn-icon" aria-label="Fermer">✕</button>
          </div>
          <form method="post" action="{{ route('niveaux.enregistrer') }}">
            <div class="modal-body">
              @csrf
              <input type="hidden" name="id" value="{{ $n->id }}">
              <div class="form-group"><label class="form-label" for="code-{{ $n->id }}">Code *</label>
                <input type="text" id="code-{{ $n->id }}" name="code" class="form-control" value="{{ $n->code }}" required style="text-transform:uppercase" maxlength="10"></div>
              <div class="form-group"><label class="form-label" for="libelle-{{ $n->id }}">Libellé *</label>
                <input type="text" id="libelle-{{ $n->id }}" name="libelle" class="form-control" value="{{ $n->libelle }}" required></div>
            </div>
            <div class="modal-footer">
              <button type="button" data-onclick="closeModal" data-arg="modal-edit-{{ $n->id }}" class="btn btn-secondary">Annuler</button>
              <button type="submit" class="btn btn-primary">💾 Enregistrer</button>
            </div>
          </form>
        </div>
      </div>
      @endforeach
      @endif
    </div>
  </div>

  <div class="card">
    <div class="card-header"><span class="card-title">➕ Ajouter un niveau</span></div>
    <div class="card-body">
      <form method="post" action="{{ route('niveaux.enregistrer') }}">
        @csrf
        <div class="form-group"><label class="form-label" for="code">Code *</label>
          <input type="text" id="code" name="code" class="form-control" placeholder="Ex: DUT1" required style="text-transform:uppercase" maxlength="10">
          <div class="form-hint">Identifiant court unique (10 caractères max)</div></div>
        <div class="form-group"><label class="form-label" for="libelle">Libellé *</label>
          <input type="text" id="libelle" name="libelle" class="form-control" placeholder="Ex: DUT 1ère Année" required></div>
        <button type="submit" class="btn btn-primary w-100">➕ Créer le niveau</button>
      </form>
    </div>
  </div>
</div>
@endsection
