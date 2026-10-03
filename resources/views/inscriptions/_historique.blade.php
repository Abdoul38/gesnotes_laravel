@if ($inscriptions->isEmpty())
<div style="text-align:center;padding:30px;color:var(--text-muted)">
  <div style="font-size:32px;margin-bottom:8px">📭</div>
  Aucune inscription
</div>
@else
<table style="width:100%;border-collapse:collapse;font-size:13px">
  <thead>
    <tr style="background:var(--primary);color:#fff">
      <th style="padding:10px 14px">Année</th><th style="padding:10px 14px">Niveau</th>
      <th style="padding:10px 14px">Filière</th><th style="padding:10px 14px;text-align:center">Statut</th>
    </tr>
  </thead>
  <tbody>
  @foreach ($inscriptions as $ins)
  <tr style="border-bottom:1px solid var(--border);background:{{ $ins->active ? '#f0fdf4' : '#fff' }}">
    <td style="padding:10px 14px;font-weight:700">
      {{ $ins->annee_lib ?? '—' }}
      @if ($ins->active)<span style="background:#dcfce7;color:#166534;padding:1px 6px;border-radius:8px;font-size:10px;margin-left:4px">En cours</span>@endif
    </td>
    <td style="padding:10px 14px"><span style="background:var(--blue-50);color:var(--blue-700);padding:2px 8px;border-radius:10px;font-size:12px;font-weight:700">{{ $ins->niveau_code ?? '—' }}</span></td>
    <td style="padding:10px 14px;font-size:12px;color:var(--text-muted)">{{ $ins->filiere_lib ?? '—' }}</td>
    <td style="padding:10px 14px;text-align:center">
      <span style="background:{{ $ins->statut === 'inscrit' ? '#dcfce7' : '#fee2e2' }};color:{{ $ins->statut === 'inscrit' ? '#166534' : '#991b1b' }};padding:2px 10px;border-radius:12px;font-size:11px;font-weight:700">{{ ucfirst($ins->statut) }}</span>
    </td>
  </tr>
  @endforeach
  </tbody>
</table>
@endif
