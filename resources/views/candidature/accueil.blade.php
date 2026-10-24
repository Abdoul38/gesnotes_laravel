{{-- Accueil public du portail candidats — portage de candidature/pages/accueil.php --}}
@php $sigle = $universite->sigle ?: strtoupper($universite->code); @endphp
<!DOCTYPE html>
<html lang="fr">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width,initial-scale=1">
<title>Bienvenue — Portail {{ $sigle }}</title>
<link rel="stylesheet" href="{{ asset('assets/candidature/accueil.css') }}">
</head>
<body>
<nav class="nav" id="mainNav">
  <a href="{{ route('candidature') }}" class="nav-brand">
    <div class="nav-logo">🎓</div>
    <div class="nav-name">{{ $universite->nom }}<small>Portail de Préinscription</small></div>
  </a>
  <div class="nav-links">
    <a href="#processus">Comment ça marche</a>
    <a href="#documents">Documents requis</a>
    @if (Route::has('candidature.inscription'))<a href="#inscription-rapide">📋 Inscription</a>@endif
    <a href="{{ route('candidature.connexion') }}">Connexion</a>
    <a href="{{ route('candidature.compte') }}" class="btn-nav-cta">📝 Déposer mon dossier</a>
  </div>
</nav>

@php
  $diapos = [
    ['🎓 Préinscription '.$annee, "Votre avenir\ncommence ici", "Déposez votre dossier de préinscription en ligne à {$universite->nom}. Rapide, simple et sécurisé.", true],
    ['📚 Nos formations', "Des filières\nd'excellence", 'Droit, Sciences Économiques, Lettres, Sciences… Choisissez parmi nos filières reconnues et construisez votre parcours académique.', false],
    ['🏛️ Notre campus', "Une université\nau cœur de ".($universite->ville ?: 'Niger'), "{$universite->nom} vous accueille dans un environnement propice aux études. Rejoignez notre communauté académique.", false],
    ['🏆 Réussite', "Inscrivez-vous\nen toute confiance", 'Un portail officiel, sécurisé, disponible 24h/24. Votre quitus de préinscription est généré instantanément après soumission.', false],
  ];
@endphp
<div class="slider" id="slider">
  @foreach ($diapos as $i => [$badge, $titreDiapo, $texte, $boutons])
  <div @class(['slide', 'active' => $i === 0]) id="slide-{{ $i }}">
    <div class="slide-bg" @if (in_array($i + 1, $slides, true)) style="background-image:url('{{ route('candidature.slide', ['n' => $i + 1]) }}')" @else style="background:linear-gradient(135deg,#1A3A5C,#2C5F9E)" @endif></div>
    <div class="slide-overlay"></div>
    <div class="slide-content">
      <div class="slide-badge">{{ $badge }}</div>
      <h1 class="slide-titre">{!! nl2br(e($titreDiapo)) !!}</h1>
      <p class="slide-sous">{{ $texte }}</p>
      @if ($i === 0 && ! $ouvert)
      <div style="background:rgba(200,134,10,.9);padding:14px 20px;border-radius:10px;color:#fff;font-size:14px;max-width:400px">🔒 {{ $messageFerme }}</div>
      @else
      <div class="slide-btns">
        @if ($i === 1)<a href="#processus" class="btn-hero-p">Découvrir le processus</a>
        @else<a href="{{ route('candidature.compte') }}" class="btn-hero-p">📝 {{ $i === 0 ? 'Déposer mon dossier' : ($i === 2 ? "S'inscrire maintenant" : 'Commencer maintenant') }}</a>@endif
        @if ($i === 0)<a href="{{ route('candidature.connexion') }}" class="btn-hero-s">🔑 J'ai déjà un compte</a>@endif
        @if ($i === 3)<a href="#documents" class="btn-hero-s">📋 Documents requis</a>@endif
      </div>
      @endif
    </div>
  </div>
  @endforeach
  <button type="button" class="slider-prev" data-diapo="-1" aria-label="Diapositive précédente">&#8592;</button>
  <button type="button" class="slider-next" data-diapo="1" aria-label="Diapositive suivante">&#8594;</button>
  <div class="slider-dots" id="sliderDots">
    @foreach ($diapos as $i => $d)<button type="button" @class(['dot', 'active' => $i === 0]) data-diapo-aller="{{ $i }}" aria-label="Diapositive {{ $i + 1 }}"></button>@endforeach
  </div>
  <div class="scroll-ind"><span>Défiler</span><div class="scroll-line"></div></div>
