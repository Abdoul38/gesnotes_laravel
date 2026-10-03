{{-- Convocations : choix du PV — portage de convocations.php (sans pv_id) --}}
@extends('layouts.app', ['titre' => 'Convocations', 'fil' => 'Documents'])

@section('contenu')
<div class="card" style="max-width:600px;margin:0 auto">
  <div class="card-header"><span class="card-title">📨 Sélectionner un PV</span></div>
  <div class="card-body">
    <form method="get" action="{{ route('convocations') }}">
      <input type="hidden" name="masse" value="1">
      <div class="form-group">
        <label class="form-label" for="sel-pv-conv">PV / Session</label>
        <select name="pv_id" id="sel-pv-conv" class="form-control" size="10" style="height:260px" required>
          @foreach ($pvs as $p)
          <option value="{{ $p->id }}">{{ $p->filiere }} {{ $p->niveau }} — {{ $p->annee }} — {{ \App\Support\Format::session($p->type_session) }} ({{ $p->libelle }})</option>
          @endforeach
        </select>
      </div>
      <div style="display:flex;gap:10px;margin-top:12px">
        <button type="submit" class="btn btn-primary flex-1">📨 Toutes les convocations</button>
      </div>
    </form>
  </div>
</div>
@endsection
