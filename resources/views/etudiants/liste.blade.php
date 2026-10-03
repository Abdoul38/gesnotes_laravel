{{-- Liste des étudiants — portage de pages/etudiants/etudiants.php --}}
@extends('layouts.app', ['titre' => 'Étudiants', 'fil' => 'Gestion académique'])
@inject('navigation', 'App\Navigation\Menu')
@use('App\Support\Format')

@section('contenu')
@if ($faculte)
  @php $cf = preg_match('/^#[0-9A-Fa-f]{6}$/', (string) $faculte->couleur) ? $faculte->couleur : '#1A3A5C'; @endphp
  <div style="display:flex;align-items:center;gap:10px;margin-bottom:12px;padding:10px 16px;border-radius:8px;background:{{ $cf }}15;border:1px solid {{ $cf }}30">
    <span style="font-size:18px">🏛️</span>
    <div>
      <span style="font-weight:700;color:{{ $cf }}">{{ $faculte->libelle }}</span>
      <span style="font-size:11px;color:var(--text-muted);margin-left:8px">{{ $q === '' ? 'Vous ne voyez que les étudiants de cette faculté' : 'Recherche en cours sur toutes les facultés' }}</span>
    </div>
  </div>
@endif

<div class="toolbar">
  <form method="get" action="{{ route('etudiants') }}" class="flex gap-8" style="flex:1;flex-wrap:wrap" id="form-filtres">
    <div class="search-box" style="flex:2;min-width:200px">
      <span class="icon" aria-hidden="true">🔍</span>
      <input type="text" id="search-q" name="q" value="{{ $q }}" placeholder="Rechercher par nom, prénom, numéro, dossier..." aria-label="Rechercher un étudiant">
    </div>
    <button type="submit" class="btn btn-primary btn-sm">🔍 Rechercher</button>
    <select name="promo" class="form-control form-control-sm" style="min-width:220px" aria-label="Promotion">
      <option value="">Toutes les promotions{{ $faculte ? ' de la faculté' : '' }}</option>
      @foreach ($promos as $p)
        <option value="{{ $p->id }}" @selected($promoId == $p->id)>{{ $p->niveau }} {{ $p->filiere }} — {{ $p->annee }}</option>
      @endforeach
    </select>
    <button type="submit" class="btn btn-secondary btn-sm">🔽 Filtrer</button>
    <a href="{{ route('etudiants') }}" class="btn btn-secondary btn-sm">↺ Réinitialiser</a>
  </form>
  @if ($peutGerer)
    <a href="{{ $navigation->lien('etudiant_importer', array_filter(['promo' => $promoId ?: null])) }}" class="btn btn-secondary btn-sm">📥 Importer</a>
    <a href="{{ route('etudiant_form') }}" class="btn btn-secondary btn-sm">➕ Ajouter</a>
  @endif
</div>

<div class="text-muted text-sm mb-8">
  {{ $pag['total'] }} étudiant(s) trouvé(s)
  @if ($q !== '') · Recherche : <strong>"{{ $q }}"</strong>@endif
  @if ($promoId && ($pr = $promos->firstWhere('id', $promoId))) · Promotion : <strong>{{ $pr->filiere }} {{ $pr->niveau }} — {{ $pr->annee }}</strong>@endif
  @if ($q !== '' || $promoId) · <a href="{{ route('etudiants') }}" style="color:var(--red-500)">✕ Effacer les filtres</a>@endif
  @if ($nbSansMatricule > 0)
    &nbsp;·&nbsp;<span style="color:var(--amber-600);font-weight:600">⚠️ {{ $nbSansMatricule }} sans inscription (pas encore de matricule)</span>
  @endif
</div>

