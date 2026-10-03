{{-- Tableau de bord du candidat — portage de candidature/pages/dashboard.php --}}
@extends('layouts.candidature', ['titre' => 'Tableau de bord', 'actif' => 'tableau'])

@section('contenu')
@php $inscription = Route::has('candidature.inscription'); @endphp
<div class="pheader">
  <div class="pheader-top">
    <div>
      <h1>Bonjour, {{ $candidat->prenom }} 👋</h1>
      <p>Année académique {{ $annee }} — Votre espace de préinscription</p>
    </div>
    @if ($brouillon)
    <a href="{{ route('candidature.depot', ['id' => $brouillon->id, 'etape' => max(1, (int) $brouillon->etape_actuelle)]) }}" class="pbtn pbtn-primary pbtn-lg">📤 Continuer mon dossier</a>
    @elseif ($ouvert)
    <form method="post" action="{{ route('candidature.nouveau') }}">@csrf<button type="submit" class="pbtn pbtn-primary pbtn-lg">📤 Nouveau dossier</button></form>
    @else
    <span class="pbtn pbtn-primary pbtn-lg" style="opacity:.5;cursor:not-allowed">🔒 Dépôt fermé</span>
    @endif
  </div>
</div>

@if ($accepte && $accepte->numero_depot && ! $matricule)
<div style="background:linear-gradient(135deg,#16a34a,#15803d);color:#fff;border-radius:14px;padding:20px 24px;margin-bottom:24px;display:flex;justify-content:space-between;align-items:center;flex-wrap:wrap;gap:12px">
  <div>
    <div style="font-size:16px;font-weight:800;margin-bottom:4px">🎉 Votre dossier a été approuvé !</div>
    <div style="font-size:13px;opacity:.9">Votre N° dépôt : <strong>{{ $accepte->numero_depot }}</strong> — Vous pouvez maintenant vous inscrire académiquement.</div>
  </div>
  @if ($inscription)<a href="{{ route('candidature.inscription') }}" class="pbtn pbtn-lg" style="background:#fff;color:#16a34a;font-weight:700">🎓 S'inscrire maintenant</a>@endif
</div>
@elseif ($matricule)
<div style="background:linear-gradient(135deg,#1d4ed8,#2563eb);color:#fff;border-radius:14px;padding:20px 24px;margin-bottom:24px">
  <div style="font-size:15px;font-weight:800;margin-bottom:4px">✅ Vous êtes inscrit !</div>
  <div style="font-size:13px;opacity:.9">Matricule : <strong style="font-size:18px;letter-spacing:1px">{{ $matricule }}</strong></div>
</div>
@endif

<div class="action-grid">
  @if ($brouillon)
  <a href="{{ route('candidature.depot', ['id' => $brouillon->id, 'etape' => max(1, (int) $brouillon->etape_actuelle)]) }}" class="action-card highlight">
    <div class="action-icon">📤</div><div class="action-title">Continuer mon dossier</div>
    <div class="action-desc">{{ $brouillon->statut === 'incomplet' ? 'Votre dossier est incomplet : complétez-le et soumettez-le à nouveau.' : 'Reprenez là où vous vous êtes arrêté.' }}</div>
    <span class="action-badge badge-or">🔄 En cours</span>
  </a>
  @elseif ($ouvert)
  <form method="post" action="{{ route('candidature.nouveau') }}" class="action-card" style="cursor:pointer">
    @csrf
    <button type="submit" style="all:unset;cursor:pointer;display:block">
      <div class="action-icon">📤</div><div class="action-title">Nouveau dossier</div>
      <div class="action-desc">Commencez une nouvelle demande de préinscription.</div>
      <span class="action-badge badge-vert">➕ Nouveau</span>
    </button>
  </form>
  @else
  <div class="action-card locked">
    <div class="action-icon">🔒</div><div class="action-title">Dépôt fermé</div>
    <div class="action-desc">Le dépôt de dossiers est actuellement fermé. Revenez plus tard.</div>
    <span class="action-badge badge-gris">🔒 Indisponible</span>
  </div>
  @endif
  <a href="{{ route('candidature.dossiers') }}" class="action-card">
    <div class="action-icon">📋</div><div class="action-title">Mes dossiers</div>
    <div class="action-desc">Consultez l'état de vos dossiers et téléchargez vos documents.</div>
    <span class="action-badge badge-bleu">👁️ {{ $dossiers->count() }} dossier(s)</span>
  </a>
  @if ($accepte && $accepte->numero_depot && ! $matricule && $inscription)
  <a href="{{ route('candidature.inscription') }}" class="action-card highlight">
    <div class="action-icon">🎓</div><div class="action-title">Inscription académique</div>
    <div class="action-desc">Votre dossier est approuvé. Finalisez votre inscription.</div>
    <span class="action-badge badge-vert">✅ Disponible</span>
  </a>
  @elseif ($matricule)
  <div class="action-card locked">
    <div class="action-icon">🎓</div><div class="action-title">Inscrit</div>
    <div class="action-desc">Matricule : <strong>{{ $matricule }}</strong></div>
    <span class="action-badge badge-vert">✅ Validé</span>
  </div>
  @else
  <a href="{{ route('candidature.profil') }}" class="action-card">
    <div class="action-icon">👤</div><div class="action-title">Mon profil</div>
    <div class="action-desc">Modifiez vos informations personnelles et mot de passe.</div>
    <span class="action-badge badge-gris">⚙️ Paramètres</span>
  </a>
  @endif
</div>
@endsection
