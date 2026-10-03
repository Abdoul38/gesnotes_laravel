{{-- Inscription pédagogique individuelle — portage de inscription_pedagogique.php --}}
@extends('layouts.app', ['titre' => 'Inscription Pédagogique', 'fil' => 'Inscriptions › Pédagogique'])

@section('contenu')
<div class="card mb-20">
  <div class="card-body">
    <form method="get" action="{{ route('inscription_pedagogique') }}" style="display:flex;gap:10px;align-items:flex-end">
      <div class="form-group" style="margin:0;flex:1">
        <label class="form-label" for="matricule" style="font-weight:700">🔍 Matricule ou N° dossier</label>
        <input type="text" name="matricule" id="matricule" class="form-control" placeholder="Ex: 00001 ou D915839" value="{{ $recherche }}"
               style="height:44px;font-size:15px" autocomplete="off" @if (! $etudiant) autofocus @endif>
      </div>
      <button type="submit" class="btn btn-primary" style="height:44px;padding:0 24px">🔍 Rechercher</button>
      @if ($cherche)<a href="{{ route('inscription_pedagogique') }}" class="btn btn-secondary" style="height:44px;padding:0 16px">↺ Effacer</a>@endif
    </form>
  </div>
</div>

@if ($cherche && ! $etudiant)
<div class="alert alert-error">❌ Aucun étudiant trouvé pour <strong>{{ $recherche }}</strong>.</div>

@elseif ($etudiant)
<div class="card mb-20">
  <div class="card-body" style="display:flex;align-items:center;gap:20px">
    <div style="width:60px;height:60px;border-radius:50%;overflow:hidden;background:var(--blue-100);display:flex;align-items:center;justify-content:center;flex-shrink:0">
      @if ($photo)<img src="{{ $photo }}" alt="Photo" style="width:60px;height:60px;object-fit:cover">@else<span style="font-size:28px">👤</span>@endif
    </div>
    <div style="flex:1">
      <div style="font-size:18px;font-weight:800;color:var(--dark)">{{ $etudiant->nom }} {{ $etudiant->prenoms }}</div>
      <div style="display:flex;gap:16px;margin-top:6px;flex-wrap:wrap">
        <span style="font-size:13px;color:var(--text-muted)">Matricule : <strong style="color:var(--blue-700);font-family:monospace">{{ $etudiant->numero }}</strong></span>
        @if ($etudiant->filiere_lib)
        <span style="font-size:13px;color:var(--text-muted)">Filière : <strong style="color:#5b21b6">{{ $etudiant->filiere_lib }}</strong>
          @if ($droit)<span style="background:#fef3c7;color:#92400e;padding:1px 6px;border-radius:8px;font-size:10px;margin-left:4px">Règles Droit</span>@endif
        </span>
        @endif
        @if ($active)<span style="font-size:13px;color:var(--text-muted)">Niveau : <strong style="color:var(--green-700)">{{ $active->niveau_code }}</strong></span>@endif
      </div>
    </div>
    <a href="{{ route('dossier_etudiant', ['id' => $etudiant->id]) }}" class="btn btn-secondary btn-sm">📁 Dossier</a>
  </div>
</div>

<div style="display:grid;grid-template-columns:1fr 1fr;gap:20px">
<div>
  <div class="card mb-20">
    <div class="card-header"><span class="card-title">✅ Semestres validés</span></div>
    <div class="card-body">
      <div style="display:flex;flex-wrap:wrap;gap:8px">
        @foreach (['S1', 'S2', 'S3', 'S4', 'S5', 'S6'] as $code)
        @php($ok = in_array($code, $valides, true))
        <div style="padding:8px 16px;border-radius:10px;font-weight:700;font-size:13px;background:{{ $ok ? '#dcfce7' : '#f3f4f6' }};color:{{ $ok ? '#166534' : '#9ca3af' }};border:2px solid {{ $ok ? '#16a34a' : '#e5e7eb' }}">{{ $code }} {{ $ok ? '✅' : '' }}</div>
        @endforeach
        @if (! $valides)<span style="color:var(--text-muted);font-style:italic;font-size:13px">Aucun semestre validé</span>@endif
      </div>
    </div>
  </div>

  @if ($active)
  <div class="card">
    <div class="card-header"><span class="card-title">🎓 Inscription pédagogique — {{ $active->annee_lib }}</span></div>
    <div class="card-body">
      @if ($deja)
      <div style="background:#eff6ff;border:1px solid #bfdbfe;border-radius:8px;padding:14px;margin-bottom:16px">
        <div style="font-weight:700;color:#1e40af;margin-bottom:8px">📋 Semestres déjà inscrits cette année :</div>
        <div style="display:flex;gap:8px;flex-wrap:wrap">
          @foreach ($semestres->whereIn('id', $deja) as $s)<span style="background:#1e40af;color:#fff;padding:4px 12px;border-radius:10px;font-weight:700;font-size:13px">{{ $s->code }}</span>@endforeach
        </div>
      </div>
      <div style="font-size:13px;color:var(--text-muted);margin-bottom:12px">Vous pouvez modifier ou ajouter des semestres :</div>
      @endif

      <form method="post" action="{{ route('inscription_pedagogique.enregistrer') }}" id="form-ped">
        @csrf
        <input type="hidden" name="etudiant_id" value="{{ $etudiant->id }}">
        <input type="hidden" name="inscription_id" value="{{ $active->id }}">
        <div class="form-group">
          <span class="form-label" style="font-weight:700">Semestres à autoriser</span>
          <div style="font-size:12px;color:var(--text-muted);margin-bottom:10px">💡 Suggestion automatique selon les résultats @if ($droit)(Règles <strong>Droit</strong>)@endif</div>
          <div style="display:grid;grid-template-columns:1fr 1fr 1fr;gap:10px">
            @foreach ($semestres as $s)
            @php($sugg = in_array((int) $s->id, $suggeres, true))
            @php($ins = in_array((int) $s->id, $deja, true))
            <label style="display:flex;align-items:center;gap:8px;padding:12px;border-radius:8px;cursor:pointer;border:2px solid {{ $sugg ? 'var(--primary)' : ($ins ? '#16a34a' : '#e5e7eb') }};background:{{ $sugg ? 'var(--blue-50)' : ($ins ? '#f0fdf4' : '#fff') }}">
              <input type="checkbox" name="semestres[]" value="{{ $s->id }}" class="chk-sem" @checked($sugg || $ins) style="width:18px;height:18px;accent-color:var(--primary)">
              <div>
                <div style="font-weight:800;font-size:15px;color:var(--primary)">{{ $s->code }}</div>
                <div style="font-size:10px;color:var(--text-muted)">{{ $s->libelle }}</div>
                @if ($sugg)<div style="font-size:9px;color:var(--primary);font-weight:600">✦ Suggéré</div>
                @elseif ($ins)<div style="font-size:9px;color:#16a34a;font-weight:600">✓ Déjà inscrit</div>
                @else<div style="font-size:9px;color:#9ca3af;font-weight:600">Non suggéré — cocher pour forcer</div>@endif
              </div>
            </label>
            @endforeach
          </div>
        </div>
        <div style="font-size:11px;color:#92400e;background:#fef3c7;border-radius:6px;padding:8px 10px;margin-bottom:10px">
          ⚠️ Décocher un semestre déjà inscrit le <strong>retire</strong> à l'enregistrement (statut annulé) — pas juste visuellement.
        </div>
        <button type="submit" class="btn btn-primary w-100" style="padding:12px;font-size:15px;margin-top:8px">✅ Enregistrer l'inscription pédagogique</button>
      </form>
    </div>
  </div>
  @else
  <div class="alert alert-error">
    ⚠️ Cet étudiant n'a pas d'inscription scolarité active. <a href="{{ route('inscription_scolarite', ['id' => $etudiant->id]) }}">Créer une inscription</a>
  </div>
  @endif
