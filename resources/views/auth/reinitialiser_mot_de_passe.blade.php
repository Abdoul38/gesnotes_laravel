{{-- Nouveau mot de passe — portage de pages/comptes/reinitialiser_mot_de_passe.php --}}
@extends('layouts.app', ['titre' => 'Nouveau mot de passe'])
@include('auth._style')

@section('contenu')
<div class="login-page">
  <div class="login-card">
    <div class="login-logo">
      <div class="logo-big">GN</div>
      <h1>{{ $universite->nomApplication() }}</h1>
      <p>{{ $universite->nom }}</p>
    </div>
    @if (! $valide)
    <div class="alert alert-danger" style="margin-bottom:16px">⚠️ Ce lien de réinitialisation est invalide, a déjà été utilisé, ou a expiré. Demandez-en un nouveau.</div>
    <a href="{{ route('mot_de_passe_oublie') }}" class="btn btn-primary w-100">Demander un nouveau lien</a>
    @else
    @if (session('erreur'))<div class="alert alert-danger" style="margin-bottom:16px">⚠️ {{ session('erreur') }}</div>@endif
    <form method="post" action="{{ route('reinitialiser_mot_de_passe.envoyer') }}" autocomplete="off">
      @csrf
      <input type="hidden" name="token" value="{{ $token }}">
      <div class="form-group"><label class="form-label" for="mot_de_passe">Nouveau mot de passe</label>
        <input type="password" name="mot_de_passe" id="mot_de_passe" class="form-control" minlength="8" required autocomplete="new-password">
        <div class="form-hint">8 caractères minimum, avec des lettres et des chiffres.</div></div>
      <div class="form-group"><label class="form-label" for="mot_de_passe2">Confirmer</label>
        <input type="password" name="mot_de_passe2" id="mot_de_passe2" class="form-control" minlength="8" required autocomplete="new-password"></div>
      <button type="submit" class="btn btn-primary w-100 btn-lg">Enregistrer</button>
    </form>
    @endif
    <div style="text-align:center;margin-top:14px;padding-top:14px;border-top:1px solid #eee">
      <a href="{{ route('login') }}" style="font-size:12.5px;color:#8B6F1F;text-decoration:none">← Retour à la connexion</a>
    </div>
  </div>
</div>
@endsection
