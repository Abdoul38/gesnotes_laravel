{{-- Mes examens : planning des examens de l'enseignant (ses matières, ses surveillances) --}}
@extends('layouts.app', ['titre' => 'Mes examens', 'fil' => 'Portail enseignant › Planning des examens'])

@section('contenu')
<div class="card">
  <div class="card-header" style="display:flex;gap:10px;align-items:center;flex-wrap:wrap">
    <span class="card-title">📅 Planning de mes examens</span>
    <div style="margin-left:auto;display:flex;gap:8px;align-items:center">
      <select class="form-control form-control-sm" aria-label="Année académique" data-navigate="{{ route('mes_examens') }}?annee_id=">
        @foreach ($annees as $a)<option value="{{ $a->id }}" @selected($a->id == $anneeId)>{{ $a->libelle }}</option>@endforeach
      </select>
      <button type="button" class="btn btn-sm btn-secondary no-print" data-print>🖨️ Imprimer</button>
    </div>
  </div>
  <div class="card-body" style="padding:0;overflow-x:auto">
    @if ($examens->isEmpty())
    <div class="empty-state" style="padding:40px"><p>Aucun examen planifié pour vos matières ou vos surveillances cette année.</p></div>
    @else
    <table class="table">
      <thead><tr><th>Date</th><th>Horaire</th><th>Matière</th><th>Filière · Semestre</th><th>Salle</th><th>Surveillants</th><th>Mon rôle</th></tr></thead>
      <tbody>
      @foreach ($examens as $x)
      @php
        $passe = $x->date_examen < $aujourdhui;
        $surveille = in_array($enseignantId, [(int) $x->surveillant_1, (int) $x->surveillant_2], true);
        $surveillants = collect([[$x->s1_nom, $x->s1_prenoms], [$x->s2_nom, $x->s2_prenoms]])->filter(fn ($s) => $s[0])->map(fn ($s) => $s[0].' '.$s[1])->implode(', ');
      @endphp
      <tr @if ($passe) style="opacity:.55" @elseif ($x->date_examen === $aujourdhui) style="background:var(--green-50)" @endif>
        <td style="white-space:nowrap;font-weight:600">{{ \Illuminate\Support\Carbon::parse($x->date_examen)->locale('fr')->isoFormat('ddd D MMM YYYY') }}</td>
        <td style="white-space:nowrap">{{ substr($x->heure_debut, 0, 5) }} – {{ substr($x->heure_fin, 0, 5) }}</td>
        <td><strong>{{ $x->matiere }}</strong>@if ($x->type_session === 'rattrapage')<span style="background:#fef3c7;color:#b45309;padding:1px 6px;border-radius:8px;font-size:10px;margin-left:6px">Rattrapage</span>@endif
          @if ($x->notes)<div style="font-size:11px;color:var(--text-muted)">{{ $x->notes }}</div>@endif</td>
        <td>{{ $x->filiere }} {{ $x->niveau }} · {{ $x->semestre }}</td>
        <td>{{ $x->salle ?: '—' }}</td>
        <td style="font-size:12px">{{ $surveillants ?: '—' }}</td>
        <td style="font-size:12px">{{ collect([$x->ma_matiere ? 'Enseignant de la matière' : null, $surveille ? 'Surveillant' : null])->filter()->implode(' · ') }}</td>
      </tr>
      @endforeach
      </tbody>
    </table>
    @endif
  </div>
</div>
@endsection
