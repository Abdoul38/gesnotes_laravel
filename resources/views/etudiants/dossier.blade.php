{{-- Dossier étudiant — portage de pages/etudiants/dossier_etudiant.php --}}
@extends('layouts.app', ['titre' => 'Dossier étudiant', 'fil' => 'Étudiants › Dossier'])
@inject('navigation', 'App\Navigation\Menu')
@use('App\Support\Format')
@use('App\Http\Controllers\Etudiants\DossierEtudiantController', 'Dossier')

@section('contenu')
@php
  $u = auth()->user();
  $peutInscrire = $u->aRole('admin') || $u->aRole('sc_centrale') || $u->aRole('sc_entite');
  $voitNotes = $u->aRole('admin') || $u->aRole('sc_entite');
  $btnClair = 'background:rgba(255,255,255,.2);color:#fff;border:1px solid rgba(255,255,255,.4)';
@endphp

{{-- En-tête --}}
<div class="card mb-20" style="background:linear-gradient(135deg,#1A3A5C,#2C5F9E);color:#fff">
  <div class="card-body" style="display:flex;align-items:center;gap:20px;padding:20px">
    <div style="width:80px;height:80px;border-radius:50%;border:3px solid rgba(255,255,255,.4);overflow:hidden;background:rgba(255,255,255,.2);display:flex;align-items:center;justify-content:center">
      @if ($photo)<img src="{{ route('fichiers.photo', $photo) }}" alt="Photo de {{ $et->nom }} {{ $et->prenoms }}" style="width:100%;height:100%;object-fit:cover">
      @else<span style="font-size:32px" aria-hidden="true">👤</span>@endif
    </div>
    <div style="flex:1">
      <div style="font-size:22px;font-weight:800">{{ $et->nom }} {{ $et->prenoms }}</div>
      <div style="opacity:.8;font-size:13px;margin-top:2px">
        Matricule : <strong>{{ $et->numero ?: '—' }}</strong>
        @if ($et->statut_etudiant)&nbsp;·&nbsp;<span style="background:rgba(255,255,255,.2);padding:2px 10px;border-radius:10px;font-size:11px">{{ mb_strtoupper($et->statut_etudiant) }}</span>@endif
      </div>
      <div style="opacity:.7;font-size:12px;margin-top:4px">
        @if ($et->date_naissance)Né(e) le {{ Format::dateFR($et->date_naissance) }}@endif
        @if ($et->lieu_naissance) à {{ $et->lieu_naissance }}@endif
      </div>
    </div>
    <div style="display:flex;gap:8px;flex-wrap:wrap">
      @unless ($lectureSeule)
        <button type="button" data-onclick="openModal" data-arg="modal-edit" class="btn btn-sm" style="{{ $btnClair }}">✏️ Modifier</button>
        @if ($peutInscrire)
          <a href="{{ $navigation->lien('inscription_scolarite', ['id' => $et->id]) }}" class="btn btn-sm" style="background:rgba(255,215,0,.35);color:#ffd700;border:1px solid rgba(255,215,0,.6);font-weight:700">🎓 Inscrire</a>
        @endif
      @endunless
      <a href="{{ $navigation->lien('carte_etudiant', ['id' => $et->id]) }}" target="_blank" rel="noopener" class="btn btn-sm" style="{{ $btnClair }}">🪪 Carte</a>
      <a href="{{ $navigation->lien('historique', ['et_id' => $et->id]) }}" class="btn btn-sm" style="{{ $btnClair }}">📋 Historique</a>
      @if ($attestation)
        <a href="{{ $navigation->lien('attestations', ['et_id' => $et->id, 'type' => $attestation[0]]) }}" target="_blank" rel="noopener" class="btn btn-sm" style="background:linear-gradient(135deg,{{ $attestation[2] }});color:#fff;border:none;font-weight:700">{{ $attestation[1] }}</a>
      @endif
    </div>
  </div>
</div>

