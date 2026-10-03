{{-- Import des notes — portage de notes_importer.php --}}
@extends('layouts.app', ['titre' => 'Import des notes', 'fil' => 'Notes › Import'])

@section('contenu')
@if ($resultat)
<div class="card mb-20">
  <div class="card-header"><span class="card-title">📊 Résultat de l'import — {{ $resultat['matiere'] }}</span></div>
  <div class="card-body">
    <div style="display:grid;grid-template-columns:repeat({{ $resultat['absents'] ? 4 : 3 }},1fr);gap:16px;margin-bottom:16px">
      <div style="text-align:center;padding:16px;background:var(--green-50);border-radius:8px;border:1px solid var(--green-200)">
        <div style="font-size:32px;font-weight:700;color:var(--green-700)">{{ $resultat['ok'] }}</div><div style="font-size:12px;color:var(--green-600)">Notes importées</div>
      </div>
      <div style="text-align:center;padding:16px;background:var(--amber-50);border-radius:8px;border:1px solid var(--amber-200)">
        <div style="font-size:32px;font-weight:700;color:var(--amber-600)">{{ $resultat['ignorees'] }}</div><div style="font-size:12px;color:var(--amber-600)">Ignorées (vides ou déjà validées)</div>
      </div>
      @if ($resultat['absents'])
      <div style="text-align:center;padding:16px;background:#fff7ed;border-radius:8px;border:1px solid #fed7aa">
        <div style="font-size:32px;font-weight:700;color:#c2410c">{{ $resultat['absents'] }}</div><div style="font-size:12px;color:#c2410c">Absents → Déf. (—)</div>
      </div>
      @endif
      <div style="text-align:center;padding:16px;background:var(--red-50);border-radius:8px;border:1px solid var(--red-200)">
        <div style="font-size:32px;font-weight:700;color:var(--red-600)">{{ $resultat['erreurs'] }}</div><div style="font-size:12px;color:var(--red-600)">Erreurs</div>
      </div>
    </div>
    <div style="font-size:11px;color:var(--text-muted);margin-bottom:12px">Total étudiants du PV : {{ $resultat['total'] }} · Résultats recalculés automatiquement.</div>
    @if ($resultat['lignes'])
    <div style="background:var(--red-50);border:1px solid var(--red-200);border-radius:6px;padding:12px;margin-bottom:16px">
      <p style="font-weight:600;color:var(--red-700);margin-bottom:8px">⚠️ Erreurs détectées :</p>
      @foreach ($resultat['lignes'] as $err)<div style="font-size:12px;color:var(--red-600);padding:2px 0">• {{ $err }}</div>@endforeach
    </div>
    @endif
    <div class="flex gap-10">
      <a href="{{ route('pv_detail', ['id' => $pv->id]) }}" class="btn btn-primary">📋 Voir le PV</a>
      <a href="{{ route('notes_importer', ['pv_id' => $pv->id]) }}" class="btn btn-secondary">↩️ Importer une autre matière</a>
    </div>
  </div>
</div>
@endif

<div class="card mb-20" style="background:var(--blue-50);border-color:var(--blue-200)">
  <div class="card-body" style="padding:12px 16px">
    <div class="flex gap-16 align-items-center">
      <span class="badge badge-blue">{{ $pv->niveau }}</span><span style="font-weight:600">{{ $pv->filiere }}</span>
      <span class="text-muted">·</span><span>{{ $pv->sem_libelle }}</span><span class="text-muted">·</span><span>{{ $pv->annee }}</span>
      <span class="text-muted">·</span><span>{{ $nbEtudiants }} étudiants</span>
    </div>
  </div>
</div>

