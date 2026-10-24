{{-- Import des photos en masse — portage de import_photos.php --}}
@extends('layouts.app', ['titre' => 'Import photos étudiants', 'fil' => 'Étudiants › Photos'])

@section('contenu')
@foreach ($erreurs as $e)
<div class="alert alert-danger">⚠️ {{ $e }}</div>
@endforeach

<div style="display:grid;grid-template-columns:1fr 380px;gap:20px">

  <div>
    <div class="card mb-16">
      <div class="card-header"><span class="card-title">📦 Importer des photos</span></div>
      <div class="card-body">
        <div style="background:var(--blue-50);border:1px solid var(--blue-200);border-radius:8px;padding:14px;margin-bottom:16px;font-size:13px;color:var(--blue-700)">
          <strong>📋 Format attendu :</strong><br>
          Chaque photo (ou son nom dans le ZIP) doit être nommée par le <strong>matricule</strong> de l'étudiant.<br><br>
          Exemples de noms valides :
          <ul style="margin:8px 0 0 16px;line-height:2">
            <li><code>00001.jpg</code> → étudiant matricule 00001</li>
            <li><code>00002.png</code> → étudiant matricule 00002</li>
            <li><code>ABDOU_IBRAHIM.jpg</code> → recherche par nom</li>
          </ul>
          Vous pouvez importer directement des <strong>images</strong> (une ou plusieurs à la fois) ou un <strong>ZIP</strong> qui en contient plusieurs — les deux peuvent être sélectionnés ensemble.<br><br>
          Formats acceptés : <strong>JPG, JPEG, PNG, GIF, WEBP, BMP</strong> et <strong>ZIP</strong> — Taille max par photo : 5 Mo
        </div>

        <form method="post" action="{{ route('import_photos.importer') }}" enctype="multipart/form-data">
          @csrf
          <div class="form-group">
            <label class="form-label" for="zip-input">Photos et/ou ZIP <span class="req">*</span></label>
            <input type="file" name="photos[]" class="form-control" multiple
              accept=".zip,.jpg,.jpeg,.png,.gif,.webp,.bmp" required
              style="padding:8px;cursor:pointer" id="zip-input">
            <div id="zip-info" class="form-hint" style="margin-top:6px"></div>
          </div>
          <button type="submit" class="btn btn-primary btn-lg w-100">📸 Importer les photos</button>
        </form>
      </div>
    </div>

    @if ($resultats)
    <div class="card">
      <div class="card-header">
        <span class="card-title">📊 Résultats — {{ $importees }} importée(s) / {{ $ignorees }} ignorée(s)</span>
      </div>
      <div class="card-body" style="padding:0;max-height:400px;overflow-y:auto">
        <table class="table" style="font-size:12px">
          <thead><tr><th>Fichier</th><th>Étudiant</th><th style="text-align:center">Statut</th><th>Aperçu</th></tr></thead>
          <tbody>
          @foreach ($resultats as $r)
          <tr>
            <td style="font-family:monospace;font-size:11px">{{ $r['fichier'] }}</td>
            <td style="font-size:11px">{{ $r['msg'] }}</td>
            <td style="text-align:center">
              @if ($r['statut'] === 'ok')
              <span style="color:var(--green-700);font-weight:600">✅</span>
              @elseif ($r['statut'] === 'skip')
              <span style="color:var(--amber-600);font-weight:600">⚠️</span>
              @else
              <span style="color:var(--red-600);font-weight:600">❌</span>
              @endif
            </td>
            <td>
              @if ($r['photo'])
              <img src="{{ $r['photo'] }}" alt="" style="width:32px;height:32px;border-radius:50%;object-fit:cover;border:1px solid var(--border)">
              @else—@endif
            </td>
          </tr>
          @endforeach
          </tbody>
        </table>
      </div>
    </div>
    @endif
  </div>

  <div>
    @php($total = $avecPhoto + $sansPhoto)
    <div class="card mb-16">
      <div class="card-header"><span class="card-title">📊 État des photos</span></div>
      <div class="card-body">
        <div style="display:grid;grid-template-columns:1fr 1fr;gap:12px;margin-bottom:16px">
          <div style="text-align:center;padding:14px;background:var(--green-50);border-radius:8px">
            <div style="font-size:22px;font-weight:800;color:var(--green-700)">{{ $avecPhoto }}</div>
            <div style="font-size:11px;color:var(--green-700);text-transform:uppercase">Avec photo</div>
          </div>
          <div style="text-align:center;padding:14px;background:var(--amber-50);border-radius:8px">
            <div style="font-size:22px;font-weight:800;color:var(--amber-600)">{{ $sansPhoto }}</div>
            <div style="font-size:11px;color:var(--amber-600);text-transform:uppercase">Sans photo</div>
          </div>
        </div>
        @if ($total > 0)
        <div style="background:var(--gray-100);border-radius:4px;height:10px;overflow:hidden">
          <div style="background:var(--green-500);height:100%;width:{{ round($avecPhoto / $total * 100) }}%;transition:width .5s"></div>
        </div>
        <div style="text-align:center;font-size:11px;color:var(--text-muted);margin-top:6px">
          {{ round($avecPhoto / $total * 100) }}% des étudiants ont une photo
        </div>
        @endif
      </div>
    </div>

    <div class="card mb-16">
      <div class="card-header"><span class="card-title">💡 Comment préparer le ZIP</span></div>
      <div class="card-body" style="font-size:12px;line-height:2">
        <div style="margin-bottom:10px">
          <strong>1.</strong> Rassemblez toutes les photos dans un dossier<br>
          <strong>2.</strong> Renommez chaque photo avec le matricule de l'étudiant<br>
          &nbsp;&nbsp;&nbsp;&nbsp;<code style="background:var(--gray-50);padding:1px 6px;border-radius:3px">00001.jpg</code> pour le matricule 00001<br>
          <strong>3.</strong> Compressez le dossier en ZIP<br>
          <strong>4.</strong> Importez ici
        </div>
        <div style="background:var(--blue-50);border-radius:6px;padding:10px;font-size:11px;color:var(--blue-700)">
          💡 <strong>Astuce Windows :</strong> Sélectionnez toutes les photos → Clic droit → Envoyer vers → Dossier compressé
        </div>
      </div>
    </div>

    @if ($sansPhotoListe->isNotEmpty())
    <div class="card">
      <div class="card-header"><span class="card-title">⚠️ Sans photo ({{ $sansPhoto }})</span></div>
      <div class="card-body" style="padding:0;max-height:300px;overflow-y:auto">
        <table class="table" style="font-size:12px">
          <thead><tr><th>Matricule</th><th>Nom & Prénoms</th><th></th></tr></thead>
          <tbody>
          @foreach ($sansPhotoListe as $et)
          <tr>
            <td style="font-family:monospace;color:var(--text-muted)">{{ $et->numero ?? '—' }}</td>
            <td style="font-weight:600">{{ $et->nom }} {{ $et->prenoms }}</td>
            <td><a href="{{ route('dossier_etudiant', ['id' => $et->id]) }}" class="btn btn-sm btn-secondary" style="padding:2px 8px;font-size:10px" title="Dossier">📁</a></td>
          </tr>
          @endforeach
          @if ($sansPhoto > 10)
          <tr><td colspan="3" style="text-align:center;color:var(--text-muted);font-size:11px">... et {{ $sansPhoto - 10 }} autres</td></tr>
          @endif
          </tbody>
        </table>
      </div>
    </div>
    @endif
  </div>

</div>
@endsection

@push('scripts')
<script src="{{ \App\Support\Ressource::url('assets/js/import-photos.js') }}"></script>
@endpush
