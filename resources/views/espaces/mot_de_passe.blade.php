{{-- Changement de mot de passe d'un compte d'espace national --}}
@extends('espaces.layout', ['titre' => 'Changer mon mot de passe'])

@section('contenu')
<div class="card" style="max-width:440px;margin:24px auto">
  <div class="card-header"><span class="card-title">🔒 Changer mon mot de passe</span></div>
  <div class="card-body">
    <form method="post" action="{{ route($espace['code'].'.mot_de_passe.envoyer') }}" autocomplete="off">
      @csrf
      <div class="form-group"><label class="form-label" for="ancien_mdp">Ancien mot de passe</label><input type="password" name="ancien_mdp" id="ancien_mdp" class="form-control" required autocomplete="current-password"></div>
      <div class="form-group"><label class="form-label" for="nouveau_mdp">Nouveau mot de passe (8 caractères, lettres et chiffres)</label><input type="password" name="nouveau_mdp" id="nouveau_mdp" class="form-control" minlength="8" required autocomplete="new-password"></div>
      <div class="form-group"><label class="form-label" for="confirmer_mdp">Confirmer</label><input type="password" name="confirmer_mdp" id="confirmer_mdp" class="form-control" minlength="8" required autocomplete="new-password"></div>
      <button type="submit" class="btn btn-primary w-100">💾 Enregistrer</button>
    </form>
    <p style="text-align:center;margin-top:14px"><a href="{{ route($espace['code'].'.accueil') }}">← Retour</a></p>
  </div>
</div>
@endsection
