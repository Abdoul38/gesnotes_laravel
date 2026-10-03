{{-- Diplômes & attestations — portage de diplomes.php --}}
@extends('layouts.app', ['titre' => 'Diplômes & Attestations', 'fil' => 'Listes & Rapports › Diplômes'])

@section('contenu')
@php
    $note = fn ($s) => $s ? number_format((float) $s->moyenne_generale, 2) : '—';
    $couleur = fn ($s) => $s ? ((float) $s->moyenne_generale >= 10 ? 'var(--green-700)' : 'var(--amber-600)') : '#ccc';
    $moy = fn ($b) => $b && $b['moyenne'] !== null ? number_format($b['moyenne'], 2).'/20' : '—';
@endphp
<div style="display:flex;justify-content:flex-end;gap:8px;margin-bottom:12px">
  <a href="{{ route('registre_diplomes') }}" class="btn btn-secondary btn-sm">📜 Registre des diplômes émis</a>
  <a href="{{ route('verifier_diplome') }}" target="_blank" rel="noopener" class="btn btn-secondary btn-sm">🔍 Vérifier un diplôme</a>
</div>
<div class="alert alert-info" style="margin-bottom:20px;font-size:12px">
  💡 Ces listes se basent sur les résultats enregistrés des PV (semestres validés, session normale en priorité) — les documents utilisent exactement les mêmes semestres.
  Un étudiant qui a validé les 6 semestres peut imprimer les deux attestations (validation et licence).
</div>
<div class="card mb-16">
  <div class="card-body">
    <form method="get" action="{{ route('diplomes') }}" style="display:flex;gap:10px;align-items:end;flex-wrap:wrap">
      <div class="form-group" style="margin:0">
        <label class="form-label" for="q">🔍 Étudiant</label>
        <input type="text" name="q" id="q" class="form-control" value="{{ $filtres['q'] }}" placeholder="Nom, matricule...">
      </div>
      <div class="form-group" style="margin:0">
        <label class="form-label" for="filiere_id">Filière</label>
        <select name="filiere_id" id="filiere_id" class="form-control">
          <option value="0">Toutes</option>
          @foreach ($filieres as $f)<option value="{{ $f->id }}" @selected($filtres['filiere_id'] == $f->id)>{{ $f->libelle }}</option>@endforeach
        </select>
      </div>
      <div class="form-group" style="margin:0">
        <label class="form-label" for="annee_id">Année académique</label>
        <select name="annee_id" id="annee_id" class="form-control">
          <option value="0">Toutes</option>
          @foreach ($annees as $a)<option value="{{ $a->id }}" @selected($filtres['annee_id'] == $a->id)>{{ $a->libelle }}</option>@endforeach
        </select>
      </div>
      <button type="submit" class="btn btn-primary">Filtrer</button>
      @if ($filtres['q'] !== '' || $filtres['filiere_id'] || $filtres['annee_id'])
      <a href="{{ route('diplomes') }}" class="btn btn-secondary">✕ Effacer</a>
      @endif
    </form>
  </div>
