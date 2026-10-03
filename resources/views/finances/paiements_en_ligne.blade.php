{{-- Paiements en ligne — portage de paiements_en_ligne.php --}}
@extends('layouts.app', ['titre' => 'Paiements en ligne', 'fil' => 'Scolarité › Paiements en ligne'])

@section('contenu')
@php
    use App\Http\Controllers\Finances\PaiementsEnLigneController as C;
    $fcfa = fn ($v) => number_format((float) $v, 0, '', ' ');
@endphp
<div style="display:grid;grid-template-columns:repeat(5,1fr);gap:14px;margin-bottom:20px">
  <div style="background:#f0fdf4;border:1px solid #86efac;border-radius:12px;padding:16px">
    <div style="font-size:11px;color:#166534;font-weight:600;margin-bottom:6px">💰 TOTAL VALIDÉ (frais d'inscription)</div>
    <div style="font-size:22px;font-weight:800;color:#16a34a">{{ $fcfa($totalBase) }} FCFA</div>
  </div>
  <div style="background:#eff6ff;border:1px solid #bfdbfe;border-radius:12px;padding:16px">
    <div style="font-size:11px;color:#1e40af;font-weight:600;margin-bottom:6px">💳 TOTAL AVEC FRAIS DE TRANSACTION ({{ C::TAUX_FRAIS * 100 }}%)</div>
    <div style="font-size:22px;font-weight:800;color:#1e40af">{{ $fcfa($totalBase + $totalFrais) }} FCFA</div>
    <div style="font-size:10px;color:#6b7280;margin-top:2px">dont {{ $fcfa($totalFrais) }} FCFA de frais</div>
  </div>
  <div style="background:#fef3c7;border:1px solid #fde68a;border-radius:12px;padding:16px">
    <div style="font-size:11px;color:#92400e;font-weight:600;margin-bottom:6px">⏳ EN ATTENTE</div>
    <div style="font-size:22px;font-weight:800;color:#92400e">{{ $nbAttente }}</div>
  </div>
  <div style="background:#f3f4f6;border:1px solid #d1d5db;border-radius:12px;padding:16px">
    <div style="font-size:11px;color:#374151;font-weight:600;margin-bottom:6px">⏰ EXPIRÉS (+{{ C::DELAI_EXPIRATION_MIN }} min)</div>
    <div style="font-size:22px;font-weight:800;color:#4b5563">{{ $nbExpire }}</div>
  </div>
  <div style="background:#fee2e2;border:1px solid #fca5a5;border-radius:12px;padding:16px">
    <div style="font-size:11px;color:#991b1b;font-weight:600;margin-bottom:6px">❌ ÉCHECS</div>
    <div style="font-size:22px;font-weight:800;color:#991b1b">{{ $nbEchec }}</div>
  </div>
</div>

<div class="card mb-16">
  <div class="card-body" style="padding:14px 16px">
    <form method="get" action="{{ route('paiements_en_ligne') }}" class="flex gap-8" style="flex-wrap:wrap;align-items:flex-end">
      <div style="flex:2;min-width:220px">
        <label class="form-label" for="q" style="font-size:11px;margin-bottom:3px">Recherche</label>
        <input type="text" name="q" id="q" value="{{ $filtres['q'] }}" class="form-control form-control-sm" placeholder="Nom, matricule, N° dossier, référence, téléphone...">
      </div>
      <div style="min-width:140px">
        <label class="form-label" for="statut" style="font-size:11px;margin-bottom:3px">Statut</label>
        <select name="statut" id="statut" class="form-control form-control-sm">
          <option value="">Tous</option>
          @foreach (C::STATUTS as $k => [$libelle])<option value="{{ $k }}" @selected($filtres['statut'] === $k)>{{ $libelle }}</option>@endforeach
        </select>
      </div>
      <div style="min-width:160px">
        <label class="form-label" for="moyen" style="font-size:11px;margin-bottom:3px">Moyen de paiement</label>
        <select name="moyen" id="moyen" class="form-control form-control-sm">
          <option value="">Tous</option>
          @foreach (C::MOYENS as $k => $libelle)<option value="{{ $k }}" @selected($filtres['moyen'] === $k)>{{ $libelle }}</option>@endforeach
        </select>
      </div>
      <div style="min-width:140px">
        <label class="form-label" for="date" style="font-size:11px;margin-bottom:3px">Date</label>
        <input type="date" name="date" id="date" value="{{ $filtres['date'] }}" class="form-control form-control-sm">
      </div>
      <button type="submit" class="btn btn-secondary btn-sm">🔍 Filtrer</button>
      <a href="{{ route('paiements_en_ligne') }}" class="btn btn-secondary btn-sm" aria-label="Réinitialiser">↺</a>
    </form>
  </div>
</div>

<div class="card">
  <div class="card-header">
    <span class="card-title">📋 Paiements initiés</span>
    <span style="font-size:12px;color:var(--text-muted)">{{ $total }} résultat(s)</span>
  </div>
  <div class="table-wrap">
    <table class="table">
      <thead><tr><th>Étudiant / Candidat</th><th>Filière / Niveau</th><th>Moyen</th><th>Référence</th><th style="text-align:right">Montant</th><th>Statut</th><th>Date</th></tr></thead>
      <tbody>
      @forelse ($paiements as $p)
      @php
          $etat = $p->statut_paiement === 'en_attente' && $p->created_at < $limite ? 'expire' : $p->statut_paiement;
          [$libelle, $fond, $couleur] = C::STATUTS[$etat] ?? ['—', '#f3f4f6', '#374151'];
          $nom = trim(($p->nom ?? '').' '.($p->prenoms ?? ''));
      @endphp
      <tr>
        <td>
          @if ($nom !== '')
          <strong>{{ $nom }}</strong><br><span class="text-xs text-muted">{{ $p->matricule ?: 'Dossier '.$p->numero_dossier }}</span>
          @else
          <span class="text-xs text-muted">N° dossier : {{ $p->numero_dossier ?: '—' }}</span>
          @endif
        </td>
        <td><span class="badge badge-blue">{{ $p->niveau ?? '—' }}</span><br><span class="text-xs text-muted">{{ mb_substr($p->filiere ?? '—', 0, 28) }}</span></td>
        <td>{{ C::MOYENS[$p->moyen_paiement] ?? ($p->moyen_paiement ?? '—') }}
          @if ($p->telephone_paiement)<div class="text-xs text-muted">{{ $p->telephone_paiement }}</div>@endif
        </td>
        <td>
          <span style="font-family:monospace;font-size:11px">{{ $p->reference_paiement ?: '—' }}</span>
          @if ($p->code_achat_komipay)<div class="text-xs text-muted">Code: {{ $p->code_achat_komipay }}</div>@endif
        </td>
        <td style="text-align:right">
          <strong>{{ $fcfa($p->montant) }} FCFA</strong>
          <div class="text-xs text-muted">+{{ $fcfa(round((float) $p->montant * C::TAUX_FRAIS)) }} frais</div>
        </td>
        <td><span style="background:{{ $fond }};color:{{ $couleur }};padding:3px 10px;border-radius:10px;font-size:11px;font-weight:700">{{ $libelle }}</span></td>
        <td>
          @if ($p->date_paiement)<span class="text-xs">Payé : {{ date('d/m/Y H:i', strtotime($p->date_paiement)) }}</span>
          @else<span class="text-xs text-muted">Non payé</span>@endif
        </td>
      </tr>
      @empty
      <tr><td colspan="7"><div class="empty-state"><div class="icon">💳</div>Aucun paiement trouvé.</div></td></tr>
      @endforelse
      </tbody>
    </table>
  </div>
  @if ($pagination['totalPages'] > 1)
  <div class="card-footer">
    <div class="pagination">
      @for ($i = 1; $i <= $pagination['totalPages']; $i++)
      <a href="{{ request()->fullUrlWithQuery(['p' => $i]) }}" class="page-btn {{ $i == $pagination['page'] ? 'active' : '' }}">{{ $i }}</a>
      @endfor
    </div>
    <span class="text-muted text-sm" style="margin-left:auto">Page {{ $pagination['page'] }}/{{ $pagination['totalPages'] }} · {{ $total }} paiement(s)</span>
  </div>
  @endif
</div>
@endsection