<div class="card">
  <div class="table-wrap">
    <table class="table">
      <thead><tr><th style="width:50px">Photo</th><th>N° Étudiant</th><th>Nom & Prénoms</th><th>Sexe</th><th>Date naiss.</th><th>Lieu naiss.</th><th>Promotions</th><th>Actions</th></tr></thead>
      <tbody>
      @forelse ($etudiants as $et)
        @php
          $nonInscrit = ! $et->est_inscrit;
          $dossier = $navigation->lien('dossier_etudiant', ['id' => $et->id]);
        @endphp
        <tr style="{{ $nonInscrit ? 'background:var(--amber-50)' : '' }}">
          <td style="text-align:center">
            <a href="{{ $dossier }}" aria-label="Dossier de {{ $et->nom }} {{ $et->prenoms }}">
              <div style="width:40px;height:40px;border-radius:50%;overflow:hidden;margin:0 auto;background:var(--blue-100);display:flex;align-items:center;justify-content:center;border:2px solid {{ $et->photo_fichier ? 'var(--blue-300)' : 'var(--border)' }}">
                @if ($et->photo_fichier)
                  <img src="{{ route('fichiers.photo', $et->photo_fichier) }}" alt="" style="width:100%;height:100%;object-fit:cover">
                @else
                  <span style="font-size:18px;color:var(--blue-300)" aria-hidden="true">👤</span>
                @endif
              </div>
            </a>
          </td>
          <td>
            <a href="{{ $dossier }}" style="font-weight:700;color:{{ $nonInscrit ? 'var(--amber-600)' : 'var(--blue-600)' }};text-decoration:none">
              @if (in_array($et->numero, [null, '', '0'], true))<span title="Pas encore inscrit">—</span>@else{{ $et->numero }}@endif
            </a>
          </td>
          <td><a href="{{ $dossier }}" style="text-decoration:none;color:inherit"><strong>{{ $et->nom }}</strong><br><span class="text-muted text-xs">{{ $et->prenoms }}</span></a></td>
          <td>
            @if ($et->sexe)<span class="badge {{ $et->sexe === 'M' ? 'badge-blue' : 'badge-red' }}">{{ $et->sexe }}</span>@else—@endif
          </td>
          <td class="text-sm text-muted">{{ Format::dateFR($et->date_naissance) }}</td>
          <td class="text-sm text-muted">{{ $et->lieu_naissance ?? '—' }}</td>
          <td style="font-size:11px">
            @if ($nonInscrit)
              <span style="background:var(--amber-100);color:var(--amber-700);padding:2px 8px;border-radius:10px;font-size:10px;font-weight:600">⏳ Non inscrit</span>
            @else
              <span style="color:var(--text-muted)">{{ $et->promotions_str ?? '—' }}</span>
            @endif
          </td>
          <td>
            <div class="flex gap-8">
              <a href="{{ $dossier }}" class="btn btn-sm btn-primary" title="Dossier" aria-label="Dossier">📁</a>
              <a href="{{ $navigation->lien('certificat_scolarite', ['et_id' => $et->id]) }}" target="_blank" rel="noopener" class="btn btn-sm btn-secondary" title="Certificat" aria-label="Certificat de scolarité">📜</a>
              <a href="{{ $navigation->lien('recu_inscription', ['et_id' => $et->id]) }}" target="_blank" rel="noopener" class="btn btn-sm btn-secondary" title="Reçu" aria-label="Reçu d'inscription">🧾</a>
              @if ($peutModifier)
                <a href="{{ route('etudiant_form', ['id' => $et->id]) }}" class="btn btn-sm btn-secondary" title="Modifier" aria-label="Modifier">✏️</a>
              @endif
              @if (auth()->user()->aRole('admin'))
                <form method="post" action="{{ route('etudiants.supprimer') }}" style="display:inline" data-confirm="Supprimer cet étudiant ?">
                  @csrf
                  <input type="hidden" name="id" value="{{ $et->id }}">
                  <button type="submit" class="btn btn-sm btn-danger" title="Supprimer" aria-label="Supprimer">🗑️</button>
                </form>
              @endif
            </div>
          </td>
        </tr>
      @empty
        <tr><td colspan="8"><div class="empty-state"><div class="icon">👥</div>Aucun étudiant trouvé.</div></td></tr>
      @endforelse
      </tbody>
    </table>
  </div>
  @if ($pag['totalPages'] > 1)
  <div class="card-footer">
    <nav class="pagination" aria-label="Pages">
      @for ($i = 1; $i <= $pag['totalPages']; $i++)
        <a href="{{ request()->fullUrlWithQuery(['p' => $i]) }}" class="page-btn {{ $i == $pag['page'] ? 'active' : '' }}" @if ($i == $pag['page']) aria-current="page" @endif>{{ $i }}</a>
      @endfor
    </nav>
  </div>
  @endif
</div>
@endsection