<div style="display:grid;grid-template-columns:1fr 1fr;gap:20px">

  {{-- Informations personnelles + dossier portail --}}
  <div class="card">
    <div class="card-header">
      <span class="card-title">👤 Informations personnelles</span>
      @if ($candidature)
        @php [$fond, $texte] = match ($candidature->statut) {
          'accepte' => ['#d1fae5', '#166534'], 'refuse' => ['#fee2e2', '#dc2626'], 'soumis' => ['#dbeafe', '#1d4ed8'],
          'en_cours' => ['#fef3c7', '#d97706'], 'incomplet' => ['#fce7f3', '#be185d'], default => ['#f3f4f6', '#6b7280'] }; @endphp
        <span style="background:{{ $fond }};color:{{ $texte }};padding:3px 10px;border-radius:10px;font-size:10px;font-weight:700">🗂️ {{ mb_strtoupper($candidature->statut) }}</span>
      @endif
    </div>
    <div class="card-body" style="font-size:13px;padding:0">
      @if ($candidature)
      <div style="display:grid;grid-template-columns:1fr 1fr{{ $candidature->date_soumission ? ' 1fr' : '' }};border-bottom:1px solid var(--border)">
        <div style="padding:10px 14px;border-right:1px solid var(--border)">
          <div style="font-size:10px;color:var(--text-muted);font-weight:600;text-transform:uppercase;margin-bottom:3px">N° Dossier</div>
          <div style="font-size:13px;font-weight:800;color:var(--blue-700);font-family:monospace">{{ $candidature->numero_dossier }}</div>
        </div>
        <div style="padding:10px 14px{{ $candidature->date_soumission ? ';border-right:1px solid var(--border)' : '' }}">
          <div style="font-size:10px;color:var(--text-muted);font-weight:600;text-transform:uppercase;margin-bottom:3px">N° Dépôt</div>
          <div style="font-size:13px;font-weight:800;color:var(--blue-700);font-family:monospace">{{ $candidature->numero_depot ?? '—' }}</div>
        </div>
        @if ($candidature->date_soumission)
        <div style="padding:10px 14px">
          <div style="font-size:10px;color:var(--text-muted);font-weight:600;text-transform:uppercase;margin-bottom:3px">Soumis le</div>
          <div style="font-size:12px;font-weight:600">{{ date('d/m/Y', strtotime($candidature->date_soumission)) }}</div>
        </div>
        @endif
      </div>
      @endif

      <table style="width:100%;border-collapse:collapse">
        @foreach ([
          'Date naissance' => Format::dateFR($et->date_naissance), 'Lieu naissance' => $et->lieu_naissance ?? '—',
          'Sexe' => $et->sexe === 'M' ? 'Masculin' : ($et->sexe === 'F' ? 'Féminin' : '—'),
          'Nationalité' => $et->nationalite ?? '—', 'Téléphone' => $et->telephone ?? '—', 'Email' => $et->email ?? '—',
          'Adresse' => $et->adresse ?? '—', 'Père' => $et->nom_pere ?? '—', 'Mère' => $et->nom_mere ?? '—',
          'Urgence' => $et->tel_urgence ?? '—', 'Bac série' => $et->bac_serie ?? '—', 'Bac année' => $et->bac_annee ?? '—',
          'Bac mention' => $et->bac_mention ?? '—', 'Établissement' => $et->etablissement_origine ?? '—',
        ] as $libelle => $valeur)
        <tr style="border-bottom:0.5px solid var(--border)"><td style="padding:6px 8px;color:var(--text-muted);width:40%;font-weight:600">{{ $libelle }}</td><td style="padding:6px 8px">{{ $valeur }}</td></tr>
        @endforeach
        @if ($candidature)
          @foreach (array_filter(['Type BAC' => $candidature->bac_type ?? null, 'Lieu BAC' => $candidature->bac_lieu ?? null, 'Établ. BAC' => $candidature->bac_etablissement ?? null, 'Notes admin' => $candidature->notes_admin ?? null]) as $libelle => $valeur)
          <tr style="border-bottom:0.5px solid var(--border);background:var(--blue-50)"><td style="padding:6px 8px;color:var(--blue-600);width:40%;font-weight:600">{{ $libelle }}</td><td style="padding:6px 8px;color:var(--blue-800)">{{ $valeur }}</td></tr>
          @endforeach
        @endif
      </table>

      @if ($candidature && ($candidature->ch1_lib || $candidature->ch2_lib || $candidature->ch3_lib))
      <div style="border-top:1px solid var(--border);padding:12px 14px">
        <div style="font-size:10px;font-weight:700;color:var(--text-muted);text-transform:uppercase;margin-bottom:8px">🎓 Choix de filières</div>
        <div style="display:flex;flex-direction:column;gap:6px">
          @foreach (['1er' => [$candidature->ch1_lib, $candidature->ch1_fac], '2ème' => [$candidature->ch2_lib, $candidature->ch2_fac], '3ème' => [$candidature->ch3_lib, $candidature->ch3_fac]] as $rang => [$lib, $fac])
            @continue(! $lib)
            <div style="background:var(--blue-50);border:1px solid var(--blue-200);border-radius:6px;padding:6px 10px;font-size:12px;display:flex;align-items:center;gap:8px">
              <span style="font-weight:700;color:var(--blue-600);min-width:28px">{{ $rang }}</span><span style="font-weight:600">{{ $lib }}</span>
              @if ($fac)<span style="color:var(--text-muted);font-size:11px">— {{ $fac }}</span>@endif
            </div>
          @endforeach
        </div>
      </div>
      @endif

      @if ($candidature)
      <div style="border-top:1px solid var(--border);padding:12px 14px">
        <div style="font-size:10px;font-weight:700;color:var(--text-muted);text-transform:uppercase;margin-bottom:8px">📎 Documents portail</div>
        <div style="display:grid;grid-template-columns:1fr 1fr;gap:4px">
          @foreach (['Photo' => 'doc_photo', 'Extrait naissance' => 'doc_extrait', 'Cert. nationalité' => 'doc_nationalite', 'Relevé notes' => 'doc_releve', 'Attestation' => 'doc_attestation', 'Bulletin' => 'doc_bulletin', 'Pièce ID' => 'doc_cni', 'Autre' => 'doc_autre'] as $libelle => $colonne)
            @php $piece = $candidature->{$colonne} ?? null; @endphp
            <div style="display:flex;align-items:center;justify-content:space-between;padding:4px 8px;border-radius:4px;background:var(--gray-50);font-size:11px">
              <span style="color:var(--text-muted)">{{ $libelle }}</span>
              @if ($piece)
                <a href="{{ route('fichiers.piece', basename($piece)) }}" target="_blank" rel="noopener" style="color:var(--green-700);font-weight:700;text-decoration:none" aria-label="Voir : {{ $libelle }}">✅</a>
              @else<span style="color:var(--gray-300)">—</span>@endif
            </div>
          @endforeach
        </div>
      </div>
      @endif
    </div>
  </div>

  <div>
    {{-- Inscriptions --}}
    <div class="card mb-16">
      <div class="card-header">
        <span class="card-title">🎓 Inscriptions</span>
        <div style="display:flex;gap:6px">
          @if ($u->aRole('admin') || $u->aRole('sc_centrale') || $u->aRole('secretaire'))
            <a href="{{ $navigation->lien('inscription_scolarite', ['id' => $et->id]) }}" class="btn btn-sm btn-secondary">📃 Inscription scolarité</a>
          @endif
          @if ($inscriptions->isNotEmpty() && ($u->aRole('admin') || $u->aRole('sc_entite')))
            <a href="{{ $navigation->lien('inscription_pedagogique', ['et_id' => $et->id, 'insc_id' => $inscriptions->first()->id]) }}" class="btn btn-sm btn-primary">🎓 Inscription pédagogique</a>
          @endif
        </div>
      </div>
      <div class="card-body" style="padding:0">
        @if ($inscriptions->isEmpty())
          <div class="empty-state" style="padding:20px"><p>Aucune inscription scolarité. Cliquez sur "Inscription scolarité" pour inscrire cet étudiant.</p></div>
        @else
        <table class="table" style="font-size:12px">
          <thead><tr><th>Filière</th><th>Niveau</th><th>Année</th><th style="text-align:center">Statut</th><th style="text-align:center">Actions</th></tr></thead>
          <tbody>
          @foreach ($inscriptions as $ins)
          <tr>
            <td style="font-weight:600">{{ $ins->filiere }}</td><td>{{ $ins->niveau }}</td><td>{{ $ins->annee }}</td>
            <td style="text-align:center"><span style="font-size:10px;padding:2px 8px;border-radius:8px;background:{{ $ins->statut === 'inscrit' ? 'var(--green-50)' : 'var(--amber-50)' }};color:{{ $ins->statut === 'inscrit' ? 'var(--green-700)' : 'var(--amber-600)' }};font-weight:600">{{ $ins->statut }}</span></td>
            <td style="text-align:center">
              <div style="display:flex;gap:4px;justify-content:center">
                @if ($u->aRole('admin') || $u->aRole('sc_entite'))
                  <a href="{{ $navigation->lien('inscription_pedagogique', ['et_id' => $et->id, 'insc_id' => $ins->id]) }}" class="btn btn-sm btn-primary" style="padding:2px 8px;font-size:10px" title="Inscription pédagogique">🎓 Péda.</a>
                @endif
                <a href="{{ $navigation->lien('recu_inscription', ['et_id' => $et->id, 'ins_id' => $ins->id]) }}" target="_blank" rel="noopener" class="btn btn-sm btn-secondary" style="padding:2px 8px;font-size:10px" title="Reçu" aria-label="Reçu d'inscription">🧾</a>
              </div>
            </td>
          </tr>
          @endforeach
          </tbody>
        </table>
        @endif
      </div>
    </div>

    {{-- Notes et résultats --}}
    @if ($voitNotes && (! empty($notesGroupees) || $inscriptions->isNotEmpty()))
      @if (empty($notesGroupees))
        <div class="card"><div class="card-body" style="text-align:center;padding:30px;color:var(--text-muted)"><div style="font-size:32px;margin-bottom:8px">📋</div>Aucune note disponible pour le moment</div></div>
      @else
        <div class="card mb-16"><div class="card-body" style="padding:0">
          @foreach ($notesGroupees as $annee => $semestres)
            <div style="background:#1A3A5C;color:#fff;padding:10px 16px;text-align:center;font-weight:700;font-size:14px">Année académique {{ $annee }}</div>
            @foreach ($semestres as $semestre => $lignes)
              @include('etudiants._notes_semestre', [
                'resS1' => $resultatsIdx[$annee][$semestre]['normale'] ?? null,
                'resS2' => $resultatsIdx[$annee][$semestre]['rattrapage'] ?? null,
              ])
            @endforeach
          @endforeach
        </div></div>
      @endif
    @endif

    {{-- Paiements --}}
    <div class="card">
      <div class="card-header">
        <span class="card-title">💰 Paiements récents</span>
        @unless ($lectureSeule)<a href="{{ $navigation->lien('paiements', ['et_id' => $et->id]) }}" class="btn btn-sm btn-primary">Voir tout</a>@endunless
      </div>
      <div class="card-body" style="padding:0">
        @if ($paiements->isEmpty())
          <div class="empty-state" style="padding:20px"><p>Aucun paiement enregistré.</p></div>
        @else
        @php $couleurs = ['en_attente' => 'var(--amber-600)', 'partiel' => 'var(--blue-600)', 'complet' => 'var(--green-700)', 'rembourse' => 'var(--gray-500)']; @endphp
        <table class="table" style="font-size:12px">
          <thead><tr><th>Type</th><th>Montant dû</th><th>Payé</th><th style="text-align:center">Statut</th></tr></thead>
          <tbody>
          @foreach ($paiements as $p)
          <tr>
            <td style="font-weight:600">{{ $p->type_paiement }}</td>
            <td>{{ number_format((float) $p->montant_du, 0, '.', ' ') }} F</td>
            <td style="color:var(--green-700);font-weight:600">{{ number_format((float) $p->montant_paye, 0, '.', ' ') }} F</td>
            <td style="text-align:center"><span style="font-size:10px;font-weight:600;color:{{ $couleurs[$p->statut] ?? '#999' }}">{{ $p->statut }}</span></td>
          </tr>
          @endforeach
          </tbody>
        </table>
        @endif
      </div>
    </div>
  </div>