</div>

@if (Route::has('candidature.inscription'))
<div id="inscription-rapide" style="scroll-margin-top:68px;background:linear-gradient(135deg,#f8fafc 0%,#e8f0f8 100%);border-bottom:3px solid var(--or)">
  <div class="ins-section-grid">
    <div>
      <div style="font-size:11px;font-weight:700;letter-spacing:2px;text-transform:uppercase;color:var(--or);margin-bottom:12px">Inscription Académique {{ $annee }}</div>
      <h2 style="font-family:'Playfair Display',serif;font-size:clamp(24px,3vw,34px);font-weight:700;color:var(--gris-f);line-height:1.2;margin-bottom:12px">Effectuez votre inscription en ligne</h2>
      <p style="font-size:15px;color:var(--gris-m);line-height:1.7;margin-bottom:24px">Anciens étudiants ou nouveaux bacheliers — saisissez votre identifiant pour commencer.</p>
      <div style="display:flex;flex-direction:column;gap:10px">
        @foreach ([['🎓', 'Ancien étudiant', 'Entrez votre numéro de matricule '.$sigle], ['📄', 'Nouveau bachelier', 'Entrez votre N° dossier ou N° dépôt'], ['✅', 'Résultat immédiat', 'Votre dossier est traité instantanément']] as [$ic, $t, $d])
        <div style="display:flex;align-items:center;gap:12px">
          <div style="width:36px;height:36px;border-radius:50%;background:var(--vert);color:#fff;display:flex;align-items:center;justify-content:center;font-size:16px;flex-shrink:0">{{ $ic }}</div>
          <div><strong style="font-size:13px;color:var(--gris-f)">{{ $t }}</strong><div style="font-size:12px;color:var(--gris-m)">{{ $d }}</div></div>
        </div>
        @endforeach
      </div>
    </div>
    <div>
      <div class="ins-wrap" style="background:#fff;border-radius:16px;padding:28px;box-shadow:0 4px 24px rgba(0,0,0,.1);border:1.5px solid #e5e7eb">
        <div style="font-weight:700;font-size:17px;color:var(--gris-f);margin-bottom:6px">🔍 Identifier mon dossier</div>
        <div style="font-size:12px;color:#6b7280;margin-bottom:20px">Entrez votre matricule <strong>ou</strong> votre N° dossier.</div>
        <form method="post" action="{{ route('candidature.inscription.identifier') }}">
          @csrf
          <div class="ins-form-group">
            <label for="ins-inp-unified">Matricule ou N° dossier / dépôt <span class="ins-req">*</span></label>
            <input type="text" name="identifiant" id="ins-inp-unified" placeholder="Ex: 00001  ou  D915839" autocomplete="off" autocapitalize="characters" required
              data-sigle="{{ $sigle }}" style="text-transform:uppercase;font-family:monospace;font-size:15px;font-weight:700;letter-spacing:1px">
            <div class="ins-hint"><span id="ins-detect-text">💡 Ancien étudiant : matricule | Nouveau bachelier : N° commençant par D ou {{ strtolower($sigle) }}</span></div>
          </div>
          <button type="submit" class="ins-btn">🔍 Vérifier et continuer</button>
        </form>
        <div style="text-align:center;margin-top:14px;font-size:12px;color:#6b7280">Pas encore de dossier ? <a href="{{ route('candidature.compte') }}" style="color:#1A3A5C;font-weight:600;text-decoration:none">Créer mon espace candidat →</a></div>
      </div>
    </div>
  </div>
</div>
@endif

