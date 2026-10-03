{{-- Litiges KomiPay — portage de pages/finances/komipay_litiges.php --}}
@extends('layouts.app', ['titre' => 'Litiges KomiPay', 'fil' => 'Finance › Litiges KomiPay'])

@section('contenu')
@php $fcfa = fn ($v) => number_format((float) $v, 0, '.', ' ').' F'; @endphp
<div style="display:grid;grid-template-columns:repeat(auto-fit,minmax(200px,1fr));gap:16px;margin-bottom:24px">
  <div class="card"><div class="card-body" style="text-align:center"><div style="font-size:28px;font-weight:800;color:#dc2626">{{ $litiges->count() }}</div><div class="text-sm text-muted">Paiements en litige</div></div></div>
  <div class="card"><div class="card-body" style="text-align:center"><div style="font-size:22px;font-weight:800;color:#d97706">{{ $fcfa($montant) }}</div><div class="text-sm text-muted">Montant concerné</div></div></div>
  <div class="card"><div class="card-body" style="text-align:center"><div style="font-size:16px;font-weight:600;color:#6b7280">⚠️ Action requise</div><div class="text-sm text-muted">Vérifier avec KomiPay avant de confirmer</div></div></div>
</div>
<div class="card">
  <div class="card-header"><span class="card-title">⚠️ Paiements en ligne sans confirmation (&gt; 30 min) ou payés mais non finalisés</span></div>
  @if ($litiges->isEmpty())
  <div class="card-body"><div class="empty-state"><div class="icon">✅</div>Aucun litige détecté.</div></div>
  @else
  <div style="overflow-x:auto"><table class="table">
    <thead><tr><th>Date</th><th>Étudiant</th><th>Moyen</th><th>Référence KomiPay</th><th>Montant</th><th>Statut</th><th>Âge</th><th>Actions</th></tr></thead>
    <tbody>
    @foreach ($litiges as $p)
    @php $total = app(\App\Services\InscriptionEnLigne::class)->montant((float) $p->montant)['total']; @endphp
    <tr>
      <td style="white-space:nowrap">{{ date('d/m/Y H:i', strtotime($p->created_at)) }}</td>
      <td><div style="font-weight:600">{{ $p->nom }} {{ $p->prenoms }}</div><div class="text-sm text-muted">{{ $p->numero ?: $p->numero_dossier }}</div></td>
      <td>{{ \App\Services\InscriptionEnLigne::MOYENS[$p->moyen_paiement] ?? $p->moyen_paiement }}</td>
      <td><code style="font-size:11px">{{ $p->reference_paiement }}</code></td>
      <td><strong>{{ $fcfa($total) }}</strong></td>
      <td><span class="badge {{ $p->statut_paiement === 'valide' ? 'badge-green' : ($p->statut_paiement === 'echec' ? 'badge-red' : 'badge-amber') }}">{{ $p->statut_paiement === 'valide' ? 'Payé, non finalisé' : ucfirst(str_replace('_', ' ', $p->statut_paiement)) }}</span></td>
      <td><span style="color:{{ $p->age_minutes > 120 ? '#dc2626' : '#d97706' }};font-weight:600">{{ $p->age_minutes < 60 ? $p->age_minutes.' min' : round($p->age_minutes / 60, 1).' h' }}</span></td>
      <td>
        <div style="display:flex;gap:6px;flex-wrap:wrap">
          <form method="post" action="{{ route('komipay_litiges.reverifier') }}">@csrf<input type="hidden" name="paiement_id" value="{{ $p->id }}"><button type="submit" class="btn btn-sm btn-secondary" title="Revérifier auprès de KomiPay">🔄 Vérifier</button></form>
          <form method="post" action="{{ route('komipay_litiges.confirmer') }}" data-confirm="Confirmer manuellement ce paiement de {{ $fcfa($total) }} et finaliser l'inscription ? Assurez-vous d'avoir vérifié avec KomiPay que le débit a bien eu lieu.">@csrf<input type="hidden" name="paiement_id" value="{{ $p->id }}"><button type="submit" class="btn btn-sm btn-success">✅ Confirmer</button></form>
          @if ($p->statut !== 'complete')
          <form method="post" action="{{ route('komipay_litiges.rejeter') }}" data-confirm="Rejeter ce paiement ? Il sera marqué comme remboursé et l'inscription annulée.">@csrf<input type="hidden" name="paiement_id" value="{{ $p->id }}"><button type="submit" class="btn btn-sm btn-danger">❌ Rejeter</button></form>
          @endif
        </div>
      </td>
    </tr>
    @endforeach
    </tbody>
  </table></div>
  @endif
</div>
@endsection
