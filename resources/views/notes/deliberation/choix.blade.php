{{-- Délibérations : choix du PV — portage de deliberation.php (sans pv_id) --}}
@extends('layouts.app', ['titre' => 'Délibérations et jurys', 'fil' => 'Délibérations'])

@section('contenu')
<div class="card">
  <div class="card-header"><span class="card-title">📋 Sélectionner un PV pour la délibération</span></div>
  <div class="card-body" style="padding:0">
    <table class="table">
      <thead><tr><th>PV</th><th>Filière</th><th>Niveau</th><th>Session</th><th>Année</th><th>Actions</th></tr></thead>
      <tbody>
      @forelse ($pvs as $pv)
      <tr>
        <td style="font-weight:600">{{ $pv->libelle }}</td>
        <td>{{ $pv->filiere }}</td>
        <td>{{ $pv->niveau }}</td>
        <td>{{ \App\Support\Format::session($pv->type_session) }}</td>
        <td>{{ $pv->annee }}</td>
        <td><a href="{{ route('deliberation', ['pv_id' => $pv->id]) }}" target="_blank" rel="noopener" class="btn btn-sm btn-primary">📄 Générer PV délibération</a></td>
      </tr>
      @empty
      <tr><td colspan="6"><div class="empty-state">Aucun PV.</div></td></tr>
      @endforelse
      </tbody>
    </table>
  </div>
</div>
@endsection
