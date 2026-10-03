{{-- Mes dossiers — portage de candidature/pages/mes_dossiers.php (détail rendu par le serveur) --}}
@extends('layouts.candidature', ['titre' => 'Mes dossiers', 'actif' => 'dossiers'])

@section('contenu')
@php $statuts = \App\Services\Candidatures::STATUTS; @endphp
<div class="pheader">
  <div class="pheader-top">
    <div>
      <div class="pbreadcrumb"><a href="{{ route('candidature.tableau') }}">Accueil</a> › Mes dossiers</div>
      <h1>📋 Mes dossiers</h1>
    </div>
    <form method="post" action="{{ route('candidature.nouveau') }}">@csrf<button type="submit" class="pbtn pbtn-primary">📤 Nouveau dossier</button></form>
  </div>
</div>

@if ($detail)
@php [$ic, $lib] = $statuts[$detail->statut] ?? ['?', $detail->statut]; @endphp
<div class="pcard">
  <div class="pcard-header">
    <h2>Dossier #{{ $detail->numero_dossier }} — {{ $ic }} {{ $lib }}</h2>
    <a href="{{ route('candidature.dossiers') }}" class="pbtn pbtn-secondary pbtn-sm">✕ Fermer</a>
  </div>
  <div class="pcard-body">
    @if ($detail->doc_photo && strtolower(pathinfo($detail->doc_photo, PATHINFO_EXTENSION)) !== 'pdf')
    <div style="text-align:center;margin-bottom:20px">
      <a href="{{ route('candidature.piece', ['id' => $detail->id, 'type' => 'photo']) }}" target="_blank" rel="noopener">
        <img src="{{ route('candidature.piece', ['id' => $detail->id, 'type' => 'photo']) }}" alt="Photo d'identité" style="max-width:160px;max-height:180px;border-radius:8px;box-shadow:0 4px 12px rgba(0,0,0,.15)">
      </a>
    </div>
    @endif
    <h3 style="font-size:13px;font-weight:600;margin-bottom:12px">👤 Informations personnelles</h3>
    <div style="display:grid;grid-template-columns:repeat(auto-fit,minmax(180px,1fr));gap:10px;margin-bottom:20px">
      @foreach (['Nom complet' => $detail->prenom.' '.$detail->nom, 'Date de naissance' => $detail->date_naissance ? date('d/m/Y', strtotime($detail->date_naissance)) : '—',
                 'Lieu de naissance' => $detail->lieu_naissance ?: '—', 'Email' => $detail->email ?: '—', 'Téléphone' => $detail->telephone ?: '—',
                 'BAC' => trim(($detail->bac_type ?? '').' — '.($detail->bac_annee ?? ''), ' —') ?: '—'] as $l => $v)
      <div style="border:1px solid #e5e7eb;border-radius:6px;padding:12px;text-align:center"><div style="font-size:11px;color:#6b7280;margin-bottom:4px">{{ $l }}</div><div style="font-weight:600;font-size:13px">{{ $v }}</div></div>
      @endforeach
    </div>
    <h3 style="font-size:13px;font-weight:600;margin-bottom:10px">📚 Choix de filières</h3>
    @foreach ([1 => ['#dcfce7', '#16a34a', '1er choix'], 2 => ['#fef9c3', '#d97706', '2ème choix'], 3 => ['#fee2e2', '#dc2626', '3ème choix']] as $n => [$bg, $fg, $lbl])
    @php $choix = $detail->{'ch'.$n.'_lib'}; @endphp
    <div style="{{ $choix ? "background:$bg;border-left:4px solid $fg" : 'border:1px dashed #e5e7eb;opacity:.5' }};border-radius:6px;padding:12px 16px;margin-bottom:8px;display:flex;align-items:center;gap:12px">
      <span style="background:{{ $choix ? $fg : '#9ca3af' }};color:#fff;font-size:10px;font-weight:800;text-transform:uppercase;padding:3px 8px;border-radius:4px">{{ $lbl }}</span>
      <span style="font-weight:600;font-size:13px">{{ $choix ?: 'Non renseigné' }}</span>
    </div>
    @endforeach
    <h3 style="font-size:13px;font-weight:600;margin:16px 0 10px">📎 Pièces jointes</h3>
    <div style="display:flex;gap:8px;flex-wrap:wrap">
      @foreach (\App\Services\Candidatures::PIECES as $type => [$col, $icone, $libelle])
      @if ($detail->$col)<a href="{{ route('candidature.piece', ['id' => $detail->id, 'type' => $type]) }}" target="_blank" rel="noopener" class="pbtn pbtn-secondary pbtn-sm">{{ $icone }} {{ $libelle }}</a>@endif
      @endforeach
    </div>
  </div>
  <div class="pcard-header" style="border-top:1px solid #e5e7eb;justify-content:flex-end;gap:8px">
    @if ($detail->statut !== 'brouillon')<a href="{{ route('candidature.quitus', ['id' => $detail->id]) }}" target="_blank" rel="noopener" class="pbtn pbtn-primary">📄 Télécharger le quitus</a>@endif
  </div>
</div>
@endif

@if ($dossiers->isEmpty())
<div class="pcard"><div class="pcard-body" style="text-align:center;padding:40px">
  <div style="font-size:48px;margin-bottom:16px">📂</div>
  <p style="color:var(--gris-m);margin-bottom:20px">Vous n'avez encore déposé aucun dossier.</p>
  <form method="post" action="{{ route('candidature.nouveau') }}">@csrf<button type="submit" class="pbtn pbtn-primary pbtn-lg">📤 Déposer mon dossier</button></form>
</div></div>
@else
<div class="pcard"><div class="pcard-body-flush"><div class="ptable-wrap">
  <table class="ptable">
    <thead><tr><th>Date de dépôt</th><th>Nom et Prénom</th><th>Numéro dossier</th><th>N° dépôt</th><th>Statut</th><th>Actions</th></tr></thead>
    <tbody>
    @foreach ($dossiers as $d)
    @php [$ic, $lib, $classe] = $statuts[$d->statut] ?? ['?', $d->statut, 'sbadge-brouillon']; @endphp
    <tr>
      <td>{{ date('d/m/Y', strtotime($d->created_at)) }}</td>
      <td><strong>{{ $d->prenom }} {{ $d->nom }}</strong></td>
      <td><span class="num-badge">{{ $d->numero_dossier }}</span></td>
      <td style="font-size:12px;font-weight:600;color:#16a34a;font-family:monospace">{{ $d->numero_depot ?: '—' }}</td>
      <td><span class="sbadge {{ $classe }}">{{ $ic }} {{ $lib }}</span></td>
      <td>
        <div style="display:flex;gap:8px;align-items:center;flex-wrap:wrap">
          @if (in_array($d->statut, \App\Services\Candidatures::MODIFIABLES, true))
          <a href="{{ route('candidature.depot', ['id' => $d->id, 'etape' => max(1, (int) $d->etape_actuelle)]) }}" class="pbtn pbtn-primary pbtn-sm">✏️ Continuer</a>
          @endif
          @if ($d->statut !== 'brouillon')
          <a href="{{ route('candidature.dossiers', ['voir' => $d->id]) }}" class="pbtn pbtn-secondary pbtn-sm">👁️ Voir</a>
          <a href="{{ route('candidature.quitus', ['id' => $d->id]) }}" target="_blank" rel="noopener" class="pbtn pbtn-primary pbtn-sm">📄 Quitus</a>
          @endif
        </div>
      </td>
    </tr>
    @endforeach
    </tbody>
  </table>
</div></div></div>
@endif
@endsection
