{{-- Page de présentation (domaine sans sous-domaine).
     PROVISOIRE : l'ancienne page vitrine (pages/landing.php) sera migrée
     avec son module ; en attendant, liste des universités. --}}
<!DOCTYPE html>
<html lang="fr">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>CURSUS — Gestion académique des universités du Niger</title>
<link rel="stylesheet" href="{{ asset('assets/css/app.css') }}">
</head>
<body style="background:#0F2942;min-height:100vh;display:flex;align-items:center;justify-content:center;font-family:Georgia,serif;padding:20px">
  <div style="background:#FAF6ED;border-radius:12px;padding:32px;max-width:520px;width:100%">
    <h1 style="color:#0F2942;font-size:22px;margin-bottom:6px">CURSUS</h1>
    <p style="color:#8B6F1F;font-style:italic;margin-bottom:20px">Choisissez votre établissement</p>
    <ul style="list-style:none;padding:0;margin:0">
      @foreach ($universites as $u)
        <li style="margin-bottom:8px">
          <a href="//{{ $u->code }}.{{ config('universites.domaine') }}{{ request()->getPort() && ! in_array(request()->getPort(), [80, 443]) ? ':'.request()->getPort() : '' }}/connexion"
             style="display:block;padding:10px 14px;border:1px solid #d8cfb8;border-radius:8px;background:#fff;color:#0F2942;text-decoration:none">
            <strong>{{ $u->sigle }}</strong> — {{ $u->nom }}
          </a>
        </li>
      @endforeach
    </ul>
  </div>
</body>
</html>
