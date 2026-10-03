{{-- Création d'un PV — portage de pv_creer.php --}}
@extends('layouts.app', ['titre' => 'Créer un nouveau PV', 'fil' => 'PV › Nouveau'])

@section('contenu')
<script type="application/json" id="donnees-pv-normales">@json($pvNormales)</script>
<div style="max-width:640px">
  @foreach (session('erreurs_pv', []) as $e)<div class="alert alert-danger">⚠️ {{ $e }}</div>@endforeach

  <div class="card">
    <div class="card-header"><span class="card-title">📋 Nouveau Procès-Verbal</span></div>
    <div class="card-body">
      <form method="post" action="{{ route('pv_creer.enregistrer') }}" id="form-pv">
        @csrf
        <div class="form-group">
          <label class="form-label" for="sel-promo">Promotion <span class="req">*</span></label>
          <select name="promotion_id" id="sel-promo" class="form-control" required>
            <option value="">— Sélectionner une promotion —</option>
            @foreach ($promotions as $p)<option value="{{ $p->id }}" @selected(old('promotion_id') == $p->id)>{{ $p->niveau }} {{ $p->filiere }} — {{ $p->annee }}</option>@endforeach
          </select>
        </div>
        <div class="form-grid form-grid-2">
          <div class="form-group">
            <label class="form-label" for="sel-sem">Semestre <span class="req">*</span></label>
            <select name="semestre_id" id="sel-sem" class="form-control" required>
              <option value="">— Semestre —</option>
              @foreach ($semestres as $s)<option value="{{ $s->id }}" @selected(old('semestre_id') == $s->id)>{{ $s->libelle }}</option>@endforeach
            </select>
          </div>
          <div class="form-group">
            <label class="form-label" for="sel-type">Type de session <span class="req">*</span></label>
            <select name="type_session" id="sel-type" class="form-control">
              @foreach (\App\Services\Pv::SESSIONS as $v => $l)<option value="{{ $v }}" @selected(old('type_session', $pvSource ? 'reclamation' : 'normale') === $v)>{{ $l }}</option>@endforeach
            </select>
          </div>
        </div>

        <div id="bloc-reclamation" style="display:none">
          <div class="alert alert-info mb-16" style="font-size:12px">ℹ️ <span id="msg-reclamation"></span></div>
          <div class="form-group">
            <label class="form-label" for="sel-pv-source">PV source (PV ayant fait l'objet de réclamations) <span class="req">*</span></label>
            <select name="pv_source_id" id="sel-pv-source" class="form-control">
              <option value="">— Sélectionner le PV obligatoirement —</option>
              @foreach ($pvSources as $p)<option value="{{ $p->id }}" @selected(old('pv_source_id', $pvSource) == $p->id)>{{ $p->libelle }}</option>@endforeach
            </select>
            <div class="form-hint" style="color:#dc2626">⚠️ Obligatoire pour les réclamations</div>
          </div>
        </div>

        <div id="bloc-rattrapage" style="display:none">
          <div class="alert alert-info mb-16" style="font-size:12px">
            ℹ️ Le PV de rattrapage n'affichera que les étudiants <strong>non validés</strong> en 1ère session.
            Les matières validées en 1ère session sont conservées automatiquement.
          </div>
          <div class="form-group">
            <label class="form-label" for="sel-pv-normale">PV de 1ère session correspondant <span class="req">*</span></label>
            <select name="pv_normale_id" id="sel-pv-normale" class="form-control" data-ancien="{{ old('pv_normale_id') }}">
              <option value="">— Sélectionner le PV normale obligatoirement —</option>
            </select>
            <div class="form-hint" style="color:#dc2626">⚠️ Obligatoire — permet la comparaison automatique des notes entre sessions</div>
          </div>
        </div>

        <div class="form-group">
          <label class="form-label" for="inp-libelle">Intitulé du PV <span class="req">*</span></label>
          <input type="text" name="libelle" id="inp-libelle" class="form-control" maxlength="200" placeholder="ex: PV S1 – L1 Droit – 1ère Session 2024-2025" value="{{ old('libelle') }}" required>
        </div>
        <div class="form-group">
          <label class="form-label" for="date_pv">Date du PV</label>
          <input type="date" name="date_pv" id="date_pv" class="form-control" value="{{ old('date_pv') }}">
        </div>
        <div class="flex gap-8 mt-16">
          <button type="submit" class="btn btn-primary">✅ Créer le PV</button>
          <a href="{{ route('pv') }}" class="btn btn-secondary">Annuler</a>
        </div>
      </form>
    </div>
  </div>
</div>
@endsection

@push('scripts')
<script src="{{ asset('assets/js/pv-creer.js') }}"></script>
@endpush
