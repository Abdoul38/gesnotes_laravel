{{-- Connexion à l'Espace Parents — portage de pages/comptes/login_parent.php --}}
@extends('layouts.app', ['titre' => 'Espace Parents'])

@push('head')
<style>
.parent-page { background:#0F2942; min-height:100vh; display:flex; align-items:center; justify-content:center; padding:20px; font-family:Georgia,'Times New Roman',serif; }
.parent-box { background:#FAF6ED; border-radius:12px; padding:36px 32px; width:100%; max-width:380px; box-shadow:0 10px 40px rgba(0,0,0,.3); }
.parent-box .logo { text-align:center; margin-bottom:24px; }
.parent-box .logo .icone { font-size:40px; }
.parent-box .logo h1 { font-size:22px; color:#0F2942; margin-top:8px; letter-spacing:1px; }
.parent-box .logo p { font-size:12.5px; color:#8B6F1F; font-style:italic; margin-top:4px; }
.parent-box label { display:block; font-size:12px; font-weight:600; color:#444; margin-bottom:5px; }
.parent-box input { width:100%; padding:10px 12px; border:1px solid #d8cfb8; border-radius:6px; font-size:14px; background:#fff; }
.parent-box .btn-connexion { width:100%; padding:11px; background:#0F2942; color:#fff; border:none; border-radius:6px; font-size:14px; font-weight:700; cursor:pointer; margin-top:6px; }
.parent-box .erreur { background:#fee2e2; color:#dc2626; padding:10px 12px; border-radius:6px; font-size:12.5px; margin-bottom:16px; }
.parent-box .info { background:#fef3c7; color:#92400e; padding:10px 12px; border-radius:6px; font-size:12.5px; margin-bottom:16px; }
.parent-box .note { text-align:center; font-size:11px; color:#8a8a8a; margin-top:20px; font-style:italic; }
</style>
@endpush

@section('contenu')
<div class="parent-page">
  <div class="parent-box">
    <div class="logo">
      <div class="icone">👨‍👩‍👧</div>
      <h1>ESPACE PARENTS</h1>
      <p>{{ $institution }}</p>
    </div>
    @if ($attente)
    <div class="erreur">Trop de tentatives. Réessayez dans {{ $attente }} minute(s).</div>
    @elseif (session('erreur'))
    <div class="erreur">{{ session('erreur') }}</div>
    @elseif (session('succes'))
    <div class="info" style="background:#dcfce7;color:#166534">{{ session('succes') }}</div>
    @elseif ($expiree)
    <div class="info">⌛ Votre session a expiré après une période d'inactivité. Veuillez vous reconnecter.</div>
    @endif
    <form method="post" action="{{ route('login_parent.envoyer') }}">
      @csrf
      <div style="margin-bottom:16px"><label for="telephone">Numéro de téléphone</label>
        <input type="text" name="telephone" id="telephone" value="{{ old('telephone') }}" placeholder="+227 90 12 34 56" required autofocus autocomplete="tel"></div>
      <div style="margin-bottom:16px"><label for="mot_de_passe">Mot de passe</label>
        <input type="password" name="mot_de_passe" id="mot_de_passe" required autocomplete="current-password"></div>
      <button type="submit" class="btn-connexion" @disabled($attente)>Se connecter</button>
    </form>
    <div style="text-align:center;margin-top:14px"><a href="{{ route('login_parent.oubli') }}" style="font-size:12.5px;color:#0F2942">Mot de passe oublié ?</a></div>
    <div class="note">Identifiants fournis par l'établissement. Mot de passe oublié : un code vous est envoyé par SMS.</div>
  </div>
</div>
@endsection
