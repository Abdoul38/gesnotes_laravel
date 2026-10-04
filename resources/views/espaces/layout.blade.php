{{-- Mise en page des espaces Ministère et ANAB (barre de navigation de l'ancien index.php) --}}
@php
  $compte = auth()->guard($espace['garde'])->user();
  $code = $espace['code'];
@endphp
<!DOCTYPE html>
<html lang="fr">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<meta name="csrf-token" content="{{ csrf_token() }}">
<meta name="app-url" content="{{ url('/') }}">
<title>{{ $titre ?? $espace['titre'] }} — CURSUS</title>
<link rel="stylesheet" href="{{ asset('assets/css/app.css') }}">
<style>
:root{--brand:{{ $espace['couleur'] }}}
body{background:#f4f6f8}
.espace-nav{background:{{ $espace['couleur'] }};color:#fff;padding:0 20px;min-height:60px;display:flex;align-items:center;justify-content:space-between;gap:12px;flex-wrap:wrap;position:sticky;top:0;z-index:100;box-shadow:0 2px 8px rgba(0,0,0,.2)}
.espace-nav a,.espace-nav button{color:rgba(255,255,255,.9);text-decoration:none;font-size:12px;padding:6px 10px;border-radius:6px;background:none;border:none;cursor:pointer;font-family:inherit}
.espace-nav a:hover,.espace-nav button:hover{background:rgba(255,255,255,.15)}
.espace-nav .deconnexion{background:#dc2626;color:#fff;font-weight:600}
.espace-contenu{max-width:1400px;margin:0 auto;padding:16px}
</style>
@stack('head')
</head>
<body>
@if ($compte)
<nav class="espace-nav">
  <a href="{{ route("$code.accueil") }}" style="display:flex;align-items:center;gap:10px;font-weight:800;font-size:15px;padding:0">
    <span style="font-size:22px">{{ $espace['icone'] }}</span>
    <span>{{ $espace['titre'] }}<br><span style="font-size:10px;font-weight:400;opacity:.75">{{ $espace['sous_titre'] }}</span></span>
  </a>
  <div style="display:flex;align-items:center;gap:6px;flex-wrap:wrap">
    <span style="font-size:12px;opacity:.85">👤 {{ $compte->nom_complet }}</span>
    <a href="{{ route("$code.mot_de_passe") }}">🔒 Mot de passe</a>
    @if ($compte->estAdmin())<a href="{{ route("$code.comptes") }}">👥 Comptes</a>@endif
    @stack('nav')
    <form method="post" action="{{ route("$code.deconnexion") }}" style="margin:0">@csrf<button type="submit" class="deconnexion">🚪 Déconnexion</button></form>
  </div>
</nav>
@endif
<div class="espace-contenu">
  @foreach (['erreur' => 'error', 'succes' => 'success'] as $cle => $type)
  @if (session($cle))<div class="alert alert-{{ $type }}" role="alert" style="margin-bottom:14px">{{ session($cle) }}</div>@endif
  @endforeach
  @yield('contenu')
</div>
<script src="{{ asset('assets/js/layout.js') }}"></script>
<script src="{{ asset('assets/js/app.js') }}"></script>
@stack('scripts')
</body>
</html>
