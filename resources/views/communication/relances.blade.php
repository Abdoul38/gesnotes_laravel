{{-- Relances des impayés — portage de pages/communication/relances.php --}}
@extends('layouts.app', ['titre' => 'Relances des impayés', 'fil' => 'Finance › Relances'])
@inject('navigation', 'App\Navigation\Menu')

@section('contenu')
@php $fcfa = fn ($v) => number_format((float) $v, 0, '.', ' '); @endphp
<div style="display:grid;grid-template-columns:repeat(3,1fr);gap:16px;margin-bottom:20px">
  <div class="card" style="text-align:center;padding:16px">
    <div style="font-size:24px">⏰</div>
    <div style="font-size:20px;font-weight:800;color:var(--red-600)">{{ $retards->count() }}</div>
    <div style="font-size:11px;color:var(--text-muted);text-transform:uppercase">En retard (+{{ $seuil }}j)</div>
  </div>
  <div class="card" style="text-align:center;padding:16px">
    <div style="font-size:24px">💰</div>
    <div style="font-size:20px;font-weight:800;color:var(--red-600)">{{ $fcfa($totalImpaye) }} FCFA</div>
    <div style="font-size:11px;color:var(--text-muted);text-transform:uppercase">Montant impayé total</div>
  </div>
  <div class="card" style="text-align:center;padding:16px">
    <div style="font-size:24px">📭</div>
    <div style="font-size:20px;font-weight:800;color:var(--amber-600)">{{ $nbSansContact }}</div>
    <div style="font-size:11px;color:var(--text-muted);text-transform:uppercase">Sans contact (non relançables)</div>
  </div>
</div>

<div class="card mb-16">
  <div class="card-body" style="display:flex;align-items:center;gap:12px;padding:12px 16px">
    <span style="font-size:13px;font-weight:600">Seuil de retard :</span>
    @foreach ($seuils as $s => $libelle)
    <a href="{{ route('relances', ['seuil' => $s]) }}" class="btn btn-sm {{ $seuil == $s ? 'btn-primary' : 'btn-secondary' }}">{{ $libelle }}</a>
    @endforeach
  </div>
</div>

<form method="post" action="{{ route('relances.envoyer') }}" id="form-relances">
@csrf
<input type="hidden" name="seuil" value="{{ $seuil }}">
<div class="card">
  <div class="card-header">
    <span class="card-title">📨 Étudiants en retard ({{ $retards->count() }})</span>
    @if ($peutRelancer && $retards->isNotEmpty())
    <button type="submit" name="mode" value="groupe" class="btn btn-sm btn-primary" id="btn-relance-groupe">📤 Relancer la sélection</button>
    @endif
  </div>
  <div class="card-body" style="padding:0">
    @if ($retards->isEmpty())
    <div class="empty-state" style="padding:32px"><div class="icon">✅</div><p>Aucun impayé en retard de plus de {{ $seuil }} jours.</p></div>
    @else
    <table class="table" style="font-size:12px">
      <thead>
        <tr>
          @if ($peutRelancer)<th style="width:30px"><input type="checkbox" id="chk-tout" aria-label="Tout sélectionner"></th>@endif
          <th>Étudiant</th><th>Type</th><th style="text-align:right">Dû</th><th style="text-align:right">Payé</th><th style="text-align:right">Reste</th>
          <th style="text-align:center">Retard</th><th>Contact</th><th>Dernière relance</th>
          @if ($peutRelancer)<th>Action</th>@endif
        </tr>
      </thead>
      <tbody>
      @foreach ($retards as $r)
      @php $sansContact = ! $r->email && ! $r->telephone; @endphp
      <tr>
        @if ($peutRelancer)
        <td><input type="checkbox" name="paiement_ids[]" value="{{ $r->id }}" class="chk-relance" @disabled($sansContact) aria-label="{{ $r->nom }} {{ $r->prenoms }}"></td>
        @endif
        <td>
          <a href="{{ $navigation->lien('dossier_etudiant', ['id' => $r->etudiant_id]) }}" style="font-weight:600;text-decoration:none;color:var(--blue-700)">{{ $r->nom }} {{ $r->prenoms }}</a>
          <div style="font-size:10px;color:var(--text-muted)">{{ $r->numero }}</div>
        </td>
        <td>{{ ucfirst($r->type_paiement) }}</td>
        <td style="text-align:right">{{ $fcfa($r->montant_du) }}</td>
        <td style="text-align:right;color:var(--green-700)">{{ $fcfa($r->montant_paye) }}</td>
        <td style="text-align:right;font-weight:700;color:var(--red-600)">{{ $fcfa((float) $r->montant_du - (float) $r->montant_paye) }}</td>
        <td style="text-align:center"><span style="background:var(--red-100);color:var(--red-700);padding:2px 8px;border-radius:8px;font-size:10px;font-weight:700">{{ $r->jours_retard }}j</span></td>
        <td style="font-size:11px">
          @if ($r->email)<div>✉️ {{ $r->email }}</div>@endif
          @if ($r->telephone)<div>📱 {{ $r->telephone }}</div>@endif
          @if ($sansContact)<span style="color:var(--amber-600)">— aucun contact —</span>@endif
        </td>
        <td style="font-size:11px;color:var(--text-muted)">{{ $r->derniere_relance ? date('d/m/Y', strtotime($r->derniere_relance)) : '—' }}</td>
        @if ($peutRelancer)
        <td>
          <button type="submit" name="paiement_id" value="{{ $r->id }}" class="btn btn-sm btn-secondary" @disabled($sansContact)
            title="{{ $sansContact ? 'Aucun contact disponible' : 'Relancer '.$r->nom }}" aria-label="Relancer {{ $r->nom }} {{ $r->prenoms }}">📤</button>
        </td>
        @endif
      </tr>
      @endforeach
      </tbody>
    </table>
    @endif
  </div>
</div>
</form>
@endsection

@push('scripts')
<script src="{{ \App\Support\Ressource::url('assets/js/relances.js') }}"></script>
@endpush
