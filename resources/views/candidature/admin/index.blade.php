{{-- Administration des candidatures — portage de pages/admin/portail_candidats_admin.php --}}
@extends('layouts.app', ['titre' => 'Candidatures bacheliers', 'fil' => 'Inscriptions › Portail candidats'])

@use('App\Services\Candidatures')
@php
  $t = $totaux;
  $lien = fn (array $p = []) => route('portail_candidats_admin', $p + ['tab' => $onglet]);
@endphp

@section('contenu')
<div style="display:flex;gap:4px;flex-wrap:wrap;margin-bottom:16px" role="tablist">
  @foreach (\App\Http\Controllers\Candidature\CandidaturesAdminController::ONGLETS as $cle => $libelle)
  <a href="{{ route('portail_candidats_admin', ['tab' => $cle]) }}" class="btn btn-sm {{ $onglet === $cle ? 'btn-primary' : 'btn-secondary' }}" role="tab" @if ($onglet === $cle) aria-selected="true" @endif>{{ $libelle }}</a>
  @endforeach
</div>

@if (in_array($onglet, ['stats', 'avancees'], true))
<form method="get" action="{{ route('portail_candidats_admin') }}" style="margin-bottom:16px;display:flex;gap:10px;align-items:center;flex-wrap:wrap">
  <input type="hidden" name="tab" value="{{ $onglet }}">
  <label for="annee_bac" style="font-size:13px;font-weight:600">📅 Année du BAC :</label>
  <select name="annee_bac" id="annee_bac" class="form-control" style="width:auto" data-autosubmit="1">
    <option value="">Toutes les années</option>
    @foreach ($anneesBac as $a)<option value="{{ $a }}" @selected($a === $anneeBac)>{{ $a }}</option>@endforeach
  </select>
  @if ($anneeBac !== '')<a href="{{ route('portail_candidats_admin', ['tab' => $onglet]) }}" class="btn btn-secondary btn-sm">↺ Toutes années</a>@endif
</form>
<script type="application/json" id="donnees-candidatures">@json($graphiques)</script>
@endif

@if ($onglet === 'stats')
<div style="display:grid;grid-template-columns:repeat(auto-fit,minmax(170px,1fr));gap:12px;margin-bottom:16px">
  @foreach ([['📊', 'Total dossiers', $t['total'], '#5b21b6,#7c3aed'], ['✅', 'Acceptés', $t['accepte'], '#16a34a,#22c55e'], ['⏳', 'En attente', $t['soumis'], '#f59e0b,#d97706'], ['❌', 'Refusés', $t['refuse'], '#ef4444,#dc2626']] as [$ic, $lb, $val, $deg])
  <div style="background:linear-gradient(135deg,{{ $deg }});color:#fff;border-radius:12px;padding:16px"><div style="font-size:20px">{{ $ic }}</div><div style="font-size:11px;opacity:.85">{{ $lb }}</div><div style="font-size:26px;font-weight:800">{{ $val }}</div></div>
  @endforeach
</div>
<div style="display:grid;grid-template-columns:repeat(auto-fit,minmax(320px,1fr));gap:14px">
  <div class="card" style="padding:14px"><h3 style="font-size:13px;margin-bottom:8px">📚 Top Filières (1er choix)</h3><div class="graphe" style="height:280px"><canvas id="c_fil" aria-label="Top filières"></canvas></div></div>
  <div class="card" style="padding:14px"><h3 style="font-size:13px;margin-bottom:8px">🎓 Par Type de Bac</h3><div class="graphe" style="height:280px"><canvas id="c_bac" aria-label="Par type de bac"></canvas></div></div>
  <div class="card" style="padding:14px"><h3 style="font-size:13px;margin-bottom:8px">👥 Genre</h3><div class="graphe" style="height:260px"><canvas id="c_gen" aria-label="Genre" data-hommes="{{ $t['hommes'] }}" data-femmes="{{ $t['femmes'] }}"></canvas></div></div>
  <div class="card" style="padding:14px"><h3 style="font-size:13px;margin-bottom:8px">🏛️ Par Faculté (tous choix)</h3><div class="graphe" style="height:260px"><canvas id="c_fac" aria-label="Par faculté"></canvas></div></div>
  <div class="card" style="padding:14px"><h3 style="font-size:13px;margin-bottom:8px">📅 Évolution des dépôts</h3><div class="graphe" style="height:240px"><canvas id="c_evo" aria-label="Évolution des dépôts"></canvas></div></div>
  <div class="card" style="padding:14px"><h3 style="font-size:13px;margin-bottom:8px">📋 Répartition par statut</h3>
    @foreach ([['Soumis', $t['soumis'], '#854d0e', '#fef9c3'], ['En cours', $t['en_cours'], '#1e40af', '#dbeafe'], ['Accepté', $t['accepte'], '#166534', '#dcfce7'], ['Refusé', $t['refuse'], '#991b1b', '#fee2e2']] as [$s, $n, $fg, $bg])
    <div style="display:flex;justify-content:space-between;align-items:center;padding:8px 12px;background:{{ $bg }};border-radius:8px;margin-bottom:8px"><span style="font-weight:600;color:{{ $fg }};font-size:12px">{{ $s }}</span><span style="font-weight:800;color:{{ $fg }};font-size:20px">{{ $n }}</span></div>
    @endforeach
  </div>