</div>

<div class="card">
  <div class="card-header"><span class="card-title">📋 Historique pédagogique</span></div>
  <div class="card-body" style="padding:16px">
    @if ($historique->isEmpty())
    <div style="text-align:center;padding:30px;color:var(--text-muted)"><div style="font-size:32px;margin-bottom:8px">📭</div>Aucune inscription pédagogique</div>
    @else
    <div style="display:flex;flex-direction:column;gap:12px">
    @foreach ($historique as $annee => $lignes)
      @php($enCours = (bool) $lignes->first()->active)
      @php($bord = $enCours ? '#86efac' : '#e5e7eb')
      @php($couleur = $enCours ? '#166534' : '#374151')
      <div style="border:1.5px solid {{ $bord }};border-radius:10px;overflow:hidden">
        <div style="background:{{ $enCours ? '#dcfce7' : '#f3f4f6' }};padding:10px 16px;display:flex;align-items:center;justify-content:space-between">
          <div style="display:flex;align-items:center;gap:10px">
            <span style="font-size:16px">{{ $enCours ? '🟢' : '📅' }}</span>
            <div>
              <div style="font-weight:800;color:{{ $couleur }};font-size:14px">Année {{ $annee }}
                @if ($enCours)<span style="background:#16a34a;color:#fff;padding:1px 8px;border-radius:8px;font-size:10px;margin-left:6px;font-weight:600">EN COURS</span>@endif
              </div>
              @if ($lignes->first()->niveau_code)<div style="font-size:11px;color:var(--text-muted)">Niveau {{ $lignes->first()->niveau_code }}</div>@endif
            </div>
          </div>
          <div style="text-align:right">
            <div style="font-size:11px;color:var(--text-muted)">Semestres inscrits</div>
            <div style="font-size:20px;font-weight:800;color:{{ $couleur }}">{{ $lignes->count() }}</div>
          </div>
        </div>
        <div style="padding:12px 16px;display:flex;flex-direction:column;gap:6px;background:{{ $enCours ? '#f0fdf4' : '#f9fafb' }}">
          @foreach ($lignes as $r)
          <div style="display:flex;align-items:center;gap:10px;padding:7px 12px;background:#fff;border:1px solid {{ $bord }};border-radius:7px">
            <span style="background:{{ $enCours ? '#1A3A5C' : '#6b7280' }};color:#fff;padding:2px 10px;border-radius:8px;font-weight:800;font-size:12px;min-width:32px;text-align:center">{{ $r->sem_code }}</span>
            <span style="font-size:13px;color:#374151;font-weight:500">{{ $r->sem_lib }}</span>
            <span style="margin-left:auto;font-size:11px;color:var(--text-muted)">Inscrit le {{ $r->date_inscription ? date('d/m/Y', strtotime($r->date_inscription)) : '—' }}</span>
          </div>
          @endforeach
        </div>
      </div>
    @endforeach
    </div>
    @endif
  </div>
</div>
</div>

@else
<div class="card">
  <div class="card-header"><span class="card-title">📋 Inscription pédagogique</span></div>
  <div class="card-body" style="text-align:center;padding:50px;color:var(--text-muted)">
    <div style="font-size:48px;margin-bottom:16px">🎓</div>
    <div style="font-size:16px;font-weight:700;color:var(--primary);margin-bottom:8px">Rechercher un étudiant</div>
    <div style="font-size:13px">Saisissez le matricule ou le N° dossier pour afficher les semestres à autoriser.</div>
  </div>
</div>
@endif
@endsection
