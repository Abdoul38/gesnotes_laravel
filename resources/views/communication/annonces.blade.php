{{-- Annonces / tableau d'affichage — portage de pages/communication/annonces.php --}}
@extends('layouts.app', ['titre' => "Annonces & Tableau d'affichage", 'fil' => 'Communication › Annonces'])

@section('contenu')
@php
    use App\Http\Controllers\Communication\AnnoncesController as A;
    $aujourdhui = date('Y-m-d');
@endphp
<div style="display:grid;grid-template-columns:repeat(4,1fr);gap:16px;margin-bottom:24px">
  @foreach ([['Total', '📋', $stats['total'], '#5b21b6', '#f5f3ff'], ['Publiées', '✅', $stats['publiees'], '#166534', '#dcfce7'],
             ['Actives', '🟢', $stats['actives'], '#0369a1', '#e0f2fe'], ['Épinglées', '📌', $stats['epinglees'], '#b45309', '#fef9c3']] as [$lbl, $ic, $val, $txt, $bg])
  <div style="background:{{ $bg }};border-radius:12px;padding:18px;text-align:center">
    <div style="font-size:28px;margin-bottom:4px">{{ $ic }}</div>
    <div style="font-size:28px;font-weight:800;color:{{ $txt }}">{{ $val }}</div>
    <div style="font-size:12px;color:{{ $txt }};opacity:.8">{{ $lbl }}</div>
  </div>
  @endforeach
</div>

<div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:16px">
  <div style="font-size:14px;font-weight:700;color:var(--dark)">Toutes les annonces</div>
  @if ($peutEcrire)<button type="button" class="btn btn-primary" data-annonce-nouvelle="1">➕ Nouvelle annonce</button>@endif
</div>

<div style="display:flex;flex-direction:column;gap:12px">
@forelse ($annonces as $an)
@php
    [$fond, $couleur, $libelle] = A::TYPES[$an->type] ?? A::TYPES['info'];
    $expiree = $an->date_fin && $an->date_fin < $aujourdhui;
    $cible = match ($an->cible) {
        'tous' => 'Tous', 'etudiants' => 'Étudiants', 'enseignants' => 'Enseignants',
        'filiere' => 'Filière : '.($an->filiere ?? ''), 'niveau' => 'Niveau : '.($an->niveau ?? ''), default => $an->cible,
    };
@endphp
<div style="background:#fff;border:1.5px solid {{ $an->epinglee ? '#f59e0b' : 'var(--border)' }};border-radius:12px;padding:18px;position:relative;{{ $expiree ? 'opacity:.65' : '' }}">
  @if ($an->epinglee)<div style="position:absolute;top:-10px;left:16px;background:#f59e0b;color:#fff;padding:2px 10px;border-radius:10px;font-size:11px;font-weight:700">📌 Épinglée</div>@endif
  <div style="display:flex;justify-content:space-between;align-items:flex-start;gap:12px">
    <div style="flex:1">
      <div style="display:flex;align-items:center;gap:10px;margin-bottom:8px;flex-wrap:wrap">
        <span style="background:{{ $fond }};color:{{ $couleur }};padding:3px 10px;border-radius:12px;font-size:11px;font-weight:700">{{ $libelle }}</span>
        <span style="font-size:16px;font-weight:800;color:var(--dark)">{{ $an->titre }}</span>
        @unless ($an->publiee)<span style="background:#f3f4f6;color:#6b7280;padding:2px 8px;border-radius:8px;font-size:11px">Brouillon</span>@endunless
        @if ($expiree)<span style="background:#fee2e2;color:#991b1b;padding:2px 8px;border-radius:8px;font-size:11px">Expirée</span>@endif
      </div>
      <div style="font-size:13px;color:var(--text-muted);line-height:1.6;margin-bottom:10px;white-space:pre-line">{{ mb_strimwidth($an->contenu, 0, 200, '…') }}</div>
      <div style="display:flex;gap:16px;font-size:11px;color:var(--text-muted);flex-wrap:wrap">
        <span>👤 {{ $an->auteur ?? 'Système' }}</span>
        <span>📅 {{ date('d/m/Y', strtotime($an->created_at)) }}</span>
        @if ($an->date_fin)<span>⏰ Expire le {{ date('d/m/Y', strtotime($an->date_fin)) }}</span>@endif
        <span>🎯 {{ $cible }}</span>
        <span>👁️ {{ $an->vues }} vue(s)</span>
      </div>
    </div>
    @if ($modifiable($an))
    <div style="display:flex;flex-direction:column;gap:6px;flex-shrink:0">
      <form method="post" action="{{ route('annonces.publication') }}">
        @csrf
        <input type="hidden" name="id" value="{{ $an->id }}">
        <button type="submit" class="btn btn-sm {{ $an->publiee ? 'btn-secondary' : 'btn-primary' }}" style="width:100%">{{ $an->publiee ? '⏸ Dépublier' : '▶ Publier' }}</button>
      </form>
      <button type="button" class="btn btn-sm btn-outline" data-annonce="{{ json_encode(['id' => $an->id, 'titre' => $an->titre, 'contenu' => $an->contenu, 'type' => $an->type,
        'cible' => $an->cible, 'filiere_id' => $an->filiere_id, 'niveau_id' => $an->niveau_id, 'date_debut' => $an->date_debut, 'date_fin' => $an->date_fin,
        'publiee' => (bool) $an->publiee, 'epinglee' => (bool) $an->epinglee]) }}">✏️ Modifier</button>
      <form method="post" action="{{ route('annonces.supprimer') }}" data-confirm="Supprimer cette annonce ?">
        @csrf
        <input type="hidden" name="id" value="{{ $an->id }}">
        <button type="submit" class="btn btn-sm" style="background:#fee2e2;color:#991b1b;border:none;width:100%">🗑 Supprimer</button>
      </form>
    </div>
    @endif
  </div>
