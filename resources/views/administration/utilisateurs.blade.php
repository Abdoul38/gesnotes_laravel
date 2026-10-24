{{-- Comptes utilisateurs — portage de pages/comptes/utilisateurs.php --}}
@extends('layouts.app', ['titre' => 'Utilisateurs', 'fil' => 'Admin › Utilisateurs'])

@use('App\Http\Controllers\Administration\UtilisateursController', 'UC')

@section('contenu')
<div style="display:grid;grid-template-columns:minmax(0,1fr) 360px;gap:20px" class="grille-admin">

  <div class="card">
    <div class="card-header"><span class="card-title">👥 Comptes ({{ count($utilisateurs) }})</span></div>
    <div class="card-body" style="padding:0;overflow-x:auto">
      <table class="table">
        <thead><tr><th>Email</th><th>Nom</th><th style="text-align:center">Rôle</th><th>Faculté</th><th>Lié à</th><th style="text-align:center">Actions</th></tr></thead>
        <tbody>
        @foreach ($utilisateurs as $u)
        @php $coul = UC::COULEURS[$u->role] ?? '#999999'; $facCoul = preg_match('/^#[0-9A-Fa-f]{6}$/', (string) $u->fac_couleur) ? $u->fac_couleur : '#1A3A5C'; @endphp
        <tr>
          <td style="font-weight:700;font-family:monospace">{{ $u->email }}</td>
          <td>{{ $u->nom ?: '—' }}</td>
          <td style="text-align:center"><span style="background:{{ $coul }}20;color:{{ $coul }};padding:2px 10px;border-radius:10px;font-size:11px;font-weight:600">{{ UC::LIBELLES[$u->role] ?? $u->role }}</span></td>
          <td>
            @if ($u->fac_code)<span style="background:{{ $facCoul }}20;color:{{ $facCoul }};padding:2px 8px;border-radius:8px;font-size:11px;font-weight:700">{{ $u->fac_code }}</span>
            @else<span style="color:var(--text-muted);font-size:11px">—</span>@endif
          </td>
          <td style="font-size:11px">
            @if ($u->et_nom)<span style="color:var(--amber-600)">🎒 {{ $u->et_nom }} {{ $u->et_pren }}</span>
            @elseif ($u->ens_nom)<span style="color:var(--green-700)">👨‍🏫 {{ $u->ens_nom }} {{ $u->ens_pren }}</span>
            @else<span style="color:var(--gray-400)">—</span>@endif
          </td>
          <td style="text-align:center;white-space:nowrap">
            <button type="button" class="btn btn-sm btn-secondary" aria-label="Modifier"
              data-utilisateur="{{ json_encode(['id' => $u->id, 'email' => $u->email, 'nom' => $u->nom, 'role' => $u->role, 'faculte_id' => $u->faculte_id, 'departement_id' => $u->departement_id, 'etudiant_id' => $u->etudiant_id, 'enseignant_id' => $u->enseignant_id]) }}">✏️</button>
            @if ($u->id != auth()->id())
            <form method="post" action="{{ route('utilisateurs.supprimer') }}" style="display:inline" data-confirm="Supprimer ce compte ?">
              @csrf<input type="hidden" name="id" value="{{ $u->id }}">
              <button type="submit" class="btn btn-sm btn-danger" aria-label="Supprimer">🗑️</button>
            </form>
            @endif
          </td>
        </tr>
        @endforeach
        </tbody>
      </table>
    </div>
  </div>

  <div class="card">
    <div class="card-header"><span class="card-title">➕ Créer un compte</span></div>
    <div class="card-body">
      <form method="post" action="{{ route('utilisateurs.creer') }}" data-form-utilisateur autocomplete="off">
        @csrf
        <div class="form-group"><label class="form-label" for="n-email">Email <span class="req">*</span></label>
          <input type="email" name="email" id="n-email" class="form-control" placeholder="ex: admin@udh.ne" maxlength="150" required></div>
        <div class="form-group"><label class="form-label" for="n-nom">Nom affiché</label>
          <input type="text" name="nom" id="n-nom" class="form-control" placeholder="Nom complet" maxlength="100"></div>
        <div class="form-group"><label class="form-label" for="n-mdp">Mot de passe <span class="req">*</span></label>
          <input type="password" name="motdepasse" id="n-mdp" class="form-control" minlength="8" autocomplete="new-password" required>
          <div class="form-hint">8 caractères minimum, avec des lettres et des chiffres.</div></div>
        @include('administration._champs_utilisateur', ['prefixe' => 'n'])
        <button type="submit" class="btn btn-primary w-100">➕ Créer le compte</button>
      </form>
    </div>
  </div>
</div>

<div class="card mt-20">
  <div class="card-header"><span class="card-title">ℹ️ Description des rôles</span></div>
  <div class="card-body">
    <div style="display:grid;grid-template-columns:repeat(auto-fit,minmax(220px,1fr));gap:12px">
      @foreach (UC::DESCRIPTIONS as $r => [$ic, $desc])
      <div style="padding:10px;border:1px solid var(--border);border-radius:6px">
        <div style="font-weight:700;font-size:13px;margin-bottom:4px">{{ $ic }} {{ UC::LIBELLES[$r] }}</div>
        <div style="font-size:11px;color:var(--text-muted)">{{ $desc }}</div>
      </div>
      @endforeach
    </div>
  </div>
</div>

<div id="modal-utilisateur" class="modal-overlay" style="display:none">
  <div class="modal">
    <div class="modal-header"><span class="modal-title">✏️ Modifier — <span data-titre-email></span></span>
      <button type="button" data-onclick="closeModal" data-arg="modal-utilisateur" class="btn btn-icon" aria-label="Fermer">✕</button></div>
    <form method="post" action="{{ route('utilisateurs.modifier') }}" data-form-utilisateur autocomplete="off">
      @csrf
      <input type="hidden" name="id" value="">
      <div class="modal-body">
        <div class="form-group"><label class="form-label" for="m-nom">Nom affiché</label>
          <input type="text" name="nom" id="m-nom" class="form-control" maxlength="100"></div>
        <div class="form-group"><label class="form-label" for="m-email">Email <span class="req">*</span></label>
          <input type="text" name="email" id="m-email" class="form-control" maxlength="150" required></div>
        @include('administration._champs_utilisateur', ['prefixe' => 'm'])
        <div class="form-group"><label class="form-label" for="m-mdp">Nouveau mot de passe <span class="text-muted text-xs">(laisser vide = inchangé)</span></label>
          <input type="password" name="motdepasse" id="m-mdp" class="form-control" minlength="8" autocomplete="new-password" placeholder="Nouveau mot de passe"></div>
      </div>
      <div class="modal-footer">
        <button type="button" data-onclick="closeModal" data-arg="modal-utilisateur" class="btn btn-secondary">Annuler</button>
        <button type="submit" class="btn btn-primary">💾 Enregistrer</button>
      </div>
    </form>
  </div>
</div>

<script type="application/json" id="donnees-departements">{!! json_encode($departements->map(fn ($d) => ['id' => $d->id, 'faculte_id' => $d->faculte_id, 'libelle' => $d->libelle, 'code' => $d->code]), JSON_HEX_TAG | JSON_HEX_AMP) !!}</script>
@endsection

@push('scripts')
<script src="{{ \App\Support\Ressource::url('assets/js/utilisateurs.js') }}"></script>
@endpush