</div>
<div class="card">
  <div class="card-header"><span class="card-title">🎓 Étudiants ayant validé au moins 4 semestres ({{ count($eligibles) }})</span></div>
  <div class="card-body" style="padding:0;overflow-x:auto">
    @if (empty($eligibles))
    <div class="empty-state" style="padding:32px"><div class="icon">🎓</div><p>Aucun étudiant éligible pour l'instant.</p></div>
    @else
    <table class="table" style="font-size:11.5px;white-space:nowrap">
      <thead>
        <tr>
          <th>Étudiant</th><th>Matricule</th><th>Filière</th>
          @foreach (['S1', 'S2', 'S3', 'S4', 'S5', 'S6'] as $sc)<th style="text-align:center">{{ $sc }}</th>@endforeach
          @foreach (['S1', 'S2', 'S3', 'S4'] as $sc)<th style="text-align:center;background:#f3e8ff33">M-{{ $sc }}</th>@endforeach
          <th style="text-align:center;background:var(--amber-50)">Moy. 4 sem.</th>
          <th style="text-align:center;background:var(--blue-50)">Moy. 6 sem.</th>
          <th style="text-align:center;background:#f3e8ff">Moy. M1</th>
          <th style="text-align:center;background:#f3e8ff">Moy. Master</th>
          @if ($peutProduire)<th style="text-align:center">Attestations</th><th style="text-align:center">Diplômes</th>@endif
        </tr>
      </thead>
      <tbody>
        @foreach ($eligibles as ['et' => $et, 'p' => $p, 'b' => $b])
        <tr>
          <td style="font-weight:600">{{ $et->nom }} {{ $et->prenoms }}</td>
          <td>{{ $et->numero }}</td>
          <td>{{ $et->filiere ?? '—' }}</td>
          @foreach (['S1', 'S2', 'S3', 'S4', 'S5', 'S6'] as $sc)
          <td style="text-align:center;color:{{ $couleur($p['premier'][$sc] ?? null) }}">{{ $note($p['premier'][$sc] ?? null) }}</td>
          @endforeach
          @foreach (['S1', 'S2', 'S3', 'S4'] as $sc)
          <td style="text-align:center;background:#f3e8ff22;color:{{ $couleur($p['master'][$sc] ?? null) }}">{{ $note($p['master'][$sc] ?? null) }}</td>
          @endforeach
          @foreach ([['validation_l2', 'var(--amber-50)'], ['licence', 'var(--blue-50)'], ['master1', '#f3e8ff'], ['master_complet', '#f3e8ff']] as [$doc, $fond])
          <td style="text-align:center;font-weight:700;background:{{ $fond }}">
            {{ $moy($b[$doc]) }}
            @if ($b[$doc])<div style="font-size:9px;color:var(--text-muted);font-weight:400">{{ $b[$doc]['annee'] }}</div>@endif
          </td>
          @endforeach
          @if ($peutProduire)
          <td style="text-align:center">
            @foreach ([['validation_l2', '📋 4 sem.', 'Attestation de validation (4 sem.)', 'btn-secondary'], ['licence', '🎓 6 sem.', 'Attestation de licence (6 sem.)', 'btn-primary'],
                       ['master1', '📋 M1', 'Attestation de M1 validé', 'btn-secondary'], ['master_complet', '🎓 Master', 'Attestation de Master complet', 'btn-primary']] as [$doc, $libelle, $titre, $classe])
            @if ($b[$doc])
            <a href="{{ route('attestations', ['et_id' => $et->id, 'type' => $doc]) }}" target="_blank" rel="noopener" class="btn btn-sm {{ $classe }}" title="{{ $titre }}, mention {{ $b[$doc]['mention'] }}">{{ $libelle }}</a>
            @endif
            @endforeach
          </td>
          <td style="text-align:center">
            @if ($b['dut'])<a href="{{ route('diplome', ['et_id' => $et->id, 'niveau' => 'dut']) }}" target="_blank" rel="noopener" class="btn btn-sm btn-secondary" title="Diplôme de DUT">📜 DUT</a>@endif
            @if ($b['licence'])<a href="{{ route('diplome', ['et_id' => $et->id, 'niveau' => 'licence']) }}" target="_blank" rel="noopener" class="btn btn-sm btn-primary" title="Diplôme de Licence">📜 Licence</a>@endif
            @if ($b['master'])<a href="{{ route('diplome', ['et_id' => $et->id, 'niveau' => 'master']) }}" target="_blank" rel="noopener" class="btn btn-sm btn-secondary" title="Diplôme de Master">📜 Master</a>@endif
          </td>
          @endif
        </tr>
        @endforeach
      </tbody>
    </table>
    @endif
  </div>
</div>
@endsection
