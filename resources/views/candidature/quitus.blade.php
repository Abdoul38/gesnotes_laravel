{{-- Quitus de préinscription — portage de candidature/pages/quitus.php (QR code produit dans la page) --}}
@php
  $d = $dossier;
  $sigle = $universite->sigle ?: strtoupper($universite->code);
  $date = \Illuminate\Support\Carbon::parse($d->date_soumission ?? $d->updated_at)->locale('fr')->isoFormat('dddd D MMMM YYYY');
@endphp
<!DOCTYPE html>
<html lang="fr">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Quitus — {{ $d->numero_dossier }}</title>
<link rel="stylesheet" href="{{ asset('assets/candidature/quitus.css') }}">
<style>.id-qr-cell [data-qr] svg{width:20mm;height:20mm;display:block;margin:0 auto}</style>
</head>
<body>
<div class="toolbar no-print">
  <a href="#" class="btn-b" data-history-back="1">← Retour</a>
  <button type="button" class="btn-p" data-print="1">🖨️ Imprimer / Enregistrer en PDF</button>
</div>
<div class="page">
<table class="hdr"><tr>
  <td class="logo-cell" style="width:65px;padding:0"><img src="{{ $logo }}" alt="" style="width:58px;height:58px;object-fit:contain;display:block;margin:0 auto" data-onerror-hide="1"></td>
  <td class="txt">
    <div class="rep">République du Niger</div>
    <div class="min">Ministère de l'Enseignement Supérieur de la Recherche<br>et de l'Innovation Technologique</div>
    <div class="univ">{{ $universite->nom }}</div>
    <div class="svc">Service Central de la Scolarité (SCScol)</div>
    <div class="contact">Tel: {{ $telephone }} &nbsp;|&nbsp; {{ $adresse }} &nbsp;|&nbsp; Email: {{ $email }}</div>
  </td>
  <td class="logo-cell" style="width:65px;padding:0">
    @if ($armoirie)<img src="{{ $armoirie }}" alt="" style="width:58px;height:58px;object-fit:contain;display:block;margin:0 auto" data-onerror-hide="1">
    @else<div class="logo-box" style="margin:0 auto">🇳🇪<br>Niger</div>@endif
  </td>
</tr></table>

<div class="titre">
  <div class="titre-badge">Quittance de Préinscription</div>
  <div class="titre-sub">au titre de l'année académique {{ $annee }}</div>
</div>
<div class="num-bar">
  <span><strong>N° de dossier :</strong> {{ $d->numero_dossier }}</span>
  <span><strong>N° de dépôt :</strong>
    @if ($d->statut === 'accepte' && $d->numero_depot)<span style="color:#166534;font-weight:800">{{ $d->numero_depot }}</span>
    @else<em style="color:#999;font-weight:400">En attente de validation</em>@endif
  </span>
</div>

<div class="s-title">Identification du bachelier</div>
<table class="id-tbl"><tr>
  <td class="id-photo-cell">
    @if ($photo)<img src="{{ $photo }}" alt="Photo">
    @else<div class="id-photo-box" style="margin:0 auto">📷<br>Photo<br>d'identité</div>@endif
  </td>
  <td>
    <table class="id-inf" style="width:100%"><tbody>
      <tr><td>Nom :</td><td><strong>{{ $d->nom ?: '—' }}</strong></td></tr>
      <tr><td>Prénom :</td><td><strong>{{ $d->prenom ?: '—' }}</strong></td></tr>
      <tr><td>Date de naissance :</td><td>{{ $d->date_naissance ? date('d/m/Y', strtotime($d->date_naissance)) : '—' }}</td></tr>
      <tr><td>Lieu :</td><td>{{ $d->lieu_naissance ?: '—' }}</td></tr>
      <tr><td>Nationalité :</td><td>{{ $d->nationalite ?: 'Nigérienne' }}</td></tr>
      <tr><td>Genre :</td><td>{{ ['M' => 'Masculin', 'F' => 'Féminin'][$d->sexe] ?? '—' }}</td></tr>
      <tr><td>Email :</td><td>{{ $d->email ?: '—' }}</td></tr>
      <tr><td>Téléphone :</td><td>{{ $d->telephone ?: '—' }}</td></tr>
      <tr><td>Adresse :</td><td>{{ str_replace(["\r\n", "\n"], ', ', $d->adresse ?: '—') }}</td></tr>
    </tbody></table>
  </td>
  <td class="id-qr-cell"><div data-qr="{{ $qr }}" data-qr-cellule="2"></div><div>Scanner pour vérifier</div></td>
</tr></table>

<div class="s-title">Information du diplôme (BAC)</div>
<table class="bac-tbl"><tbody>
  <tr><td class="lbl">Niveau :</td><td>Supérieur</td><td class="lbl">Nom du diplôme :</td><td><strong>{{ $d->bac_type ?: '—' }}</strong></td></tr>
  <tr><td class="lbl">Année d'obtention :</td><td>{{ $d->bac_annee ?: '—' }}</td><td class="lbl">Mention :</td><td>{{ $d->bac_mention ?: '—' }}</td></tr>
  <tr><td class="lbl">Pays d'obtention :</td><td>Niger</td><td class="lbl">Région :</td><td>{{ $d->bac_lieu ?: '—' }}</td></tr>
</tbody></table>

<div class="s-title">Les choix de sections</div>
<div class="choix-box">
  <div class="choix-intro">Je soussigné(e), manifeste mon intérêt pour entreprendre des études dans les sections ci-dessus par ordre de préférence.</div>
  @foreach (['ch1_lib' => 'Premier choix', 'ch2_lib' => 'Deuxième choix', 'ch3_lib' => 'Troisième choix'] as $k => $lbl)
  @if ($d->$k)<div class="choix-line">{{ $lbl }} : <strong>{{ $d->$k }}</strong></div>@endif
  @endforeach
</div>

<div class="s-title">Liste des fichiers joints</div>
<div class="docs-box">
  @if ($pieces)<ul>@foreach ($pieces as $p)<li>{{ $p }}</li>@endforeach</ul>
  @else<div style="color:#999;font-style:italic">Aucun document joint</div>@endif
</div>

<div class="note-box"><strong>NB :</strong> La validation de la préinscription est assujettie au dépôt, au Service Central de la Scolarité, {{ $dateLimite }}, d'un dossier physique constitué du quitus de préinscription et des documents joints en ligne.</div>
<div class="certif">Je soussigné(e) certifie exactes les informations ci-dessus.</div>
<div class="datestr">Fait le {{ $date }}</div>
<div class="sig-r"><div>Signature du candidat</div><div class="sig-space"></div></div>
<div class="ftr"><strong>{{ $universite->nom }} - Service Central de la Scolarité</strong><br>N° {{ $numeroQuitus }}</div>
</div>
<script src="{{ \App\Support\Ressource::url('assets/js/qrcode.js') }}"></script>
<script src="{{ \App\Support\Ressource::url('assets/js/impression.js') }}"></script>
</body>
</html>
