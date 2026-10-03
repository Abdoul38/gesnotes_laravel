{{-- Dépôt du dossier en 4 étapes — portage de candidature/pages/depot.php --}}
@extends('layouts.candidature', ['titre' => 'Dépôt de dossier — Étape '.$etape, 'largeur' => '820px'])

@use('App\Services\Candidatures')
@php
  $d = $dossier;
  $val = fn (string $champ, $defaut = '') => old($champ, $d->$champ ?? $defaut);
  $titres = [1 => ['👤', 'Informations personnelles'], 2 => ['📚', 'Choix de filières'], 3 => ['📎', 'Documents justificatifs'], 4 => ['✅', 'Résumé & Validation']];
  $action = route('candidature.depot.enregistrer', ['id' => $d->id, 'etape' => $etape]);
  $lienEtape = fn (int $n) => route('candidature.depot', ['id' => $d->id, 'etape' => $n]);
@endphp

@section('avant')
<div style="background:#fff;border-bottom:1px solid #e5e7eb;padding:16px 0">
  <div style="max-width:1060px;margin:0 auto;padding:0 24px">
    <div class="progress-steps">
      @foreach ([1 => 'Informations', 2 => 'Filières', 3 => 'Documents', 4 => 'Résumé'] as $n => $lbl)
      <div class="pstep {{ $n < $etape ? 'done' : ($n === $etape ? 'active' : '') }}"><div class="pstep-dot">{{ $n < $etape ? '✓' : $n }}</div><div class="pstep-label">{{ $lbl }}</div></div>
      @endforeach
    </div>
  </div>
</div>
@endsection

@section('contenu')
<div class="pheader">
  <div class="pbreadcrumb"><a href="{{ route('candidature.tableau') }}">Accueil</a> › <a href="{{ route('candidature.dossiers') }}">Mes dossiers</a> › Étape {{ $etape }}</div>
  <h1>{{ $titres[$etape][0] }} {{ $titres[$etape][1] }}</h1>
  <p>N° Dossier : <strong style="color:#1A3A5C;font-family:monospace">{{ $d->numero_dossier }}</strong>
    @if ($d->statut === 'incomplet') — <span style="color:#92400e;font-weight:600">⚠️ Dossier incomplet : complétez-le puis soumettez-le à nouveau.</span>@endif</p>
</div>