</div>

@elseif ($onglet === 'avancees')
@php $tauxAppro = $t['total'] > 0 ? round($t['accepte'] / $t['total'] * 100, 1) : 0; $pctF = $t['total'] > 0 ? round($t['femmes'] / $t['total'] * 100, 1) : 0; @endphp
<div style="display:grid;grid-template-columns:repeat(auto-fit,minmax(320px,1fr));gap:14px">
  <div class="card" style="padding:14px"><h3 style="font-size:13px;margin-bottom:8px">👥 × 🎓 Genre × Type de Bac</h3><div class="graphe" style="height:310px"><canvas id="c_gb" aria-label="Genre par type de bac"></canvas></div></div>
  <div class="card" style="padding:14px"><h3 style="font-size:13px;margin-bottom:8px">🏆 Mentions par Filière</h3><div class="graphe" style="height:310px"><canvas id="c_mpf" aria-label="Mentions par filière"></canvas></div></div>
  <div class="card" style="padding:14px"><h3 style="font-size:13px;margin-bottom:8px">🌍 Répartition Géographique</h3><div class="graphe" style="height:340px"><canvas id="c_reg" aria-label="Répartition géographique"></canvas></div></div>
  <div class="card" style="padding:14px"><h3 style="font-size:13px;margin-bottom:8px">📈 Indices de Performance</h3>
    @foreach ([["Taux d'approbation", $tauxAppro.'%'], ['Part des femmes', $pctF.'%'], ['Mention la plus fréquente', $graphiques['mention']->first()->libelle ?? '—'], ['Filière la plus demandée', $graphiques['filieres']->first()->libelle ?? '—']] as [$lb, $val])
    <div style="display:flex;justify-content:space-between;padding:10px 12px;border-bottom:1px solid var(--border);font-size:13px"><span>{{ $lb }}</span><strong>{{ $val }}</strong></div>
    @endforeach
  </div>
</div>

