{{-- Journal des 20 derniers envois (e-mail ou SMS) — repris de parametres_email.php / parametres_sms.php --}}
@php $badges = ['envoye' => ['#dcfce7', '#166534', 'Envoyé'], 'echec' => ['#fee2e2', '#dc2626', 'Échec'], 'simule' => ['#fef3c7', '#92400e', 'Simulé']]; @endphp
<div class="card mt-16">
  <div class="card-header"><span class="card-title">📜 Journal des 20 derniers envois</span></div>
  <div class="card-body" style="padding:0">
    @if ($logs->isEmpty())
    <div style="padding:30px;text-align:center;color:var(--text-muted)">Aucun envoi pour l'instant.</div>
    @else
    <table class="table" style="font-size:12px">
      <thead><tr><th>Date</th><th>Destinataire</th><th>{{ $sms ? 'Message' : 'Sujet' }}</th><th style="text-align:center">Contexte</th><th style="text-align:center">Statut</th></tr></thead>
      <tbody>
        @foreach ($logs as $l)
        @php $b = $badges[$l->statut] ?? ['#eee', '#333', $l->statut]; @endphp
        <tr>
          <td style="color:var(--text-muted);white-space:nowrap">{{ date('d/m/Y H:i', strtotime($l->date_envoi)) }}</td>
          <td>{{ $sms ? $l->numero : $l->destinataire }}</td>
          <td style="max-width:250px;overflow:hidden;text-overflow:ellipsis;white-space:nowrap">{{ $sms ? $l->message : $l->sujet }}</td>
          <td style="text-align:center">{{ $l->contexte ?: '—' }}</td>
          <td style="text-align:center">
            <span style="background:{{ $b[0] }};color:{{ $b[1] }};padding:2px 8px;border-radius:8px;font-size:10.5px;font-weight:700">{{ $b[2] }}</span>
          </td>
        </tr>
        @endforeach
      </tbody>
    </table>
    @endif
  </div>
</div>
