{{-- Paiement par carte bancaire — portage de candidature/pages/inscription_carte.php (données de carte jamais conservées) --}}
@php $fcfa = fn ($v) => number_format((float) $v, 0, '', ' ').' FCFA'; @endphp
<!DOCTYPE html>
<html lang="fr">
<head>
<meta charset="UTF-8"><meta name="viewport" content="width=device-width,initial-scale=1">
<title>Paiement par carte — {{ $sigle }}</title>
<link rel="stylesheet" href="{{ asset('assets/candidature/inscription.css') }}">
</head>
<body>
<nav class="navbar"><div class="navbar-brand"><span class="brand-title">🎓 {{ $universite->nom }} — Paiement sécurisé</span></div></nav>
<div class="container" style="max-width:520px">
  @if (session('erreur'))<div class="alert alert-error" role="alert">{{ session('erreur') }}</div>@endif
  <div class="recap-bloc">
    <div class="recap-row"><span>Filière</span><span>{{ $ins->filiere_lib ?? '—' }}</span></div>
    <div class="recap-row"><span>Total à payer</span><span style="font-weight:800;color:#16a34a">{{ $fcfa($montant['total']) }}</span></div>
  </div>
  <div class="card"><div class="card-body">
    <div class="section-title">💳 Carte Visa / Mastercard</div>
    <div class="alert alert-info" style="font-size:12px">🔒 Les données de votre carte sont transmises directement à l'agrégateur de paiement et ne sont jamais enregistrées.</div>
    <form method="post" action="{{ route('candidature.inscription.carte.payer', ['id' => $ins->id]) }}" autocomplete="off" id="form-carte">
      @csrf
      <input type="hidden" name="screenHeight" value=""><input type="hidden" name="screenWidth" value=""><input type="hidden" name="TZ" value=""><input type="hidden" name="javaEnabled" value="0">
      <div class="form-group"><label class="form-label" for="nom_titulaire">Nom du titulaire <span class="req">*</span></label><input type="text" name="nom_titulaire" id="nom_titulaire" class="form-control" required maxlength="100" autocomplete="cc-name"></div>
      <div class="form-group"><label class="form-label" for="numero_carte">Numéro de carte <span class="req">*</span></label><input type="text" name="numero_carte" id="numero_carte" class="form-control" required inputmode="numeric" maxlength="19" autocomplete="cc-number" placeholder="xxxx xxxx xxxx xxxx"></div>
      <div style="display:grid;grid-template-columns:1fr 1fr;gap:10px">
        <div class="form-group"><label class="form-label" for="expiration">Expiration (MM/AA) <span class="req">*</span></label><input type="text" name="expiration" id="expiration" class="form-control" required maxlength="5" autocomplete="cc-exp" placeholder="MM/AA"></div>
        <div class="form-group"><label class="form-label" for="cvv">CVV <span class="req">*</span></label><input type="password" name="cvv" id="cvv" class="form-control" required inputmode="numeric" maxlength="3" autocomplete="cc-csc"></div>
      </div>
      <button type="submit" class="btn btn-primary btn-lg" style="width:100%;justify-content:center">💳 Payer {{ $fcfa($montant['total']) }}</button>
    </form>
    <a href="{{ route('candidature.inscription.etape', ['id' => $ins->id, 'etape' => 3]) }}" style="display:block;margin-top:12px;color:#6b7280;font-size:12px;text-align:center">← Changer de mode de paiement</a>
  </div></div>
</div>
<script src="{{ \App\Support\Ressource::url('assets/js/inscription-en-ligne.js') }}"></script>
</body>
</html>
