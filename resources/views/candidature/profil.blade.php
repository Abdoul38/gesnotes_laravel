{{-- Profil du candidat — portage de candidature/pages/profil.php --}}
@extends('layouts.candidature', ['titre' => 'Mon profil', 'actif' => 'profil', 'largeur' => '640px'])

@section('contenu')
<div class="pheader">
  <div class="pbreadcrumb"><a href="{{ route('candidature.tableau') }}">Accueil</a> › Mon profil</div>
  <h1>👤 Mon profil</h1>
</div>
<form method="post" action="{{ route('candidature.profil.maj') }}" autocomplete="off">
  @csrf
  <div class="pcard">
    <div class="pcard-header"><h2>Informations</h2></div>
    <div class="pcard-body">
      <div class="pform-grid-2">
        <div class="pform-group"><label for="nom">Nom</label><input type="text" name="nom" id="nom" value="{{ $candidat->nom }}" required maxlength="100" style="text-transform:uppercase"></div>
        <div class="pform-group"><label for="prenom">Prénom</label><input type="text" name="prenom" id="prenom" value="{{ $candidat->prenom }}" required maxlength="150"></div>
      </div>
      <div class="pform-group"><label for="email">Email</label><input type="email" id="email" value="{{ $candidat->email }}" disabled><div class="pform-hint">L'email sert d'identifiant : il ne peut pas être modifié.</div></div>
      <div class="pform-group"><label for="telephone">Téléphone</label><input type="tel" name="telephone" id="telephone" value="{{ $candidat->telephone }}" maxlength="30"></div>
    </div>
  </div>
  <div class="pcard">
    <div class="pcard-header"><h2>🔒 Changer le mot de passe</h2></div>
    <div class="pcard-body">
      <div class="pform-group"><label for="mdp_actuel">Mot de passe actuel</label><input type="password" name="mdp_actuel" id="mdp_actuel" autocomplete="current-password"></div>
      <div class="pform-grid-2">
        <div class="pform-group"><label for="mdp">Nouveau mot de passe</label><input type="password" name="mdp" id="mdp" placeholder="8 caractères, lettres et chiffres" autocomplete="new-password"></div>
        <div class="pform-group"><label for="mdp2">Confirmer</label><input type="password" name="mdp2" id="mdp2" placeholder="Répéter" autocomplete="new-password"></div>
      </div>
      <div class="pform-hint">Laissez vide pour ne pas changer.</div>
    </div>
  </div>
  <button type="submit" class="pbtn pbtn-primary pbtn-lg">💾 Enregistrer</button>
</form>
@endsection