@elseif ($onglet === 'dossiers')
<details class="card mb-16" @if ($errors->any() || session('erreur')) open @endif>
  <summary class="btn btn-primary" style="margin:12px">➕ Nouveau dossier (dépôt pour un candidat)</summary>
  <div class="card-body">
    <p style="font-size:12px;color:var(--text-muted);margin-bottom:14px">À utiliser pour un bachelier venu en personne, par téléphone, ou sans accès pratique au portail en ligne. Le dossier est directement marqué « soumis », comme un dépôt classique.</p>
    <form method="post" action="{{ route('portail_candidats_admin.creer') }}" enctype="multipart/form-data" autocomplete="off">
      @csrf
      <div style="display:grid;grid-template-columns:repeat(auto-fit,minmax(200px,1fr));gap:10px">
        <div class="form-group"><label class="form-label" for="a-nom">Nom</label><input type="text" name="nom" id="a-nom" class="form-control" required maxlength="100"></div>
        <div class="form-group"><label class="form-label" for="a-prenom">Prénom</label><input type="text" name="prenom" id="a-prenom" class="form-control" required maxlength="150"></div>
        <div class="form-group"><label class="form-label" for="a-ddn">Né(e) le</label><input type="date" name="date_naissance" id="a-ddn" class="form-control"></div>
        <div class="form-group"><label class="form-label" for="a-lieu">Lieu de naissance</label><input type="text" name="lieu_naissance" id="a-lieu" class="form-control" maxlength="200"></div>
        <div class="form-group"><label class="form-label" for="a-sexe">Sexe</label><select name="sexe" id="a-sexe" class="form-control"><option value="M">Masculin</option><option value="F">Féminin</option></select></div>
        <div class="form-group"><label class="form-label" for="a-nat">Nationalité</label><input type="text" name="nationalite" id="a-nat" class="form-control" value="Nigérienne" maxlength="100"></div>
        <div class="form-group"><label class="form-label" for="a-email">Email</label><input type="email" name="email" id="a-email" class="form-control" required maxlength="150"></div>
        <div class="form-group"><label class="form-label" for="a-tel">Téléphone</label><input type="text" name="telephone" id="a-tel" class="form-control" maxlength="30"></div>
        <div class="form-group" style="grid-column:1/-1"><label class="form-label" for="a-adr">Adresse</label><input type="text" name="adresse" id="a-adr" class="form-control" maxlength="1000"></div>
      </div>
      <h4 style="font-size:12px;font-weight:700;margin:16px 0 8px">🎓 Baccalauréat</h4>
      <div style="display:grid;grid-template-columns:repeat(auto-fit,minmax(160px,1fr));gap:10px">
        <div class="form-group"><label class="form-label" for="a-bt">Type</label><select name="bac_type" id="a-bt" class="form-control"><option value="">—</option>@foreach (Candidatures::BACS as $code => $lbl)<option value="{{ $code }}">{{ $lbl }}</option>@endforeach</select></div>
        <div class="form-group"><label class="form-label" for="a-bm">Mention</label><select name="bac_mention" id="a-bm" class="form-control"><option value="">—</option>@foreach (Candidatures::MENTIONS as $m)<option value="{{ $m }}">{{ $m }}</option>@endforeach</select></div>
        <div class="form-group"><label class="form-label" for="a-bl">Lieu</label><select name="bac_lieu" id="a-bl" class="form-control"><option value="">—</option>@foreach (Candidatures::LIEUX as $l)<option value="{{ $l }}">{{ $l }}</option>@endforeach</select></div>
        <div class="form-group"><label class="form-label" for="a-ba">Année</label><input type="text" name="bac_annee" id="a-ba" class="form-control" placeholder="Ex : 2025-2026" maxlength="20"></div>
        <div class="form-group" style="grid-column:1/-1"><label class="form-label" for="a-be">Établissement</label><input type="text" name="bac_etablissement" id="a-be" class="form-control" maxlength="200"></div>
      </div>
      <h4 style="font-size:12px;font-weight:700;margin:16px 0 8px">📎 Documents apportés en personne (PDF, JPG, PNG — 3 Mo)</h4>
      <div style="display:grid;grid-template-columns:repeat(auto-fit,minmax(220px,1fr));gap:10px">
        @foreach (Candidatures::PIECES as $champ => [$col, $ic, $lbl])
        <div class="form-group"><label class="form-label" for="a-{{ $champ }}">{{ $ic }} {{ $lbl }}</label><input type="file" name="{{ $champ }}" id="a-{{ $champ }}" accept="{{ $champ === 'photo' ? '.jpg,.jpeg,.png' : '.jpg,.jpeg,.png,.pdf' }}" class="form-control"></div>
        @endforeach
      </div>
      <h4 style="font-size:12px;font-weight:700;margin:16px 0 8px">📋 Choix de filières</h4>
      <div style="display:grid;grid-template-columns:repeat(auto-fit,minmax(220px,1fr));gap:10px">
        @foreach ([1 => '1er choix', 2 => '2e choix', 3 => '3e choix'] as $n => $lbl)
        <div class="form-group"><label class="form-label" for="a-choix{{ $n }}">{{ $lbl }}</label>
          <select name="choix{{ $n }}" id="a-choix{{ $n }}" class="form-control" @required($n === 1)><option value="">—</option>@foreach ($filieres as $f)<option value="{{ $f->id }}">{{ $f->libelle }}</option>@endforeach</select></div>
        @endforeach
      </div>
      <button type="submit" class="btn btn-primary" style="margin-top:8px">💾 Enregistrer le dossier</button>
    </form>
  </div>
</details>