</div>

{{-- Documents --}}
<div class="card mt-20">
  <div class="card-header">
    <span class="card-title">📎 Documents ({{ count($documents) }})</span>
    @unless ($lectureSeule)<button type="button" data-onclick="openModal" data-arg="modal-doc" class="btn btn-sm btn-primary">➕ Ajouter</button>@endunless
  </div>
  <div class="card-body" style="padding:0">
    @if ($documents->isEmpty())
      <div class="empty-state" style="padding:24px"><div class="icon">📎</div><p>Aucun document.</p></div>
    @else
    @php $icones = ['cni' => '🪪', 'acte_naissance' => '📜', 'bac' => '🎓', 'photo' => '📷', 'certificat_residence' => '🏠', 'autre' => '📄']; @endphp
    <div style="display:grid;grid-template-columns:repeat(auto-fill,minmax(180px,1fr));gap:12px;padding:16px">
      @foreach ($documents as $doc)
      <div style="border:1px solid var(--border);border-radius:8px;padding:12px;text-align:center">
        <div style="font-size:32px;margin-bottom:6px" aria-hidden="true">{{ $icones[$doc->type_doc] ?? '📄' }}</div>
        <div style="font-size:11px;font-weight:600;margin-bottom:2px">{{ $doc->libelle }}</div>
        <div style="font-size:10px;color:var(--text-muted)">{{ Dossier::TYPES_DOCUMENTS[$doc->type_doc] ?? $doc->type_doc }}</div>
        @if ($doc->valide)<div style="color:var(--green-700);font-size:10px;margin-top:4px">✅ Validé</div>
        @else<div style="color:var(--amber-600);font-size:10px;margin-top:4px">⏳ En attente</div>@endif
        <div style="display:flex;gap:4px;justify-content:center;margin-top:8px">
          <a href="{{ route('fichiers.document', $doc->id) }}" target="_blank" rel="noopener" class="btn btn-sm btn-secondary" style="padding:2px 8px;font-size:10px" aria-label="Voir {{ $doc->libelle }}">👁️</a>
          @unless ($lectureSeule)
            @unless ($doc->valide)
            <form method="post" action="{{ route('dossier_etudiant.valider_document') }}" style="display:inline">
              @csrf <input type="hidden" name="id" value="{{ $et->id }}"><input type="hidden" name="doc_id" value="{{ $doc->id }}">
              <button type="submit" class="btn btn-sm btn-primary" style="padding:2px 8px;font-size:10px" aria-label="Valider {{ $doc->libelle }}">✅</button>
            </form>
            @endunless
            <form method="post" action="{{ route('dossier_etudiant.supprimer_document') }}" style="display:inline" data-confirm="Supprimer ?">
              @csrf <input type="hidden" name="id" value="{{ $et->id }}"><input type="hidden" name="doc_id" value="{{ $doc->id }}">
              <button type="submit" class="btn btn-sm btn-danger" style="padding:2px 8px;font-size:10px" aria-label="Supprimer {{ $doc->libelle }}">🗑️</button>
            </form>
          @endunless
        </div>
      </div>
      @endforeach
    </div>
    @endif
  </div>
