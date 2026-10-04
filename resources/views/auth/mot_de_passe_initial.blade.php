{{-- Premier mot de passe d'un étudiant (obligatoire avant d'accéder au portail) --}}
@extends('layouts.app', ['titre' => 'Choisir votre mot de passe'])
@include('auth._style')

@section('contenu')
<div class="login-page">
  <div class="login-card">
    <div class="login-logo">
      <div class="logo-big">GN</div>
      <h1>{{ $universite->nomApplication() }}</h1>
      <p>{{ $universite->nom }}</p>
    </div>
    <p style="font-size:13.5px;color:#555;margin-bottom:16px">Première connexion : remplacez votre mot de passe provisoire par un mot de passe personnel. Ne le communiquez à personne.</p>
    @if (session('erreur'))<div class="alert alert-danger" style="margin-bottom:16px">⚠️ {{ session('erreur') }}</div>@endif
    <form method="post" action="{{ route('mot_de_passe_initial.envoyer') }}" autocomplete="off">
      @csrf
      <div class="form-group"><label class="form-label" for="mot_de_passe">Nouveau mot de passe</label>
        <input type="password" name="mot_de_passe" id="mot_de_passe" class="form-control" minlength="8" required autocomplete="new-password">
        <div class="form-hint">8 caractères minimum, avec des lettres et des chiffres ; différent de votre matricule.</div></div>
      <div class="form-group"><label class="form-label" for="mot_de_passe_confirmation">Confirmer</label>
        <input type="password" name="mot_de_passe_confirmation" id="mot_de_passe_confirmation" class="form-control" minlength="8" required autocomplete="new-password"></div>
      <button type="submit" class="btn btn-primary w-100 btn-lg">Enregistrer</button>
    </form>
    <form method="post" action="{{ route('logout') }}" style="text-align:center;margin-top:14px;padding-top:14px;border-top:1px solid #eee">
      @csrf<button type="submit" style="background:none;border:none;font-size:12.5px;color:#8B6F1F;cursor:pointer">Se déconnecter</button>
    </form>
  </div>
</div>
@endsection
