{{-- Vue d'ensemble des notifications — portage de dashboard_notifications.php --}}
@extends('layouts.app', ['titre' => "Vue d'ensemble Notifications", 'fil' => 'Communication › Tableau de bord'])
@inject('navigation', 'App\Navigation\Menu')

@section('contenu')
@php $libelles = ['pv_publie' => '📋 PV publié', 'paiement_retard' => '💰 Paiement en retard', 'test' => '🧪 Test', 'email_etudiants' => '✉️ Email aux étudiants']; @endphp
<div class="alert alert-info" style="margin-bottom:16px;font-size:12.5px">
  💡 Toutes les statistiques ci-dessous portent sur les <strong>30 derniers jours</strong>, sauf mention contraire.
</div>

<div style="display:grid;grid-template-columns:repeat(4,1fr);gap:16px;margin-bottom:20px">
  @foreach ([['✉️', (int) ($email->ok ?? 0), 'Emails envoyés', false], ['⚠️', (int) ($email->echec ?? 0), 'Emails en échec', true],
             ['📱', (int) ($sms->ok ?? 0), 'SMS envoyés', false], ['⚠️', (int) ($sms->echec ?? 0), 'SMS en échec', true]] as [$ic, $val, $lbl, $echec])
  <div class="card" style="text-align:center;padding:18px">
    <div style="font-size:22px">{{ $ic }}</div>
    <div style="font-size:22px;font-weight:800;color:{{ $echec ? ($val > 0 ? 'var(--red-600)' : 'var(--text-muted)') : 'var(--green-700)' }}">{{ $val }}</div>
    <div style="font-size:11px;color:var(--text-muted);text-transform:uppercase">{{ $lbl }}</div>
  </div>
  @endforeach
</div>

<div style="display:grid;grid-template-columns:1fr 1fr;gap:16px;margin-bottom:16px">
  <div class="card">
    <div class="card-header"><span class="card-title">📊 Par type de notification</span></div>
    <div class="card-body" style="padding:0">
      @if ($parContexte->isEmpty())
      <div style="padding:24px;text-align:center;color:var(--text-muted);font-size:13px">Aucune notification envoyée sur cette période.</div>
      @else
      <table class="table" style="font-size:12.5px">
        <thead><tr><th>Type</th><th style="text-align:center">Total</th><th style="text-align:center">Réussis</th></tr></thead>
        <tbody>
        @foreach ($parContexte as $c)
        <tr><td>{{ $libelles[$c->contexte] ?? $c->contexte }}</td><td style="text-align:center">{{ (int) $c->nb }}</td><td style="text-align:center;color:var(--green-700);font-weight:700">{{ (int) $c->nb_ok }}</td></tr>
        @endforeach
        </tbody>
      </table>
      @endif
    </div>
  </div>

  <div class="card">
    <div class="card-header"><span class="card-title">📅 Présence par filière</span></div>
    <div class="card-body" style="padding:0">
      @if ($presences->isEmpty())
      <div style="padding:24px;text-align:center;color:var(--text-muted);font-size:13px">Aucune présence enregistrée sur cette période.</div>
      @else
      <table class="table" style="font-size:12.5px">
        <thead><tr><th>Filière</th><th style="text-align:center">Taux présence</th><th style="text-align:center">Absences</th></tr></thead>
        <tbody>
        @foreach ($presences as $p)
        @php $taux = $p->nb_total > 0 ? round($p->nb_present / $p->nb_total * 100) : 0; @endphp
        <tr>
          <td>{{ $p->filiere }}</td>
          <td style="text-align:center"><span style="background:{{ $taux >= 80 ? '#dcfce7' : ($taux >= 60 ? '#fef3c7' : '#fee2e2') }};color:{{ $taux >= 80 ? '#166534' : ($taux >= 60 ? '#92400e' : '#dc2626') }};padding:2px 10px;border-radius:10px;font-weight:700">{{ $taux }}%</span></td>
          <td style="text-align:center;color:var(--red-600)">{{ (int) $p->nb_absent }}</td>
        </tr>
        @endforeach
        </tbody>
      </table>
      @endif
    </div>
  </div>
</div>

<div class="card mb-16">
  <div class="card-header">
    <span class="card-title">🔒 Parents n'ayant jamais ouvert leur portail</span>
    <span style="font-size:12px;color:var(--text-muted)">{{ $nbParentsJamais }} / {{ $nbParentsActifs }} comptes actifs</span>
  </div>
  <div class="card-body" style="padding:0">
    @if ($parents->isEmpty())
    <div style="padding:24px;text-align:center;color:var(--green-700);font-size:13px">✅ Tous les parents actifs se sont déjà connectés au moins une fois.</div>
    @else
    <table class="table" style="font-size:12.5px">
      <thead><tr><th>Parent</th><th>Contact</th><th style="text-align:center">Enfants liés</th><th>Compte créé le</th></tr></thead>
      <tbody>
      @foreach ($parents as $p)
      <tr>
        <td style="font-weight:600">{{ $p->prenoms }} {{ $p->nom }}</td>
        <td>{{ $p->telephone }}{{ $p->email ? ' · '.$p->email : '' }}</td>
        <td style="text-align:center">{{ (int) $p->nb_enfants }}</td>
        <td style="color:var(--text-muted)">{{ date('d/m/Y', strtotime($p->created_at)) }}</td>
      </tr>
      @endforeach
      </tbody>
    </table>
    @if ($parents->count() >= 50)<div style="padding:10px 14px;font-size:11px;color:var(--text-muted)">Liste limitée aux 50 premiers.</div>@endif
    @endif
  </div>
</div>

<div class="card">
  <div class="card-header">
    <span class="card-title">📭 Étudiants n'ayant jamais reçu de notification</span>
    <span style="font-size:12px;color:var(--text-muted)">{{ $jamaisNotifies->count() }}{{ $jamaisNotifies->count() >= 50 ? '+' : '' }} / {{ $nbInscrits }} inscrits</span>
  </div>
  <div class="card-body" style="padding:0">
    @if ($jamaisNotifies->isEmpty())
    <div style="padding:24px;text-align:center;color:var(--green-700);font-size:13px">✅ Tous les étudiants inscrits ont reçu au moins une notification.</div>
    @else
    <table class="table" style="font-size:12.5px">
      <thead><tr><th>Étudiant</th><th>Filière / Niveau</th><th>Contact disponible</th></tr></thead>
      <tbody>
      @foreach ($jamaisNotifies as $e)
      <tr>
        <td>
          <a href="{{ $navigation->lien('dossier_etudiant', ['id' => $e->id]) }}" style="font-weight:600;text-decoration:none;color:var(--blue-700)">{{ $e->nom }} {{ $e->prenoms }}</a>
          <div style="font-size:10px;color:var(--text-muted)">{{ $e->numero }}</div>
        </td>
        <td>{{ $e->promotion ?? '—' }}</td>
        <td>
          @if (! $e->email && ! $e->telephone)<span style="color:var(--amber-600)">— aucun contact renseigné —</span>
          @else{{ $e->email ? '✉️ '.$e->email : '' }} {{ $e->telephone ? '📱 '.$e->telephone : '' }}@endif
        </td>
      </tr>
      @endforeach
      </tbody>
    </table>
    @if ($jamaisNotifies->count() >= 50)<div style="padding:10px 14px;font-size:11px;color:var(--text-muted)">Liste limitée aux 50 premiers.</div>@endif
    @endif
  </div>
</div>
@endsection
