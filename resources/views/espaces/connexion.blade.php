{{-- Connexion à un espace national — ancien formulaire des blocs IS_MINISTERE / IS_ANAB --}}
<!DOCTYPE html>
<html lang="fr">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width,initial-scale=1">
<title>Connexion — {{ $espace['titre'] }} · CURSUS</title>
<link rel="stylesheet" href="{{ \App\Support\Ressource::url('assets/css/app.css') }}">
<style>
body{font-family:Georgia,'Times New Roman',serif;background:{{ $espace['code'] === 'anab' ? '#3b1d78' : '#0F2942' }};min-height:100vh;display:flex;align-items:center;justify-content:center;padding:20px;margin:0}
.boite{background:#FAF6ED;border-radius:12px;padding:36px 32px;width:100%;max-width:380px;box-shadow:0 10px 40px rgba(0,0,0,.3);text-align:center}
.boite h1{color:#0F2942;font-size:21px;margin:8px 0 4px}
.boite .sous{color:#8B6F1F;font-size:12.5px;font-style:italic;margin-bottom:24px}
.boite .form-group{text-align:left}
.boite button{width:100%;padding:11px;background:{{ $espace['couleur'] }};color:#fff;border:none;border-radius:6px;font-size:14px;font-weight:700;cursor:pointer;margin-top:6px}
.boite .pied{color:#8a8a8a;font-size:11px;margin-top:20px;font-style:italic}
</style>
</head>
<body>
<div class="boite">
  <div style="font-size:48px">{{ $espace['icone'] }}</div>
  <h1>{{ $espace['titre'] }}</h1>
  <p class="sous">{{ $espace['sous_titre'] }}</p>
  @if (session('erreur'))<div class="alert alert-error" role="alert" style="text-align:left">⚠️ {{ session('erreur') }}</div>@endif
  <form method="post" action="{{ route($espace['code'].'.connexion.envoyer') }}">
    @csrf
    <div class="form-group"><label class="form-label" for="user">Identifiant</label><input type="text" name="user" id="user" class="form-control" value="{{ old('user') }}" maxlength="50" required autocomplete="username"></div>
    <div class="form-group"><label class="form-label" for="pass">Mot de passe</label><input type="password" name="pass" id="pass" class="form-control" required autocomplete="current-password"></div>
    <button type="submit">🔑 Se connecter</button>
  </form>
  <p class="pied">Accès réservé — les consultations sont journalisées.</p>
</div>
<script src="{{ \App\Support\Ressource::url('assets/js/layout.js') }}"></script>
</body>
</html>
