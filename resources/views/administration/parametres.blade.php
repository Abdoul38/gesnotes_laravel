{{-- Paramètres — portage de pages/admin/parametres.php --}}
@extends('layouts.app', ['titre' => 'Paramètres', 'fil' => 'Configuration'])

@use('App\Http\Controllers\Administration\ParametresController', 'PC')

@push('head')
<style>
.settings-grid{display:grid;grid-template-columns:1.3fr 1fr;gap:20px;align-items:start}
@media (max-width:960px){.settings-grid{grid-template-columns:1fr}}
.form-section{margin-bottom:22px}.form-section:last-child{margin-bottom:0}
.form-section-title{font-size:12px;font-weight:700;text-transform:uppercase;letter-spacing:.04em;color:var(--text-muted);margin-bottom:12px;padding-bottom:6px;border-bottom:1px solid var(--border)}
.form-row{display:grid;grid-template-columns:1fr 1fr;gap:14px}@media (max-width:640px){.form-row{grid-template-columns:1fr}}
.form-row .form-group{margin-bottom:0}
.settings-actions{display:flex;justify-content:flex-end;gap:10px;margin-top:16px;flex-wrap:wrap}
.logo-preview{display:flex;align-items:center;gap:14px;padding:14px;border:1px dashed var(--border);border-radius:8px;margin-bottom:14px;background:var(--bg-subtle,#f8fafc)}
.logo-preview img{max-height:64px;max-width:150px;object-fit:contain;background:#fff;border:1px solid var(--border);border-radius:6px;padding:6px}
.logo-preview .logo-placeholder{min-width:64px;height:64px;border-radius:6px;background:var(--border);display:flex;align-items:center;justify-content:center;font-size:12px;color:var(--text-muted);padding:0 4px}
.logo-preview .logo-meta{font-size:12px;color:var(--text-muted);word-break:break-all}
.settings-section-title{font-size:14px;font-weight:700;color:var(--brand,var(--blue-900));display:flex;align-items:center;gap:8px;margin:28px 0 12px;padding-bottom:8px;border-bottom:2px solid var(--border)}
.settings-section-title:first-child{margin-top:0}
.onglets-param{display:flex;gap:6px;margin-bottom:20px;border-bottom:2px solid var(--border);overflow-x:auto}
.onglets-param a{padding:10px 20px;text-decoration:none;font-weight:600;font-size:14px;border-bottom:2px solid transparent;margin-bottom:-2px;color:var(--text-muted);white-space:nowrap}
.onglets-param a.actif{border-bottom-color:var(--blue-700);color:var(--blue-700)}
</style>
@endpush

@section('contenu')
@php $v = fn (string $cle, string $defaut = '') => (string) ($valeurs[$cle] ?? $defaut); @endphp
<nav class="onglets-param">
  @foreach (PC::ONGLETS as $cle => [$ic, $lb])<a href="{{ route('parametres', ['tab' => $cle]) }}" @class(['actif' => $onglet === $cle])>{{ $ic }} {{ $lb }}</a>@endforeach
</nav>

@if ($onglet === 'general')
<div class="settings-section-title">🏫 Institution</div>
<div class="settings-grid">
  <div class="card">
    <div class="card-header"><span class="card-title">🏫 Institution</span></div>
    <div class="card-body">
      <p style="font-size:12px;color:var(--text-muted);margin-bottom:20px">Informations affichées dans l'en-tête des documents officiels.</p>
      <form method="post" action="{{ route('parametres.general') }}">
        @csrf
        @php $champ = fn ($cle) => '<div class="form-group"><label class="form-label" for="p-'.$cle.'">'.e(PC::INSTITUTION[$cle]).'</label><input type="text" name="params['.$cle.']" id="p-'.$cle.'" class="form-control" maxlength="255" value="'.e($v($cle)).'"></div>'; @endphp
        <div class="form-section"><div class="form-section-title">Identification</div>
          {!! $champ('institution_nom') !!}{!! $champ('institution_republique') !!}{!! $champ('institution_service') !!}{!! $champ('institution_ville') !!}</div>
        <div class="form-section"><div class="form-section-title">Coordonnées</div>
          <div class="form-row">{!! $champ('institution_bp') !!}{!! $champ('institution_tel') !!}</div>
          <div class="form-row" style="margin-top:14px">{!! $champ('institution_email') !!}{!! $champ('institution_site') !!}</div></div>
        <div class="settings-actions"><button type="submit" class="btn btn-primary btn-lg">💾 Enregistrer les paramètres</button></div>
      </form>
    </div>
  </div>
</div>

<div class="settings-section-title">🎨 Identité visuelle</div>
<div class="settings-grid">
  <div class="card">
    <div class="card-header"><span class="card-title">🖼️ Logo de l'université</span></div>
    <div class="card-body">
      <p style="font-size:12px;color:var(--text-muted);margin-bottom:14px">Utilisé en filigrane et en en-tête des documents officiels (attestations, relevés, PV imprimés...). Si aucun logo n'est défini ici, le logo par défaut du système est utilisé.</p>
      <div class="logo-preview">
        @if ($logo)<img src="{{ $logo }}" alt="Logo actuel"><div class="logo-meta">Logo actuel<br>{{ $v('logo_universite') }}</div>
        @else<div class="logo-placeholder">🏫</div><div class="logo-meta">Aucun logo personnalisé<br>Le logo par défaut du système est utilisé</div>@endif
      </div>
      <form method="post" action="{{ route('parametres.logo') }}" enctype="multipart/form-data">
        @csrf
        <div class="form-group"><input type="file" name="logo_universite" accept=".png,.jpg,.jpeg" class="form-control" aria-label="Logo de l'université" required><div class="form-hint">Formats acceptés : PNG, JPG (2 Mo maximum).</div></div>
        <div class="settings-actions"><button type="submit" class="btn btn-primary">📤 Téléverser</button></div>
      </form>
    </div>
  </div>

  @php $perso = preg_match('/^#[0-9A-Fa-f]{6}$/', $v('couleur_marque')); $couleurActuelle = $perso ? $v('couleur_marque') : $couleurDefaut; @endphp
  <div class="card">
    <div class="card-header"><span class="card-title">🎨 Couleur de marque</span></div>
    <div class="card-body">
      <p style="font-size:12px;color:var(--text-muted);margin-bottom:14px">Utilisée pour la barre latérale, les boutons principaux et l'en-tête des documents imprimés.@unless ($perso) Actuellement la couleur par défaut de cette université.@endunless</p>
      <div class="logo-preview"><div class="logo-placeholder" style="background:{{ $couleurActuelle }};color:#fff">{{ $couleurActuelle }}</div><div class="logo-meta">{{ $perso ? 'Couleur personnalisée' : 'Couleur par défaut' }}<br>{{ $couleurActuelle }}</div></div>
      <form method="post" action="{{ route('parametres.couleur') }}">
        @csrf
        <div class="form-group"><input type="color" name="couleur_marque" value="{{ $couleurActuelle }}" class="form-control" style="height:44px;padding:4px" aria-label="Couleur de marque"><div class="form-hint">Choisissez une couleur, puis enregistrez.</div></div>
        <div class="settings-actions"><button type="submit" class="btn btn-primary">💾 Enregistrer</button></div>
      </form>
      @if ($perso)
      <form method="post" action="{{ route('parametres.couleur') }}" style="margin-top:8px">@csrf<input type="hidden" name="couleur_marque" value="">
        <button type="submit" class="btn btn-secondary w-100">↺ Revenir à la couleur par défaut</button></form>
      @endif
    </div>
  </div>
</div>

<div class="settings-section-title">🪪 Carte étudiante</div>
<div class="settings-grid">
  <div class="card">
    <div class="card-header"><span class="card-title">🪪 Modèle de carte étudiant</span></div>
    <div class="card-body">
      <p style="font-size:12px;color:var(--text-muted);margin-bottom:14px">Choisissez le style visuel utilisé pour imprimer les cartes étudiantes.</p>
      @php $modele = $v('carte_etudiant_modele', 'moderne'); @endphp
      <form method="post" action="{{ route('parametres.carte') }}">
        @csrf
        <div style="display:grid;grid-template-columns:repeat(auto-fit,minmax(110px,1fr));gap:8px;margin-bottom:14px">
          @foreach (PC::MODELES_CARTE as $cle => [$icon, $label, $desc])
          <label style="cursor:pointer;border:2px solid {{ $modele === $cle ? 'var(--blue-600)' : 'var(--border)' }};border-radius:8px;padding:10px 6px;text-align:center;display:block">
            <input type="radio" name="carte_etudiant_modele" value="{{ $cle }}" @checked($modele === $cle) style="margin-bottom:4px">
            <div style="font-size:18px">{{ $icon }}</div><div style="font-weight:700;font-size:11.5px;margin:3px 0">{{ $label }}</div>
            <div style="font-size:9.5px;color:var(--text-muted);line-height:1.3">{{ $desc }}</div>
          </label>
          @endforeach
        </div>
        <div class="settings-actions">@if (Route::has('carte_etudiant'))<a href="{{ route('carte_etudiant') }}" target="_blank" rel="noopener" class="btn btn-secondary">👁️ Prévisualiser</a>@endif
          <button type="submit" class="btn btn-primary">💾 Enregistrer</button></div>
      </form>
    </div>
  </div>

  <div class="card">
    <div class="card-header"><span class="card-title">✍️ Signature de l'autorité</span></div>
    <div class="card-body">
      <p style="font-size:12px;color:var(--text-muted);margin-bottom:14px">Image de la signature placée dans le cadre « Signature de l'autorité » au recto des cartes étudiantes. Un PNG à fond transparent donne le meilleur rendu. Sans image, le cadre reste vide.</p>
      <div class="logo-preview">
        @if ($signature)<img src="{{ $signature }}" alt="Signature actuelle"><div class="logo-meta">Signature actuelle<br>{{ $v('signature_autorite') }}</div>
        @else<div class="logo-placeholder">✍️</div><div class="logo-meta">Aucune signature définie<br>Le cadre reste vide sur les cartes</div>@endif
      </div>
      <form method="post" action="{{ route('parametres.signature') }}" enctype="multipart/form-data">
        @csrf
        <div class="form-group"><input type="file" name="signature_autorite" accept=".png,.jpg,.jpeg" class="form-control" aria-label="Signature de l'autorité" required><div class="form-hint">Formats acceptés : PNG, JPG (2 Mo maximum).</div></div>
        <div class="settings-actions"><button type="submit" class="btn btn-primary">📤 Téléverser</button></div>
      </form>
      @if ($v('signature_autorite') !== '')
      <form method="post" action="{{ route('parametres.signature.supprimer') }}" style="margin-top:10px" data-confirm="Retirer la signature de l'autorité ?">@csrf
        <button type="submit" class="btn btn-secondary">🗑️ Retirer la signature</button></form>
      @endif
    </div>
  </div>
</div>

@elseif ($onglet === 'regles_pv')
@php
  [$seuil, $elim, $elimUe] = [$v('seuil_validation', '10'), $v('note_eliminatoire', '5'), $v('note_eliminatoire_ue', '5')];
  [$tb, $b, $ab] = [$v('mention_tres_bien', '16'), $v('mention_bien', '14'), $v('mention_assez_bien', '12')];
  $pct = fn ($x) => max(0, min(100, ((float) $x / 20) * 100));
  $reperes = array_merge($elim !== '' ? [['Élim. matière', (float) $elim, '#dc2626']] : [], $elimUe !== '' ? [['Élim. UE', (float) $elimUe, '#ea580c']] : [],
    [['Validation', (float) $seuil, '#2563eb'], ['Assez bien', (float) $ab, '#65a30d'], ['Bien', (float) $b, '#16a34a'], ['Très bien', (float) $tb, '#15803d']]);
@endphp
<form method="post" action="{{ route('parametres.regles') }}">
  @csrf
  <div class="alert alert-info" style="font-size:12px;margin-bottom:16px;max-width:760px">💡 Ces règles s'appliquent au moteur de calcul des résultats (moyennes, décisions, compensations). Chaque université a sa propre base de données — ces réglages sont donc indépendants d'un établissement à l'autre.</div>
  <div class="card" style="max-width:760px"><div class="card-body">
    <div class="form-section-title" style="border:none;margin-bottom:18px">Échelle des seuils (/20)</div>
    <div style="position:relative;height:6px;border-radius:3px;background:var(--border);margin:0 8px 30px">
      @foreach ($reperes as [$lbl, $val, $col])
      <div style="position:absolute;left:{{ $pct($val) }}%;top:50%;transform:translate(-50%,-50%)">
        <div style="width:12px;height:12px;border-radius:50%;background:{{ $col }};border:2px solid #fff;box-shadow:0 0 0 1px {{ $col }}"></div>
        <div style="position:absolute;top:16px;left:50%;transform:translateX(-50%);white-space:nowrap;font-size:10px;text-align:center;color:var(--text-muted)">{{ $lbl }}<br><strong style="color:{{ $col }}">{{ rtrim(rtrim(number_format($val, 1), '0'), '.') }}</strong></div>
      </div>
      @endforeach
    </div>
    <div class="form-hint">Les seuils de mention doivent rester décroissants et au-dessus du seuil de validation ; les notes éliminatoires doivent rester en-dessous.</div>
  </div></div>

  <div class="settings-grid" style="max-width:760px;margin-top:16px">
    <div>
      <div class="card"><div class="card-header"><span class="card-title">🎯 Seuil de validation</span></div><div class="card-body">
        <div class="form-group" style="margin-bottom:0"><label class="form-label" for="r-seuil">Seuil de validation (/20)</label>
          <input type="number" name="seuil_validation" id="r-seuil" class="form-control" style="max-width:160px" min="0" max="20" step="0.5" value="{{ $seuil }}">
          <div class="form-hint">Note minimale (matière, UE, semestre) pour être considéré comme validé. C'est la référence de toutes les autres règles ci-dessous.</div></div>
      </div></div>
      <div class="card" style="margin-top:16px"><div class="card-header"><span class="card-title">⛔ Notes éliminatoires</span></div><div class="card-body">
        <div class="form-group"><label class="form-label" for="champ-note-elim">Par matière (/20)</label>
          <input type="number" name="note_eliminatoire" id="champ-note-elim" class="form-control" style="max-width:160px" min="0" max="20" step="0.5" value="{{ $elim }}" data-show-if-empty="elim-warning">
          <div class="form-hint">Sous cette note, aucune compensation n'est possible pour la matière. <strong>Vide</strong> = règle désactivée.</div>
          <div id="elim-warning" style="display:{{ $elim === '' ? 'block' : 'none' }};font-size:11px;color:#b45309;margin-top:4px">⚠️ Désactivée — 0/20 dans une matière reste compensable.</div></div>
        <div class="form-group" style="margin-bottom:0"><label class="form-label" for="champ-note-elim-ue">Moyenne d'UE (/20)</label>
          <input type="number" name="note_eliminatoire_ue" id="champ-note-elim-ue" class="form-control" style="max-width:160px" min="0" max="20" step="0.5" value="{{ $elimUe }}" data-show-if-empty="elim-ue-warning">
          <div class="form-hint">Sous ce seuil, l'UE est défaillante même sans matière éliminatoire. <strong>Vide</strong> = règle désactivée.</div>
          <div id="elim-ue-warning" style="display:{{ $elimUe === '' ? 'block' : 'none' }};font-size:11px;color:#b45309;margin-top:4px">⚠️ Désactivée — une UE reste validable avec une moyenne très basse.</div></div>
      </div></div>
    </div>
    <div>
      <div class="card"><div class="card-header"><span class="card-title">🔄 Compensation</span></div><div class="card-body">
        <div class="form-group"><label class="form-label" for="r-cue">Entre matières d'une UE</label>
          <select name="compensation_ue" id="r-cue" class="form-control"><option value="oui" @selected($v('compensation_ue', 'oui') === 'oui')>Oui — autorisée</option><option value="non" @selected($v('compensation_ue') === 'non')>Non — chaque matière doit être validée</option></select>
          <div class="form-hint">Une matière sous le seuil peut être rattrapée par les autres matières de la même UE.</div></div>
        <div class="form-group" style="margin-bottom:0"><label class="form-label" for="r-csem">D'une UE par le semestre</label>
          <select name="compensation_semestre" id="r-csem" class="form-control"><option value="oui" @selected($v('compensation_semestre', 'oui') === 'oui')>Oui — autorisée</option><option value="non" @selected($v('compensation_semestre') === 'non')>Non — chaque UE doit être validée</option></select>
          <div class="form-hint">Une UE non validée peut être compensée si la moyenne du semestre atteint le seuil.</div></div>
      </div></div>
      <div class="card" style="margin-top:16px"><div class="card-header"><span class="card-title">🏅 Seuils de mention</span></div><div class="card-body">
        @foreach (['mention_tres_bien' => ['Très bien', $tb], 'mention_bien' => ['Bien', $b], 'mention_assez_bien' => ['Assez bien', $ab]] as $cle => [$lib, $val])
        <div class="form-group"><label class="form-label" for="r-{{ $cle }}">{{ $lib }} (/20)</label>
          <input type="number" name="{{ $cle }}" id="r-{{ $cle }}" class="form-control" style="max-width:160px" min="0" max="20" step="0.5" value="{{ $val }}"></div>
        @endforeach
        <div class="form-hint">En dessous (et au-dessus du seuil de validation) → mention "Passable" automatiquement.</div>
      </div></div>
    </div>
  </div>
  <div class="settings-actions" style="max-width:760px"><button type="submit" class="btn btn-primary btn-lg">💾 Enregistrer les règles</button></div>
</form>

<div class="card mb-16" style="max-width:640px;margin-top:20px">
  <div class="card-header"><span class="card-title">🔒 Verrouillage des règles par année</span></div>
  <div class="card-body">
    <p style="font-size:12px;color:var(--text-muted);margin-bottom:14px">Une fois une année académique terminée, verrouille ses règles pour qu'un futur changement de seuil ou de note éliminatoire ne vienne jamais rejuger rétroactivement ses PV. Tant qu'une année n'est pas verrouillée, elle suit simplement la règle globale ci-dessus, comme avant.</p>
    @if ($annees->isEmpty())
    <div class="empty-state" style="padding:16px"><p>Aucune année académique trouvée.</p></div>
    @else
    <table class="table" style="font-size:12.5px">
      <thead><tr><th>Année académique</th><th style="text-align:center">Statut</th><th></th></tr></thead>
      <tbody>
      @foreach ($annees as $a)
      <tr>
        <td style="font-weight:600">{{ $a->libelle }}</td>
        <td style="text-align:center">@if ($a->verrouillee)<span class="badge" style="background:var(--amber-50);color:#92400e">🔒 Verrouillée</span>@else<span class="badge" style="background:var(--blue-50);color:#1e40af">🔓 Suit le global</span>@endif</td>
        <td style="text-align:right">
          @if ($a->verrouillee)
          <form method="post" action="{{ route('parametres.deverrouiller') }}" style="display:inline" data-confirm="Retirer le verrouillage de {{ $a->libelle }} ? Elle suivra à nouveau la règle globale.">@csrf<input type="hidden" name="annee_id" value="{{ $a->id }}">
            <button type="submit" class="btn btn-sm btn-secondary">🔓 Déverrouiller</button></form>
          @else
          <form method="post" action="{{ route('parametres.verrouiller') }}" style="display:inline" data-confirm="Verrouiller {{ $a->libelle }} avec la règle actuelle ? Un futur changement global ne l'affectera plus.">@csrf<input type="hidden" name="annee_id" value="{{ $a->id }}">
            <button type="submit" class="btn btn-sm btn-primary">🔒 Verrouiller</button></form>
          @endif
        </td>
      </tr>
      @endforeach
      </tbody>
    </table>
    @endif
  </div>
</div>
<div class="alert alert-info mt-16" style="font-size:13px;max-width:640px">⚠️ Ces réglages n'affectent pas les PV déjà calculés — il faut relancer un recalcul (bouton "Recalculer" dans le détail d'un PV) pour qu'ils soient pris en compte sur des résultats existants.</div>

@elseif ($onglet === 'signataires')
<div class="settings-grid">
  <div class="card">
    <div class="card-header"><span class="card-title">➕ Définir un signataire</span></div>
    <div class="card-body">
      <form method="post" action="{{ route('parametres.signataire') }}">
        @csrf
        <div class="form-group"><label class="form-label" for="s-fac">Faculté</label>
          <select name="faculte_id" id="s-fac" class="form-control">
            @unless (auth()->user()->faculteId())<option value="">🌐 Signataire global (toutes facultés)</option>@endunless
            @foreach ($facultes as $f)<option value="{{ $f->id }}">{{ $f->libelle }}</option>@endforeach
          </select>
          <div class="form-hint">Le signataire de la faculté est prioritaire sur le signataire global</div></div>
        <div class="form-group"><label class="form-label" for="s-titre">Titre</label><input type="text" name="sig_titre" id="s-titre" class="form-control" maxlength="100" placeholder="Ex: Le Doyen, Le Chef de Département"></div>
        <div class="form-group"><label class="form-label" for="s-nom">Nom complet</label><input type="text" name="sig_nom" id="s-nom" class="form-control" maxlength="150" placeholder="Nom et prénom du signataire" required></div>
        <div class="form-group"><label class="form-label" for="s-qual">Qualité (optionnel)</label><input type="text" name="sig_qualite" id="s-qual" class="form-control" maxlength="200" placeholder="Ex: Maître de Conférences"></div>
        <button type="submit" class="btn btn-primary w-100">💾 Enregistrer</button>
      </form>
    </div>
  </div>
  <div class="card">
    <div class="card-header"><span class="card-title">📋 Signataires actifs</span></div>
    <div class="card-body" style="padding:0">
      @forelse ($signatairesActifs->keyBy(fn ($s) => $s->faculte_id ?? 'global') as $s)
      <div style="padding:14px 16px;border-bottom:1px solid var(--border);display:flex;justify-content:space-between;align-items:center">
        <div>
          <div style="font-weight:700;font-size:13px">@if ($s->faculte_id)<span class="badge badge-blue">{{ $s->fac_code }}</span> {{ $s->fac_lib }}@else<span class="badge badge-gray">🌐 Global</span>@endif</div>
          <div style="font-size:12px;color:var(--text-muted);margin-top:4px">{{ $s->titre }} · <strong>{{ $s->nom }}</strong>@if ($s->qualite) · {{ $s->qualite }}@endif</div>
        </div>
        <span style="color:#16a34a;font-size:18px">✅</span>
      </div>
      @empty
      <div class="empty-state" style="padding:32px"><div class="icon">✍️</div><p>Aucun signataire configuré.<br><span style="font-size:12px;color:var(--text-muted)">Le signataire par défaut sera utilisé.</span></p></div>
      @endforelse
    </div>
  </div>
</div>
<div class="alert alert-info mt-16" style="font-size:13px">💡 <strong>Priorité des signataires :</strong> Signataire de la faculté du PV → Signataire global (table signataires) → Paramètre général (table parametres)</div>

@else
<div class="card mb-16">
  <div class="card-header"><span class="card-title">🛡️ Armoirie du Niger</span></div>
  <div class="card-body">
    <p style="font-size:12px;color:var(--text-muted);margin-bottom:12px">Remplace le logo de faculté par ce symbole national, à droite de l'en-tête des diplômes uniquement.</p>
    @if ($armoirie)<div style="margin-bottom:10px"><img src="{{ $armoirie }}" style="height:60px" alt="Armoirie actuelle"></div>@endif
    <form method="post" action="{{ route('parametres.armoirie') }}" enctype="multipart/form-data">
      @csrf
      <div class="form-group"><input type="file" name="armoirie_niger" accept=".png,.jpg,.jpeg" class="form-control" aria-label="Armoirie du Niger" required><div class="form-hint">Formats acceptés : PNG, JPG (2 Mo maximum).</div></div>
      <button type="submit" class="btn btn-primary">📤 Téléverser</button>
    </form>
  </div>
</div>

<div class="card mb-16">
  <div class="card-header"><span class="card-title">✍️ Signataire des diplômes</span></div>
  <div class="card-body">
    <p style="font-size:12px;color:var(--text-muted);margin-bottom:12px">Sur un diplôme, la signature est généralement au niveau de toute l'université (ex: Le Recteur), pas de la faculté — indépendant du signataire des attestations/PV.</p>
    <form method="post" action="{{ route('parametres.signataire_diplome') }}">
      @csrf
      <div class="form-grid form-grid-2">
        <div class="form-group"><label class="form-label" for="d-titre">Titre *</label><input type="text" name="signataire_diplome_titre" id="d-titre" class="form-control" maxlength="100" value="{{ $v('signataire_diplome_titre') }}" placeholder="Ex: Le Recteur"></div>
        <div class="form-group"><label class="form-label" for="d-nom">Nom *</label><input type="text" name="signataire_diplome_nom" id="d-nom" class="form-control" maxlength="150" value="{{ $v('signataire_diplome_nom') }}" placeholder="Ex: Pr. ADAMOU Rabani"></div>
        <div class="form-group" style="grid-column:1/-1"><label class="form-label" for="d-qual">Qualité (optionnel)</label><input type="text" name="signataire_diplome_qualite" id="d-qual" class="form-control" maxlength="200" value="{{ $v('signataire_diplome_qualite') }}" placeholder="Ex: Président du Conseil de l'Université"></div>
      </div>
      <button type="submit" class="btn btn-primary">💾 Enregistrer</button>
    </form>
  </div>
</div>

<div class="alert alert-info mb-16" style="font-size:12px">💡 Ce texte s'affiche tel quel sur le diplôme, dans la partie « Vu le décret..., Vu l'arrêté... ». Une ligne par visa. Modifiable à tout moment — les décrets et arrêtés changent parfois.</div>

@foreach (PC::NIVEAUX_VISA as $niveau => [$label, $sousTitre])
<div class="card mb-16">
  <div class="card-header"><span class="card-title">🎓 Visas — {{ $label }}</span></div>
  <div class="card-body">
    <form method="post" action="{{ route('parametres.visa') }}">
      @csrf
      <input type="hidden" name="niveau" value="{{ $niveau }}">
      <div class="form-group"><label class="form-label" for="visa-{{ $niveau }}">{{ $sousTitre }} — une ligne par visa (« Vu le... », « Vu l'arrêté... »)</label>
        <textarea name="visa_texte" id="visa-{{ $niveau }}" class="form-control" rows="6" maxlength="5000" style="font-family:monospace;font-size:12px" placeholder="Vu le décret N°... du ... portant institution du système LMD...&#10;Vu l'arrêté N°... du ... fixant le régime des études...&#10;Vu les procès-verbaux d'examen dressés par les jurys...">{{ $v('visa_diplome_'.$niveau) }}</textarea>
        <div class="form-hint">Chaque ligne devient un paragraphe « Vu » séparé sur le diplôme.</div></div>
      <button type="submit" class="btn btn-primary">💾 Enregistrer les visas — {{ $label }}</button>
    </form>
  </div>
</div>
@endforeach

<div class="card mb-16">
  <div class="card-header"><span class="card-title">🎓 Domaine, Mention & Type de formation par filière</span></div>
  <div class="card-body">
    <p style="font-size:12px;color:var(--text-muted);margin-bottom:14px">Sur un diplôme, la Spécialité correspond au nom de la filière elle-même. Domaine et Mention sont des catégories académiques plus larges, propres à chaque filière — ex: filière « Droit Privé » → Domaine « Droit et Gestion », Mention « Droit ». « Type de formation » détermine si les attestations/diplômes de cette filière portent la mention « Professionnelle » (Licence Pro, Master Pro) — indépendant de la faculté, deux filières d'une même faculté peuvent avoir des types différents.</p>
    @if ($filieres->isEmpty())
    <div class="empty-state" style="padding:20px"><p>Aucune filière trouvée.</p></div>
    @else
    <form method="post" action="{{ route('parametres.filieres') }}">
      @csrf
      <div style="overflow-x:auto">
      <table class="table" style="font-size:12px">
        <thead><tr><th>Filière (= Spécialité)</th><th>Domaine</th><th>Mention</th><th>Type de formation</th></tr></thead>
        <tbody>
        @foreach ($filieres as $f)
        <tr>
          <td style="font-weight:600;vertical-align:middle">{{ $f->libelle }}</td>
          <td><input type="text" name="domaine[{{ $f->id }}]" class="form-control" maxlength="150" value="{{ $f->domaine }}" placeholder="Ex: Droit et Gestion" style="font-size:12px" aria-label="Domaine — {{ $f->libelle }}"></td>
          <td><input type="text" name="mention_academique[{{ $f->id }}]" class="form-control" maxlength="150" value="{{ $f->mention_academique }}" placeholder="Ex: Droit" style="font-size:12px" aria-label="Mention — {{ $f->libelle }}"></td>
          <td><select name="type_formation[{{ $f->id }}]" class="form-control" style="font-size:12px" aria-label="Type de formation — {{ $f->libelle }}">
            <option value="classique" @selected(($f->type_formation ?? 'classique') === 'classique')>Classique</option>
            <option value="professionnelle" @selected($f->type_formation === 'professionnelle')>Professionnelle</option></select></td>
        </tr>
        @endforeach
        </tbody>
      </table>
      </div>
      <button type="submit" class="btn btn-primary" style="margin-top:10px">💾 Enregistrer toutes les filières</button>
    </form>
    @endif
  </div>
</div>
@endif
@endsection