@if ($etape === 1)
<form method="post" action="{{ $action }}">
  @csrf
  <div class="pcard">
    <div class="pcard-header"><h2>🪪 Identité civile</h2></div>
    <div class="pcard-body"><div class="pform-grid-2">
      <div class="pform-group"><label for="nom">Nom <span class="req">*</span></label><input type="text" name="nom" id="nom" value="{{ $val('nom') }}" required maxlength="100" style="text-transform:uppercase" placeholder="NOM DE FAMILLE"></div>
      <div class="pform-group"><label for="prenom">Prénom <span class="req">*</span></label><input type="text" name="prenom" id="prenom" value="{{ $val('prenom') }}" required maxlength="150" placeholder="Prénom(s)"></div>
      <div class="pform-group"><label for="date_naissance">Date de naissance <span class="req">*</span></label><input type="date" name="date_naissance" id="date_naissance" value="{{ $val('date_naissance') }}" required></div>
      <div class="pform-group"><label for="lieu_naissance">Lieu de naissance <span class="req">*</span></label><input type="text" name="lieu_naissance" id="lieu_naissance" value="{{ $val('lieu_naissance') }}" required maxlength="200" placeholder="Ville, région"></div>
      <div class="pform-group"><label for="nationalite">Nationalité <span class="req">*</span></label><input type="text" name="nationalite" id="nationalite" value="{{ $val('nationalite', 'Nigérienne') }}" required maxlength="100"></div>
      <div class="pform-group"><label for="sexe">Genre <span class="req">*</span></label>
        <select name="sexe" id="sexe" required><option value="">— Sélectionner —</option><option value="M" @selected($val('sexe') === 'M')>Masculin</option><option value="F" @selected($val('sexe') === 'F')>Féminin</option></select></div>
      <div class="pform-group"><label for="telephone">Téléphone <span class="req">*</span></label><input type="tel" name="telephone" id="telephone" value="{{ $val('telephone') }}" required maxlength="30" placeholder="+227 00 00 00 00"></div>
      <div class="pform-group"><label for="email">Email <span class="req">*</span></label><input type="email" name="email" id="email" value="{{ $val('email') }}" required maxlength="150"></div>
    </div></div>
  </div>
  <div class="pcard">
    <div class="pcard-header"><h2>🏠 Adresse & Famille</h2></div>
    <div class="pcard-body">
      <div class="pform-group"><label for="adresse">Adresse complète <span class="req">*</span></label><textarea name="adresse" id="adresse" rows="3" required maxlength="1000" placeholder="Quartier, ville, région...">{{ $val('adresse') }}</textarea></div>
      <div class="pform-grid-2">
        <div class="pform-group"><label for="nom_pere">Nom du père</label><input type="text" name="nom_pere" id="nom_pere" value="{{ $val('nom_pere') }}" maxlength="200" placeholder="Facultatif"></div>
        <div class="pform-group"><label for="nom_mere">Nom de la mère</label><input type="text" name="nom_mere" id="nom_mere" value="{{ $val('nom_mere') }}" maxlength="200" placeholder="Facultatif"></div>
        <div class="pform-group"><label for="tel_urgence">Téléphone d'urgence</label><input type="tel" name="tel_urgence" id="tel_urgence" value="{{ $val('tel_urgence') }}" maxlength="30" placeholder="+227..."></div>
      </div>
    </div>
  </div>
  <div class="pcard">
    <div class="pcard-header"><h2>🎓 Baccalauréat</h2></div>
    <div class="pcard-body"><div class="pform-grid-2">
      <div class="pform-group"><label for="bac_type">Type de Bac <span class="req">*</span></label>
        <select name="bac_type" id="bac_type" required><option value="">— Sélectionner —</option>@foreach (Candidatures::BACS as $code => $lbl)<option value="{{ $code }}" @selected($val('bac_type') === $code)>{{ $lbl }}</option>@endforeach</select></div>
      <div class="pform-group"><label for="bac_lieu">Lieu d'obtention <span class="req">*</span></label>
        <select name="bac_lieu" id="bac_lieu" required><option value="">— Sélectionner —</option>@foreach (Candidatures::LIEUX as $l)<option value="{{ $l }}" @selected($val('bac_lieu') === $l)>{{ $l }}</option>@endforeach</select></div>
      <div class="pform-group"><label for="bac_annee">Année d'obtention <span class="req">*</span></label>
        <select name="bac_annee" id="bac_annee" required><option value="">— Sélectionner —</option>
          @for ($y = (int) date('Y'); $y >= 2000; $y--)@php $ys = ($y - 1).'-'.$y; @endphp<option value="{{ $ys }}" @selected($val('bac_annee') === $ys)>{{ $ys }}</option>@endfor
        </select></div>
      <div class="pform-group"><label for="bac_mention">Mention <span class="req">*</span></label>
        <select name="bac_mention" id="bac_mention" required><option value="">— Sélectionner —</option>@foreach (Candidatures::MENTIONS as $m)<option value="{{ $m }}" @selected($val('bac_mention') === $m)>{{ $m }}</option>@endforeach</select></div>
      <div class="pform-group"><label for="bac_etablissement">Établissement d'origine</label><input type="text" name="bac_etablissement" id="bac_etablissement" value="{{ $val('bac_etablissement') }}" maxlength="200" placeholder="Lycée..."></div>
    </div></div>
  </div>
  <div style="display:flex;justify-content:space-between;gap:12px;margin-top:8px">
    <a href="{{ route('candidature.tableau') }}" class="pbtn pbtn-secondary pbtn-lg">← Retour</a>
    <button type="submit" class="pbtn pbtn-primary pbtn-lg">Suivant : Choix de filières →</button>
  </div>
</form>