<form method="get" action="{{ route('portail_candidats_admin') }}" style="display:flex;gap:8px;flex-wrap:wrap;margin-bottom:12px">
  <input type="hidden" name="tab" value="dossiers">
  <input type="text" name="q" value="{{ $q }}" class="form-control" style="flex:1;min-width:200px" placeholder="Nom, prénom, n° dossier ou n° dépôt..." aria-label="Rechercher un dossier">
  <select name="statut" class="form-control" style="width:auto" aria-label="Statut">
    <option value="">Tous les statuts</option>
    @foreach (['soumis', 'en_cours', 'accepte', 'refuse', 'incomplet'] as $s)<option value="{{ $s }}" @selected($statut === $s)>{{ Candidatures::STATUTS[$s][0] }} {{ Candidatures::STATUTS[$s][1] }}</option>@endforeach
  </select>
  <select name="annee_bac" class="form-control" style="width:auto" aria-label="Année du bac">
    <option value="">Toutes années BAC</option>@foreach ($anneesBac as $a)<option value="{{ $a }}" @selected($a === $anneeBac)>{{ $a }}</option>@endforeach
  </select>
  <button type="submit" class="btn btn-primary">🔍 Rechercher</button>
  <a href="{{ route('portail_candidats_admin', ['tab' => 'dossiers']) }}" class="btn btn-secondary">↺ Réinitialiser</a>
</form>
<div class="card"><div style="overflow-x:auto"><table class="table" style="font-size:12px">
  <thead><tr><th>Photo</th><th>N° dossier</th><th>Candidat</th><th>BAC</th><th>1er choix</th><th>Statut</th><th>Déposé le</th><th>Actions</th></tr></thead>
  <tbody>
  @forelse ($dossiers as $d)
  @php [$ic, $lib] = Candidatures::STATUTS[$d->statut] ?? ['?', $d->statut]; @endphp
  <tr>
    <td>@if ($d->doc_photo && ! str_ends_with(strtolower($d->doc_photo), '.pdf'))<img src="{{ route('portail_candidats_admin.piece', ['id' => $d->id, 'type' => 'photo']) }}" alt="" style="width:34px;height:40px;object-fit:cover;border-radius:4px" loading="lazy">@else 👤 @endif</td>
    <td><span style="font-family:monospace;font-weight:700">{{ $d->numero_dossier }}</span>@if ($d->numero_depot)<div style="font-size:10px;color:#16a34a;font-family:monospace">{{ $d->numero_depot }}</div>@endif</td>
    <td><a href="{{ route('dossier_candidat', ['id' => $d->id]) }}" style="font-weight:600">{{ $d->nom }} {{ $d->prenom }}</a><div style="font-size:10px;color:var(--text-muted)">{{ ['M' => 'Masculin', 'F' => 'Féminin'][$d->sexe] ?? '' }}</div></td>
    <td>{{ $d->bac_type }}<div style="font-size:10px;color:var(--text-muted)">{{ $d->bac_annee }} · {{ $d->bac_mention }}</div></td>
    <td>{{ $d->ch1_lib ?? '—' }}</td>
    <td><span class="badge {{ ['accepte' => 'badge-green', 'refuse' => 'badge-red', 'en_cours' => 'badge-blue'][$d->statut] ?? 'badge-amber' }}">{{ $ic }} {{ $lib }}</span></td>
    <td style="white-space:nowrap">{{ date('d/m/Y', strtotime($d->created_at)) }}</td>
    <td style="white-space:nowrap">
      <a href="{{ route('dossier_candidat', ['id' => $d->id]) }}" class="btn btn-sm btn-secondary">👁️</a>
      <a href="{{ route('portail_candidats_admin.quitus', ['id' => $d->id]) }}" target="_blank" rel="noopener" class="btn btn-sm btn-secondary">📄 Quitus</a>
      @if ($d->statut !== 'accepte')
      <form method="post" action="{{ route('portail_candidats_admin.statut') }}" style="display:inline" data-confirm="Approuver ce dossier ?">@csrf<input type="hidden" name="dos_id" value="{{ $d->id }}"><input type="hidden" name="statut" value="accepte"><button type="submit" class="btn btn-sm btn-success">✅</button></form>
      @endif
      @if ($d->statut !== 'refuse')
      <form method="post" action="{{ route('portail_candidats_admin.statut') }}" style="display:inline" data-confirm="Rejeter ce dossier ?">@csrf<input type="hidden" name="dos_id" value="{{ $d->id }}"><input type="hidden" name="statut" value="refuse"><button type="submit" class="btn btn-sm btn-danger">❌</button></form>
      @endif
      @if (! $d->etudiant_id)
      <form method="post" action="{{ route('portail_candidats_admin.etudiant') }}" style="display:inline" data-confirm="Créer cet étudiant dans GesNotes ?">@csrf<input type="hidden" name="dos_id" value="{{ $d->id }}"><button type="submit" class="btn btn-sm btn-primary">🎓</button></form>
      @endif
    </td>
  </tr>
  @empty
  <tr><td colspan="8" style="text-align:center;color:var(--text-muted);padding:24px">Aucun dossier.</td></tr>
  @endforelse
  </tbody>
