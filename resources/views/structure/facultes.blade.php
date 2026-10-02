{{-- Facultés — portage de pages/structure/facultes.php --}}
@extends('layouts.app', ['titre' => 'Gestion des facultés', 'fil' => 'Administration › Facultés'])

@section('contenu')
<div style="display:grid;grid-template-columns:1fr 380px;gap:20px">

  <div class="card">
    <div class="card-header"><span class="card-title">🏛️ Facultés ({{ count($facultes) }})</span></div>
    <div class="card-body" style="padding:0">
      @if (empty($facultes))
        <div class="empty-state" style="padding:32px"><p>Aucune faculté. Ajoutez-en une.</p></div>
      @else
      <table class="table">
        <thead><tr>
          <th>Logo</th><th>Code</th><th>Faculté</th><th>Couleur</th>
          <th style="text-align:center">Filières</th>
          <th style="text-align:center">Agents</th>
          <th style="text-align:center">Statut</th>
          <th style="text-align:center">Actions</th>
        </tr></thead>
        <tbody>
        @foreach ($facultes as $f)
        <tr>
          <td>
            @if ($f->logo_present)
              <img src="{{ route('fichiers.logo', basename($f->logo)) }}" alt="" style="width:36px;height:36px;object-fit:contain;border-radius:4px">
            @else
              <div style="width:36px;height:36px;border-radius:4px;background:{{ $f->couleur }};display:flex;align-items:center;justify-content:center;color:#fff;font-weight:800;font-size:11px">{{ mb_substr($f->code, 0, 2) }}</div>
            @endif
          </td>
          <td><code style="background:var(--gray-100);padding:2px 6px;border-radius:4px">{{ $f->code }}</code></td>
          <td style="font-weight:600">{{ $f->libelle }}</td>
          <td>
            <div style="display:flex;align-items:center;gap:8px">
              <div style="width:20px;height:20px;border-radius:4px;background:{{ $f->couleur }}"></div>
              <code style="font-size:11px">{{ $f->couleur }}</code>
            </div>
          </td>
          <td style="text-align:center"><span style="background:var(--blue-50);color:var(--blue-700);padding:2px 10px;border-radius:10px;font-weight:600">{{ $f->nb_filieres }}</span></td>
          <td style="text-align:center"><span style="background:var(--green-50);color:var(--green-700);padding:2px 10px;border-radius:10px;font-weight:600">{{ $f->nb_users }}</span></td>
          <td style="text-align:center">
            <form method="post" action="{{ route('facultes.basculer') }}" style="display:inline">
              @csrf
              <input type="hidden" name="id" value="{{ $f->id }}">
              <button type="submit" style="background:none;border:none;cursor:pointer;font-size:16px" title="{{ $f->actif ? 'Désactiver' : 'Activer' }}" aria-label="{{ $f->actif ? 'Désactiver' : 'Activer' }} {{ $f->code }}">{{ $f->actif ? '✅' : '⭕' }}</button>
            </form>
          </td>
          <td>
            <div style="display:flex;gap:4px;justify-content:center">
              <button type="button" data-onclick="openModal" data-arg="modal-edit-{{ $f->id }}" class="btn btn-sm btn-secondary" aria-label="Modifier {{ $f->code }}">✏️</button>
              <form method="post" action="{{ route('facultes.supprimer') }}" style="display:inline" data-confirm="Supprimer cette faculté ?">
                @csrf
                <input type="hidden" name="id" value="{{ $f->id }}">
                <button type="submit" class="btn btn-sm btn-danger" aria-label="Supprimer {{ $f->code }}">🗑️</button>
              </form>
            </div>
          </td>
        </tr>
        @endforeach
        </tbody>
      </table>

      @foreach ($facultes as $f)
      <div id="modal-edit-{{ $f->id }}" class="modal-overlay" style="display:none">
        <div class="modal">
          <div class="modal-header">
            <span class="modal-title">✏️ Modifier — {{ $f->libelle }}</span>
            <button type="button" data-onclick="closeModal" data-arg="modal-edit-{{ $f->id }}" class="btn btn-icon" aria-label="Fermer">✕</button>
          </div>
          <form method="post" action="{{ route('facultes.enregistrer') }}" enctype="multipart/form-data">
            <div class="modal-body">
              @csrf
              <input type="hidden" name="id" value="{{ $f->id }}">
              <div class="form-group"><label class="form-label" for="code-{{ $f->id }}">Code *</label>
                <input type="text" id="code-{{ $f->id }}" name="code" class="form-control" value="{{ $f->code }}" required style="text-transform:uppercase"></div>
              <div class="form-group"><label class="form-label" for="libelle-{{ $f->id }}">Libellé *</label>
                <input type="text" id="libelle-{{ $f->id }}" name="libelle" class="form-control" value="{{ $f->libelle }}" required></div>
              <div class="form-group"><label class="form-label" for="ville-{{ $f->id }}">Ville</label>
                <input type="text" id="ville-{{ $f->id }}" name="ville" class="form-control" value="{{ $f->ville ?? 'Tahoua' }}"></div>
              <div class="form-group"><label class="form-label" for="couleur-{{ $f->id }}">Couleur principale</label>
                <input type="color" id="couleur-{{ $f->id }}" name="couleur" value="{{ $f->couleur }}" style="width:48px;height:36px;border:none;cursor:pointer"></div>
              <div class="form-group"><label class="form-label" for="logo-{{ $f->id }}">Logo (PNG, JPG)</label>
                @if ($f->logo_present)
                  <div style="margin-bottom:8px"><img src="{{ route('fichiers.logo', basename($f->logo)) }}" alt="Logo actuel" style="height:48px;object-fit:contain"></div>
                @endif
                <input type="file" id="logo-{{ $f->id }}" name="logo" class="form-control" accept=".png,.jpg,.jpeg"></div>
            </div>
            <div class="modal-footer">
              <button type="button" data-onclick="closeModal" data-arg="modal-edit-{{ $f->id }}" class="btn btn-secondary">Annuler</button>
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
    <div class="card-header"><span class="card-title">➕ Ajouter une faculté</span></div>
    <div class="card-body">
      <form method="post" action="{{ route('facultes.enregistrer') }}" enctype="multipart/form-data">
        @csrf
        <div class="form-group"><label class="form-label" for="code">Code *</label>
          <input type="text" id="code" name="code" class="form-control" placeholder="Ex: FADEG" required style="text-transform:uppercase">
          <div class="form-hint">Identifiant court unique</div></div>
        <div class="form-group"><label class="form-label" for="libelle">Libellé *</label>
          <input type="text" id="libelle" name="libelle" class="form-control" placeholder="Ex: Faculté de Droit..." required></div>
        <div class="form-group"><label class="form-label" for="ville">Ville</label>
          <input type="text" id="ville" name="ville" class="form-control" value="Tahoua"></div>
        <div class="form-group"><label class="form-label" for="couleur">Couleur principale</label>
          <div style="display:flex;gap:8px;align-items:center">
            <input type="color" id="couleur" name="couleur" value="#1A3A5C" style="width:48px;height:36px;border:none;cursor:pointer">
            <span style="font-size:12px;color:var(--text-muted)">Utilisée dans les en-têtes des documents</span>
          </div></div>
        <div class="form-group"><label class="form-label" for="logo">Logo</label>
          <input type="file" id="logo" name="logo" class="form-control" accept=".png,.jpg,.jpeg"></div>
        <button type="submit" class="btn btn-primary w-100">➕ Créer la faculté</button>
      </form>
    </div>
  </div>
</div>
@endsection
