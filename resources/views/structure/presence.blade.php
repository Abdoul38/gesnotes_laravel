{{-- Prise de présence par séance — portage de pages/structure/presence.php --}}
@extends('layouts.app', ['titre' => 'Prise de présence', 'fil' => 'Notes & PV › Présence'])

@section('contenu')
<div style="display:grid;grid-template-columns:minmax(260px,340px) 1fr;gap:16px;align-items:start">
  <div>
    <div class="card mb-16">
      <div class="card-header"><span class="card-title">➕ Nouvelle séance</span></div>
      <div class="card-body">
        <form method="post" action="{{ route('presence.creer') }}">
          @csrf
          <div class="form-group"><label class="form-label" for="promotion_id">Promotion</label>
            <select name="promotion_id" id="promotion_id" class="form-control" required data-url-matieres="{{ route('api_matieres_promotion') }}">
              <option value="">-- Choisir --</option>@foreach ($promotions as $p)<option value="{{ $p->id }}">{{ $p->annee }} — {{ $p->filiere }} {{ $p->niveau }}</option>@endforeach
            </select></div>
          <div class="form-group"><label class="form-label" for="sel-matiere">Matière</label>
            <select name="matiere_id" id="sel-matiere" class="form-control" required><option value="">-- Choisir une promotion d'abord --</option></select></div>
          <div class="form-group"><label class="form-label" for="date_seance">Date</label><input type="date" name="date_seance" id="date_seance" class="form-control" value="{{ date('Y-m-d') }}" required></div>
          <div class="form-group"><label class="form-label" for="type_seance">Type</label>
            <select name="type_seance" id="type_seance" class="form-control">@foreach (\App\Http\Controllers\Structure\PresenceController::TYPES as $t)<option value="{{ $t }}">{{ $t }}</option>@endforeach</select></div>
          <button type="submit" class="btn btn-primary w-100">Créer la séance</button>
        </form>
      </div>
    </div>
    <div class="card">
      <div class="card-header"><span class="card-title">🕐 Séances récentes</span></div>
      @forelse ($recentes as $s)
      <a href="{{ route('presence', ['seance_id' => $s->id]) }}" style="display:block;padding:10px 14px;border-bottom:1px solid #f0f0f0;text-decoration:none;color:inherit;{{ $seance && $s->id == $seance->id ? 'background:#eff6ff' : '' }}">
        <div style="font-weight:600;font-size:12.5px">{{ $s->matiere }}</div>
        <div style="font-size:11px;color:var(--text-muted)">{{ date('d/m/Y', strtotime($s->date_seance)) }} · {{ $s->type_seance }} · {{ $s->filiere }} {{ $s->niveau }} ·
          @if ($s->nb_saisies > 0)<span style="color:var(--green-700)">✓ saisie</span>@else<span style="color:var(--amber-600)">à saisir</span>@endif</div>
      </a>
      @empty
      <div style="padding:20px;text-align:center;color:var(--text-muted);font-size:13px">Aucune séance pour l'instant.</div>
      @endforelse
    </div>
  </div>
  <div>
    @if (! $seance)
    <div class="card" style="text-align:center;padding:50px"><div style="font-size:40px">📅</div><div style="color:var(--text-muted);margin-top:10px">Créez une séance ou choisissez-en une pour saisir les présences.</div></div>
    @else
    <div class="card">
      <div class="card-header"><span class="card-title">{{ $seance->matiere }} — {{ date('d/m/Y', strtotime($seance->date_seance)) }} ({{ $seance->type_seance }}) · {{ $seance->filiere }} {{ $seance->niveau }}</span></div>
      <div class="card-body">
        @if ($etudiants->isEmpty())
        <p style="color:var(--text-muted)">Aucun étudiant inscrit dans cette promotion.</p>
        @else
        <form method="post" action="{{ route('presence.enregistrer') }}">
          @csrf
          <input type="hidden" name="seance_id" value="{{ $seance->id }}">
          <div style="overflow-x:auto"><table class="table" style="font-size:12.5px">
            <thead><tr><th>Étudiant</th><th style="text-align:center">Statut</th><th>Motif (si absent/retard)</th></tr></thead>
            <tbody>
            @foreach ($etudiants as $et)
            @php $p = $presences[$et->id] ?? null; @endphp
            <tr>
              <td>{{ $et->nom }} {{ $et->prenoms }} <span style="color:var(--text-muted)">({{ $et->numero }})</span></td>
              <td style="text-align:center"><select name="statut[{{ $et->id }}]" class="form-control" style="font-size:12px;padding:4px 6px" aria-label="Statut de {{ $et->nom }}">
                @foreach (\App\Http\Controllers\Structure\PresenceController::STATUTS as $cle => $lib)<option value="{{ $cle }}" @selected(($p->statut ?? 'present') === $cle)>{{ $lib }}</option>@endforeach
              </select></td>
              <td><input type="text" name="motif[{{ $et->id }}]" class="form-control" style="font-size:12px;padding:4px 6px" value="{{ $p->motif ?? '' }}" maxlength="200" placeholder="Optionnel" aria-label="Motif"></td>
            </tr>
            @endforeach
            </tbody>
          </table></div>
          <div style="text-align:right;margin-top:12px"><button type="submit" class="btn btn-primary">💾 Enregistrer les présences</button></div>
        </form>
        @endif
      </div>
    </div>
    @endif
  </div>
</div>
@endsection

@push('scripts')
<script src="{{ asset('assets/js/presence.js') }}"></script>
@endpush
