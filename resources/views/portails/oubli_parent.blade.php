{{-- Espace Parents : mot de passe oublié (code envoyé par SMS) --}}
@extends('layouts.app', ['titre' => 'Espace Parents — mot de passe oublié'])

@push('head')
<style>
.parent-page { background:#0F2942; min-height:100vh; display:flex; align-items:center; justify-content:center; padding:20px; font-family:Georgia,'Times New Roman',serif; }
.parent-box { background:#FAF6ED; border-radius:12px; padding:36px 32px; width:100%; max-width:400px; box-shadow:0 10px 40px rgba(0,0,0,.3); }
.parent-box h1 { font-size:20px; color:#0F2942; text-align:center; margin-bottom:6px; }
.parent-box .sous { text-align:center; font-size:12.5px; color:#8B6F1F; font-style:italic; margin-bottom:20px; }
.parent-box label { display:block; font-size:12px; font-weight:600; color:#444; margin-bottom:5px; }
.parent-box input { width:100%; padding:10px 12px; border:1px solid #d8cfb8; border-radius:6px; font-size:14px; background:#fff; }
.parent-box .btn-connexion { width:100%; padding:11px; background:#0F2942; color:#fff; border:none; border-radius:6px; font-size:14px; font-weight:700; cursor:pointer; margin-top:6px; }
.parent-box .erreur { background:#fee2e2; color:#dc2626; padding:10px 12px; border-radius:6px; font-size:12.5px; margin-bottom:16px; }
.parent-box .info { background:#dcfce7; color:#166534; padding:10px 12px; border-radius:6px; font-size:12.5px; margin-bottom:16px; }
.parent-box .liens { text-align:center; font-size:12px; margin-top:18px; }
.parent-box .liens a { color:#0F2942; }
</style>
@endpush

@section('contenu')
<div class="parent-page">
  <div class="parent-box">
    <h1>🔑 Mot de passe oublié</h1>
    <div class="sous">Espace Parents — {{ $institution }}</div>
    @if (session('erreur'))<div class="erreur">{{ session('erreur') }}</div>@endif
    @if (session('succes'))<div class="info">{{ session('succes') }}</div>@endif

    @if (! $telephone)
    <form method="post" action="{{ route('login_parent.oubli.code') }}">
      @csrf
      <p style="font-size:13px;color:#444;margin-bottom:14px">Saisissez le numéro de téléphone de votre compte : un code de vérification vous sera envoyé par SMS.</p>
      <div style="margin-bottom:16px"><label for="telephone">Numéro de téléphone</label>
        <input type="text" name="telephone" id="telephone" placeholder="+227 90 12 34 56" required autofocus autocomplete="tel"></div>
      <button type="submit" class="btn-connexion">Recevoir un code</button>
    </form>
    @else
    <form method="post" action="{{ route('login_parent.oubli.reinitialiser') }}">
      @csrf
      <p style="font-size:13px;color:#444;margin-bottom:14px">Code envoyé au <strong>{{ $telephone }}</strong>.</p>
      <div style="margin-bottom:14px"><label for="code">Code reçu par SMS</label>
        <input type="text" name="code" id="code" inputmode="numeric" maxlength="6" placeholder="6 chiffres" required autofocus autocomplete="one-time-code"></div>
      <div style="margin-bottom:14px"><label for="nouveau">Nouveau mot de passe</label>
        <input type="password" name="nouveau" id="nouveau" required autocomplete="new-password" placeholder="8 caractères, lettres et chiffres"></div>
      <button type="submit" class="btn-connexion">Changer mon mot de passe</button>
    </form>
    <div class="liens"><a href="{{ route('login_parent.oubli', ['autre' => 1]) }}">Utiliser un autre numéro / renvoyer un code</a></div>
    @endif
    <div class="liens"><a href="{{ route('login_parent') }}">← Retour à la connexion</a></div>
  </div>
</div>
@endsection
