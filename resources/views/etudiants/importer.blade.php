{{-- Import d'étudiants sans inscription — portage de etudiant_importer.php --}}
@extends('layouts.app', ['titre' => 'Importer / Ajouter des étudiants', 'fil' => 'Étudiants › Import'])

@section('contenu')
@foreach (session('erreurs_import', []) as $e)
<div class="alert alert-danger">⚠️ {{ $e }}</div>
@endforeach

<div style="background:var(--blue-50);border:1px solid var(--blue-200);border-radius:8px;padding:14px 18px;margin-bottom:20px;display:flex;gap:14px;align-items:flex-start">
  <span style="font-size:24px">ℹ️</span>
  <div style="font-size:13px;color:var(--blue-700)">
    <strong>Fonctionnement :</strong><br>
    Les étudiants sont ajoutés <strong>sans promotion</strong>, sans matricule.<br>
    Ils viennent ensuite faire leur <strong>inscription à la scolarité</strong> (depuis leur dossier → bouton 🎓 Inscrire) pour payer les <strong>10 000 FCFA</strong> de frais d'inscription.<br>
    C'est à ce moment que le matricule définitif est généré automatiquement.
  </div>
</div>

<div class="card">
  <div class="card-header"><span class="card-title">📥 Import depuis liste CSV / Excel</span></div>
  <div class="card-body">
    <form method="post" action="{{ route('etudiant_importer.importer') }}" enctype="multipart/form-data">
      @csrf

      <div style="background:var(--green-50);border:1px solid var(--green-200);border-radius:8px;padding:14px 18px;margin-bottom:20px;display:flex;align-items:center;justify-content:space-between">
        <div>
          <div style="font-weight:700;font-size:13px;color:var(--green-700)">📥 Modèle Excel à remplir</div>
          <div style="font-size:12px;color:var(--green-600);margin-top:2px">Téléchargez le fichier modèle, remplissez-le, puis importez-le ici.</div>
        </div>
        <a href="{{ route('fichiers.modele_import') }}" class="btn btn-primary" style="background:var(--green-700);border-color:var(--green-700);white-space:nowrap">
          ⬇️ Télécharger le modèle Excel
        </a>
      </div>

      <div style="display:grid;grid-template-columns:1fr 1fr;gap:16px;align-items:start;margin-bottom:16px">
        <div class="form-group" style="margin:0">
          <label class="form-label" for="fichier_import">Fichier à importer <span class="req">*</span></label>
          <div style="font-size:12px;color:var(--text-muted);margin-bottom:6px">
            Formats : <strong>.xlsx</strong>, <strong>.csv</strong>, <strong>.txt</strong> — export du portail des candidatures reconnu automatiquement
          </div>
          <input type="file" name="fichier_import" id="fichier_import" class="form-control"
            accept=".xlsx,.csv,.txt" required style="padding:8px;cursor:pointer">
          <div id="file-info" class="form-hint" style="margin-top:4px;color:var(--blue-700)"></div>
        </div>

        <div class="form-group" style="margin:0">
          <label class="form-label" for="filiere_id_import">Filière <span class="req">*</span></label>
          <div style="font-size:12px;color:var(--text-muted);margin-bottom:6px">
            Tous les étudiants du fichier seront rattachés à cette filière
          </div>
          <select name="filiere_id_import" id="filiere_id_import" class="form-control" required>
            <option value="">— Sélectionner la filière —</option>
            @foreach ($filieres->groupBy('faculte') as $faculte => $liste)
            <optgroup label="{{ $faculte }}">
              @foreach ($liste as $f)<option value="{{ $f->id }}" @selected(old('filiere_id_import') == $f->id)>{{ $f->libelle }}</option>@endforeach
            </optgroup>
            @endforeach
          </select>
        </div>
      </div>

      <button type="submit" class="btn btn-primary btn-lg">📥 Importer les étudiants</button>
    </form>
  </div>
</div>
@endsection

@push('scripts')
<script src="{{ asset('assets/js/import-fichier.js') }}"></script>
@endpush