@elseif ($etape === 2)
<form method="post" action="{{ $action }}">
  @csrf
  <div class="pcard">
    <div class="pcard-header"><h2>📚 Vos choix de formation</h2></div>
    <div class="pcard-body">
      <div class="palert palert-info" style="margin-bottom:20px">💡 Classez vos choix par ordre de préférence. Le premier choix sera traité en priorité lors des affectations.</div>
      @foreach ([1 => ['#1A3A5C', '#e8f0f8', 'Premier choix', 'Priorité 1 — Choix principal', 'Obligatoire'], 2 => ['#c8860a', '#fef3cd', 'Deuxième choix', 'Priorité 2 — Choix de secours', 'Recommandé'],
                 3 => ['#dc2626', '#fee2e2', 'Troisième choix', 'Priorité 3 — Dernier recours', 'Facultatif']] as $n => [$fg, $bg, $lbl, $aide, $req])
      @php $courant = (int) old('choix'.$n, $d->{'choix'.$n.'_id'}); @endphp
      <div class="choix-card" style="border-color:{{ $fg }};background:{{ $bg }}20">
        <label class="choix-num" for="choix{{ $n }}" style="color:{{ $fg }}">{{ $lbl }} <span style="font-weight:400;font-size:10px">({{ $aide }})</span></label>
        <select name="choix{{ $n }}" id="choix{{ $n }}" @required($n === 1)>
          <option value="">— Sélectionner une filière —</option>
          @foreach ($filieres->groupBy('faculte') as $faculte => $liste)
          <optgroup label="{{ $faculte ?: '—' }}">@foreach ($liste as $f)<option value="{{ $f->id }}" @selected($f->id === $courant)>{{ $f->libelle }}</option>@endforeach</optgroup>
          @endforeach
        </select>
        <div class="pform-hint" style="margin-top:6px;color:{{ $fg }}">{{ $req }}</div>
      </div>
      @endforeach
    </div>
  </div>
  <div style="display:flex;justify-content:space-between;gap:12px;margin-top:8px">
    <a href="{{ $lienEtape(1) }}" class="pbtn pbtn-secondary pbtn-lg">← Précédent</a>
    <button type="submit" class="pbtn pbtn-primary pbtn-lg">Suivant : Documents →</button>
  </div>
</form>

@elseif ($etape === 3)
<form method="post" action="{{ $action }}" enctype="multipart/form-data">
  @csrf
  <div class="palert palert-warning" style="margin-bottom:20px"><strong>📋 Consignes :</strong> Taille max 3 Mo par fichier — Formats acceptés : PDF, JPG, PNG (photo : JPG ou PNG)</div>
  @foreach ([true => ['🔑 Documents obligatoires', 'sbadge-refuse', 'Requis'], false => ['📎 Documents facultatifs', 'sbadge-brouillon', 'Optionnel']] as $obligatoires => [$titreBloc, $classeBadge, $texteBadge])
  <div class="pcard">
    <div class="pcard-header"><h2>{{ $titreBloc }}</h2><span class="sbadge {{ $classeBadge }}">{{ $texteBadge }}</span></div>
    <div class="pcard-body">
      @foreach (Candidatures::PIECES as $champ => [$col, $ic, $titrePiece, $requis, $aide])
      @continue($requis !== (bool) $obligatoires)
      <div style="margin-bottom:20px">
        <div style="display:flex;align-items:center;gap:8px;margin-bottom:8px">
          <span style="font-size:20px">{{ $ic }}</span><strong style="font-size:14px">{{ $titrePiece }}</strong>
          <span class="sbadge {{ $requis ? 'sbadge-refuse' : 'sbadge-brouillon' }}" style="font-size:10px">{{ $requis ? 'Obligatoire' : 'Facultatif' }}</span>
        </div>
        @if ($d->$col)
        <div class="doc-preview-card"><span>✅</span><span style="font-size:12px;color:var(--vert);font-weight:600">Document déjà déposé</span>
          <a href="{{ route('candidature.piece', ['id' => $d->id, 'type' => $champ]) }}" target="_blank" rel="noopener" style="font-size:11px;margin-left:auto">Voir</a></div>
        <div class="pform-hint">Sélectionnez un nouveau fichier pour remplacer</div>
        @endif
        <div class="upload-zone" data-zone-depot>
          <input type="file" name="{{ $champ }}" accept="{{ $champ === 'photo' ? '.jpg,.jpeg,.png' : '.jpg,.jpeg,.png,.pdf' }}" aria-label="{{ $titrePiece }}">
          <div class="upload-icon">☁️</div>
          <div class="upload-text">Cliquez ou <strong>glissez votre fichier</strong> ici<small>{{ $aide }}</small></div>
        </div>
      </div>
      @endforeach
    </div>
  </div>
  @endforeach
  <div style="display:flex;justify-content:space-between;gap:12px;margin-top:8px">
    <a href="{{ $lienEtape(2) }}" class="pbtn pbtn-secondary pbtn-lg">← Précédent</a>
    <button type="submit" class="pbtn pbtn-primary pbtn-lg">Suivant : Résumé →</button>
  </div>
</form>