</div>
@empty
<div style="text-align:center;padding:60px;color:var(--text-muted)">
  <div style="font-size:48px;margin-bottom:12px">📢</div>
  <div style="font-weight:600">Aucune annonce</div>
  <div style="font-size:13px">Créez votre première annonce pour les étudiants</div>
</div>
@endforelse
</div>

@if ($peutEcrire)
<div id="modal-annonce" class="modal-overlay" style="display:none">
  <div class="modal" style="max-width:620px">
    <div class="modal-header">
      <span class="modal-title" id="modal-titre-annonce">➕ Nouvelle annonce</span>
      <button type="button" data-onclick="closeModal" data-arg="modal-annonce" class="btn btn-icon" aria-label="Fermer">✕</button>
    </div>
    <form method="post" action="{{ route('annonces.enregistrer') }}" id="form-annonce" data-aujourdhui="{{ $aujourdhui }}">
      <div class="modal-body">
        @csrf
        <input type="hidden" name="action" id="f-action" value="creer">
        <input type="hidden" name="id" id="f-id" value="">
        <div style="display:grid;grid-template-columns:1fr 1fr;gap:12px">
          <div class="form-group" style="grid-column:1/-1"><label class="form-label" for="f-titre">Titre <span class="req">*</span></label>
            <input type="text" name="titre" id="f-titre" class="form-control" required maxlength="200" placeholder="Titre de l'annonce"></div>
          <div class="form-group"><label class="form-label" for="f-type">Type</label>
            <select name="type" id="f-type" class="form-control">
              <option value="info">ℹ️ Information</option><option value="important">⚠️ Important</option>
              <option value="urgent">🚨 Urgent</option><option value="evenement">🎉 Événement</option>
            </select></div>
          <div class="form-group"><label class="form-label" for="f-cible">Destinataires</label>
            <select name="cible" id="f-cible" class="form-control">
              @foreach (A::CIBLES as $k => $v)<option value="{{ $k }}">{{ $v }}</option>@endforeach
            </select></div>
          <div class="form-group" id="zone-filiere" style="display:none"><label class="form-label" for="f-filiere">Filière</label>
            <select name="filiere_id" id="f-filiere" class="form-control">
              <option value="">— Choisir —</option>
              @foreach ($filieres as $f)<option value="{{ $f->id }}">{{ $f->libelle }}</option>@endforeach
            </select></div>
          <div class="form-group" id="zone-niveau" style="display:none"><label class="form-label" for="f-niveau">Niveau</label>
            <select name="niveau_id" id="f-niveau" class="form-control">
              <option value="">— Choisir —</option>
              @foreach ($niveaux as $n)<option value="{{ $n->id }}">{{ $n->code }} — {{ $n->libelle }}</option>@endforeach
            </select></div>
          <div class="form-group"><label class="form-label" for="f-debut">Date début</label>
            <input type="date" name="date_debut" id="f-debut" class="form-control" value="{{ $aujourdhui }}"></div>
          <div class="form-group"><label class="form-label" for="f-fin">Date fin (optionnel)</label>
            <input type="date" name="date_fin" id="f-fin" class="form-control"></div>
          <div class="form-group" style="grid-column:1/-1"><label class="form-label" for="f-contenu">Contenu <span class="req">*</span></label>
            <textarea name="contenu" id="f-contenu" class="form-control" rows="5" required maxlength="10000" placeholder="Rédigez votre annonce ici..."></textarea></div>
          <div class="form-group" style="display:flex;gap:20px;align-items:center;grid-column:1/-1">
            <label style="display:flex;align-items:center;gap:8px;cursor:pointer"><input type="checkbox" name="publiee" value="1" id="f-publiee" style="width:16px;height:16px"><span style="font-size:13px;font-weight:600">✅ Publier immédiatement</span></label>
            <label style="display:flex;align-items:center;gap:8px;cursor:pointer"><input type="checkbox" name="epinglee" value="1" id="f-epinglee" style="width:16px;height:16px"><span style="font-size:13px;font-weight:600">📌 Épingler en haut</span></label>
          </div>
        </div>
      </div>
      <div class="modal-footer">
        <button type="button" data-onclick="closeModal" data-arg="modal-annonce" class="btn btn-secondary">Annuler</button>
        <button type="submit" class="btn btn-primary">💾 Enregistrer</button>
      </div>
    </form>
  </div>
</div>
@endif
@endsection

@push('scripts')
<script src="{{ \App\Support\Ressource::url('assets/js/annonces.js') }}"></script>
@endpush
