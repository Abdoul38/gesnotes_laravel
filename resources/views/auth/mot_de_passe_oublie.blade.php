{{-- Mot de passe oublié — portage de pages/comptes/mot_de_passe_oublie.php --}}
@extends('layouts.app', ['titre' => 'Mot de passe oublié'])
@include('auth._style')

@section('contenu')
<div class="login-page">
  <div class="login-card">
    <div class="login-logo">
      <div class="logo-big">GN</div>
      <h1>{{ $universite->nomApplication() }}</h1>
      <p>{{ $universite->nom }}</p>
    </div>
    @if ($bloque)
    <div class="alert alert-danger" style="margin-bottom:16px">🔒 Trop de demandes. Réessayez dans quelques minutes.</div>
    @elseif (session('succes'))
    <div class="alert alert-success" style="margin-bottom:16px">✅ {{ session('succes') }}</div>
    @else
    <p style="font-size:13.5px;color:#555;margin-bottom:16px">Saisissez votre adresse e-mail de connexion — un lien pour choisir un nouveau mot de passe vous sera envoyé.</p>
    <form method="post" action="{{ route('mot_de_passe_oublie.envoyer') }}" autocomplete="off">
      @csrf
      <div class="form-group">
        <label class="form-label" for="email">Adresse e-mail</label>
        <input type="email" name="email" id="email" class="form-control" maxlength="150" placeholder="vous@exemple.ne" required autofocus>
      </div>
      <button type="submit" class="btn btn-primary w-100 btn-lg">Envoyer le lien</button>
    </form>
    @endif
    <div style="text-align:center;margin-top:14px;padding-top:14px;border-top:1px solid #eee">
      <a href="{{ route('login') }}" style="font-size:12.5px;color:#8B6F1F;text-decoration:none">← Retour à la connexion</a>
    </div>
  </div>
</div>
@endsection