</div>

@unless ($lectureSeule)
<div id="modal-edit" class="modal-overlay" style="display:none">
  <div class="modal" style="max-width:680px">
    <div class="modal-header">
      <span class="modal-title">✏️ Modifier le dossier</span>
      <button type="button" data-onclick="closeModal" data-arg="modal-edit" class="btn btn-icon" aria-label="Fermer">✕</button>
    </div>
    <form method="post" action="{{ route('dossier_etudiant.modifier') }}" enctype="multipart/form-data">
      <div class="modal-body">
        @csrf
        <input type="hidden" name="id" value="{{ $et->id }}">
        <div class="form-grid form-grid-2">
          @foreach (['nom' => 'Nom *', 'prenoms' => 'Prénoms *'] as $champ => $libelle)
            <div class="form-group"><label class="form-label" for="d-{{ $champ }}">{{ $libelle }}</label><input type="text" id="d-{{ $champ }}" name="{{ $champ }}" class="form-control" value="{{ $et->{$champ} }}" required></div>
          @endforeach
          <div class="form-group"><label class="form-label" for="d-sexe">Sexe</label>
            <select id="d-sexe" name="sexe" class="form-control"><option value="">—</option><option value="M" @selected($et->sexe === 'M')>Masculin</option><option value="F" @selected($et->sexe === 'F')>Féminin</option></select></div>
          @foreach (['telephone' => 'Téléphone', 'email' => 'Email', 'nationalite' => 'Nationalité', 'nom_pere' => 'Nom du père', 'nom_mere' => 'Nom de la mère', 'tel_urgence' => 'Téléphone urgence', 'bac_serie' => 'Série Bac'] as $champ => $libelle)
            <div class="form-group"><label class="form-label" for="d-{{ $champ }}">{{ $libelle }}</label>
              <input type="{{ $champ === 'email' ? 'email' : 'text' }}" id="d-{{ $champ }}" name="{{ $champ }}" class="form-control" value="{{ $champ === 'nationalite' ? ($et->nationalite ?? 'Nigérienne') : $et->{$champ} }}" @if ($champ === 'bac_serie') placeholder="Ex: D, A4..." @endif></div>
          @endforeach
          <div class="form-group"><label class="form-label" for="d-bac_annee">Année Bac</label><input type="number" id="d-bac_annee" name="bac_annee" class="form-control" value="{{ $et->bac_annee }}" min="1990" max="{{ date('Y') }}"></div>
          <div class="form-group"><label class="form-label" for="d-bac_mention">Mention Bac</label>
            <select id="d-bac_mention" name="bac_mention" class="form-control"><option value="">—</option>@foreach (['Passable', 'Assez Bien', 'Bien', 'Très Bien'] as $m)<option @selected($et->bac_mention === $m)>{{ $m }}</option>@endforeach</select></div>
          <div class="form-group"><label class="form-label" for="d-statut">Statut</label>
            <select id="d-statut" name="statut_etudiant" class="form-control">@foreach (Dossier::STATUTS as $s)<option value="{{ $s }}" @selected(($et->statut_etudiant ?? 'actif') === $s)>{{ ucfirst($s) }}</option>@endforeach</select></div>
        </div>
        <div class="form-group"><label class="form-label" for="d-adresse">Adresse</label><textarea id="d-adresse" name="adresse" class="form-control" rows="2">{{ $et->adresse }}</textarea></div>
        <div class="form-group"><label class="form-label" for="d-etab">Établissement d'origine</label><input type="text" id="d-etab" name="etablissement_origine" class="form-control" value="{{ $et->etablissement_origine }}"></div>
        <div class="form-group"><label class="form-label" for="d-photo">Photo de profil</label><input type="file" id="d-photo" name="photo" class="form-control" accept=".jpg,.jpeg,.png,.webp"></div>
      </div>
      <div class="modal-footer">
        <button type="button" data-onclick="closeModal" data-arg="modal-edit" class="btn btn-secondary">Annuler</button>
        <button type="submit" class="btn btn-primary">💾 Enregistrer</button>
      </div>
    </form>
  </div>
