{{-- Notifier un PV — portage de pages/notes_pv/notifier_pv.php --}}
@extends('layouts.app', ['titre' => 'Notifier un PV', 'fil' => 'Communication › Notifications PV'])

@section('contenu')
<div class="card mb-16">
  <div class="card-header"><span class="card-title">📨 Choisir un PV à notifier</span></div>
  <div class="card-body">
    <form method="get" action="{{ route('notifier_pv') }}" style="display:flex;gap:10px;align-items:flex-end">
      <div class="form-group" style="margin:0;flex:1">
        <label class="form-label" for="pv_id">PV</label>
        <select name="pv_id" id="pv_id" class="form-control" data-autosubmit="1">
          <option value="">— Sélectionner —</option>
          @foreach ($pvs as $p)
          <option value="{{ $p->id }}" @selected($pv && $p->id == $pv->id)>{{ $p->annee }} — {{ $p->filiere }} {{ $p->niveau }} {{ $p->semestre }} ({{ $p->type_session === 'rattrapage' ? 'Rattrapage' : 'Normale' }}) — {{ $p->nb_etudiants }} étudiant(s){{ $p->statut === 'publie' ? '' : ' — non publié' }}</option>
          @endforeach
        </select>
      </div>
    </form>
  </div>
</div>

@if ($pv)
<div class="card mb-16">
  <div class="card-header"><span class="card-title">📤 Envoyer les notifications</span></div>
  <div class="card-body">
    @if ($pv->statut !== 'publie')
    <div class="alert alert-warning" style="font-size:13px">⚠️ Ce PV n'est pas publié : les étudiants ne peuvent pas encore voir leurs résultats. Publiez-le avant d'envoyer les notifications.</div>
    @else
    <p style="font-size:13px;color:var(--text-muted);margin-bottom:14px">
      {{ $nbEtudiants }} étudiant(s) concerné(s) par ce PV.
      Un message sera envoyé par email et/ou SMS (selon ce qui est configuré et disponible pour chaque destinataire).
    </p>
    <form method="post" action="{{ route('notifier_pv.envoyer') }}" data-confirm="Envoyer les notifications maintenant ?">
      @csrf
      <input type="hidden" name="pv_id" value="{{ $pv->id }}">
      <div class="form-group">
        <label style="display:block;margin-bottom:6px"><input type="checkbox" name="cibles[]" value="etudiants" checked style="vertical-align:middle"> Notifier les étudiants</label>
        <label style="display:block"><input type="checkbox" name="cibles[]" value="parents" checked style="vertical-align:middle"> Notifier les parents liés</label>
      </div>
      <button type="submit" class="btn btn-primary">📨 Envoyer les notifications</button>
    </form>
    @endif
  </div>
</div>

@if ($historique->isNotEmpty())
@php $badges = ['envoye' => ['#dcfce7', '#166534', 'Envoyé'], 'echec' => ['#fee2e2', '#dc2626', 'Échec'], 'simule' => ['#fef3c7', '#92400e', 'Simulé']]; @endphp
<div class="card">
  <div class="card-header"><span class="card-title">📜 Envois précédents pour ce PV</span></div>
  <div class="card-body" style="padding:0">
    <table class="table" style="font-size:12px">
      <thead><tr><th>Date</th><th>Destinataire</th><th style="text-align:center">Statut</th></tr></thead>
      <tbody>
        @foreach ($historique as $h)
        @php $b = $badges[$h->statut] ?? ['#eee', '#333', $h->statut]; @endphp
        <tr>
          <td style="color:var(--text-muted);white-space:nowrap">{{ date('d/m/Y H:i', strtotime($h->date_envoi)) }}</td>
          <td>{{ $h->destinataire }}</td>
          <td style="text-align:center"><span style="background:{{ $b[0] }};color:{{ $b[1] }};padding:2px 8px;border-radius:8px;font-size:10.5px;font-weight:700">{{ $b[2] }}</span></td>
        </tr>
        @endforeach
      </tbody>
    </table>
  </div>
</div>
@endif
@endif
@endsection
