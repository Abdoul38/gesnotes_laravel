{{-- Inscription académique en ligne — portage de candidature/pages/inscription.php --}}
@php
  $etapes = [1 => 'Identification', 2 => 'Filière & Niveau', 3 => 'Paiement', 4 => 'Confirmation', 5 => 'Inscription complète'];
  $fcfa = fn ($v) => number_format((float) $v, 0, '', ' ').' FCFA';
  $ouverte = app(\App\Services\InscriptionEnLigne::class)->ouverte();
@endphp
<!DOCTYPE html>
<html lang="fr">
<head>
<meta charset="UTF-8"><meta name="viewport" content="width=device-width,initial-scale=1">
<title>Inscription Académique — {{ $sigle }}</title>
<link rel="stylesheet" href="{{ asset('assets/candidature/inscription.css') }}">
</head>
<body>
<nav class="navbar">
  <div class="navbar-brand"><span class="brand-title">🎓 {{ $universite->nom }} — Inscription {{ $annee }}</span></div>
  <div class="navbar-actions">
    @if (Auth::guard('candidat')->check())<a href="{{ route('candidature.tableau') }}" class="btn btn-outline">🏠 Mon espace</a>
    @else<a href="{{ route('candidature.connexion') }}" class="btn btn-outline">🔑 Connexion</a>@endif
  </div>
