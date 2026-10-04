{{-- Journal d'activité — portage de pages/admin/logs.php --}}
@extends('layouts.app', ['titre' => "Logs d'activité", 'fil' => 'Système › Logs'])

@use('App\Http\Controllers\Administration\JournauxController', 'JC')

@section('contenu')
<div style="display:grid;grid-template-columns:repeat(auto-fit,minmax(160px,1fr));gap:16px;margin-bottom:24px">
  @foreach ($stats as [$lbl, $ic, $val, $txt, $bg])
  <div style="background:{{ $bg }};border-radius:12px;padding:16px;text-align:center">
    <div style="font-size:24px;margin-bottom:4px">{{ $ic }}</div>
    <div style="font-size:24px;font-weight:800;color:{{ $txt }}">{{ number_format($val, 0, ',', ' ') }}</div>
    <div style="font-size:11px;color:{{ $txt }};opacity:.8">{{ $lbl }}</div>
  </div>
  @endforeach
</div>

<div class="card" style="margin-bottom:16px"><div class="card-body" style="padding:14px 16px">
  <form method="get" action="{{ route('logs') }}" style="display:flex;gap:10px;flex-wrap:wrap;align-items:flex-end">
    <div><label for="f-action" style="font-size:11px;font-weight:600;color:var(--text-muted);display:block;margin-bottom:3px">Action</label>
      <select name="action" id="f-action" class="form-control form-control-sm" style="width:160px"><option value="">Toutes</option>
        @foreach ($actions as $a)<option value="{{ $a }}" @selected($filtres['action'] === $a)>{{ $a }}</option>@endforeach</select></div>
    <div><label for="f-module" style="font-size:11px;font-weight:600;color:var(--text-muted);display:block;margin-bottom:3px">Module</label>
      <select name="module" id="f-module" class="form-control form-control-sm" style="width:160px"><option value="">Tous</option>
        @foreach ($modules as $m)<option value="{{ $m }}" @selected($filtres['module'] === $m)>{{ $m }}</option>@endforeach</select></div>
    <div><label for="f-user" style="font-size:11px;font-weight:600;color:var(--text-muted);display:block;margin-bottom:3px">Utilisateur</label>
      <input type="text" name="user" id="f-user" class="form-control form-control-sm" value="{{ $filtres['user'] }}" placeholder="Nom ou ID..." style="width:140px"></div>
    <div><label for="f-date" style="font-size:11px;font-weight:600;color:var(--text-muted);display:block;margin-bottom:3px">Date</label>
      <input type="date" name="date" id="f-date" class="form-control form-control-sm" value="{{ $filtres['date'] }}" style="width:140px"></div>
    <button type="submit" class="btn btn-primary btn-sm">🔍 Filtrer</button>
    <a href="{{ route('logs') }}" class="btn btn-secondary btn-sm">↺ Réinitialiser</a>
  </form>
</div></div>

<div class="card">
  <div class="card-header"><span class="card-title">📋 Journal des activités</span><span style="font-size:12px;color:var(--text-muted)">{{ number_format($pagination['total'], 0, ',', ' ') }} entrée(s)</span></div>
  <div class="card-body" style="padding:0;overflow-x:auto">
    @if ($journaux->isEmpty())
    <div style="text-align:center;padding:50px;color:var(--text-muted)"><div style="font-size:48px;margin-bottom:12px">📭</div>Aucun log trouvé pour ces critères</div>
    @else
    <table class="table" style="font-size:12px">
      <thead><tr><th style="width:140px">Date / Heure</th><th style="width:130px">Utilisateur</th><th style="width:160px">Action</th><th style="width:110px">Module</th><th>Description</th><th style="width:100px">IP</th></tr></thead>
      <tbody>
      @foreach ($journaux as $j)
      @php [$ic, $fg, $bg] = JC::STYLES[$j->action] ?? ['📌', '#374151', '#f3f4f6']; $d = \Illuminate\Support\Carbon::parse($j->created_at); @endphp
      <tr>
        <td style="color:var(--text-muted);font-family:monospace;font-size:11px">{{ $d->format('d/m/Y') }}<br><strong>{{ $d->format('H:i:s') }}</strong></td>
        <td><div style="font-weight:600;color:var(--dark)">{{ $j->user_nom ?: 'Système' }}</div><div style="font-size:10px;color:var(--text-muted)">{{ $j->user_role ?? '—' }}</div></td>
        <td style="text-align:center"><span style="background:{{ $bg }};color:{{ $fg }};padding:3px 10px;border-radius:10px;font-size:11px;font-weight:700;white-space:nowrap">{{ $ic }} {{ $j->action }}</span></td>
        <td style="text-align:center"><span style="background:#f3f4f6;color:#374151;padding:2px 8px;border-radius:8px;font-size:11px">{{ $j->module ?? '—' }}</span>
          @if ($j->cible_id)<div style="font-size:10px;color:var(--text-muted);margin-top:2px">#{{ (int) $j->cible_id }}</div>@endif</td>
        <td style="color:#374151;max-width:320px;word-wrap:break-word;white-space:normal">{{ $j->description ?? '—' }}</td>
        <td style="font-family:monospace;font-size:11px;color:var(--text-muted)">{{ $j->ip_address ?? '—' }}</td>
      </tr>
      @endforeach
      </tbody>
    </table>
    @if ($pagination['totalPages'] > 1)
    <div style="display:flex;justify-content:center;gap:6px;padding:16px;flex-wrap:wrap">
      @for ($p = max(1, $pagination['page'] - 3); $p <= min($pagination['totalPages'], $pagination['page'] + 3); $p++)
      <a href="{{ request()->fullUrlWithQuery(['p' => $p]) }}" style="padding:6px 14px;border-radius:8px;font-size:13px;text-decoration:none;font-weight:{{ $p === $pagination['page'] ? '800' : '400' }};background:{{ $p === $pagination['page'] ? '#5b21b6' : '#f3f4f6' }};color:{{ $p === $pagination['page'] ? '#fff' : '#374151' }}">{{ $p }}</a>
      @endfor
    </div>
    @endif
    @endif
  </div>
</div>
@endsection