</div>

<div id="modal-doc" class="modal-overlay" style="display:none">
  <div class="modal">
    <div class="modal-header">
      <span class="modal-title">📎 Ajouter un document</span>
      <button type="button" data-onclick="closeModal" data-arg="modal-doc" class="btn btn-icon" aria-label="Fermer">✕</button>
    </div>
    <form method="post" action="{{ route('dossier_etudiant.ajouter_document') }}" enctype="multipart/form-data">
      <div class="modal-body">
        @csrf
        <input type="hidden" name="id" value="{{ $et->id }}">
        <div class="form-group"><label class="form-label" for="type_doc">Type de document</label>
          <select id="type_doc" name="type_doc" class="form-control" required>@foreach (Dossier::TYPES_DOCUMENTS as $k => $l)<option value="{{ $k }}">{{ $l }}</option>@endforeach</select></div>
        <div class="form-group"><label class="form-label" for="libelle-doc">Libellé</label><input type="text" id="libelle-doc" name="libelle" class="form-control" placeholder="Ex: CNI n°12345"></div>
        <div class="form-group"><label class="form-label" for="doc_fichier">Fichier <span class="req">*</span></label>
          <input type="file" id="doc_fichier" name="doc_fichier" class="form-control" required accept=".pdf,.doc,.docx,.jpg,.jpeg,.png"></div>
      </div>
      <div class="modal-footer">
        <button type="button" data-onclick="closeModal" data-arg="modal-doc" class="btn btn-secondary">Annuler</button>
        <button type="submit" class="btn btn-primary">📎 Ajouter</button>
      </div>
    </form>
  </div>
</div>
@endunless
@endsection
