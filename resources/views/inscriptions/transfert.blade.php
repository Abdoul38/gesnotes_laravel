{{-- Transfert / passage de niveau individuel — portage de transfert_niveau.php --}}
@extends('layouts.app', ['titre' => 'Transfert / Passage de niveau', 'fil' => 'Inscriptions › Transfert'])

@section('contenu')
<div class="card mb-20">
  <div class="card-body">
    <form method="get" action="{{ route('transfert_niveau') }}" style="display:flex;gap:10px;align-items:flex-end">
      <div class="form-group" style="margin:0;flex:1">
        <label class="form-label" for="matricule" style="font-weight:700">🔍 Matricule ou N° dossier</label>
        <input type="text" name="matricule" id="matricule" class="form-control" placeholder="Ex: 00001 ou D915839"
               value="{{ $recherche }}" style="height:44px;font-size:15px" autocomplete="off" @if (! $etudiant) autofocus @endif>
      </div>
      <button type="submit" class="btn btn-primary" style="height:44px;padding:0 24px">🔍 Rechercher</button>
      @if ($cherche)
      <a href="{{ route('transfert_niveau') }}" class="btn btn-secondary" style="height:44px;padding:0 16px">↺ Effacer</a>
      @endif
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
        @if ($etudiant->filiere_lib)<span style="font-size:13px;color:var(--text-muted)">Filière : <strong style="color:#5b21b6">{{ $etudiant->filiere_lib }}</strong></span>@endif
        @if ($derniere)<span style="font-size:13px;color:var(--text-muted)">Niveau actuel : <strong style="color:var(--green-700)">{{ $derniere->niveau_code }}</strong></span>@endif
      </div>
    </div>
    <a href="{{ route('dossier_etudiant', ['id' => $etudiant->id]) }}" class="btn btn-secondary btn-sm">📁 Dossier</a>
  </div>
</div>

<div style="display:grid;grid-template-columns:1fr 1fr;gap:20px">

<div class="card">
  <div class="card-header"><span class="card-title">📈 Passage de niveau</span></div>
  <div class="card-body">
    @if ($suivant)
    @php([$fond, $couleur, $libelle] = \App\Services\Inscriptions::styleStatut($statut))
    <div style="background:{{ $fond }};border-left:4px solid {{ $couleur }};border-radius:6px;padding:12px 16px;margin-bottom:16px">
      <div style="font-size:12px;color:var(--text-muted);margin-bottom:6px">Semestres validés :</div>
      <div style="display:flex;gap:4px;flex-wrap:wrap;margin-bottom:10px">
        @foreach (['S1', 'S2', 'S3', 'S4', 'S5', 'S6'] as $sc)
        @if (in_array($sc, $valides, true))
        <span style="background:#dcfce7;color:#166534;padding:2px 8px;border-radius:6px;font-size:12px;font-weight:700">{{ $sc }}</span>
        @else
        <span style="background:#f3f4f6;color:#9ca3af;padding:2px 8px;border-radius:6px;font-size:12px">{{ $sc }}</span>
        @endif
        @endforeach
      </div>
      <div style="display:flex;align-items:center;gap:10px;flex-wrap:wrap">
        <span style="background:var(--blue-50);color:var(--blue-700);padding:3px 10px;border-radius:8px;font-weight:700;font-size:14px">{{ $derniere->niveau_code }}</span>
        <span style="font-size:18px">→</span>
        <span style="background:{{ $fond }};color:{{ $couleur }};padding:3px 10px;border-radius:8px;font-weight:800;font-size:14px;border:1px solid {{ $couleur }}">{{ $suivant }}</span>
        <span style="font-size:12px;font-weight:700;color:{{ $couleur }}">{{ $libelle }}</span>
      </div>
      @if ($statut === 'licence_validee')
      <div style="margin-top:8px;font-size:12px;color:#166534;font-style:italic">✅ Tous les semestres de la Licence sont validés (S1→S6). Le passage en Master se fait via une nouvelle inscription.</div>
      @elseif ($statut === 'master_valide')
      <div style="margin-top:8px;font-size:12px;color:#065f46;font-style:italic">✅ Tous les semestres du Master sont validés (S1→S4). L'étudiant a obtenu son Master.</div>
      @endif
    </div>
    @else
    <div class="alert alert-info" style="font-size:13px">ℹ️ Aucune inscription active ou résultats manquants.</div>
    @endif

    <form method="post" action="{{ route('transfert_niveau.passage') }}">
      @csrf
      <input type="hidden" name="etudiant_id" value="{{ $etudiant->id }}">
      @if ($specialisations->count() > 1)
      <div class="form-group">
        <label class="form-label" for="filiere_l3_id">Spécialisation {{ $suivant }} <span class="req">*</span></label>
        <select name="filiere_l3_id" id="filiere_l3_id" class="form-control" required>
          <option value="">— Choisir la filière —</option>
          @foreach ($specialisations as $sp)<option value="{{ $sp->id }}">{{ $sp->libelle }}</option>@endforeach
        </select>
      </div>
      @endif
      <div class="form-group">
        <label class="form-label" for="nouvel_annee_id">Nouvelle année académique <span class="req">*</span></label>
        <select name="nouvel_annee_id" id="nouvel_annee_id" class="form-control" required>
          <option value="">— Choisir —</option>
          @foreach ($annees as $a)
          @continue($derniere && $a->id == $derniere->annee_id)
          <option value="{{ $a->id }}" @selected($a->active)>{{ $a->libelle }} {{ $a->active ? '(En cours)' : '' }}</option>
          @endforeach
        </select>
      </div>
      <div style="background:#f0f9ff;border:1px solid #bae6fd;border-radius:6px;padding:10px 14px;font-size:12px;color:#0369a1;margin-bottom:14px">
        ℹ️ Cette action enregistre <strong>uniquement la décision</strong>. L'inscription académique se fait ensuite via <strong>Inscription Scolarité</strong>.
      </div>
      <button type="submit" class="btn btn-primary w-100" style="padding:12px;font-size:15px" data-confirm-click="Confirmer la décision de passage pour cet étudiant ?">✅ Enregistrer la décision</button>
    </form>
  </div>
