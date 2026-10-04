{{-- Signataires par faculté — portage de pages/admin/signataires.php --}}
@extends('layouts.app', ['titre' => 'Signataires', 'fil' => 'Configuration › Signataires'])

@section('contenu')
@php $teinte = fn ($c) => preg_match('/^#[0-9A-Fa-f]{6}$/', (string) $c) ? $c : '#1A3A5C'; @endphp
<div style="display:grid;grid-template-columns:minmax(0,1fr) 360px;gap:20px" class="grille-admin">
  <div>
    @if ($aucun)
    <div class="card"><div class="empty-state" style="padding:40px"><div style="font-size:40px;margin-bottom:12px">✍️</div><p>Aucun signataire. Ajoutez-en un depuis le panneau à droite.</p></div></div>
    @endif

    @foreach ($groupes as $g)
    @php $fac = $g['info']; $actif = $g['sigs']->firstWhere('actif', 1); $c = $teinte($fac->couleur); @endphp
    <div class="card mb-16">
      <div class="card-header" style="border-left:4px solid {{ $c }};background:{{ $c }}10">
        <div>
          <span class="card-title" style="color:{{ $c }}">🏛️ {{ $fac->libelle }}</span>
          @if ($actif)
          <div style="font-size:11px;color:var(--text-muted);margin-top:3px">✅ Signataire actif : <strong>{{ $actif->titre }} {{ $actif->nom }}</strong>@if ($actif->qualite)<em style="font-weight:400"> — {{ $actif->qualite }}</em>@endif</div>
          @else
          <div style="font-size:11px;color:var(--amber-600);margin-top:3px">⚠️ Aucun signataire actif pour cette faculté</div>
          @endif
        </div>
        <span style="background:{{ $c }}20;color:{{ $c }};padding:3px 12px;border-radius:10px;font-size:12px;font-weight:700">{{ count($g['sigs']) }} signataire(s)</span>
      </div>
      <div class="card-body" style="padding:0;overflow-x:auto">
        <table class="table">
          <thead><tr><th>Titre</th><th>Nom & Qualité</th><th style="text-align:center">Statut</th><th style="text-align:center">Actions</th></tr></thead>
          <tbody>
          @foreach ($g['sigs'] as $s)
          <tr @if ($s->actif) style="background:var(--green-50)" @endif>
            <td style="font-size:12px;color:var(--text-muted);white-space:nowrap">{{ $s->titre }}</td>
            <td>
              <div style="font-weight:700;font-size:13px">{{ $s->nom }}@if ($s->actif)<span style="background:#dcfce7;color:#16a34a;font-size:9px;padding:1px 6px;border-radius:6px;margin-left:6px;font-weight:700">ACTIF</span>@endif</div>
              @if ($s->qualite)<div style="font-size:11px;color:var(--text-muted);font-style:italic">{{ $s->qualite }}</div>@endif
            </td>
            <td style="text-align:center">{!! $s->actif ? '<span style="color:var(--green-600);font-weight:600;font-size:13px">✅</span>' : '<span style="color:var(--gray-400);font-size:13px">⭕</span>' !!}</td>
            <td>
              <div style="display:flex;gap:4px;justify-content:center;flex-wrap:wrap">
                @if (! $s->actif)
                <form method="post" action="{{ route('signataires.activer') }}" style="display:inline">@csrf<input type="hidden" name="id" value="{{ $s->id }}">
                  <button type="submit" class="btn btn-sm btn-primary" title="Activer comme signataire de {{ $fac->libelle }}">✅ Activer</button></form>
                @else
                <span style="font-size:11px;color:var(--green-600);padding:4px 8px;background:var(--green-50);border-radius:6px">Actif</span>
                @endif
                <button type="button" class="btn btn-sm btn-secondary" aria-label="Modifier"
                  data-signataire="{{ json_encode(['id' => $s->id, 'faculte_id' => $s->faculte_id, 'titre' => $s->titre, 'nom' => $s->nom, 'qualite' => $s->qualite]) }}">✏️</button>
                @if (! $s->actif)
                <form method="post" action="{{ route('signataires.supprimer') }}" style="display:inline" data-confirm="Supprimer ce signataire ?">@csrf<input type="hidden" name="id" value="{{ $s->id }}">
                  <button type="submit" class="btn btn-sm btn-danger" aria-label="Supprimer">🗑️</button></form>
                @endif
              </div>
            </td>
          </tr>
          @endforeach
          </tbody>
        </table>
      </div>
    </div>
    @endforeach
  </div>

  <div>
    <div class="card">
      <div class="card-header"><span class="card-title">➕ Ajouter un signataire</span></div>
      <div class="card-body">
        <form method="post" action="{{ route('signataires.ajouter') }}">
          @csrf
          <div class="form-group"><label class="form-label" for="a-fac">Faculté <span class="req">*</span></label>
            <select name="faculte_id" id="a-fac" class="form-control" required><option value="">— Sélectionner —</option>
              @foreach ($facultes as $f)<option value="{{ $f->id }}">{{ $f->code }} — {{ $f->libelle }}</option>@endforeach</select></div>
          <div class="form-group"><label class="form-label" for="a-titre">Titre</label>
            <input type="text" name="titre" id="a-titre" class="form-control" value="Le Doyen/pi" maxlength="100" placeholder="Ex: Le Doyen/pi, Le Directeur">
            <div class="form-hint">Affiché au-dessus du nom sur les documents</div></div>
          <div class="form-group"><label class="form-label" for="a-nom">Nom complet <span class="req">*</span></label>
            <input type="text" name="nom" id="a-nom" class="form-control" maxlength="150" placeholder="Dr. NOM Prénom" required></div>
          <div class="form-group"><label class="form-label" for="a-qual">Qualité <span class="text-muted text-xs">(optionnel)</span></label>
            <input type="text" name="qualite" id="a-qual" class="form-control" maxlength="200" placeholder="Ex: Professeur Titulaire"></div>
          <button type="submit" class="btn btn-primary w-100">➕ Ajouter</button>
        </form>
      </div>
    </div>

    <div class="card mt-16">
      <div class="card-header"><span class="card-title">📊 Résumé</span></div>
      <div class="card-body" style="padding:8px">
        @foreach ($facultes as $fac)
        @php $sigs = $parFaculte[$fac->id] ?? collect(); $actif = $sigs->firstWhere('actif', 1); $c = $teinte($fac->couleur); @endphp
        <div style="display:flex;align-items:center;justify-content:space-between;padding:8px 10px;border-radius:6px;margin-bottom:4px;border:1px solid {{ $c }}30">
          <div style="display:flex;align-items:center;gap:8px"><div style="width:10px;height:10px;border-radius:50%;background:{{ $c }}"></div>
            <div><div style="font-weight:700;font-size:12px">{{ $fac->code }}</div>
              @if ($actif)<div style="font-size:10px;color:var(--green-600)">✅ {{ $actif->nom }}</div>@else<div style="font-size:10px;color:var(--amber-600)">⚠️ Aucun actif</div>@endif</div></div>
          <span style="background:{{ $c }}20;color:{{ $c }};padding:2px 8px;border-radius:8px;font-size:11px;font-weight:700">{{ count($sigs) }}</span>
        </div>
        @endforeach
      </div>
    </div>
  </div>
