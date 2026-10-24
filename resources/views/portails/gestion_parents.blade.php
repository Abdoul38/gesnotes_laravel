{{-- Comptes parents — portage de pages/comptes/gestion_parents.php --}}
@extends('layouts.app', ['titre' => 'Comptes Parents', 'fil' => 'Administration › Portail parent'])

@section('contenu')
<div style="display:grid;grid-template-columns:1fr 1.3fr;gap:16px;align-items:start">
  <div>
    <div class="card mb-16">
      <div class="card-header"><span class="card-title">➕ Créer un compte parent</span></div>
      <div class="card-body">
        <form method="post" action="{{ route('gestion_parents.creer') }}" autocomplete="off">
          @csrf
          <div style="display:grid;grid-template-columns:1fr 1fr;gap:10px">
            <div class="form-group"><label class="form-label" for="c-nom">Nom</label><input type="text" name="nom" id="c-nom" class="form-control" maxlength="100" required></div>
            <div class="form-group"><label class="form-label" for="c-prenoms">Prénoms</label><input type="text" name="prenoms" id="c-prenoms" class="form-control" maxlength="100" required></div>
          </div>
          <div class="form-group"><label class="form-label" for="c-tel">Téléphone (identifiant de connexion)</label><input type="text" name="telephone" id="c-tel" class="form-control" placeholder="+227 90 12 34 56" required></div>
          <div class="form-group"><label class="form-label" for="c-email">Email (optionnel)</label><input type="email" name="email" id="c-email" class="form-control" maxlength="150"></div>
          <div class="form-group"><label class="form-label" for="c-mdp">Mot de passe initial</label><input type="text" name="mot_de_passe" id="c-mdp" class="form-control" placeholder="8 caractères min., lettres et chiffres" required autocomplete="new-password"></div>
          <button type="submit" class="btn btn-primary w-100">Créer le compte</button>
        </form>
      </div>
    </div>

    <div class="card">
      <div class="card-header"><span class="card-title">👨‍👩‍👧 Comptes existants</span></div>
      <div class="card-body" style="padding:10px 14px">
        <form method="get" action="{{ route('gestion_parents') }}" style="margin-bottom:10px">
          <input type="text" name="q" class="form-control" style="font-size:12px" placeholder="Rechercher..." value="{{ $q }}" aria-label="Rechercher un parent">
        </form>
      </div>
      <div style="max-height:400px;overflow-y:auto">
        @forelse ($parents as $p)
        <a href="{{ route('gestion_parents', ['parent_id' => $p->id]) }}" style="display:flex;justify-content:space-between;align-items:center;padding:10px 14px;border-bottom:1px solid #f0f0f0;text-decoration:none;color:inherit;{{ $choisi && $p->id == $choisi->id ? 'background:#eff6ff' : '' }}">
          <div>
            <div style="font-size:12.5px;font-weight:600">{{ $p->prenoms }} {{ $p->nom }}</div>
            <div style="font-size:11px;color:var(--text-muted)">{{ $p->telephone }} · {{ (int) $p->nb_enfants }} enfant(s)</div>
          </div>
          <span style="background:{{ $p->actif ? '#dcfce7' : '#fee2e2' }};color:{{ $p->actif ? '#166534' : '#dc2626' }};padding:2px 8px;border-radius:8px;font-size:10px;font-weight:700">{{ $p->actif ? 'ACTIF' : 'INACTIF' }}</span>
        </a>
        @empty
        <div style="padding:20px;text-align:center;color:var(--text-muted);font-size:13px">Aucun compte parent pour l'instant.</div>
        @endforelse
      </div>
    </div>
  </div>

  <div>
    @if (! $choisi)
    <div class="card" style="text-align:center;padding:50px">
      <div style="font-size:40px">👨‍👩‍👧</div>
      <div style="color:var(--text-muted);margin-top:10px">Sélectionnez un compte parent pour gérer ses enfants liés.</div>
    </div>
    @else
    <div class="card mb-16">
      <div class="card-header" style="display:flex;justify-content:space-between;align-items:center">
        <span class="card-title">✏️ Modifier {{ $choisi->prenoms }} {{ $choisi->nom }}</span>
        @if ($modifiable)
        <form method="post" action="{{ route('gestion_parents.basculer') }}" data-confirm="{{ $choisi->actif ? 'Désactiver' : 'Réactiver' }} ce compte ?">
          @csrf
          <input type="hidden" name="parent_id" value="{{ $choisi->id }}">
          <button type="submit" class="btn btn-sm btn-secondary">{{ $choisi->actif ? '🔒 Désactiver' : '🔓 Réactiver' }}</button>
        </form>
        @endif
      </div>
      <div class="card-body">
        @if (! $modifiable)
        <div class="alert alert-warning" style="font-size:12.5px">🔒 Ce compte est aussi lié à un étudiant hors de votre périmètre : seule la scolarité centrale peut le modifier.</div>
        @endif
        <form method="post" action="{{ route('gestion_parents.modifier') }}" autocomplete="off">
          @csrf
          <input type="hidden" name="parent_id" value="{{ $choisi->id }}">
          <fieldset @disabled(! $modifiable) style="border:none;padding:0;margin:0">
            <div style="display:grid;grid-template-columns:1fr 1fr;gap:10px">
              <div class="form-group"><label class="form-label" for="m-nom">Nom</label><input type="text" name="nom" id="m-nom" class="form-control" value="{{ $choisi->nom }}" maxlength="100" required></div>
              <div class="form-group"><label class="form-label" for="m-prenoms">Prénoms</label><input type="text" name="prenoms" id="m-prenoms" class="form-control" value="{{ $choisi->prenoms }}" maxlength="100" required></div>
            </div>
            <div class="form-group"><label class="form-label" for="m-tel">Téléphone</label><input type="text" name="telephone" id="m-tel" class="form-control" value="{{ $choisi->telephone }}" required></div>
            <div class="form-group"><label class="form-label" for="m-email">Email</label><input type="email" name="email" id="m-email" class="form-control" value="{{ $choisi->email }}" maxlength="150"></div>
            <div class="form-group"><label class="form-label" for="m-mdp">Réinitialiser le mot de passe (laisser vide pour ne pas changer)</label>
              <input type="text" name="nouveau_mot_de_passe" id="m-mdp" class="form-control" placeholder="8 caractères min., lettres et chiffres" autocomplete="new-password"></div>
            <div style="color:var(--text-muted);font-size:11px;margin-bottom:12px">Créé le {{ date('d/m/Y', strtotime($choisi->created_at)) }}</div>
            <button type="submit" class="btn btn-primary">💾 Enregistrer les modifications</button>
          </fieldset>
        </form>
      </div>
    </div>

    <div class="card mb-16">
      <div class="card-header"><span class="card-title">🔗 Lier un enfant</span></div>
      <div class="card-body">
        <form method="post" action="{{ route('gestion_parents.lier') }}" id="form-lier" style="display:flex;gap:8px;align-items:flex-end;flex-wrap:wrap">
          @csrf
          <input type="hidden" name="parent_id" value="{{ $choisi->id }}">
          <div class="form-group" style="margin:0;flex:1;min-width:220px;position:relative">
            <label class="form-label" for="rech-etudiant">Rechercher un étudiant (nom, prénom ou matricule)</label>
            <input type="text" id="rech-etudiant" class="form-control" placeholder="Taper pour rechercher..." autocomplete="off" data-url="{{ route('api_recherche_etudiants') }}">
            <input type="hidden" name="etudiant_id" id="etudiant_id_choisi">
            <div id="resultats-etudiant" role="listbox" style="display:none;position:absolute;top:100%;left:0;right:0;background:#fff;border:1px solid #ddd;border-radius:6px;max-height:200px;overflow-y:auto;z-index:10"></div>
          </div>
          <div class="form-group" style="margin:0"><label class="form-label" for="lien">Lien</label>
            <select name="lien" id="lien" class="form-control">@foreach ($liens as $k => $v)<option value="{{ $k }}">{{ $v }}</option>@endforeach</select></div>
          <button type="submit" class="btn btn-primary">Lier</button>
        </form>
      </div>
    </div>

    <div class="card">
      <div class="card-header"><span class="card-title">👶 Enfants liés ({{ $enfants->count() }})</span></div>
      <div class="card-body" style="padding:0">
        @forelse ($enfants as $e)
        <div style="display:flex;justify-content:space-between;align-items:center;padding:10px 14px;border-bottom:1px solid #f0f0f0">
          <div>
            <div style="font-size:13px;font-weight:600">{{ $e->nom }} {{ $e->prenoms }}</div>
            <div style="font-size:11px;color:var(--text-muted)">{{ $e->numero }} · {{ $liens[$e->lien] ?? ucfirst((string) $e->lien) }}</div>
          </div>
          <form method="post" action="{{ route('gestion_parents.delier') }}" data-confirm="Délier cet enfant de ce compte parent ?">
            @csrf
            <input type="hidden" name="parent_id" value="{{ $choisi->id }}">
            <input type="hidden" name="lien_id" value="{{ $e->lien_id }}">
            <button type="submit" class="btn btn-sm btn-secondary">✕ Délier</button>
          </form>
        </div>
        @empty
        <div style="padding:20px;text-align:center;color:var(--text-muted);font-size:13px">Aucun enfant lié pour l'instant.</div>
        @endforelse
      </div>
    </div>
    @endif
  </div>
</div>
@endsection

@push('scripts')
<script src="{{ \App\Support\Ressource::url('assets/js/gestion-parents.js') }}"></script>
@endpush