</nav>
<div class="container">
  <div class="stepper">
    @foreach ($etapes as $n => $lbl)
    <div class="step-item {{ $n < $etape ? 'done' : ($n === $etape ? 'active' : '') }}"><div class="step-circle">{{ $n < $etape ? '✓' : $n }}</div><div class="step-label">{{ $lbl }}</div></div>
    @endforeach
  </div>
  @foreach (['erreur' => 'error', 'succes' => 'success', 'info' => 'info'] as $cle => $classe)
  @if (session($cle))<div class="alert alert-{{ $classe }}" role="alert">{{ session($cle) }}</div>@endif
  @endforeach
  <div class="page-title">Étape {{ $etape }} : {{ $etapes[$etape] }}</div>

  @if ($etape === 1)
  @if (! $ouverte)
  <div style="background:#fee2e2;border:1.5px solid #fca5a5;border-radius:10px;padding:20px 24px;text-align:center;margin-bottom:16px">
    <div style="font-size:28px;margin-bottom:8px">🔒</div>
    <div style="font-weight:700;font-size:15px;color:#991b1b;margin-bottom:6px">Inscriptions fermées</div>
    <div style="font-size:13px;color:#7f1d1d">{{ app(\App\Services\Candidatures::class)->config('message_inscription_fermee', 'Les inscriptions académiques en ligne sont actuellement fermées.') }}</div>
  </div>
  @else
  <div class="card"><div class="card-body">
    @if ($restriction === 'ancien')
    <div style="background:#e8f0f8;border-radius:10px;padding:14px 16px;margin-bottom:20px"><strong style="color:#1A3A5C">🎓 Réservé aux anciens étudiants {{ $sigle }}</strong><div style="font-size:12px;color:#6b7280;margin-top:2px">Entrez votre numéro de matricule</div></div>
    @elseif ($restriction === 'nouveau')
    <div style="background:#f0fdf4;border-radius:10px;padding:14px 16px;margin-bottom:20px"><strong style="color:#166534">📄 Réservé aux nouveaux bacheliers</strong><div style="font-size:12px;color:#6b7280;margin-top:2px">Entrez votre N° dossier ou N° dépôt</div></div>
    @endif
    <form method="post" action="{{ route('candidature.inscription.identifier') }}">
      @csrf
      <div class="form-group">
        <label class="form-label" for="ins-inp-unified">{{ ['ancien' => 'Numéro matricule', 'nouveau' => 'N° dossier ou N° dépôt'][$restriction] ?? 'Votre identifiant' }} <span class="req">*</span></label>
        <input type="text" name="identifiant" id="ins-inp-unified" class="form-control" value="{{ old('identifiant') }}" required autocomplete="off" data-sigle="{{ $sigle }}"
          placeholder="{{ ['ancien' => 'Ex: 00001', 'nouveau' => 'Ex: D915839'][$restriction] ?? 'Matricule, N° dossier ou N° dépôt' }}"
          style="font-family:monospace;font-size:18px;font-weight:700;letter-spacing:2px;text-transform:uppercase">
        <div class="form-hint" id="ins-detect-text">Saisissez votre matricule {{ $sigle }}, N° dossier (D...) ou N° dépôt ({{ strtolower($sigle) }}...)</div>
      </div>
      <button type="submit" class="btn btn-primary btn-lg" style="width:100%;justify-content:center">🔍 Vérifier et continuer</button>
    </form>
  </div></div>
  @endif

  @elseif ($etape === 2)
  <div class="card"><div class="card-body">
    <div class="section-title">📋 Récapitulatif de votre inscription</div>
    <div class="recap-bloc">
      <div class="recap-row"><span>Étudiant</span><span style="font-weight:700">{{ $ins->prenoms }} {{ $ins->nom }}</span></div>
      @if ($ins->numero)<div class="recap-row"><span>Matricule</span><span style="font-family:monospace">{{ $ins->numero }}</span></div>@endif
      @if ($ins->numero_dossier)<div class="recap-row"><span>N° dossier</span><span style="font-family:monospace">{{ $ins->numero_dossier }}</span></div>@endif
      <div class="recap-row"><span>Filière</span><span style="font-weight:700;color:#5b21b6">{{ $ins->filiere_lib ?? '—' }}</span></div>
      <div class="recap-row"><span>Niveau</span><span>{{ $ins->niveau_code ?? '—' }}</span></div>
      <div class="recap-row"><span>Année académique</span><span>{{ $ins->annee_lib }}</span></div>
      <div class="recap-row"><span>Frais d'inscription</span><span style="font-weight:700">{{ $fcfa($montant['base']) }}</span></div>
    </div>
    <div class="alert alert-info" style="font-size:12px">ℹ️ La filière et le niveau sont fixés par votre dossier. En cas d'erreur, contactez la scolarité avant de payer.</div>
    <div style="display:flex;justify-content:space-between;gap:10px;flex-wrap:wrap">
      <a href="{{ route('candidature.inscription') }}" class="btn btn-outline">← Retour</a>
      <a href="{{ route('candidature.inscription.etape', ['id' => $ins->id, 'etape' => 3]) }}" class="btn btn-primary btn-lg" style="background:linear-gradient(135deg,#5b21b6,#7c3aed)">Continuer vers le paiement →</a>
    </div>
  </div></div>

  @elseif ($etape === 3)
  <div class="recap-bloc" style="margin-bottom:16px">
    <div class="recap-row"><span>Filière</span><span style="font-weight:700;color:#5b21b6">{{ $ins->filiere_lib ?? '—' }}</span></div>
    <div class="recap-row"><span>Niveau</span><span>{{ $ins->niveau_code ?? '—' }}</span></div>
    <div class="recap-row"><span>Frais d'inscription</span><span style="font-weight:700">{{ $fcfa($montant['base']) }}</span></div>
    <div class="recap-row"><span>Frais de transaction ({{ round(config('komipay.taux_frais') * 100) }}%)</span><span style="font-weight:700">{{ $fcfa($montant['frais']) }}</span></div>
    <div class="recap-row" style="font-size:16px"><span><strong>Total à payer</strong></span><span style="font-weight:800;color:#16a34a">{{ $fcfa($montant['total']) }}</span></div>
  </div>
  <div class="card"><div class="card-body">
    <div class="section-title">💳 Choisir votre mode de paiement</div>
    @if (! $moyens)
    <div class="alert alert-warning">Aucun moyen de paiement en ligne n'est disponible pour le moment. Contactez la scolarité.</div>
    @else
    <form method="post" action="{{ route('candidature.inscription.payer', ['id' => $ins->id]) }}" id="formPay">
      @csrf
      <div class="pay-grid" role="radiogroup" aria-label="Mode de paiement">
        @foreach ($moyens as $val => $nom)
        <label class="pay-card {{ $loop->first ? 'selected' : '' }}" data-moyen="{{ $val }}">
          <input type="radio" name="moyen" value="{{ $val }}" @checked($loop->first) style="position:absolute;opacity:0">
          <div class="pay-icon">{{ ['mynita' => '🏦', 'amana' => '🕌', 'mobile_money' => '📱', 'visa_master' => '💳'][$val] }}</div>
          <div class="pay-name">{{ $nom }}</div>
          <div class="pay-desc">{{ ['mynita' => 'Plateforme NITA Niger', 'amana' => 'Amana Microfinance', 'mobile_money' => 'Airtel Money', 'visa_master' => 'Carte bancaire internationale'][$val] }}</div>
        </label>
        @endforeach
      </div>
      <div class="pay-extra" id="zone-telephone">
        <div class="form-group" style="margin:0">
          <label class="form-label" for="telephone">Numéro de téléphone / de compte <span class="req">*</span></label>
          <input type="tel" name="telephone" id="telephone" class="form-control" placeholder="+227 XX XX XX XX" maxlength="20">
          <div class="form-hint">MyNITA : un code vous sera envoyé pour confirmer. Airtel Money : 8 chiffres, sans indicatif.</div>
        </div>
      </div>
      <div class="pay-extra" id="zone-carte" style="display:none"><div class="alert alert-info" style="font-size:12px;margin:0">🔒 Vous serez dirigé vers la page de paiement sécurisée. Munissez-vous de votre carte.</div></div>
      <button type="submit" class="btn btn-primary" style="width:100%;justify-content:center;padding:14px;font-size:15px;margin-top:16px;background:linear-gradient(135deg,#5b21b6,#7c3aed)">💳 Procéder au paiement — {{ $fcfa($montant['total']) }}</button>
    </form>
    @endif
  </div></div>

  @elseif ($etape === 4)
  <div class="card"><div class="card-body" style="text-align:center;padding:32px">
    <div id="paiement-en-attente" data-statut-url="{{ route('candidature.inscription.statut', ['id' => $ins->id]) }}" @if ($ins->statut_paiement === 'valide') data-deja-valide="1" @endif>
      <div style="font-size:56px;margin-bottom:16px">{{ ['mobile_money' => '📱', 'mynita' => '🏦', 'amana' => '🕌'][$ins->moyen_paiement] ?? '💳' }}</div>
      <h2 style="font-size:18px;font-weight:700;color:#1A3A5C;margin-bottom:8px">{{ $ins->statut_paiement === 'valide' ? 'Paiement reçu — finalisation de votre inscription' : 'En attente de votre paiement' }}</h2>
      <p style="color:#6b7280;font-size:13px;margin-bottom:24px">Via <strong>{{ \App\Services\InscriptionEnLigne::MOYENS[$ins->moyen_paiement] ?? '' }}</strong>@if ($ins->telephone_paiement) au <strong>{{ $ins->telephone_paiement }}</strong>@endif</p>
      @if ($ins->code_achat_komipay)
      <div class="alert alert-info" style="text-align:center;font-size:13px;margin-bottom:16px">Code d'achat MyNITA à saisir pour confirmer :
        <div style="font-family:monospace;font-size:22px;font-weight:800;color:#5b21b6;margin-top:6px">{{ $ins->code_achat_komipay }}</div></div>
      @endif
      <div class="recap-bloc" style="text-align:left;margin-bottom:20px">
        <div class="recap-row"><span>Référence</span><span style="font-family:monospace;font-weight:700;color:#5b21b6">{{ $ins->reference_paiement ?? '—' }}</span></div>
        <div class="recap-row"><span>Frais d'inscription</span><span>{{ $fcfa($montant['base']) }}</span></div>
        <div class="recap-row"><span>Frais de transaction</span><span>{{ $fcfa($montant['frais']) }}</span></div>
        <div class="recap-row" style="font-size:15px"><span><strong>Total</strong></span><span style="font-weight:800;color:#16a34a">{{ $fcfa($montant['total']) }}</span></div>
        <div class="recap-row"><span>Filière</span><span>{{ $ins->filiere_lib ?? '—' }}</span></div>
      </div>
      @if ($ins->statut_paiement !== 'valide')
      <div class="alert alert-warning" style="text-align:left;font-size:12px;margin-bottom:20px">✅ Acceptez/validez la demande sur votre téléphone ou votre compte. Cette page se met à jour automatiquement.</div>
      <div id="spinner-attente" style="margin-bottom:16px">
        <div class="spinner" style="width:28px;height:28px;border:3px solid #e5e7eb;border-top-color:#5b21b6;border-radius:50%;display:inline-block;animation:spin 1s linear infinite"></div>
        <div id="texte-attente" style="font-size:12px;color:#6b7280;margin-top:8px">Vérification automatique en cours…</div>
      </div>
      @endif
      <form method="post" action="{{ route('candidature.inscription.confirmer', ['id' => $ins->id]) }}" id="formConfirmer">
        @csrf
        <button type="submit" class="btn btn-primary" style="width:100%;justify-content:center;padding:14px;font-size:15px">{{ $ins->statut_paiement === 'valide' ? '✅ Finaliser mon inscription' : "🔄 J'ai payé, vérifier maintenant" }}</button>
      </form>
      @if ($ins->statut_paiement !== 'valide')<a href="{{ route('candidature.inscription.etape', ['id' => $ins->id, 'etape' => 3]) }}" style="display:block;margin-top:12px;color:#6b7280;font-size:12px">← Changer de mode de paiement</a>@endif
    </div>
    <div id="paiement-echec" style="display:none">
      <div style="font-size:56px;margin-bottom:16px">⚠️</div>
      <h2 style="font-size:18px;font-weight:700;color:#991b1b;margin-bottom:8px">Paiement non confirmé</h2>
      <p style="color:#6b7280;font-size:13px;margin-bottom:20px">La transaction a échoué. Vous pouvez réessayer.</p>
      <a href="{{ route('candidature.inscription.etape', ['id' => $ins->id, 'etape' => 3]) }}" class="btn btn-primary" style="width:100%;justify-content:center;padding:14px">↺ Réessayer le paiement</a>
    </div>
    <div id="paiement-expire" style="display:none">
      <div style="font-size:56px;margin-bottom:16px">⏰</div>
      <h2 style="font-size:18px;font-weight:700;color:#991b1b;margin-bottom:8px">Délai de paiement expiré</h2>
      <a href="{{ route('candidature.inscription.etape', ['id' => $ins->id, 'etape' => 3]) }}" class="btn btn-primary" style="width:100%;justify-content:center;padding:14px">↺ Recommencer le paiement</a>
    </div>
  </div></div>

  @else
  <div class="success-mat">
    <div style="font-size:48px;margin-bottom:10px">✅</div>
    <h1 style="font-size:22px;font-weight:800;margin-bottom:4px">Inscription confirmée !</h1>
    <p style="opacity:.85;font-size:13px">Bienvenue à {{ $universite->nom }}</p>
    <div style="margin-top:18px;background:rgba(255,255,255,.2);border-radius:12px;padding:16px">
      <div style="font-size:12px;opacity:.85;margin-bottom:4px">Votre matricule</div>
      <div class="mat-num">{{ $ins->matricule ?? '—' }}</div>
      <div style="font-size:11px;opacity:.75">Conservez ce numéro — c'est votre identifiant académique</div>
    </div>
  </div>
  <div class="recap-bloc">
    <div class="info-row"><span style="color:#6b7280">N° Reçu</span><span style="font-family:monospace;font-weight:700;color:#5b21b6">{{ $ins->recu_numero ?? '—' }}</span></div>
    <div class="info-row"><span style="color:#6b7280">Filière</span><span>{{ $ins->filiere_lib ?? '—' }}</span></div>
    <div class="info-row"><span style="color:#6b7280">Niveau</span><span>{{ $ins->niveau_code ?? '—' }}</span></div>
    <div class="info-row"><span style="color:#6b7280"><strong>Montant total payé</strong></span><span style="font-weight:800;font-size:16px;color:#16a34a">{{ $fcfa($montant['total']) }}</span></div>
    <div class="info-row"><span style="color:#6b7280">Date</span><span>{{ $ins->date_paiement ? date('d/m/Y à H:i', strtotime($ins->date_paiement)) : '—' }}</span></div>
  </div>
  <div class="steps-next" style="margin-bottom:24px">
    <div style="font-size:14px;font-weight:700;color:#5b21b6;margin-bottom:12px">📋 Prochaines étapes</div>
    <ol style="font-size:13px;padding-left:18px">
      <li style="margin-bottom:8px">Téléchargez votre <strong>reçu d'inscription</strong></li>
      <li style="margin-bottom:8px">Présentez-vous à la <strong>Scolarité</strong> pour finaliser</li>
      <li style="margin-bottom:8px">Récupérez votre <strong>carte étudiante</strong></li>
      <li>Effectuez votre <strong>inscription pédagogique</strong></li>
    </ol>
  </div>
  <div style="display:flex;gap:12px;justify-content:center;flex-wrap:wrap">
    <a href="{{ route('candidature.inscription.recu', ['id' => $ins->id]) }}" target="_blank" rel="noopener" class="btn btn-primary btn-lg">📄 Télécharger mon reçu</a>
    @if (Auth::guard('candidat')->check())<a href="{{ route('candidature.tableau') }}" class="btn btn-outline btn-lg">🏠 Mon espace</a>@endif
  </div>
  @endif
</div>
<script src="{{ \App\Support\Ressource::url('assets/js/candidature.js') }}"></script>
<script src="{{ \App\Support\Ressource::url('assets/js/inscription-en-ligne.js') }}"></script>
</body>
</html>
