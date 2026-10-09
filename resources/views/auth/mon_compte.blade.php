{{-- Mon compte : profil de l'utilisateur connecté et changement de son mot de passe --}}
@extends('layouts.app', ['titre' => 'Mon compte', 'fil' => 'Mon compte'])

@section('contenu')
@php [$icone, $libelleRole, $fondRole, $couleurRole] = $role; @endphp
<div class="mon-compte">

  <div class="card">
    <div class="card-header"><span class="card-title">👤 Mon profil</span></div>
    <div class="card-body">
      <div style="display:flex;align-items:center;gap:14px;margin-bottom:16px">
        <div class="avatar-sm" style="width:52px;height:52px;font-size:18px">{{ $u->initiales() }}</div>
        <div style="min-width:0">
          <div style="font-size:17px;font-weight:700">{{ $u->nomComplet() }}</div>
          <span style="background:{{ $fondRole }};color:{{ $couleurRole }};padding:2px 10px;border-radius:10px;font-size:11px;font-weight:600">{{ $icone }} {{ $libelleRole }}</span>
        </div>
      </div>
      <dl class="profil-dl">
        <dt>Identifiant (email)</dt><dd>{{ $u->email }}</dd>
        @if ($faculte)<dt>Faculté</dt><dd>{{ $faculte->libelle }}</dd>@endif
        @if ($departement)<dt>Département</dt><dd>{{ $departement->libelle }}</dd>@endif
        @if ($matricule)<dt>Matricule</dt><dd>{{ $matricule }}</dd>@endif
        @if ($enseignant)<dt>Enseignant</dt><dd>{{ $enseignant->nom }} {{ $enseignant->prenoms }}@if ($enseignant->grade) — {{ $enseignant->grade }}@endif</dd>@endif
        <dt>Dernière connexion</dt><dd>{{ $u->derniere_connexion ? $u->derniere_connexion->format('d/m/Y à H:i') : '—' }}</dd>
      </dl>
      <p style="font-size:12px;color:var(--text-muted);margin:14px 0 0">Pour modifier ces informations, adressez-vous à l'administrateur.</p>
    </div>
  </div>

  <div class="card">
    <div class="card-header"><span class="card-title">🔑 Changer mon mot de passe</span></div>
    <div class="card-body">
      <form method="post" action="{{ route('mon_compte.mot_de_passe') }}" autocomplete="off">
        @csrf
        <div class="form-group"><label class="form-label" for="mot_de_passe_actuel">Mot de passe actuel</label>
          <input type="password" name="mot_de_passe_actuel" id="mot_de_passe_actuel" class="form-control" required autocomplete="current-password"></div>
        <div class="form-group"><label class="form-label" for="mot_de_passe">Nouveau mot de passe</label>
          <input type="password" name="mot_de_passe" id="mot_de_passe" class="form-control" minlength="8" required autocomplete="new-password">
          <div class="form-hint">8 caractères minimum, avec des lettres et des chiffres.</div></div>
        <div class="form-group"><label class="form-label" for="mot_de_passe_confirmation">Confirmer le nouveau mot de passe</label>
          <input type="password" name="mot_de_passe_confirmation" id="mot_de_passe_confirmation" class="form-control" minlength="8" required autocomplete="new-password"></div>
        <button type="submit" class="btn btn-primary">💾 Enregistrer le nouveau mot de passe</button>
      </form>
    </div>
  </div>

</div>
@endsection

@push('head')
<style>
.mon-compte{display:grid;grid-template-columns:repeat(auto-fit,minmax(320px,1fr));gap:20px;align-items:start}
.profil-dl{display:grid;grid-template-columns:160px 1fr;gap:8px 12px;margin:0;font-size:13px}
.profil-dl dt{color:var(--text-muted);font-weight:600}
.profil-dl dd{margin:0;word-break:break-word}
@media (max-width:480px){.mon-compte{grid-template-columns:1fr}.profil-dl{grid-template-columns:1fr}.profil-dl dd{margin-bottom:6px}}
</style>
@endpush
