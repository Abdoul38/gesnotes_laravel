{{-- Onglet Filières --}}
@php
    $libelleDept = $departements->pluck('libelle', 'id');
    $libelleFac = $facultes->pluck('libelle', 'id');
@endphp
<div style="display:grid;grid-template-columns:1fr 380px;gap:20px">
  <div class="card">
    <div class="card-header"><span class="card-title">📚 Filières existantes</span></div>
    <div class="table-wrap">
      <table class="table">
        <thead><tr><th>Code</th><th>Libellé</th><th>Département</th><th>Faculté</th><th>Spécialité de</th><th>Actions</th></tr></thead>
        <tbody>
        @foreach ($filieres as $f)
        <tr>
          <td><span class="badge badge-blue">{{ $f->code }}</span></td>
          <td><strong>{{ $f->libelle }}</strong></td>
          <td class="text-sm text-muted">{{ ($f->departement_id ? ($libelleDept[$f->departement_id] ?? null) : null) ?? ($f->departement ?: '—') }}</td>
          <td class="text-sm text-muted">{{ ($f->faculte_id ? ($libelleFac[$f->faculte_id] ?? null) : null) ?? $f->faculte }}</td>
          <td>
            @if ($f->filiere_parente_id && $f->parente_libelle)
              <span style="background:#ede9fe;color:#5b21b6;padding:2px 8px;border-radius:8px;font-size:11px;font-weight:700">🎓 {{ $f->parente_libelle }}</span>
            @else
              <span style="color:#9ca3af;font-size:11px">—</span>
            @endif
          </td>
          <td>
            <button type="button" data-onclick="openModal" data-arg="modal-edit-{{ $f->id }}" class="btn btn-sm btn-secondary" aria-label="Modifier {{ $f->code }}">✏️</button>
            <form method="post" action="{{ route('filieres.supprimer') }}" style="display:inline" data-confirm="Supprimer cette filière ?">
              @csrf
              <input type="hidden" name="id" value="{{ $f->id }}">
              <input type="hidden" name="tab" value="filieres">
              <button type="submit" class="btn btn-sm btn-danger" aria-label="Supprimer {{ $f->code }}">🗑️</button>
            </form>
          </td>
        </tr>
        @endforeach
        </tbody>
      </table>
    </div>
  </div>

  <div class="card">
    <div class="card-header"><span class="card-title">➕ Nouvelle filière</span></div>
    <div class="card-body">
      @include('structure.filieres._formulaire_filiere', ['f' => null])
    </div>
  </div>
</div>

@foreach ($filieres as $f)
<div id="modal-edit-{{ $f->id }}" class="modal-overlay" style="display:none">
  <div class="modal">
    <div class="modal-header">
      <span class="modal-title">Modifier «{{ $f->code }}»</span>
      <button type="button" data-onclick="closeModal" data-arg="modal-edit-{{ $f->id }}" class="btn btn-icon" aria-label="Fermer">✕</button>
    </div>
    <div class="modal-body">
      @include('structure.filieres._formulaire_filiere', ['f' => $f])
    </div>
  </div>
</div>
@endforeach
