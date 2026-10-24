{{-- Liste des enseignants — portage de enseignants.php (sans ?id=) --}}
@extends('layouts.app', ['titre' => 'Gestion des enseignants', 'fil' => 'Administratif › Enseignants'])

@section('contenu')
@include('enseignants._donnees')
<div style="display:grid;grid-template-columns:1fr 340px;gap:20px">
  <div class="card">
    <div class="card-header" style="display:flex;justify-content:space-between;align-items:center;gap:10px;flex-wrap:wrap">
      <span class="card-title">👨‍🏫 Enseignants ({{ $enseignants->count() }})</span>
      <form method="get" action="{{ route('enseignants') }}" style="display:flex;gap:6px;align-items:center;margin:0">
        <label class="form-label" for="aff_annee" style="margin:0;font-size:12px">Affectations de l'année :</label>
        <select name="aff_annee" id="aff_annee" class="form-control" style="width:auto" data-autosubmit="1">@include('enseignants._options_annee')</select>
      </form>
    </div>
    <div class="card-body" style="padding:0">
      @if ($enseignants->isEmpty())
      <div class="empty-state" style="padding:32px"><div class="icon">👨‍🏫</div><p>Aucun enseignant.</p></div>
      @else
      @php($departementsParId = $departements->pluck('libelle', 'id'))
      <table class="table">
        <thead><tr><th>Nom & Prénoms</th><th>Matricule</th><th>Grade</th><th>Spécialité</th><th>Département</th><th>Contact</th><th>Affectations</th><th>Actions</th></tr></thead>
        <tbody>
        @foreach ($enseignants as $e)
        @php($sesAff = $affectations->get($e->id, collect()))
        <tr>
          <td><a href="{{ route('enseignants', ['id' => $e->id] + $filtreQs) }}" style="font-weight:700;text-decoration:none;color:var(--blue-700)">{{ $e->nom }} {{ $e->prenoms }}</a></td>
          <td style="font-size:11px;color:var(--text-muted)">{{ $e->matricule ?? '—' }}</td>
          <td style="font-size:11px">{{ $e->grade ?? '—' }}</td>
          <td style="font-size:11px">{{ $e->specialite ?? '—' }}</td>
          <td style="font-size:11px">{{ $departementsParId[$e->departement_id] ?? '—' }}</td>
          <td style="font-size:11px">
            @if ($e->email)<div>{{ $e->email }}</div>@endif
            @if ($e->telephone)<div>{{ $e->telephone }}</div>@endif
          </td>
          <td style="text-align:center">
            @if ($sesAff->isNotEmpty())
            <span data-onclick="togglePopup" data-arg="{{ $e->id }}" style="background:var(--blue-50);color:var(--blue-700);padding:2px 10px;border-radius:10px;font-weight:600;cursor:pointer;border:1px solid #bfdbfe" title="Voir les matières">{{ $sesAff->count() }} 📚</span>
            <div id="popup-{{ $e->id }}" style="display:none;position:fixed;top:50%;left:50%;transform:translate(-50%,-50%);background:#fff;border:1px solid var(--border);border-radius:10px;box-shadow:0 8px 32px rgba(0,0,0,.18);z-index:9999;min-width:480px;max-width:640px;max-height:80vh;overflow:hidden">
              <div style="background:#1e3a5f;color:#fff;padding:12px 16px;display:flex;justify-content:space-between;align-items:center;border-radius:10px 10px 0 0">
                <span style="font-weight:700;font-size:14px">📚 {{ $e->nom }} {{ $e->prenoms }}</span>
                <button type="button" data-onclick="togglePopup" data-arg="{{ $e->id }}" style="background:rgba(255,255,255,.2);border:none;color:#fff;border-radius:6px;padding:2px 10px;cursor:pointer;font-size:16px" aria-label="Fermer">✕</button>
              </div>
              <div style="overflow-y:auto;max-height:calc(80vh - 50px)">
                <table style="width:100%;border-collapse:collapse;font-size:13px">
                  <thead>
                    <tr style="background:#f1f5f9;font-size:11px;text-transform:uppercase;color:var(--text-muted)">
                      <th style="padding:8px 12px;text-align:left">Matière</th>
                      <th style="padding:8px 12px;text-align:left">Filière / Niveau</th>
                      <th style="padding:8px 12px;text-align:left">Année</th>
                      <th style="padding:8px 12px;text-align:center">Charge</th>
                      @if ($droits['affecter'])<th style="padding:8px 12px;text-align:center">Action</th>@endif
                    </tr>
                  </thead>
                  <tbody>
                  @foreach ($sesAff as $ma)
                  <tr style="border-top:1px solid var(--border);background:{{ $loop->even ? '#f8faff' : '#fff' }}">
                    <td style="padding:8px 12px;font-weight:600">{{ $ma->matiere }}</td>
                    <td style="padding:8px 12px;font-size:12px">{{ $ma->filiere }} <span style="color:var(--text-muted)">/ {{ $ma->niveau }}</span></td>
                    <td style="padding:8px 12px;font-size:12px;color:var(--text-muted)">{{ $ma->annee }}</td>
                    <td style="padding:8px 12px;text-align:center"><span style="background:var(--blue-50);color:var(--blue-700);padding:1px 8px;border-radius:8px;font-size:11px">{{ $ma->type_charge }}</span></td>
                    @if ($droits['affecter'])
                    <td style="padding:8px 12px;text-align:center">
                      @if ($droits['modifier'] || (int) $ma->dep_id === $droits['departement'])
                      <form method="post" action="{{ route('enseignants.desaffecter') }}" style="display:inline" data-confirm="Retirer cette affectation ?">
                        @csrf
                        <input type="hidden" name="aff_annee" value="{{ $filtre }}">
                        <input type="hidden" name="id" value="{{ $ma->id }}">
                        <button type="submit" class="btn btn-sm btn-danger" title="Retirer" style="padding:2px 8px;font-size:11px">🗑️</button>
                      </form>
                      @endif
                    </td>
                    @endif
                  </tr>
                  @endforeach
                  </tbody>
                </table>
              </div>
            </div>
            <div id="overlay-{{ $e->id }}" data-onclick="togglePopup" data-arg="{{ $e->id }}" style="display:none;position:fixed;inset:0;background:rgba(0,0,0,.35);z-index:9998"></div>
            @else
            <span style="background:#f3f4f6;color:#9ca3af;padding:2px 10px;border-radius:10px;font-weight:600">0</span>
            @endif
          </td>
          <td>
            <div style="display:flex;gap:4px">
              <a href="{{ route('enseignants', ['id' => $e->id] + $filtreQs) }}" class="btn btn-sm btn-primary" title="Dossier">📁</a>
              @if ($droits['modifier'])
              <form method="post" action="{{ route('enseignants.supprimer') }}" style="display:inline" data-confirm="Supprimer cet enseignant ?">
                @csrf
                <input type="hidden" name="id" value="{{ $e->id }}">
                <button type="submit" class="btn btn-sm btn-danger" title="Supprimer">🗑️</button>
              </form>
              @endif
            </div>
          </td>
        </tr>
        @endforeach
        </tbody>
      </table>
      @endif
    </div>
  </div>

  @if ($droits['modifier'])
  <div class="card">
    <div class="card-header"><span class="card-title">➕ Ajouter un enseignant</span></div>
    <div class="card-body">
      <form method="post" action="{{ route('enseignants.enregistrer') }}" enctype="multipart/form-data">
        @csrf
        <div class="form-group"><label class="form-label" for="a-nom">Nom *</label>
          <input type="text" name="nom" id="a-nom" class="form-control" placeholder="NOM" value="{{ old('nom') }}" required></div>
        <div class="form-group"><label class="form-label" for="a-prenoms">Prénoms *</label>
          <input type="text" name="prenoms" id="a-prenoms" class="form-control" placeholder="Prénoms" value="{{ old('prenoms') }}" required></div>
        <div class="form-group"><label class="form-label" for="a-matricule">Matricule</label>
          <input type="text" name="matricule" id="a-matricule" class="form-control" value="{{ old('matricule') }}"></div>
        <div class="form-group"><label class="form-label" for="a-grade">Grade</label>
          <input type="text" name="grade" id="a-grade" class="form-control" placeholder="Ex: Maître Assistant" value="{{ old('grade') }}"></div>
        <div class="form-group"><label class="form-label" for="a-specialite">Spécialité</label>
          <input type="text" name="specialite" id="a-specialite" class="form-control" value="{{ old('specialite') }}"></div>
        <div class="form-group"><label class="form-label" for="a-email">Email</label>
          <input type="email" name="email" id="a-email" class="form-control" value="{{ old('email') }}"></div>
        <div class="form-group"><label class="form-label" for="a-telephone">Téléphone</label>
          <input type="text" name="telephone" id="a-telephone" class="form-control" value="{{ old('telephone') }}"></div>
        @include('enseignants._faculte_departement', ['prefixe' => 'add', 'faculteActuelle' => null, 'departementActuel' => null])
        <button type="submit" class="btn btn-primary w-100">➕ Ajouter</button>
      </form>
    </div>
  </div>
  @endif
</div>
@endsection

@push('scripts')
<script src="{{ \App\Support\Ressource::url('assets/js/enseignants.js') }}"></script>
@endpush