</table></div></div>

@elseif ($onglet === 'import_export')
<div class="card" style="max-width:640px"><div class="card-body">
  <div style="font-weight:700;color:#5b21b6;margin-bottom:16px;font-size:14px">📤 Export des données</div>
  <form method="get" action="{{ route('portail_export') }}">
    <div style="display:grid;grid-template-columns:1fr 1fr;gap:10px;margin-bottom:10px">
      <div class="form-group"><label class="form-label" for="e-debut">Date début</label><input type="date" name="date_debut" id="e-debut" class="form-control"></div>
      <div class="form-group"><label class="form-label" for="e-fin">Date fin</label><input type="date" name="date_fin" id="e-fin" class="form-control"></div>
    </div>
    <div class="form-group"><label class="form-label" for="e-fac">Faculté (1er choix)</label>
      <select name="faculte" id="e-fac" class="form-control"><option value="">— Toutes les facultés —</option>@foreach ($facultes as $fac)<option value="{{ $fac }}">{{ $fac }}</option>@endforeach</select></div>
    <div class="form-group"><label class="form-label" for="e-type">Dossiers</label>
      <select name="type" id="e-type" class="form-control">
        <option value="complet">Tous (hors brouillons)</option>
        @foreach (['accepte' => 'Approuvés', 'refuse' => 'Refusés', 'soumis' => 'Soumis', 'en_cours' => 'En cours', 'incomplet' => 'Incomplets'] as $k => $v)<option value="{{ $k }}">{{ $v }}</option>@endforeach
      </select></div>
    <button type="submit" class="btn btn-primary w-100">📊 Télécharger Excel</button>
  </form>
</div></div>

@elseif ($onglet === 'config')
@php
  $c = fn (string $k, string $d = '') => (string) ($config[$k] ?? $d);
  $restrictions = array_filter([$c('ins_type_candidat'), $c('ins_filiere_id'), $c('ins_niveau_id'), $c('ins_faculte')], fn ($v) => $v !== '');
@endphp
<div style="display:grid;grid-template-columns:repeat(auto-fit,minmax(320px,1fr));gap:16px;margin-bottom:16px">
  <div class="card">
    <div class="card-header"><span class="card-title">📤 Dépôt de dossiers</span></div>
    <div class="card-body">
      <form method="post" action="{{ route('portail_candidats_admin.config') }}">
        @csrf
        <fieldset style="border:none;padding:0;margin:0 0 12px"><legend class="form-label">Statut du dépôt</legend>
          <label style="margin-right:16px"><input type="radio" name="ouvert" value="1" @checked($c('ouvert', '1') !== '0')> 🟢 Ouvert</label>
          <label><input type="radio" name="ouvert" value="0" @checked($c('ouvert', '1') === '0')> 🔴 Fermé</label></fieldset>
        <div class="form-group"><label class="form-label">Année académique</label><div class="form-control" style="background:#f3f4f6">{{ $annee ?: '—' }} <span style="font-size:11px;color:var(--text-muted)">(année active du système)</span></div></div>
        <div class="form-group"><label class="form-label" for="date_limite">Période de dépôt physique (quitus)</label><input type="text" name="date_limite" id="date_limite" class="form-control" value="{{ $c('date_limite') }}" maxlength="255" placeholder="du 28 Juillet au 29 Août 2025"></div>
        <div class="form-group"><label class="form-label" for="message_ferme">Message affiché si fermé</label><textarea name="message_ferme" id="message_ferme" class="form-control" rows="3" maxlength="1000">{{ $c('message_ferme', 'Les dépôts de dossiers sont actuellement fermés.') }}</textarea></div>
        <button type="submit" class="btn btn-primary">💾 Enregistrer</button>
      </form>
    </div>
  </div>
  <div class="card">
    <div class="card-header"><span class="card-title">🎓 Inscription académique</span></div>
    <div class="card-body">
      <form method="post" action="{{ route('portail_candidats_admin.config') }}">
        @csrf
        <fieldset style="border:none;padding:0;margin:0 0 12px"><legend class="form-label">Inscription en ligne</legend>
          <label style="margin-right:16px"><input type="radio" name="inscription_ouverte" value="1" @checked($c('inscription_ouverte', '1') !== '0')> 🟢 Ouverte</label>
          <label><input type="radio" name="inscription_ouverte" value="0" @checked($c('inscription_ouverte', '1') === '0')> 🔴 Fermée</label></fieldset>
        <div class="form-group"><label class="form-label" for="message_inscription_fermee">Message affiché si fermée</label><textarea name="message_inscription_fermee" id="message_inscription_fermee" class="form-control" rows="3" maxlength="1000">{{ $c('message_inscription_fermee', 'Les inscriptions académiques en ligne sont actuellement fermées.') }}</textarea></div>
        <button type="submit" class="btn btn-primary">💾 Enregistrer</button>
      </form>
    </div>
  </div>
