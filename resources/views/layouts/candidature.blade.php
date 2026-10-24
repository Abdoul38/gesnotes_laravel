{{-- Mise en page de l'espace candidat (barre « pnav » de l'ancien portail) --}}
@php $u = app(\App\Universites\UniversiteCourante::class)->get(); $actif = $actif ?? ''; @endphp
<!DOCTYPE html>
<html lang="fr">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width,initial-scale=1">
<title>{{ $titre ?? 'Portail candidat' }} — Portail {{ $u->sigle ?: strtoupper($u->code) }}</title>
<link rel="stylesheet" href="{{ asset('assets/candidature/portail.css') }}">
</head>
<body>
<nav class="pnav">
  <a href="{{ route('candidature.tableau') }}" class="pnav-brand">
    <div class="pnav-logo">🎓</div>
    <div class="pnav-name">{{ $u->nom }}<small>Portail Candidat</small></div>
  </a>
  <div class="pnav-links">
    <a href="{{ route('candidature.tableau') }}" @class(['active' => $actif === 'tableau'])>🏠 Accueil</a>
    <a href="{{ route('candidature.dossiers') }}" @class(['active' => $actif === 'dossiers'])>📋 Mes dossiers</a>
    <a href="{{ route('candidature.profil') }}" @class(['hide-mobile', 'active' => $actif === 'profil'])>👤 Profil</a>
    <div class="pnav-sep"></div>
    <form method="post" action="{{ route('candidature.deconnexion') }}" style="margin:0">
      @csrf
      <button type="submit" title="Déconnexion" aria-label="Déconnexion" style="background:none;border:none;color:inherit;font-size:16px;cursor:pointer;padding:6px 10px">🚪</button>
    </form>
  </div>
</nav>
@yield('avant')
<div class="pcontainer" @isset($largeur) style="max-width:{{ $largeur }}" @endisset>
  @foreach (['erreur' => ['error', '⚠️'], 'succes' => ['success', '✅']] as $cle => [$classe, $icone])
  @if (session($cle))<div class="palert palert-{{ $classe }}" role="alert">{{ $icone }} {{ session($cle) }}</div>@endif
  @endforeach
  @yield('contenu')
</div>
<script src="{{ \App\Support\Ressource::url('assets/js/candidature.js') }}"></script>
</body>
</html>
