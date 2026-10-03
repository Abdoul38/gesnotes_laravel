{{-- Fiche étudiant — portage de pages/etudiants/etudiant_form.php --}}
@extends('layouts.app', ['titre' => $id ? 'Modifier étudiant' : 'Ajouter un étudiant', 'fil' => 'Étudiants'])
@inject('navigation', 'App\Navigation\Menu')
@use('App\Http\Controllers\Etudiants\FicheEtudiantController', 'Fiche')

@section('contenu')
@php
  $val = fn (string $champ, $defaut = '') => old($champ, $v->{$champ} ?? $defaut);
  $numero = $v->numero ?? null;
  $sansMatricule = ! $id || in_array($numero, [null, '', '0'], true);
  $natActuelle = $val('nationalite', 'Nigérienne') ?: 'Nigérienne';
  $natAutre = ! in_array($natActuelle, Fiche::NATIONALITES, true);
@endphp

@if (session('erreur'))<div class="alert alert-danger">⚠️ {{ session('erreur') }}</div>@endif

<form method="post" action="{{ route('etudiant_form.enregistrer') }}">
@csrf
@if ($id)<input type="hidden" name="id" value="{{ $id }}">@endif
<div style="display:grid;grid-template-columns:1fr 320px;gap:20px">
  <div>
    <div class="card mb-16">
      <div class="card-header"><span class="card-title">👤 Identité</span></div>
      <div class="card-body">
        <div class="form-group">
          <label class="form-label" for="numero">Matricule</label>
          @if ($sansMatricule)
            <div style="display:flex;align-items:center;gap:10px">
              <input type="text" id="numero" class="form-control" value="—" disabled style="background:var(--gray-50);color:var(--text-muted);max-width:160px">
              <span style="font-size:12px;color:var(--amber-600)">⚠️ Sera généré automatiquement lors de l'inscription formelle</span>
            </div>
          @else
            <input type="text" id="numero" name="numero" class="form-control" value="{{ $val('numero') }}" style="max-width:200px" placeholder="Matricule">
            <div class="form-hint">Modifiable uniquement si l'étudiant est déjà inscrit</div>
          @endif
        </div>
        <div class="form-grid form-grid-2">
          <div class="form-group"><label class="form-label" for="nom">Nom <span class="req">*</span></label>
            <input type="text" id="nom" name="nom" class="form-control" value="{{ $val('nom') }}" required placeholder="NOM DE FAMILLE" style="text-transform:uppercase"></div>
          <div class="form-group"><label class="form-label" for="prenoms">Prénoms <span class="req">*</span></label>
            <input type="text" id="prenoms" name="prenoms" class="form-control" value="{{ $val('prenoms') }}" required placeholder="Prénoms"></div>
          <div class="form-group"><label class="form-label" for="ddn">Date de naissance <span class="req">*</span></label>
            <input type="date" id="ddn" name="date_naissance" class="form-control" required value="{{ $val('date_naissance') }}"></div>
          <div class="form-group"><label class="form-label" for="lieu">Lieu de naissance <span class="req">*</span></label>
            <input type="text" id="lieu" name="lieu_naissance" class="form-control" required value="{{ $val('lieu_naissance') }}"></div>
          <div class="form-group"><label class="form-label" for="sexe">Sexe <span class="req">*</span></label>
            <select id="sexe" name="sexe" class="form-control" required>
              <option value="">—</option>
              <option value="M" @selected($val('sexe') === 'M')>Masculin</option>
              <option value="F" @selected($val('sexe') === 'F')>Féminin</option>
            </select></div>
          <div class="form-group">
            <label class="form-label" for="sel-nationalite">Nationalité <span class="req">*</span></label>
            <select name="nationalite" id="sel-nationalite" class="form-control" required data-show-if-equals="nationalite-autre" data-equals-value="__autre__">
              @foreach (Fiche::NATIONALITES as $nat)<option value="{{ $nat }}" @selected($natActuelle === $nat)>{{ $nat }}</option>@endforeach
              <option value="__autre__" @selected($natAutre)>Autre (préciser)</option>
            </select>
            <input type="text" id="nationalite-autre" name="nationalite_autre" class="form-control" style="margin-top:8px;display:{{ $natAutre ? 'block' : 'none' }}" placeholder="Précisez la nationalité" value="{{ $natAutre ? $natActuelle : '' }}" aria-label="Autre nationalité">
          </div>
        </div>
      </div>
    </div>

    <div class="card mb-16">
      <div class="card-header"><span class="card-title">📞 Contact</span></div>
      <div class="card-body">
        <div class="form-grid form-grid-2">
          <div class="form-group"><label class="form-label" for="tel">Téléphone</label><input type="text" id="tel" name="telephone" class="form-control" value="{{ $val('telephone') }}" placeholder="+227..."></div>
          <div class="form-group"><label class="form-label" for="email">Email</label><input type="email" id="email" name="email" class="form-control" value="{{ $val('email') }}"></div>
          <div class="form-group"><label class="form-label" for="tel_urgence">Téléphone urgence</label><input type="text" id="tel_urgence" name="tel_urgence" class="form-control" value="{{ $val('tel_urgence') }}"></div>
        </div>
        <div class="form-group"><label class="form-label" for="adresse">Adresse</label><textarea id="adresse" name="adresse" class="form-control" rows="2">{{ $val('adresse') }}</textarea></div>
      </div>
    </div>

    <div class="card mb-16">
      <div class="card-header"><span class="card-title">👨‍👩‍👦 Famille</span></div>
      <div class="card-body">
        <div class="form-grid form-grid-2">
          <div class="form-group"><label class="form-label" for="nom_pere">Nom du père</label><input type="text" id="nom_pere" name="nom_pere" class="form-control" value="{{ $val('nom_pere') }}"></div>
          <div class="form-group"><label class="form-label" for="nom_mere">Nom de la mère</label><input type="text" id="nom_mere" name="nom_mere" class="form-control" value="{{ $val('nom_mere') }}"></div>
        </div>
      </div>
    </div>

    @if ($filieres->isNotEmpty())
    <div class="card mb-16">
      <div class="card-header"><span class="card-title">📚 Filière</span></div>
      <div class="card-body">
        <div class="form-group">
          <label class="form-label" for="filiere_id">Filière de rattachement</label>
          <select id="filiere_id" name="filiere_id" class="form-control" required>
            <option value="">— Aucune / Non définie —</option>
            @foreach ($filieres->groupBy('faculte') as $faculteLib => $groupe)
              <optgroup label="{{ $faculteLib }}">
                @foreach ($groupe as $fl)<option value="{{ $fl->id }}" @selected($val('filiere_id') == $fl->id)>{{ $fl->libelle }}</option>@endforeach
              </optgroup>
            @endforeach
          </select>
          <div class="form-hint">La filière principale de l'étudiant (indépendante des inscriptions)</div>
        </div>
      </div>
    </div>
    @endif

    <div class="card mb-16">
      <div class="card-header"><span class="card-title">🎓 Baccalauréat</span></div>
      <div class="card-body">
        <div class="form-grid form-grid-2">
          <div class="form-group"><label class="form-label" for="bac_serie">Série</label><input type="text" id="bac_serie" name="bac_serie" class="form-control" value="{{ $val('bac_serie') }}" placeholder="Ex: D, A4, C..."></div>
          <div class="form-group"><label class="form-label" for="bac_annee">Année</label><input type="number" id="bac_annee" name="bac_annee" class="form-control" value="{{ $val('bac_annee') }}" min="1990" max="{{ date('Y') }}"></div>
          <div class="form-group"><label class="form-label" for="bac_mention">Mention</label>
            <select id="bac_mention" name="bac_mention" class="form-control">
              <option value="">—</option>
              @foreach (['Passable', 'Assez Bien', 'Bien', 'Très Bien'] as $m)<option @selected($val('bac_mention') === $m)>{{ $m }}</option>@endforeach
            </select></div>
          <div class="form-group"><label class="form-label" for="etab">Établissement d'origine</label><input type="text" id="etab" name="etablissement_origine" class="form-control" value="{{ $val('etablissement_origine') }}"></div>
        </div>
        <div class="form-grid form-grid-2" style="margin-top:14px;padding-top:14px;border-top:1px solid var(--border)">
          <div class="form-group"><label class="form-label" for="ndos">N° Dossier <span class="req">*</span></label>
            <input type="text" id="ndos" name="numero_dossier" class="form-control" value="{{ $val('numero_dossier') }}" required placeholder="Ex: D915839" style="text-transform:uppercase">
            <div class="form-hint">N° dossier portail candidats</div></div>
          <div class="form-group"><label class="form-label" for="ndep">N° Dépôt</label>
            <input type="text" id="ndep" name="numero_depot" class="form-control" value="{{ $val('numero_depot') }}" placeholder="Ex: udh543732">
            <div class="form-hint">N° dépôt portail candidats</div></div>
        </div>
      </div>
    </div>
  </div>

  <div>
    <div class="card mb-16">
      <div class="card-header"><span class="card-title">📋 Statut</span></div>
      <div class="card-body">
        <div class="form-group">
          <label class="form-label" for="statut">Statut étudiant</label>
          <select id="statut" name="statut_etudiant" class="form-control">
            @foreach (Fiche::STATUTS as $s => $l)<option value="{{ $s }}" @selected($val('statut_etudiant', 'actif') === $s)>{{ $l }}</option>@endforeach
          </select>
        </div>
        @unless ($id)
        <div style="border-top:1px solid var(--border);padding-top:14px;margin-top:6px">
          <label class="form-label" for="promotion_id" style="color:var(--blue-700);font-weight:700">🎓 Inscrire maintenant</label>
          <div class="form-hint mb-8">Si vous sélectionnez une promotion, le matricule sera généré automatiquement.</div>
          <select id="promotion_id" name="promotion_id" class="form-control">
            <option value="">— Sans inscription (pas encore de matricule) —</option>
            @foreach ($promos as $p)<option value="{{ $p->id }}" @selected(old('promotion_id') == $p->id)>{{ $p->filiere }} {{ $p->niveau }} — {{ $p->annee }}</option>@endforeach
          </select>
        </div>
        @endunless
      </div>
    </div>

    @if ($id && $inscriptions->isNotEmpty())
    <div class="card mb-16">
      <div class="card-header"><span class="card-title">📚 Inscriptions</span></div>
      <div class="card-body" style="padding:0">
        <table class="table" style="font-size:12px">
          <thead><tr><th>Filière</th><th>Niv.</th><th>Année</th><th style="text-align:center">Statut</th></tr></thead>
          <tbody>
          @foreach ($inscriptions as $ins)
          <tr><td>{{ $ins->filiere }}</td><td>{{ $ins->niveau }}</td><td>{{ $ins->annee }}</td>
            <td style="text-align:center"><span style="font-size:10px;font-weight:600;color:{{ $ins->statut === 'inscrit' ? 'var(--green-700)' : 'var(--amber-600)' }}">{{ $ins->statut }}</span></td></tr>
          @endforeach
          </tbody>
        </table>
      </div>
    </div>
    @endif

    <div class="card">
      <div class="card-body">
        <button type="submit" class="btn btn-primary w-100 mb-8">💾 {{ $id ? 'Enregistrer les modifications' : "Créer l'étudiant" }}</button>
        @if ($id)<a href="{{ $navigation->lien('dossier_etudiant', ['id' => $id]) }}" class="btn btn-secondary w-100 mb-8">📁 Voir le dossier</a>@endif
        <a href="{{ route('etudiants') }}" class="btn btn-secondary w-100">← Retour à la liste</a>
      </div>
    </div>
  </div>
</div>
</form>

@if ($id && auth()->user()->aRole('admin'))
<div style="max-width:320px;margin:12px 0 0 auto">
  <form method="post" action="{{ route('etudiants.supprimer') }}" data-confirm="Supprimer définitivement cet étudiant ?">
    @csrf
    <input type="hidden" name="id" value="{{ $id }}">
    <button type="submit" class="btn btn-danger w-100">🗑️ Supprimer</button>
  </form>
</div>
@endif
@endsection