</div>
<div class="card">
  <div class="card-header"><span class="card-title">🎯 Restrictions d'inscription académique</span></div>
  <div class="card-body">
    <form method="post" action="{{ route('portail_candidats_admin.config') }}">
      @csrf
      <div style="display:grid;grid-template-columns:repeat(auto-fit,minmax(220px,1fr));gap:12px;margin-bottom:14px">
        <div class="form-group" style="margin:0"><label class="form-label" for="ins_type_candidat">👥 Type de candidat</label>
          <select name="ins_type_candidat" id="ins_type_candidat" class="form-control"><option value="">— Tous (anciens + nouveaux) —</option>
            <option value="ancien" @selected($c('ins_type_candidat') === 'ancien')>🎓 Anciens étudiants uniquement</option><option value="nouveau" @selected($c('ins_type_candidat') === 'nouveau')>📄 Nouveaux bacheliers uniquement</option></select></div>
        <div class="form-group" style="margin:0"><label class="form-label" for="ins_filiere_id">🎓 Filière autorisée</label>
          <select name="ins_filiere_id" id="ins_filiere_id" class="form-control"><option value="">— Toutes les filières —</option>
            @foreach ($filieresListe->groupBy('faculte') as $fac => $liste)<optgroup label="{{ $fac ?: '—' }}">@foreach ($liste as $f)<option value="{{ $f->id }}" @selected($c('ins_filiere_id') === (string) $f->id)>{{ $f->libelle }}</option>@endforeach</optgroup>@endforeach
          </select></div>
        <div class="form-group" style="margin:0"><label class="form-label" for="ins_niveau_id">📊 Niveau autorisé</label>
          <select name="ins_niveau_id" id="ins_niveau_id" class="form-control"><option value="">— Tous les niveaux —</option>@foreach ($niveaux as $n)<option value="{{ $n->id }}" @selected($c('ins_niveau_id') === (string) $n->id)>{{ $n->code }} — {{ $n->libelle }}</option>@endforeach</select></div>
        <div class="form-group" style="margin:0"><label class="form-label" for="ins_faculte">🏛️ Faculté autorisée</label>
          <select name="ins_faculte" id="ins_faculte" class="form-control"><option value="">— Toutes les facultés —</option>@foreach ($facultes as $fac)<option value="{{ $fac }}" @selected($c('ins_faculte') === $fac)>{{ $fac }}</option>@endforeach</select></div>
      </div>
      @if ($restrictions)<div class="alert alert-warning" style="font-size:13px">⚠️ <strong>Restriction active</strong> sur l'inscription académique en ligne.</div>
      @else<div class="alert alert-success" style="font-size:13px">✅ <strong>Aucune restriction</strong> — Tous les étudiants identifiés peuvent s'inscrire.</div>@endif
      <button type="submit" class="btn btn-primary">💾 Enregistrer</button>
    </form>
    @if ($restrictions)
    <form method="post" action="{{ route('portail_candidats_admin.restrictions') }}" style="margin-top:10px" data-confirm="Supprimer toutes les restrictions ?">@csrf<button type="submit" class="btn btn-secondary">🗑️ Tout supprimer</button></form>
    @endif
  </div>
</div>
@endif
@endsection

@push('scripts')
@if (in_array($onglet, ['stats', 'avancees'], true))
<script src="{{ \App\Support\Ressource::url('assets/js/chart.min.js') }}"></script>
<script src="{{ \App\Support\Ressource::url('assets/js/candidatures-admin.js') }}"></script>
@endif
@endpush
