{{-- Historique académique — portage de pages/admin/historique.php --}}
@extends('layouts.app', ['titre' => 'Historique académique', 'fil' => 'Étudiants › Historique'])

@section('contenu')
<div style="display:grid;grid-template-columns:minmax(260px,320px) 1fr;gap:20px;align-items:start">
  <div class="card">
    <div class="card-header"><span class="card-title">🔍 Rechercher un étudiant</span></div>
    <div class="card-body">
      <form method="get" action="{{ route('historique') }}">
        <div class="form-group"><input type="text" name="q" class="form-control" placeholder="Nom, prénoms ou matricule..." value="{{ $q }}" aria-label="Rechercher un étudiant"></div>
        <button type="submit" class="btn btn-primary w-100">🔍 Rechercher</button>
      </form>
      @if ($q !== '' && $etudiants->isEmpty())
      <div class="empty-state mt-16"><p>Aucun étudiant trouvé.</p></div>
      @else
      <div style="margin-top:12px">
        @foreach ($etudiants as $e)
        <a href="{{ route('historique', ['et_id' => $e->id, 'q' => $q]) }}" style="display:block;padding:8px 10px;border-radius:6px;text-decoration:none;color:inherit;border:1px solid var(--border);margin-bottom:6px;background:{{ $e->id == $etId ? 'var(--blue-50)' : 'var(--surface)' }}">
          <div style="font-weight:600;font-size:13px">{{ $e->nom }} {{ $e->prenoms }}</div><div style="font-size:11px;color:var(--text-muted)">{{ $e->numero }}</div>
        </a>
        @endforeach
      </div>
      @endif
    </div>
  </div>
  <div>
    @if ($et)
    <div class="card mb-16"><div class="card-body" style="display:flex;align-items:center;gap:16px;flex-wrap:wrap">
      <div style="font-size:30px">👤</div>
      <div style="flex:1">
        <div style="font-size:16px;font-weight:700">{{ $et->nom }} {{ $et->prenoms }}</div>
        <div style="font-size:12px;color:var(--text-muted)">Matricule : {{ $et->numero }}@if ($et->date_naissance) · Né(e) le {{ date('d/m/Y', strtotime($et->date_naissance)) }}@endif @if ($et->lieu_naissance) à {{ $et->lieu_naissance }}@endif</div>
      </div>
      <span style="font-size:12px;color:var(--text-muted)">{{ $historique->flatten(1)->count() }} semestre(s) au total</span>
    </div></div>
    @forelse ($historique as $annee => $semestres)
    <div class="card mb-16">
      <div class="card-header" style="background:#0F2942;color:#fff"><span class="card-title" style="color:#fff">📅 Année académique {{ $annee }}</span></div>
      <div class="card-body" style="padding:0;overflow-x:auto"><table class="table">
        <thead><tr><th>Semestre</th><th>Filière / Niveau</th><th>Session</th><th style="text-align:center">Moy./20</th><th style="text-align:center">Crédits</th><th style="text-align:center">Mention</th><th style="text-align:center">Décision</th><th style="text-align:center">Actions</th></tr></thead>
        <tbody>
        @foreach ($semestres as $s)
        <tr>
          <td style="font-weight:600">{{ $s->sem_label }}</td>
          <td><div style="font-weight:600;font-size:12px">{{ $s->filiere }}</div><div style="font-size:11px;color:var(--text-muted)">{{ $s->niv_label }}</div></td>
          <td><span class="badge badge-blue">{{ \App\Services\Pv::SESSIONS[$s->type_session] ?? $s->type_session }}</span></td>
          <td style="text-align:center;font-weight:700;color:{{ ($s->moyenne_generale ?? 0) >= 10 ? 'var(--green-700)' : 'var(--amber-600)' }}">{{ $s->moyenne_generale !== null ? number_format($s->moyenne_generale, 2) : '—' }}</td>
          <td style="text-align:center;font-size:12px"><strong style="color:{{ $s->credits_obtenus >= $s->credits_total ? 'var(--green-700)' : 'var(--amber-600)' }}">{{ (int) $s->credits_obtenus }}</strong>/{{ (int) $s->credits_total }}</td>
          <td style="text-align:center;font-size:11px;font-weight:600;color:var(--green-700)">{{ $s->mention ?? '—' }}</td>
          <td style="text-align:center;font-weight:700;font-size:12px;color:{{ $s->decision === 'Admis' ? 'var(--green-700)' : (in_array($s->decision, ['Défaillant', 'Elim'], true) ? 'var(--red-600)' : 'var(--amber-600)') }}">{{ $s->decision ?? '—' }}</td>
          <td style="text-align:center">
            @if ($s->valide)
            <a href="{{ route('releve_notes', ['pv_id' => $s->pv_id, 'et_id' => $et->id]) }}" target="_blank" rel="noopener" class="btn btn-sm btn-secondary" title="Relevé de notes">📄</a>
            <a href="{{ route('attestation', ['pv_id' => $s->pv_id, 'et_id' => $et->id]) }}" target="_blank" rel="noopener" class="btn btn-sm btn-primary" title="Attestation">🏆</a>
            @else<span style="font-size:11px;color:var(--text-muted)">Non validé</span>@endif
          </td>
        </tr>
        @endforeach
        </tbody>
      </table></div>
    </div>
    @empty
    <div class="card"><div class="empty-state" style="padding:40px"><p>Aucun résultat enregistré pour cet étudiant.</p></div></div>
    @endforelse
    @elseif ($etId)
    <div class="card"><div class="empty-state" style="padding:40px"><p>Étudiant introuvable dans votre périmètre.</p></div></div>
    @else
    <div class="card"><div class="empty-state" style="padding:60px"><div class="icon" style="font-size:48px">🎓</div><h3>Historique académique</h3><p>Recherchez un étudiant pour voir son parcours complet.</p></div></div>
    @endif
  </div>
</div>
@endsection