<div class="stats-bar">
  @foreach ([['4+', 'Facultés & Départements'], ['100%', '100% en ligne'], ['24h', 'Disponible 24h/24'], ['Instantané', 'Quitus immédiat']] as [$n, $l])
  <div class="stat-item"><div class="stat-num">{{ $n }}</div><div class="stat-lbl">{{ $l }}</div></div>
  @endforeach
</div>

<section class="section" id="processus">
  <div class="container">
    <div style="text-align:center;margin-bottom:16px">
      <div class="section-tag">Comment déposer son dossier</div>
      <h2 class="section-titre" style="text-align:center">4 étapes simples</h2>
      <p class="section-sous" style="margin:0 auto;text-align:center">Tout se fait en ligne depuis chez vous. Votre quitus est généré instantanément.</p>
    </div>
    <div class="etapes-grid">
      @foreach ([['1', '📝', 'Créer mon compte', 'Renseignez vos informations personnelles et créez votre espace candidat.'], ['2', '📚', 'Choisir mes filières', "Sélectionnez jusqu'à 3 filières par ordre de préférence."],
                 ['3', '📎', 'Joindre les pièces', 'Uploadez votre photo, extrait de naissance, relevé BAC et CNI.'], ['4', '✅', 'Soumettre & obtenir', 'Soumettez votre dossier et téléchargez votre quitus de préinscription.']] as [$num, $ic, $t, $d])
      <div class="etape"><div class="etape-num">{{ $num }}</div><div class="etape-icon">{{ $ic }}</div><div class="etape-titre">{{ $t }}</div><div class="etape-desc">{{ $d }}</div></div>
      @endforeach
    </div>
  </div>
</section>

<section class="section section-alt" id="documents">
  <div class="container">
    <div class="docs-section-inner" style="display:grid;grid-template-columns:1fr 1fr;gap:60px;align-items:center">
      <div>
        <div class="section-tag">Ce qu'il faut préparer</div>
        <h2 class="section-titre">Documents requis</h2>
        <p class="section-sous">Préparez ces documents avant de commencer votre dossier pour un processus fluide et rapide.</p>
        @if ($ouvert)<a href="{{ route('candidature.compte') }}" class="btn-hero-p" style="margin-top:28px;display:inline-flex">📝 Commencer mon dossier</a>@endif
      </div>
      <div class="docs-grid">
        @foreach (\App\Services\Candidatures::PIECES as [$col, $ic, $t, $requis, $aide])
        <div class="doc-card">
          <div class="doc-icon">{{ $ic }}</div>
          <div class="doc-info"><strong>{{ $t }} @if ($requis)<span class="doc-req">Requis</span>@else<span class="doc-opt">Facultatif</span>@endif</strong><span>{{ $aide }}</span></div>
        </div>
        @endforeach
      </div>
    </div>
  </div>
</section>

@if ($ouvert)
<section class="cta-section">
  <div class="container">
    <h2 class="cta-titre">Prêt à rejoindre {{ $sigle }} ?</h2>
    <p class="cta-sous">Les dépôts sont ouverts pour l'année académique {{ $annee }}. Ne tardez pas !</p>
    <div class="cta-btns">
      <a href="{{ route('candidature.compte') }}" class="btn-cta-p">📝 Déposer mon dossier maintenant</a>
      <a href="{{ route('candidature.connexion') }}" class="btn-cta-s">🔑 J'ai déjà un compte</a>
    </div>
  </div>
</section>
@endif

<footer>
  <div class="container">
    <div style="font-size:14px;font-weight:600;color:rgba(255,255,255,.9);margin-bottom:8px">🎓 {{ $universite->nom }}</div>
    <div style="font-size:12px;display:flex;gap:20px;justify-content:center;flex-wrap:wrap;margin-bottom:12px">
      <span>{{ $adresse }}</span>@if ($email)<span>{{ $email }}</span>@endif @if ($telephone)<span>{{ $telephone }}</span>@endif
    </div>
    <div class="foot-line">© {{ date('Y') }} {{ $universite->nom }} — Portail de Préinscription</div>
  </div>
</footer>
<script src="{{ \App\Support\Ressource::url('assets/js/candidature.js') }}"></script>
</body>
</html>
