{{-- Import en masse avec inscription — portage de import_etudiants.php --}}
@extends('layouts.app', ['titre' => "Import d'étudiants en masse", 'fil' => 'Étudiants › Import'])

@section('contenu')
@if (session('details_import'))
<div class="card mb-20" style="border-color:#fca5a5">
  <div class="card-header" style="background:#fee2e2">
    <span class="card-title" style="color:#991b1b">⚠️ Détail des erreurs / doublons</span>
  </div>
  <div class="card-body">
    <ul style="margin:0;padding-left:20px;font-size:13px">
      @foreach (session('details_import') as $e)
      <li style="margin-bottom:4px">{{ $e }}</li>
      @endforeach
    </ul>
  </div>
</div>
@endif

<div style="display:grid;grid-template-columns:1fr 420px;gap:20px">

<div class="card">
  <div class="card-header"><span class="card-title">📥 Importer depuis un fichier Excel ou CSV</span></div>
  <div class="card-body">

    <div style="background:#eff6ff;border:1px solid #bfdbfe;border-radius:8px;padding:14px;margin-bottom:20px;display:flex;justify-content:space-between;align-items:center">
      <div>
        <div style="font-weight:700;color:#1e40af;margin-bottom:4px">📋 Template Excel (XLSX)</div>
        <div style="font-size:12px;color:#3b82f6">Téléchargez le modèle, remplissez-le dans Excel puis importez-le ici</div>
      </div>
      <a href="{{ route('import_etudiants.modele') }}" class="btn btn-secondary">⬇️ Télécharger le template</a>
    </div>

    <form method="post" action="{{ route('import_etudiants.importer') }}" enctype="multipart/form-data"
          data-confirm="Lancer l'import ? Les étudiants seront créés et inscrits.">
      @csrf

      <div style="display:grid;grid-template-columns:1fr 1fr;gap:12px">
        <div class="form-group">
          <label class="form-label" for="annee_id">Année académique <span class="req">*</span></label>
          <select name="annee_id" id="annee_id" class="form-control" required>
            <option value="">— Choisir —</option>
            @foreach ($annees as $a)
            <option value="{{ $a->id }}" @selected(old('annee_id', $anneeActive) == $a->id)>{{ $a->libelle }}{{ $a->active ? ' (En cours)' : '' }}</option>
            @endforeach
          </select>
        </div>
        <div class="form-group">
          <label class="form-label" for="filiere_id">Filière <span class="req">*</span></label>
          <select name="filiere_id" id="filiere_id" class="form-control" required>
            <option value="">— Choisir —</option>
            @foreach ($filieres as $f)<option value="{{ $f->id }}" @selected(old('filiere_id') == $f->id)>{{ $f->libelle }}</option>@endforeach
          </select>
        </div>
        <div class="form-group">
          <label class="form-label" for="niveau_id">Niveau <span class="req">*</span></label>
          <select name="niveau_id" id="niveau_id" class="form-control" required>
            <option value="">— Choisir —</option>
            @foreach ($niveaux as $n)<option value="{{ $n->id }}" @selected(old('niveau_id') == $n->id)>{{ $n->code }} — {{ $n->libelle }}</option>@endforeach
          </select>
        </div>
        <div class="form-group">
          <label class="form-label" for="montant_def">Montant par défaut (FCFA)</label>
          <input type="number" name="montant_def" id="montant_def" class="form-control" value="{{ old('montant_def', 10000) }}" min="0" step="500">
        </div>
        <div class="form-group">
          <label class="form-label" for="mode_def">Mode paiement par défaut</label>
          <select name="mode_def" id="mode_def" class="form-control">
            @foreach (['especes' => 'Espèces', 'mobile_money' => 'Mobile Money', 'mynita' => 'MyNITA', 'virement' => 'Virement'] as $val => $lib)
            <option value="{{ $val }}" @selected(old('mode_def') === $val)>{{ $lib }}</option>
            @endforeach
          </select>
        </div>
      </div>

      <div class="form-group">
        <label class="form-label" for="fichier">Fichier Excel ou CSV <span class="req">*</span></label>
        <input type="file" name="fichier" id="fichier" class="form-control" accept=".xlsx,.csv,.txt" required>
        <div style="font-size:12px;color:var(--text-muted);margin-top:4px">
          Le système détecte automatiquement le séparateur (tabulation, point-virgule ou virgule)
        </div>
      </div>

      <div style="background:#fef9c3;border:1px solid #fde047;border-radius:8px;padding:12px;margin-bottom:16px;font-size:12px">
        <strong>⚠️ Notes importantes :</strong>
        <ul style="margin:6px 0 0 16px;padding:0">
          <li>Les doublons (même nom+prénom+date de naissance) sont ignorés automatiquement</li>
          <li>Les matricules sont générés automatiquement (séquence)</li>
          <li>Les colonnes Montant et Mode peuvent être dans le fichier ou en valeur par défaut ci-dessus</li>
          <li>Le template s'ouvre directement dans Excel — remplissez les lignes et importez tel quel (format .xlsx ou .csv)</li>
        </ul>
      </div>

      <button type="submit" class="btn btn-primary w-100" style="padding:12px;font-size:15px">📥 Lancer l'import</button>
    </form>
  </div>
</div>

<div class="card">
  <div class="card-header"><span class="card-title">📋 Colonnes du fichier CSV</span></div>
  <div class="card-body" style="padding:0">
    <table style="width:100%;border-collapse:collapse;font-size:12px">
      <thead>
        <tr style="background:var(--primary);color:#fff">
          <th style="padding:8px 12px">Colonne</th>
          <th style="padding:8px 12px;text-align:center">Obligatoire</th>
        </tr>
      </thead>
      <tbody>
        @foreach ($colonnes as $col => $obligatoire)
        <tr style="border-bottom:1px solid var(--border);background:{{ $loop->odd ? '#fff' : 'var(--gray-50)' }}">
          <td style="padding:6px 12px;font-family:monospace;font-size:11px">{{ $col }}</td>
          <td style="padding:6px 12px;text-align:center">
            @if ($obligatoire)<span style="color:#166534;font-weight:700">✓ Oui</span>@else<span style="color:#6b7280">Optionnel</span>@endif
          </td>
        </tr>
        @endforeach
      </tbody>
    </table>
  </div>
</div>

</div>
@endsection