</div>

<div id="modal-signataire" class="modal-overlay" style="display:none">
  <div class="modal">
    <div class="modal-header"><span class="modal-title">✏️ Modifier — <span data-titre-nom></span></span>
      <button type="button" data-onclick="closeModal" data-arg="modal-signataire" class="btn btn-icon" aria-label="Fermer">✕</button></div>
    <form method="post" action="{{ route('signataires.modifier') }}">
      @csrf
      <input type="hidden" name="id" value="">
      <div class="modal-body">
        <div class="form-group"><label class="form-label" for="m-fac">Faculté</label>
          <select name="faculte_id" id="m-fac" class="form-control">
            @unless ($facultes->count() === 1 && auth()->user()->faculteId())<option value="">— Sans faculté —</option>@endunless
            @foreach ($facultes as $f)<option value="{{ $f->id }}">{{ $f->code }} — {{ $f->libelle }}</option>@endforeach</select></div>
        <div class="form-group"><label class="form-label" for="m-titre">Titre</label><input type="text" name="titre" id="m-titre" class="form-control" maxlength="100" required></div>
        <div class="form-group"><label class="form-label" for="m-nom">Nom complet *</label><input type="text" name="nom" id="m-nom" class="form-control" maxlength="150" required></div>
        <div class="form-group"><label class="form-label" for="m-qual">Qualité</label><input type="text" name="qualite" id="m-qual" class="form-control" maxlength="200"></div>
      </div>
      <div class="modal-footer">
        <button type="button" data-onclick="closeModal" data-arg="modal-signataire" class="btn btn-secondary">Annuler</button>
        <button type="submit" class="btn btn-primary">💾 Enregistrer</button>
      </div>
    </form>
  </div>
</div>
@endsection

@push('scripts')
<script src="{{ asset('assets/js/signataires.js') }}"></script>
@endpush