@if ($importComplet)
@inject('navigation', 'App\Navigation\Menu')
<div class="card mb-20" style="border:2px solid var(--blue-400)">
  <div class="card-header" style="background:var(--blue-800);color:#fff"><span class="card-title" style="color:#fff">📊 Import PV complet — depuis le fichier Excel exporté</span></div>
  <div class="card-body">
    <div style="display:grid;grid-template-columns:1fr auto;gap:16px;align-items:center">
      <div style="font-size:13px;color:var(--text-muted)">
        Importez le fichier Excel (ou CSV) exporté depuis <strong>📊 Exporter Excel</strong> du PV.
        Toutes les notes Exam. et TD de tous les étudiants seront importées en une seule opération,
        puis les résultats recalculés.
      </div>
      <a href="{{ $navigation->lien('pv_exporter', ['id' => $pv->id]) }}" class="btn btn-secondary" style="white-space:nowrap">📊 Télécharger le fichier Excel</a>
    </div>
    <form method="post" action="{{ route('notes_importer.pv') }}" enctype="multipart/form-data" style="margin-top:14px;display:flex;gap:12px;align-items:flex-end"
          data-confirm="Importer toutes les notes du PV ? Les notes existantes seront mises à jour.">
      @csrf
      <input type="hidden" name="pv_id" value="{{ $pv->id }}">
      <div class="form-group" style="flex:1;margin:0">
        <label class="form-label" for="fichier_pv">Fichier Excel (.xlsx) ou CSV (.csv)</label>
        <input type="file" name="fichier_pv" id="fichier_pv" class="form-control" accept=".xlsx,.csv" required>
      </div>
      <button type="submit" class="btn btn-primary">📥 Importer le PV complet</button>
    </form>
  </div>
</div>
@endif

<div style="display:grid;grid-template-columns:1fr 380px;gap:20px">
  <div class="card">
    <div class="card-header"><span class="card-title">📥 Étape 1 — Télécharger le modèle</span></div>
    <div class="card-body" style="padding:0">
      <table class="table table-sm">
        <thead><tr><th>UE</th><th>Matière</th><th>Max Exam.</th><th>Max TD</th><th>Action</th></tr></thead>
        <tbody>
        @foreach ($matieres as $m)
        <tr>
          <td><span class="badge badge-blue" style="font-size:10px">{{ $m->ue_code }}</span></td>
          <td style="font-weight:500">{{ $m->libelle }}</td>
          <td>{{ (float) $m->note_max_examen }}</td>
          <td>{{ (float) $m->note_max_cc > 0 ? (float) $m->note_max_cc : '—' }}</td>
          <td><a href="{{ route('notes_importer.modele', ['pv_id' => $pv->id, 'mat_id' => $m->id]) }}" class="btn btn-sm btn-secondary" title="Télécharger le modèle Excel">⬇️ Modèle Excel</a></td>
        </tr>
        @endforeach
        </tbody>
      </table>
    </div>
  </div>

  <div>
    <div class="card">
      <div class="card-header"><span class="card-title">📤 Étape 2 — Importer les notes</span></div>
      <div class="card-body">
        <form method="post" action="{{ route('notes_importer.matiere') }}" enctype="multipart/form-data">
          @csrf
          <input type="hidden" name="pv_id" value="{{ $pv->id }}">
          <div class="form-group">
            <label class="form-label" for="mat_id">Matière <span class="req">*</span></label>
            <select name="mat_id" id="mat_id" class="form-control" required>
              <option value="">— Choisir une matière —</option>
              @foreach ($matieres as $m)<option value="{{ $m->id }}" @selected($matiereChoisie == $m->id)>{{ $m->ue_code }} · {{ $m->libelle }}</option>@endforeach
            </select>
          </div>
          <div class="form-group">
            <label class="form-label" for="fichier">Fichier Excel ou CSV <span class="req">*</span></label>
            <input type="file" name="fichier" id="fichier" class="form-control" accept=".xlsx,.csv,.txt" required>
            <div class="form-hint">Modèle rempli (.xlsx) ou CSV (séparateur ; ou ,)</div>
          </div>
          <div style="background:var(--blue-50);border:1px solid var(--blue-200);border-radius:6px;padding:12px;margin-bottom:16px;font-size:12px">
            <p style="font-weight:600;color:var(--blue-800);margin-bottom:6px">📋 Instructions :</p>
            <ol style="padding-left:16px;line-height:1.8;color:var(--blue-700)">
              <li>Téléchargez le modèle de la matière</li>
              <li>Remplissez les colonnes Examen (et TD si applicable)</li>
              <li>Importez le fichier ici (Excel ou CSV)</li>
            </ol>
          </div>
          <button type="submit" class="btn btn-primary w-100">📤 Importer les notes</button>
        </form>
      </div>
    </div>
    <div class="card mt-16"><div class="card-body" style="padding:12px"><a href="{{ route('pv_detail', ['id' => $pv->id]) }}" class="btn btn-secondary w-100">← Retour au PV</a></div></div>
  </div>
</div>
@endsection
