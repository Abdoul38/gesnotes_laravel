{{-- Registre des documents émis — portage de registre_diplomes.php --}}
@extends('layouts.app', ['titre' => 'Registre des diplômes & attestations', 'fil' => 'Diplômes › Registre'])

@section('contenu')
@php
    $niveaux = ['dut' => 'DUT', 'licence' => 'Licence', 'master' => 'Master'];
    $attestations = ['validation_l2' => 'Attestation validation', 'licence' => 'Attestation licence', 'master1' => 'Attestation M1', 'master_complet' => 'Attestation Master'];
    $libelle = fn ($r) => match ($r->type_doc) {
        'diplome' => 'Diplôme de '.($niveaux[$r->sous_type] ?? $r->sous_type),
        'attestation' => $attestations[$r->sous_type] ?? $r->sous_type,
        'releve' => 'Relevé de notes',
        'certificat' => 'Certificat de scolarité',
        default => "Carte d'étudiant",
    };
@endphp
<div style="display:flex;justify-content:flex-end;gap:8px;margin-bottom:12px">
  <a href="{{ route('diplomes') }}" class="btn btn-secondary btn-sm">← Diplômes & attestations</a>
</div>
<div class="alert alert-info" style="margin-bottom:16px;font-size:12px">
  💡 Liste en lecture seule des documents générés — chaque numéro n'apparaît qu'une fois, même si le document a été réimprimé.
  Ces numéros sont ceux vérifiables par le QR code des documents ou la <a href="{{ route('verifier_diplome') }}" target="_blank" rel="noopener">page de vérification publique</a>.
</div>
<div class="card mb-16">
  <div class="card-body">
    <form method="get" action="{{ route('registre_diplomes') }}" style="display:flex;gap:10px;align-items:end;flex-wrap:wrap">
      <div class="form-group" style="margin:0">
        <label class="form-label" for="q">🔍 Recherche</label>
        <input type="text" name="q" id="q" class="form-control" value="{{ $q }}" placeholder="Nom, numéro, filière/spécialité...">
      </div>
      <div class="form-group" style="margin:0">
        <label class="form-label" for="type_doc">Type</label>
        <select name="type_doc" id="type_doc" class="form-control">
          <option value="">Tous les documents</option>
          @foreach (['diplome' => 'Diplômes', 'attestation' => 'Attestations', 'releve' => 'Relevés', 'certificat' => 'Certificats', 'carte' => 'Cartes'] as $cle => $nom)
          <option value="{{ $cle }}" @selected($type === $cle)>{{ $nom }} uniquement</option>
          @endforeach
        </select>
      </div>
      <button type="submit" class="btn btn-primary">Filtrer</button>
    </form>
  </div>
</div>
<div class="card">
  <div class="card-header"><span class="card-title">📜 Registre ({{ $total }})</span></div>
  <div class="card-body" style="padding:0;overflow-x:auto">
    @if ($registre->isEmpty())
    <div class="empty-state" style="padding:32px"><div class="icon">📜</div><p>Aucun document émis pour l'instant.</p></div>
    @else
    <table class="table" style="font-size:12px;white-space:nowrap">
      <thead>
        <tr>
          <th>N°</th><th>Étudiant</th><th>Document</th><th>Filière / Spécialité</th>
          <th style="text-align:center">Mention</th><th style="text-align:center">Moyenne</th>
          <th>Émis le</th><th style="text-align:center">Vérifier</th>
        </tr>
      </thead>
      <tbody>
        @foreach ($registre as $r)
        <tr>
          <td style="font-family:monospace;font-weight:700">{{ $r->numero }}</td>
          <td style="font-weight:600">{{ $r->nom }} {{ $r->prenoms }}</td>
          <td>{{ $libelle($r) }}</td>
          <td>{{ $r->detail ?? '—' }}</td>
          <td style="text-align:center">{{ $r->mention_grade ?? '—' }}</td>
          <td style="text-align:center">{{ $r->moyenne !== null ? number_format((float) $r->moyenne, 2) : '—' }}</td>
          <td style="color:var(--text-muted)">{{ date('d/m/Y H:i', strtotime($r->date_emission)) }}</td>
          <td style="text-align:center">
            <a href="{{ route('verifier_diplome', ['num' => $r->numero]) }}" target="_blank" rel="noopener" class="btn btn-sm btn-secondary" title="Ouvrir la vérification" aria-label="Vérifier {{ $r->numero }}">🔍</a>
          </td>
        </tr>
        @endforeach
      </tbody>
    </table>
    @endif
  </div>
  @if ($pagination['totalPages'] > 1)
  <div class="card-footer">
    <div class="pagination">
      @for ($i = 1; $i <= $pagination['totalPages']; $i++)
      <a href="{{ request()->fullUrlWithQuery(['p' => $i]) }}" class="page-btn {{ $i == $pagination['page'] ? 'active' : '' }}">{{ $i }}</a>
      @endfor
    </div>
    <span class="text-muted text-sm" style="margin-left:auto">Page {{ $pagination['page'] }}/{{ $pagination['totalPages'] }}</span>
  </div>
  @endif
</div>
@endsection
