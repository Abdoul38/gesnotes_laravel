{{-- Mes jurys : PV des semestres présidés par l'enseignant --}}
@extends('layouts.app', ['titre' => 'Mes jurys', 'fil' => 'Président du jury'])

@section('contenu')
<div class="card">
  <div class="card-header"><span class="card-title">⚖️ PV des semestres que je préside</span></div>
  <div class="card-body" style="padding:0;overflow-x:auto">
    @if ($pvs->isEmpty())
    <div class="empty-state" style="padding:40px"><p>Vous n'êtes président d'aucun jury.</p></div>
    @else
    <table class="table">
      <thead><tr><th>Année</th><th>Filière</th><th>Semestre</th><th>PV</th><th style="text-align:center">Statut</th><th style="text-align:center">Verrou</th><th>Documents</th></tr></thead>
      <tbody>
      @foreach ($pvs as $p)
      <tr>
        <td>{{ $p->annee }}</td>
        <td>{{ $p->filiere }} {{ $p->niveau }}</td>
        <td>{{ $p->sem_libelle }}</td>
        <td><strong>{{ $p->libelle }}</strong><div style="font-size:11px;color:var(--text-muted)">{{ \App\Services\Pv::SESSIONS[$p->type_session] ?? $p->type_session }} · {{ $p->nb_resultats }} résultat(s)</div></td>
        <td style="text-align:center">{{ \App\Services\Pv::STATUTS[$p->statut] ?? $p->statut }}</td>
        <td style="text-align:center">
          <form method="post" action="{{ route('jury.verrou', $p->id) }}" style="margin:0"
                data-confirm="{{ $p->verrouille ? 'Déverrouiller ce PV ? Les notes pourront de nouveau être modifiées.' : 'Verrouiller ce PV ? Plus aucune note ne pourra y être modifiée.' }}">
            @csrf
            <input type="hidden" name="action" value="{{ $p->verrouille ? 'deverrouiller' : 'verrouiller' }}">
            <button type="submit" class="btn btn-sm {{ $p->verrouille ? 'btn-secondary' : 'btn-primary' }}">{{ $p->verrouille ? '🔓 Déverrouiller' : '🔒 Verrouiller' }}</button>
          </form>
          <div style="font-size:11px;margin-top:3px;color:{{ $p->verrouille ? 'var(--green-700)' : 'var(--text-muted)' }}">{{ $p->verrouille ? 'Verrouillé' : 'Ouvert' }}</div>
        </td>
        <td style="white-space:nowrap">
          <a href="{{ route('jury.document', [$p->id, 'pv']) }}" class="btn btn-sm btn-secondary" target="_blank" rel="noopener">🖨️ PV</a>
          <a href="{{ route('jury.document', [$p->id, 'deliberation']) }}" class="btn btn-sm btn-secondary" target="_blank" rel="noopener">⚖️ Délibération</a>
          <a href="{{ route('jury.document', [$p->id, 'admis']) }}" class="btn btn-sm btn-secondary" target="_blank" rel="noopener">✅ Admis</a>
        </td>
      </tr>
      @endforeach
      </tbody>
    </table>
    @endif
  </div>
</div>
<p style="font-size:12px;color:var(--text-muted);margin-top:10px">
  Le président du jury verrouille le PV après la délibération : plus aucune note ne peut y être saisie ni modifiée, par personne, jusqu'au déverrouillage.
</p>
@endsection
