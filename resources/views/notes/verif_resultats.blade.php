{{-- Vérification de cohérence des résultats — portage de verif_resultats.php --}}
@extends('layouts.app', ['titre' => 'Vérification résultats', 'fil' => 'Outils › Vérification cohérence'])

@section('contenu')
<div class="card mb-16">
  <div class="card-header"><span class="card-title">🔍 Vérifier la cohérence d'un PV</span></div>
  <div class="card-body">
    <div style="display:flex;gap:12px;align-items:flex-end;flex-wrap:wrap">
      <form method="get" action="{{ route('verif_resultats') }}" style="display:flex;gap:12px;align-items:flex-end;flex-wrap:wrap">
        <div class="form-group" style="margin:0;min-width:350px">
          <label class="form-label" for="pv_id">Sélectionner un PV</label>
          <select name="pv_id" id="pv_id" class="form-control" required>
            <option value="">-- Choisir --</option>
            @foreach ($pvs as $p)
            <option value="{{ $p->id }}" @selected($pv && $p->id == $pv->id)>{{ $p->annee }} — {{ $p->filiere }} {{ $p->niveau }} {{ $p->sem }} ({{ \App\Support\Format::session($p->type_session) }})</option>
            @endforeach
          </select>
        </div>
        <button type="submit" class="btn btn-primary">🔍 Vérifier</button>
      </form>
      @if ($pv)
      <form method="post" action="{{ route('verif_resultats.corriger') }}" data-confirm="Recalculer et corriger TOUS les résultats de ce PV ?">
        @csrf
        <input type="hidden" name="pv_id" value="{{ $pv->id }}">
        <button type="submit" class="btn btn-warning" @disabled($pv->verrouille) @if ($pv->verrouille) title="PV verrouillé" @endif>🔧 Corriger automatiquement</button>
      </form>
      @endif
    </div>
  </div>
</div>

@if ($pv)
@php $nbDiff = count($differences); @endphp
<div style="display:grid;grid-template-columns:repeat(3,1fr);gap:16px;margin-bottom:20px">
  <div class="card" style="text-align:center;padding:20px">
    <div style="font-size:36px;font-weight:800;color:var(--green-700)">{{ $identiques }}</div>
    <div style="font-size:12px;color:var(--text-muted)">✅ Résultats identiques</div>
  </div>
  <div class="card" style="text-align:center;padding:20px">
    <div style="font-size:36px;font-weight:800;color:{{ $nbDiff > 0 ? 'var(--red-600)' : 'var(--green-700)' }}">{{ $nbDiff }}</div>
    <div style="font-size:12px;color:var(--text-muted)">⚠️ Différences détectées</div>
  </div>
  <div class="card" style="text-align:center;padding:20px">
    <div style="font-size:36px;font-weight:800;color:var(--blue-700)">{{ $total }}</div>
    <div style="font-size:12px;color:var(--text-muted)">📊 Total étudiants</div>
  </div>
</div>

@if ($nbDiff === 0)
<div class="card" style="text-align:center;padding:40px">
  <div style="font-size:48px">✅</div>
  <div style="font-size:18px;font-weight:700;color:var(--green-700);margin-top:12px">Parfait — tous les résultats sont cohérents !</div>
  <div style="color:var(--text-muted);margin-top:8px">Les données de <code>resultats_semestre</code> correspondent exactement au recalcul du PV.</div>
</div>
@else
<div class="card">
  <div class="card-header">
    <span class="card-title" style="color:var(--red-600)">⚠️ {{ $nbDiff }} différence(s) détectée(s)</span>
    @unless ($pv->verrouille)
    <form method="post" action="{{ route('verif_resultats.corriger') }}" data-confirm="Recalculer et corriger tous les résultats ?">
      @csrf
      <input type="hidden" name="pv_id" value="{{ $pv->id }}">
      <button type="submit" class="btn btn-sm btn-warning">🔧 Tout corriger</button>
    </form>
    @endunless
  </div>
  <div class="card-body" style="padding:0">
    <table class="table" style="font-size:12px">
      <thead>
        <tr>
          <th>Étudiant</th>
          <th>Matricule</th>
          <th style="text-align:center">Moy. base</th>
          <th style="text-align:center">Moy. live</th>
          <th style="text-align:center">Crédits base</th>
          <th style="text-align:center">Crédits live</th>
          <th style="text-align:center">Validé base</th>
          <th style="text-align:center">Validé live</th>
          <th style="text-align:center">Déf. base</th>
          <th style="text-align:center">Déf. live</th>
        </tr>
      </thead>
      <tbody>
      @foreach ($differences as $d)
      @php $et = $d['et']; $live = $d['live']; $e = $d['ecarts']; @endphp
      <tr style="background:#fff5f5">
        <td style="font-weight:600">{{ $et->nom }} {{ $et->prenoms }}</td>
        <td style="color:var(--text-muted)">{{ $et->numero }}</td>
        <td style="text-align:center;{{ $e['moy'] ? 'color:var(--red-600);font-weight:700' : '' }}">{{ number_format((float) $et->moyenne_generale, 2) }}</td>
        <td style="text-align:center;{{ $e['moy'] ? 'color:var(--green-700);font-weight:700' : '' }}">{{ number_format((float) $live->moyenne_generale, 2) }}</td>
        <td style="text-align:center;{{ $e['creds'] ? 'color:var(--red-600);font-weight:700' : '' }}">{{ $et->credits_obtenus }}/{{ $et->credits_total }}</td>
        <td style="text-align:center;{{ $e['creds'] ? 'color:var(--green-700);font-weight:700' : '' }}">{{ $live->credits_obtenus }}/{{ $creditsMax }}</td>
        @foreach ([$et->valide, $live->valide] as $v)
        <td style="text-align:center">
          <span style="background:{{ $v ? '#dcfce7' : '#fee2e2' }};color:{{ $v ? '#166534' : '#dc2626' }};padding:2px 8px;border-radius:8px;font-size:11px;font-weight:700">{{ $v ? 'OUI' : 'NON' }}</span>
        </td>
        @endforeach
        @foreach ([$et->defaillant, $live->defaillant] as $v)
        <td style="text-align:center">@if ($v)<span style="color:#dc2626;font-weight:700">OUI</span>@else — @endif</td>
        @endforeach
      </tr>
      @endforeach
      </tbody>
    </table>
  </div>
</div>
@endif
@endif
@endsection
