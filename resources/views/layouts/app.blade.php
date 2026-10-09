{{-- Mise en page du back-office — portage de includes/layout.php
     (render_head, render_sidebar, render_topbar, render_scripts). --}}
@inject('parametres', 'App\Services\Parametres')
@inject('navigation', 'App\Navigation\Menu')
@php
    // Couleur de marque : celle choisie dans Paramètres, sinon celle de
    // l'université ; toujours vérifiée au format hexadécimal.
    $couleur = collect([$parametres->valeur('couleur_marque'), $universite->couleur])
        ->first(fn ($c) => preg_match('/^#[0-9A-Fa-f]{6}$/', (string) $c));
    $utilisateur = auth()->user();
    $pageCourante = request()->route()?->getName();
    $flash = collect(['succes' => 'success', 'erreur' => 'error', 'avertissement' => 'warning', 'info' => 'info'])
        ->map(fn ($type, $cle) => session($cle) ? ['type' => $type, 'msg' => session($cle)] : null)
        ->filter()->first();
@endphp
<!DOCTYPE html>
<html lang="fr">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<meta name="csrf-token" content="{{ csrf_token() }}">
<meta name="app-url" content="{{ url('/') }}">
<title>{{ isset($titre) ? $titre.' — ' : '' }}{{ $universite->nomApplication() }}</title>
<link rel="stylesheet" href="{{ asset('assets/css/app.css') }}">
@if ($couleur)
<style>:root{--brand:{{ $couleur }}}</style>
@endif
<link rel="manifest" href="{{ asset('manifest.json') }}">
<meta name="theme-color" content="{{ $couleur ?: '#0F2942' }}">
<meta name="mobile-web-app-capable" content="yes">
<meta name="apple-mobile-web-app-capable" content="yes">
<meta name="apple-mobile-web-app-status-bar-style" content="black-translucent">
<meta name="apple-mobile-web-app-title" content="EDUFILE">
<link rel="apple-touch-icon" href="{{ asset('assets/icons/icon-192.png') }}">
<link rel="icon" type="image/png" sizes="192x192" href="{{ asset('assets/icons/icon-192.png') }}">
<script src="{{ asset('assets/js/pwa-register.js') }}"></script>
@stack('head')
</head>
<body>
@auth
<div class="layout">
<aside class="sidebar" id="sidebar">
  <div class="sidebar-logo">
    <div class="logo-icon">GS</div>
    <div><div class="logo-text">{{ $universite->nomApplication() }}</div><div class="logo-sub">Gestion Scolarité</div></div>
  </div>
  <nav class="sidebar-nav" id="sidebar-nav">
    @foreach ($navigation->pour($utilisateur) as $section => $items)
      <div class="nav-section-label">{{ $section }}</div>
      @foreach ($items as $item)
        <a href="{{ $item['url'] }}" class="nav-item{{ $pageCourante === $item['page'] ? ' active' : '' }}">
          <span class="nav-icon">{{ $item['icone'] }}</span><span>{{ $item['libelle'] }}</span>
        </a>
      @endforeach
    @endforeach
  </nav>
  <div class="sidebar-footer">
    <a href="{{ route('mon_compte') }}" class="sidebar-user" title="Mon compte : profil et mot de passe" @if (request()->routeIs('mon_compte')) aria-current="page" @endif>
      <div class="avatar-sm">{{ $utilisateur->initiales() }}</div>
      <div class="sidebar-user-info">
        <div class="sidebar-user-name">{{ $utilisateur->nomComplet() }}</div>
        <div class="sidebar-user-role">{{ $utilisateur->role }}</div>
        @if ($faculte = $utilisateur->faculte)
          @php $cf = preg_match('/^#[0-9A-Fa-f]{6}$/', (string) $faculte->couleur) ? $faculte->couleur : '#1A3A5C'; @endphp
          <div style="font-size:10px;margin-top:2px;padding:2px 6px;border-radius:4px;background:{{ $cf }}20;color:{{ $cf }}">{{ $faculte->code }} — {{ $faculte->libelle }}</div>
        @endif
      </div>
    </a>
    <form method="post" action="{{ route('logout') }}" style="margin:0">
      @csrf
      <button type="submit" class="nav-item" style="margin-top:0;width:100%;background:none;border:none;cursor:pointer;text-align:left;font:inherit">
        <span class="nav-icon">🚪</span><span>Déconnexion</span>
      </button>
    </form>
  </div>
</aside>
<div class="sidebar-backdrop" id="sidebar-backdrop"></div>

<div class="main-wrap">
  <header class="topbar">
    <button type="button" class="menu-toggle" id="menu-toggle" aria-label="Ouvrir le menu" aria-controls="sidebar" aria-expanded="false">☰</button>
    <div class="topbar-title">{{ $titre ?? '' }}</div>
    @isset($fil)<div class="topbar-breadcrumb">{{ $fil }}</div>@endisset
  </header>
  @if ($flash)
    <div id="flash-data" data-msg="{{ $flash['msg'] }}" data-type="{{ $flash['type'] }}" style="display:none"></div>
  @endif
  <div class="page-content">
    @yield('contenu')
  </div>
</div>
</div>
@else
  @if ($flash)
    <div id="flash-data" data-msg="{{ $flash['msg'] }}" data-type="{{ $flash['type'] }}" style="display:none"></div>
  @endif
  @yield('contenu')
@endauth

<script src="{{ asset('assets/js/layout.js') }}"></script>
<script src="{{ asset('assets/js/app.js') }}"></script>
@stack('scripts')
</body>
</html>
