{{-- Comptes d'un espace national (administrateurs) — anciennes pages gerer_comptes / gerer_comptes_anab --}}
@extends('espaces.layout', ['titre' => 'Gestion des comptes'])

@section('contenu')
<div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:16px;flex-wrap:wrap;gap:8px">
  <h2 style="color:var(--brand);font-size:18px;margin:0">👥 Gestion des comptes {{ $espace['code'] === 'anab' ? 'ANAB' : 'MESR' }}</h2>
  <a href="{{ route($espace['code'].'.accueil') }}" class="btn btn-secondary btn-sm">← Retour</a>
</div>
<div class="card mb-16">
  <div class="card-header"><span class="card-title">➕ Créer un nouveau compte</span></div>
  <div class="card-body">
    <form method="post" action="{{ route($espace['code'].'.comptes.creer') }}" autocomplete="off">
      @csrf
      <div style="display:grid;grid-template-columns:repeat(auto-fit,minmax(200px,1fr));gap:12px">
        <div class="form-group"><label class="form-label" for="c-login">Login</label><input type="text" name="login" id="c-login" class="form-control" maxlength="50" placeholder="ex: dsi" required></div>
        <div class="form-group"><label class="form-label" for="c-nom">Nom complet</label><input type="text" name="nom" id="c-nom" class="form-control" maxlength="100" placeholder="Prénom Nom" required></div>
        <div class="form-group"><label class="form-label" for="c-mdp">Mot de passe (8 car., lettres et chiffres)</label><input type="password" name="mdp" id="c-mdp" class="form-control" minlength="8" required autocomplete="new-password"></div>
        <div class="form-group"><label class="form-label" for="c-role">Rôle</label><select name="role" id="c-role" class="form-control">@foreach ($roles as $r => $lib)<option value="{{ $r }}">{{ $lib }}</option>@endforeach</select></div>
      </div>
      <button type="submit" class="btn btn-primary">➕ Créer</button>
    </form>
  </div>
</div>
<div class="card">
  <div class="card-header"><span class="card-title">📋 Comptes existants</span></div>
  <div class="card-body" style="padding:0;overflow-x:auto">
    <table class="table">
      <thead><tr><th>Login</th><th>Nom</th><th>Rôle</th><th>Statut</th><th>Dernière connexion</th><th>Actions</th></tr></thead>
      <tbody>
      @foreach ($comptes as $c)
      <tr>
        <td><strong>{{ $c->login }}</strong></td>
        <td>{{ $c->nom_complet }}</td>
        <td><span class="badge {{ $c->role === 'admin' ? 'badge-blue' : 'badge-green' }}">{{ $roles[$c->role] ?? $c->role }}</span></td>
        <td><span class="badge {{ $c->actif ? 'badge-green' : 'badge-red' }}">{{ $c->actif ? 'Actif' : 'Inactif' }}</span></td>
        <td style="color:var(--text-muted);font-size:12px">{{ $c->derniere_connexion?->format('d/m/Y H:i') ?? 'Jamais' }}</td>
        <td>
          <div style="display:flex;gap:6px;flex-wrap:wrap;align-items:center">
            <form method="post" action="{{ route($espace['code'].'.comptes.reinitialiser') }}" style="display:flex;gap:4px" data-confirm="Réinitialiser le mot de passe de {{ $c->login }} ?" autocomplete="off">
              @csrf<input type="hidden" name="compte_id" value="{{ $c->id }}">
              <input type="password" name="nouveau_mdp" class="form-control" placeholder="Nouveau MDP" minlength="8" required autocomplete="new-password" style="width:140px;padding:4px 8px;font-size:12px" aria-label="Nouveau mot de passe pour {{ $c->login }}">
              <button type="submit" class="btn btn-primary btn-sm">🔑 Reset</button>
            </form>
            @if ($c->login !== $protege && $c->id !== auth()->guard($espace['garde'])->id())
            <form method="post" action="{{ route($espace['code'].'.comptes.basculer') }}">
              @csrf<input type="hidden" name="compte_id" value="{{ $c->id }}">
              <button type="submit" class="btn btn-sm {{ $c->actif ? 'btn-danger' : 'btn-primary' }}">{{ $c->actif ? '🚫 Désactiver' : '✅ Activer' }}</button>
            </form>
            @endif
          </div>
        </td>
      </tr>
      @endforeach
      </tbody>
    </table>
  </div>
</div>
@endsection
