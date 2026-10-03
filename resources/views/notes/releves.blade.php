{{-- Relevés de notes individuels — portage de releves.php (sélection) --}}
@extends('layouts.app', ['titre' => 'Relevés de notes individuels', 'fil' => 'Rapports'])

@section('contenu')
<div class="card mb-16">
  <div class="card-body" style="padding:14px 16px">
    <form method="get" action="{{ route('releves') }}" class="flex gap-10" style="flex-wrap:wrap;align-items:flex-end">
      <div style="min-width:280px">
        <label class="form-label" for="pv_id" style="font-size:11px;margin-bottom:3px">PV / Session</label>
        <select name="pv_id" id="pv_id" class="form-control" data-autosubmit="1">
          <option value="">— Sélectionner un PV —</option>
          @foreach ($pvs as $p)
          <option value="{{ $p->id }}" @selected($pvId == $p->id)>{{ $p->annee }} — {{ $p->filiere }} {{ $p->niveau }} {{ $p->sem }} ({{ \App\Support\Format::session($p->type_session) }})</option>
          @endforeach
        </select>
      </div>
      @if ($pvId)
      <div style="flex:1;min-width:200px">
        <label class="form-label" for="q" style="font-size:11px;margin-bottom:3px">Rechercher un étudiant</label>
        <input type="text" name="q" id="q" value="{{ $q }}" class="form-control" placeholder="Nom, prénom ou numéro...">
      </div>
      <button type="submit" class="btn btn-secondary btn-sm" aria-label="Rechercher">🔍</button>
      @endif
    </form>
  </div>
</div>

@if ($pvId && $etudiants->isNotEmpty())
<div class="card">
  <div class="card-header"><span class="card-title">👥 {{ $etudiants->count() }} étudiant(s) validé(s)</span></div>
  <div class="table-wrap">
    <table class="table">
      <thead><tr><th>N° Étudiant</th><th>Nom &amp; Prénoms</th><th>Relevé</th></tr></thead>
      <tbody>
      @foreach ($etudiants as $et)
      <tr>
        <td>{{ $et->numero }}</td>
        <td><strong>{{ $et->nom }}</strong> {{ $et->prenoms }}</td>
        <td>
          <a href="{{ route('releve_notes', ['pv_id' => $pvId, 'et_id' => $et->id]) }}" target="_blank" rel="noopener" class="btn btn-sm btn-primary">📄 Relevé officiel</a>
          <a href="{{ route('releves', ['pv_id' => $pvId, 'et_id' => $et->id]) }}" class="btn btn-sm btn-secondary">👁 Aperçu</a>
        </td>
      </tr>
      @endforeach
      </tbody>
    </table>
  </div>
</div>
@elseif ($pvId)
<div class="alert alert-info">Aucun étudiant trouvé. {{ $q !== '' ? 'Modifiez votre recherche.' : '' }}</div>
@endif
@endsection
