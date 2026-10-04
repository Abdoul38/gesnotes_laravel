{{-- Connexion — portage de pages/comptes/login.php --}}
@extends('layouts.app', ['titre' => 'Connexion'])

@include('auth._style')

@section('contenu')
@php $bloque = $attente > 0; @endphp
<div class="login-page">
  <div class="login-card">
    <div class="login-logo">
      @if ($logo)
      <div class="logo-big" style="background:transparent !important;overflow:hidden">
        <img src="{{ route('fichiers.logo', $logo) }}" alt="" style="width:100%;height:100%;object-fit:contain" data-onerror-fallback-icon="GN">
      </div>
      @else
      <div class="logo-big">GN</div>
      @endif
      <h1>{{ $universite->nomApplication() }}</h1>
      <p>{{ $universite->nom }}</p>
    </div>

    @if ($expiree)
    <div class="alert alert-warning" style="margin-bottom:16px;display:flex;align-items:center;gap:8px;padding:12px 16px;border-radius:8px;background:#fef3c7;border:1px solid #fbbf24;color:#92400e">
      ⏱️ Votre session a expiré après {{ config('gesnotes.inactivite_minutes') }} minutes d'inactivité. Veuillez vous reconnecter.
    </div>
    @endif

    @if ($bloque)
    <div class="alert alert-danger" style="margin-bottom:16px;padding:12px 16px;border-radius:8px;background:#fee2e2;border:1px solid #fca5a5;color:#991b1b">
      🔒 Accès temporairement bloqué. Réessayez dans {{ (int) ceil($attente / 60) }} minute(s).
    </div>
    @elseif (session('erreur'))
    <div class="alert alert-danger">⚠️ {{ session('erreur') }}</div>
    @elseif (session('succes'))
    <div class="alert alert-success">✅ {{ session('succes') }}</div>
    @endif

    <form method="post" action="{{ route('login.envoyer') }}" autocomplete="off" novalidate @if ($bloque) style="opacity:.5;pointer-events:none" @endif>
      @csrf
      <div class="form-group">
        <label class="form-label" for="email">Adresse e-mail</label>
        <input type="email" id="email" name="email" class="form-control" value="{{ old('email') }}"
               placeholder="vous@gmail.ne" required autofocus @disabled($bloque)>
      </div>
      <div class="form-group">
        <label class="form-label" for="mot_de_passe">Mot de passe</label>
        <input type="password" id="mot_de_passe" name="mot_de_passe" class="form-control" placeholder="••••••••" required @disabled($bloque)>
        <div style="text-align:right;margin-top:6px">
          <a href="{{ route('mot_de_passe_oublie') }}" style="font-size:12px;color:#8B6F1F;text-decoration:none">Mot de passe oublié ?</a>
        </div>
      </div>
      <button type="submit" class="btn btn-primary w-100 btn-lg" @disabled($bloque)>
        {{ $bloque ? '🔒 Accès bloqué' : 'Se connecter' }}
      </button>
    </form>

    <div style="text-align:center;margin-top:14px;padding-top:14px;border-top:1px solid #eee">
      <a href="{{ route('login_parent') }}" style="font-size:12.5px;color:#8B6F1F;text-decoration:none">
        👨‍👩‍👧 Vous êtes un parent d'élève ? Accéder à l'Espace Parents →
      </a>
    </div>
  </div>
</div>
@endsection
