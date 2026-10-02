{{-- Années académiques — portage de pages/structure/annees.php --}}
@extends('layouts.app', ['titre' => 'Années académiques', 'fil' => 'Configuration'])
@use('App\Support\Format')

@section('contenu')
<div style="display:grid;grid-template-columns:1fr 320px;gap:20px">

  <div class="card">
    <div class="card-header"><span class="card-title">📅 Années académiques</span></div>
    <div class="table-wrap">
      <table class="table">
        <thead><tr><th>Année</th><th>Début</th><th>Fin</th><th>Promotions</th><th>Étudiants</th><th>Statut</th><th>Actions</th></tr></thead>
        <tbody>
        @foreach ($annees as $a)
        <tr>
          <td><strong style="font-size:15px">{{ $a->libelle }}</strong></td>
          <td class="text-muted">{{ Format::dateFR($a->date_debut) }}</td>
          <td class="text-muted">{{ Format::dateFR($a->date_fin) }}</td>
          <td>{{ $a->nb_promos }}</td>
          <td>{{ number_format($a->nb_etudiants) }}</td>
          <td>
            @if ($a->active)
              <span class="badge badge-green">🟢 Active</span>
            @else
              <span class="badge badge-gray">Inactive</span>
            @endif
          </td>
          <td>
            <div class="flex gap-8">
              @unless ($a->active)
                <form method="post" action="{{ route('annees.activer') }}" style="display:inline">
                  @csrf
                  <input type="hidden" name="id" value="{{ $a->id }}">
                  <button type="submit" class="btn btn-sm btn-success">Activer</button>
                </form>
                @unless ($a->nb_promos)
                <form method="post" action="{{ route('annees.supprimer') }}" style="display:inline" data-confirm="Supprimer ?">
                  @csrf
                  <input type="hidden" name="id" value="{{ $a->id }}">
                  <button type="submit" class="btn btn-sm btn-danger" aria-label="Supprimer {{ $a->libelle }}">🗑️</button>
                </form>
                @endunless
              @else
                <span class="text-muted text-xs">En cours</span>
              @endunless
            </div>
          </td>
        </tr>
        @endforeach
        </tbody>
      </table>
    </div>
  </div>

  <div class="card">
    <div class="card-header"><span class="card-title">➕ Nouvelle année</span></div>
    <div class="card-body">
      <form method="post" action="{{ route('annees.creer') }}">
        @csrf
        <div class="form-group">
          <label class="form-label" for="libelle">Libellé <span class="req">*</span></label>
          <input type="text" id="libelle" name="libelle" class="form-control" placeholder="2025-2026" pattern="\d{4}-\d{4}" required>
          <div class="form-hint">Format : AAAA-AAAA (ex: 2025-2026)</div>
        </div>
        <div class="form-group">
          <label class="form-label" for="date_debut">Date de début</label>
          <input type="date" id="date_debut" name="date_debut" class="form-control">
        </div>
        <div class="form-group">
          <label class="form-label" for="date_fin">Date de fin</label>
          <input type="date" id="date_fin" name="date_fin" class="form-control">
        </div>
        <button type="submit" class="btn btn-primary w-100">Créer l'année</button>
      </form>
    </div>
  </div>

</div>
@endsection