</div>

<div class="card">
  <div class="card-header"><span class="card-title">↔️ Transfert de filière</span></div>
  <div class="card-body">
    <div style="background:#fef3c7;border-left:4px solid #d97706;border-radius:6px;padding:10px 14px;font-size:12px;color:#92400e;margin-bottom:16px">
      ⚠️ Le transfert <strong>ne crée pas de nouvelle inscription</strong>. Il met à jour la filière de l'étudiant et rattache son inscription existante à la nouvelle filière.
    </div>
    <form method="post" action="{{ route('transfert_niveau.transferer') }}">
      @csrf
      <input type="hidden" name="etudiant_id" value="{{ $etudiant->id }}">
      <div class="form-group">
        <label class="form-label" for="nouvelle_filiere_id">Nouvelle filière <span class="req">*</span></label>
        <select name="nouvelle_filiere_id" id="nouvelle_filiere_id" class="form-control" required>
          <option value="">— Choisir la filière —</option>
          @foreach ($filieres as $f)<option value="{{ $f->id }}" @selected($f->id == $etudiant->filiere_id)>{{ $f->libelle }}@if ($f->faculte) ({{ $f->faculte }})@endif</option>@endforeach
        </select>
      </div>
      <div class="form-group">
        <label class="form-label" for="annee_id">Année académique concernée <span class="req">*</span></label>
        <select name="annee_id" id="annee_id" class="form-control" required>
          <option value="">— Choisir —</option>
          @foreach ($annees as $a)<option value="{{ $a->id }}" @selected($a->id == $anneeActive)>{{ $a->libelle }} {{ $a->active ? '(En cours)' : '' }}</option>@endforeach
        </select>
      </div>
      <button type="submit" class="btn btn-warning w-100" style="padding:12px;font-size:15px" data-confirm-click="Confirmer le transfert de filière ? L'inscription existante sera mise à jour.">↔️ Confirmer le transfert</button>
    </form>
  </div>
</div>

<div class="card">
  <div class="card-header"><span class="card-title">📋 Historique des inscriptions</span></div>
  <div class="card-body" style="padding:0">
    @include('inscriptions._historique')
  </div>
</div>

</div>

@else
<div class="card">
  <div class="card-header"><span class="card-title">📋 Historique des inscriptions</span></div>
  <div class="card-body" style="padding:0">
    <div style="padding:40px;text-align:center;color:var(--text-muted)">
      <div style="font-size:40px;margin-bottom:12px">🔍</div>
      Saisissez un matricule pour afficher les inscriptions de l'étudiant
    </div>
  </div>
</div>
@endif
@endsection