@else
<div class="palert palert-info" style="margin-bottom:20px">🔍 Vérifiez attentivement toutes les informations avant de soumettre. Une fois validé, votre quitus sera généré.</div>
@if ($manquantes)<div class="palert palert-error">⚠️ Pièces obligatoires manquantes : {{ implode(', ', $manquantes) }}.</div>@endif
<div class="pcard">
  <div class="pcard-header"><h2>👤 Informations personnelles</h2><a href="{{ $lienEtape(1) }}" class="pbtn pbtn-secondary pbtn-sm">✏️ Modifier</a></div>
  <div class="pcard-body"><div class="pform-grid-2">
    @foreach (['Nom complet' => trim($d->nom.' '.$d->prenom), 'Date de naissance' => $d->date_naissance ? date('d/m/Y', strtotime($d->date_naissance)).' à '.$d->lieu_naissance : '—',
               'Nationalité / Genre' => ($d->nationalite ?: '—').' — '.(['M' => 'Masculin', 'F' => 'Féminin'][$d->sexe] ?? '—'), 'Contact' => ($d->email ?: '—').' · '.($d->telephone ?: '—'),
               'Adresse' => $d->adresse ?: '—', 'Baccalauréat' => ($d->bac_type ?: '—').' — '.$d->bac_annee.' — '.$d->bac_mention] as $l => $v)
    <div style="padding:10px 0;border-bottom:1px solid #f3f4f6"><div style="font-size:11px;color:#6b7280;font-weight:600;text-transform:uppercase;margin-bottom:4px">{{ $l }}</div><div style="font-size:13px;font-weight:600;color:#1a1a2e">{{ $v }}</div></div>
    @endforeach
  </div></div>
</div>
<div class="pcard">
  <div class="pcard-header"><h2>📚 Choix de filières</h2><a href="{{ $lienEtape(2) }}" class="pbtn pbtn-secondary pbtn-sm">✏️ Modifier</a></div>
  <div class="pcard-body">
    @foreach ([1 => ['#1A3A5C', '#e8f0f8', '1er choix'], 2 => ['#c8860a', '#fef3cd', '2e choix'], 3 => ['#dc2626', '#fee2e2', '3e choix']] as $n => [$fg, $bg, $lbl])
    @if ($d->{'ch'.$n.'_lib'})
    <div style="background:{{ $bg }};border-left:4px solid {{ $fg }};border-radius:8px;padding:12px 16px;margin-bottom:10px"><div style="font-size:10px;font-weight:700;color:{{ $fg }};text-transform:uppercase;margin-bottom:4px">{{ $lbl }}</div><div style="font-weight:600;font-size:14px">{{ $d->{'ch'.$n.'_lib'} }}</div></div>
    @endif
    @endforeach
  </div>
</div>
<div class="pcard">
  <div class="pcard-header"><h2>📎 Documents joints</h2><a href="{{ $lienEtape(3) }}" class="pbtn pbtn-secondary pbtn-sm">✏️ Modifier</a></div>
  <div class="pcard-body"><div style="display:grid;grid-template-columns:repeat(auto-fit,minmax(150px,1fr));gap:10px">
    @foreach (Candidatures::PIECES as $champ => [$col, $ic, $titrePiece])
    @if ($d->$col)
    <a href="{{ route('candidature.piece', ['id' => $d->id, 'type' => $champ]) }}" target="_blank" rel="noopener" style="background:#f0f5fa;border-radius:8px;padding:14px;text-align:center;text-decoration:none">
      <div style="font-size:28px;margin-bottom:6px">{{ str_ends_with(strtolower($d->$col), '.pdf') ? '📄' : '🖼️' }}</div>
      <div style="font-size:12px;font-weight:600;color:#1A3A5C">{{ $titrePiece }}</div>
    </a>
    @endif
    @endforeach
  </div></div>
</div>
<form method="post" action="{{ route('candidature.soumettre', ['id' => $d->id]) }}">
  @csrf
  <div class="certif-box">
    <input type="checkbox" name="certifie" id="certifie" value="1" required>
    <label for="certifie" style="font-size:14px;cursor:pointer;color:#1a1a2e">Je certifie sur l'honneur que toutes les informations fournies sont exactes et complètes. Je comprends que toute fausse déclaration entraîne l'annulation de ma candidature.</label>
  </div>
  <div style="display:flex;justify-content:space-between;gap:12px">
    <a href="{{ $lienEtape(3) }}" class="pbtn pbtn-secondary pbtn-lg">← Précédent</a>
    <button type="submit" class="pbtn pbtn-primary pbtn-lg" style="min-width:240px">✅ Valider et soumettre mon dossier</button>
  </div>
</form>
@endif
@endsection
