{{-- Connexion / création du compte candidat — portage de candidature/pages/accueil.php (?page=login|register) --}}
@php $sigle = $universite->sigle ?: strtoupper($universite->code); $connexion = $mode === 'connexion'; @endphp
<!DOCTYPE html>
<html lang="fr">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width,initial-scale=1">
<title>{{ $connexion ? 'Connexion' : 'Créer un compte' }} — Portail {{ $sigle }}</title>
<link rel="stylesheet" href="{{ asset('assets/candidature/accueil.css') }}">
</head>
<body>
<div class="auth-wrap">
  <div class="auth-left">
    <div class="auth-left-logo">🎓</div>
    <h2>{{ $universite->nom }}</h2>
    <p>Portail officiel de dépôt de dossiers de préinscription — Année académique {{ $annee }}</p>
    <div class="auth-steps">
      @foreach ([['Informations personnelles', 'Identité, naissance, baccalauréat'], ['Choix de filières', "Jusqu'à 3 filières par ordre de préférence"],
                 ['Pièces justificatives', 'Photo, extrait, relevé, CNI'], ['Soumission', 'Dossier envoyé + quitus généré']] as $i => [$t, $d])
      <div class="auth-step"><div class="auth-step-num">{{ $i + 1 }}</div><div class="auth-step-text"><strong>{{ $t }}</strong><span>{{ $d }}</span></div></div>
      @endforeach
    </div>
  </div>
  <div class="auth-right">
    <div class="auth-right-inner">
      @if (session('erreur'))<div class="alert alert-error" role="alert">⚠️ {{ session('erreur') }}</div>@endif
      @if ($attente)<div class="alert alert-error" role="alert">⚠️ Trop de tentatives. Réessayez dans {{ $attente }} minute(s).</div>@endif
      @if ($connexion)
      <h1>Bon retour 👋</h1>
      <p class="sub">Connectez-vous pour accéder à votre espace candidat.</p>
      <form method="post" action="{{ route('candidature.connexion.envoyer') }}">
        @csrf
        <div class="form-group"><label for="email">Adresse email <span class="req">*</span></label>
          <input type="email" name="email" id="email" value="{{ old('email') }}" placeholder="votre@email.com" required autocomplete="email"></div>
        <div class="form-group"><label for="mdp">Mot de passe <span class="req">*</span></label>
          <input type="password" name="mdp" id="mdp" placeholder="••••••••" required autocomplete="current-password"></div>
        <button type="submit" class="btn-submit">🔑 Se connecter</button>
      </form>
      <div class="auth-switch">Pas encore de compte ? <a href="{{ route('candidature.compte') }}">Créer mon espace candidat</a></div>
      @else
      <h1>Créer mon compte</h1>
      <p class="sub">Déposez votre dossier de préinscription en quelques minutes.</p>
      <form method="post" action="{{ route('candidature.compte.creer') }}">
        @csrf
        <div class="form-grid-2">
          <div class="form-group"><label for="nom">Nom <span class="req">*</span></label>
            <input type="text" name="nom" id="nom" value="{{ old('nom') }}" placeholder="NOM" required maxlength="100" style="text-transform:uppercase" autocomplete="family-name"></div>
          <div class="form-group"><label for="prenom">Prénom <span class="req">*</span></label>
            <input type="text" name="prenom" id="prenom" value="{{ old('prenom') }}" placeholder="Prénom" required maxlength="150" autocomplete="given-name"></div>
        </div>
        <div class="form-group"><label for="email">Email <span class="req">*</span></label>
          <input type="email" name="email" id="email" value="{{ old('email') }}" placeholder="votre@email.com" required maxlength="150" autocomplete="email"></div>
        <div class="form-group"><label for="telephone">Téléphone</label>
          <input type="tel" name="telephone" id="telephone" value="{{ old('telephone') }}" placeholder="+227 00 00 00 00" maxlength="30" autocomplete="tel"></div>
        <div class="sep-pass">
          <div class="sep-label">🔒 Mot de passe</div>
          <div class="form-grid-2">
            <div class="form-group"><label for="mdp">Mot de passe <span class="req">*</span></label>
              <input type="password" name="mdp" id="mdp" placeholder="8 caractères, lettres et chiffres" required minlength="8" autocomplete="new-password"></div>
            <div class="form-group"><label for="mdp2">Confirmer <span class="req">*</span></label>
              <input type="password" name="mdp2" id="mdp2" placeholder="Répéter" required minlength="8" autocomplete="new-password"></div>
          </div>
        </div>
        <button type="submit" class="btn-submit">📝 Créer mon espace candidat</button>
      </form>
      <div class="auth-switch">Déjà inscrit ? <a href="{{ route('candidature.connexion') }}">Me connecter</a></div>
      @endif
      <div class="auth-switch"><a href="{{ route('candidature') }}">← Retour à l'accueil du portail</a></div>
    </div>
  </div>
</div>
</body>
</html>
