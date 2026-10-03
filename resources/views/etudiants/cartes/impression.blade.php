{{-- Impression des cartes — portage de carte_etudiant.php (page autonome, sans menu) --}}
<!DOCTYPE html>
<html lang="fr">
<head>
<meta charset="UTF-8">
<title>Carte étudiant — {{ $institution }}</title>
<style>
@include('etudiants.cartes._styles')
</style>
</head>
<body>
<div class="btn-bar no-print">
  <button type="button" id="btn-imprimer">🖨️ Imprimer{{ $masse ? ' ('.count($cartes).' cartes)' : '' }}</button>
  <a href="{{ route('carte_etudiant') }}" id="btn-retour">✕ Retour</a>
</div>
<div class="spacer no-print"></div>

<div class="planche m-{{ $modele }}">
@foreach ($cartes as $k)
<div class="slot">
<div class="carte">
  @if ($modele === 'administratif')<div class="a-topband"><div class="u1">République du Niger</div></div>@endif
  <div class="c-header">
    <div class="c-logo-box"><img src="{{ $logo }}" alt="" data-onerror-src="{{ asset('assets/img/logo_udh.png') }}"></div>
    <div class="c-header-text">
      <div class="h1">{{ $institution }}</div>
      <div class="h2">{{ $k['faculte'] }}</div>
    </div>
    <div class="c-badge">Carte d'Étudiant</div>
  </div>
  @if ($modele === 'administratif')<div class="a-titre">Carte d'étudiant</div>@endif

  <div class="c-body">
    <div class="c-watermark"></div>
    <div class="c-infos">
      <div class="c-nom">{{ $k['et']->nom }}</div>
      <div class="c-prenom">{{ $k['et']->prenoms }}</div>
      <div class="c-ddn">@if ($modele === 'badge')Né(e) le @endif{{ $k['ddn'] }}{{ $k['lieu'] ? ' à '.$k['lieu'] : '' }}</div>
      <div class="c-grille">
        @if ($modele === 'moderne')
          <div class="c-champ"><span class="lbl">NIE</span><span class="val">{{ $k['nie'] }}</span></div>
          <div class="c-champ"><span class="lbl">Niveau</span><span class="val">{{ $k['niveau'] }}</span></div>
          <div class="c-champ full"><span class="lbl">Filière</span><span class="val">{{ $k['filiere'] }}</span></div>
        @else
          <div class="c-champ f-mat"><span class="lbl">Matricule</span><span class="val lg">{{ $k['nie'] }}</span></div>
          <div class="c-champ f-niv"><span class="lbl">Niveau</span><span class="val lg">{{ $k['niveau'] }}</span></div>
          <div class="c-champ full f-fil"><span class="lbl">Filière</span><span class="val">{{ $k['filiere'] }}</span></div>
          <div class="c-champ full f-nat"><span class="lbl">Nationalité</span><span class="val">{{ $k['nationalite'] }}</span></div>
          <div class="c-champ f-an"><span class="lbl">Année Acad.</span><span class="val">{{ $k['annee'] }}</span></div>
        @endif
      </div>
      @if ($modele === 'badge')
        <div class="c-auth"><div class="au-titre">Signature de l'autorité</div><div class="au-zone">@if ($signature)<img src="{{ $signature }}" alt="">@endif</div></div>
      @endif
    </div>

    <div class="c-sep"></div>

    <div class="c-right">
      <div class="c-photo">
        @if ($k['photo'])<img src="{{ $k['photo'] }}" alt="Photo">@else<span class="np">👤</span>@endif
      </div>
      @if ($modele !== 'badge')
        <div class="c-auth"><div class="au-titre">Signature de l'autorité</div><div class="au-zone">@if ($signature)<img src="{{ $signature }}" alt="">@endif</div></div>
      @endif
      @if ($cachet)
        <div class="c-sig-zone"><div class="c-cachet"><img src="{{ $cachet }}" alt=""></div></div>
      @endif
    </div>
  </div>

  @if ($modele === 'badge')
  <div class="c-footer">
    <span class="mat">{{ $k['nie'] }}</span>
    <div class="c-qr" data-qr="{{ $k['urlVerification'] }}" data-qr-cellule="1"></div>
    <span class="annee">{{ $k['annee'] }}</span>
  </div>
  @else
  <div class="c-bande">
    <span class="cb-num">N° {{ $k['numero'] }}</span>
    <span class="cb-val">Année académique {{ $k['annee'] }}</span>
  </div>
  @endif
</div>
</div>
@if ($modele !== 'badge')
<div class="slot">
<div class="carte verso">
  <div class="v-head">
    <span class="v-inst">{{ $institution }}</span>
    <span class="v-titre">Carte d'étudiant</span>
  </div>
  <div class="v-body">
    <div class="v-left">
      <div>
        <p class="v-mention">Cette carte est strictement personnelle. Elle doit être présentée à toute réquisition des autorités universitaires. En cas de perte ou de vol, prévenir immédiatement la scolarité.</p>
        @if ($contact !== '')<p class="v-contact">{{ $contact }}</p>@endif
      </div>
      <div class="v-sign"><span>Signature du titulaire</span></div>
    </div>
    <div class="v-right">
      <div class="v-qr" data-qr="{{ $k['urlVerification'] }}" data-qr-cellule="2"></div>
      <div class="v-cap">Scanner pour vérifier<br>l'authenticité</div>
    </div>
  </div>
  <div class="v-foot">
    <div class="c-bc-left">
      <div class="c-bc-bars">{!! $k['codeBarres'] !!}</div>
      <div class="c-bc-txt">{{ $k['numero'] }}</div>
    </div>
    <div class="v-valid">Valide pour l'année <strong>{{ $k['annee'] }}</strong><br>Émise le {{ $k['emission'] }}</div>
  </div>
</div>
</div>
@endif
@endforeach
</div>

<script src="{{ asset('assets/js/qrcode.js') }}"></script>
<script src="{{ asset('assets/js/cartes-impression.js') }}"></script>
</body>
</html>
