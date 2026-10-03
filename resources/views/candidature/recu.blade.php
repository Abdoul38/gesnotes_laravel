{{-- Reçu d'inscription en ligne — portage de candidature/pages/recu.php (université et QR code locaux) --}}
@php
  $fcfa = fn ($v) => number_format((float) $v, 0, ',', ' ').' FCFA';
  $nom = trim($ins->prenoms.' '.$ins->nom);
@endphp
<!DOCTYPE html>
<html lang="fr">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width,initial-scale=1">
<title>Reçu d'inscription — {{ $nom }}</title>
<link rel="stylesheet" href="{{ asset('assets/candidature/recu.css') }}">
</head>
<body>
<div class="action-bar">
  <div class="title">📄 Reçu d'inscription — {{ $nom }}</div>
  <div class="btns">
    <a href="#" class="btn-back" data-history-back="1">← Retour</a>
    <button type="button" class="btn-print" data-print="1">🖨️ Imprimer / Enregistrer en PDF</button>
  </div>
</div>
<div class="recu-wrap">
  <div class="recu-header">
    <div class="recu-header-logo"><img src="{{ $logoGauche }}" alt="" data-onerror-hide="1"></div>
    <div class="recu-header-text">
      <div class="ministere">REPUBLIQUE DU NIGER</div>
      <div class="institution">{{ mb_strtoupper($universite->nom) }}</div>
      <div class="service">SERVICE CENTRAL DE LA SCOLARITÉ (SCScol)</div>
      <div class="contact">@if ($telephone)Tel : {{ $telephone }} &nbsp;|&nbsp; @endif{{ $adresse }}@if ($email) &nbsp;|&nbsp; {{ $email }}@endif</div>
    </div>
    <div class="recu-header-logo">@if ($logoDroit !== $logoGauche)<img src="{{ $logoDroit }}" alt="" data-onerror-hide="1">@endif</div>
  </div>
  <div class="recu-title">REÇU D'INSCRIPTION</div>
  <div class="recu-annee">Année académique {{ $ins->annee_lib }}</div>
  <div class="recu-meta">
    <div>N° reçu : <strong>{{ $ins->recu_numero }}</strong></div>
    <div>Date d'inscription : <strong>{{ $ins->date_paiement ? date('d/m/Y à H:i:s', strtotime($ins->date_paiement)) : '—' }}</strong></div>
  </div>
  <div class="recu-body">
    <div class="section">
      <div class="section-title">Informations de l'étudiant</div>
      <div class="section-grid">
        <div class="info-block">
          <div class="row"><span class="info-label">Nom et Prénom :</span><span class="info-val">{{ $nom }}</span></div>
          @if ($ins->matricule)<div class="row"><span class="info-label">Matricule :</span><span class="info-val" style="font-family:monospace;color:#1A3A5C;font-size:13pt;font-weight:900">{{ $ins->matricule }}</span></div>@endif
          @if ($ins->date_naissance)<div class="row"><span class="info-label">Date de naissance :</span><span class="info-val">{{ date('d/m/Y', strtotime($ins->date_naissance)) }}</span></div>@endif
          @if ($ins->email)<div class="row"><span class="info-label">Email :</span><span class="info-val">{{ $ins->email }}</span></div>@endif
          @if ($ins->telephone ?: $ins->telephone_paiement)<div class="row"><span class="info-label">Téléphone :</span><span class="info-val">{{ $ins->telephone ?: $ins->telephone_paiement }}</span></div>@endif
          @if ($ins->numero_dossier)<div class="row"><span class="info-label">N° dossier :</span><span class="info-val" style="font-family:monospace;color:#5b21b6">{{ $ins->numero_dossier }}</span></div>@endif
        </div>
        <div class="qr-zone"><div data-qr="{{ $ins->recu_numero }}|{{ $nom }}|{{ $ins->annee_lib }}" data-qr-cellule="2" style="width:90px;height:90px"></div><div>Vérification</div></div>
      </div>
    </div>
    <div class="section">
      <div class="section-title">Formation inscrite</div>
      <div class="info-block">
        <div class="row"><span class="info-label">Filière :</span><span class="info-val">{{ $ins->filiere_lib ?? '—' }}</span></div>
        <div class="row"><span class="info-label">Niveau :</span><span class="info-val">{{ $ins->niveau_lib }} ({{ $ins->niveau_code }})</span></div>
        @if ($ins->faculte)<div class="row"><span class="info-label">Faculté :</span><span class="info-val">{{ $ins->faculte }}</span></div>@endif
        <div class="row"><span class="info-label">Statut :</span><span class="badge-valide">✅ Inscription validée</span></div>
      </div>
    </div>
    <div class="section">
      <div class="section-title">Détails du paiement</div>
      <div class="info-block">
        <div class="row"><span class="info-label">Mode de paiement :</span><span class="info-val">{{ $moyen }}</span></div>
        @if ($ins->reference_paiement)<div class="row"><span class="info-label">Référence :</span><span class="info-val" style="font-family:monospace">{{ $ins->reference_paiement }}</span></div>@endif
        <div class="row"><span class="info-label">Année universitaire :</span><span class="info-val">{{ $ins->annee_lib }}</span></div>
      </div>
    </div>
    <div class="montant-bloc">
      <div class="montant-label">💳 Récapitulatif du paiement</div>
      <div class="montant-detail">
        <div class="ligne"><span>Frais d'inscription</span><span>{{ $fcfa($montant['base']) }}</span></div>
        <div class="ligne"><span>Frais de transaction ({{ round(config('komipay.taux_frais') * 100) }}%)</span><span>{{ $fcfa($montant['frais']) }}</span></div>
        <div class="ligne total"><span>Total payé</span><span>{{ $fcfa($montant['total']) }}</span></div>
      </div>
    </div>
    <div class="alerte"><strong>⚠️ IMPORTANT</strong> Ce reçu confirme votre inscription administrative en ligne. Veuillez le présenter au Service Central de la Scolarité pour finaliser votre inscription académique. Conservez-le précieusement. Toute falsification expose à des poursuites.</div>
    <div style="font-size:10pt;margin-bottom:6mm">Fait à {{ $ville }}, le {{ \Illuminate\Support\Carbon::now()->locale('fr')->isoFormat('dddd D MMMM YYYY') }}</div>
    <div class="signatures">
      <div class="sig-bloc"><div class="sig-role">L'étudiant(e)</div><div class="sig-space"></div><div class="sig-name">(Signature)</div></div>
      <div class="sig-bloc"><div class="sig-role">Le Service Central de la Scolarité</div><div class="sig-space"></div><div class="sig-name">(Cachet et signature)</div></div>
    </div>
  </div>
</div>
<script src="{{ asset('assets/js/qrcode.js') }}"></script>
<script src="{{ asset('assets/js/impression.js') }}"></script>
</body>
</html>
