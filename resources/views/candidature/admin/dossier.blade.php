{{-- Dossier complet d'un candidat — portage de pages/admin/dossier_candidat.php --}}
@extends('layouts.app', ['titre' => 'Dossier '.$d->numero_dossier, 'fil' => 'Inscriptions › Portail candidats › Dossier'])

@use('App\Services\Candidatures')

@section('contenu')
@php [$ic, $lib] = Candidatures::STATUTS[$d->statut] ?? ['?', $d->statut]; $photo = $d->doc_photo && ! str_ends_with(strtolower($d->doc_photo), '.pdf'); @endphp
<div style="margin-bottom:12px"><a href="{{ route('portail_candidats_admin', ['tab' => 'dossiers']) }}" class="btn btn-secondary">← Retour à la liste</a></div>

<div class="card mb-16" style="background:linear-gradient(135deg,#5b21b6,#7c3aed);color:#fff">
  <div class="card-body" style="display:flex;gap:16px;align-items:center;flex-wrap:wrap">
    @if ($photo)<img src="{{ route('portail_candidats_admin.piece', ['id' => $d->id, 'type' => 'photo']) }}" alt="Photo" style="width:64px;height:76px;object-fit:cover;border-radius:8px;border:2px solid rgba(255,255,255,.5)">
    @else<div style="font-size:40px">👤</div>@endif
    <div style="flex:1;min-width:200px">
      <div style="font-size:18px;font-weight:800">{{ $d->nom }} {{ $d->prenom }}</div>
      <div style="font-size:12px;opacity:.85">N° {{ $d->numero_dossier }}@if ($d->numero_depot) · Dépôt {{ $d->numero_depot }}@endif · {{ $ic }} {{ $lib }}
        @if ($d->date_naissance) · Né(e) le {{ date('d/m/Y', strtotime($d->date_naissance)) }}@endif</div>
    </div>
    <div style="display:flex;gap:6px;flex-wrap:wrap">
      <a href="{{ route('portail_candidats_admin.quitus', ['id' => $d->id]) }}" target="_blank" rel="noopener" class="btn btn-sm" style="background:rgba(255,255,255,.2);color:#fff">📄 Quitus</a>
      @foreach (['accepte' => ['✅ Approuver', 'Approuver ce dossier ?'], 'refuse' => ['❌ Rejeter', 'Rejeter ce dossier ?'], 'incomplet' => ['⚠️ Incomplet', 'Marquer ce dossier incomplet (le candidat pourra le compléter) ?'], 'en_cours' => ['🔄 En cours', 'Passer ce dossier en cours de traitement ?']] as $s => [$texte, $question])
      @if ($d->statut !== $s)
      <form method="post" action="{{ route('portail_candidats_admin.statut') }}" data-confirm="{{ $question }}">@csrf<input type="hidden" name="dos_id" value="{{ $d->id }}"><input type="hidden" name="statut" value="{{ $s }}"><input type="hidden" name="depuis_dossier" value="1"><button type="submit" class="btn btn-sm" style="background:rgba(255,255,255,.2);color:#fff">{{ $texte }}</button></form>
      @endif
      @endforeach
      @if ($d->etudiant_id)
      <a href="{{ route('dossier_etudiant', ['id' => $d->etudiant_id]) }}" class="btn btn-sm" style="background:rgba(255,255,255,.2);color:#fff">📁 Dossier étudiant</a>
      @else
      <form method="post" action="{{ route('portail_candidats_admin.etudiant') }}" data-confirm="Créer cet étudiant dans GesNotes ?">@csrf<input type="hidden" name="dos_id" value="{{ $d->id }}"><input type="hidden" name="depuis_dossier" value="1"><button type="submit" class="btn btn-sm" style="background:#fff;color:#5b21b6">🎓 Créer l'étudiant</button></form>
      @endif
    </div>
  </div>
</div>

<form method="post" action="{{ route('dossier_candidat.modifier', ['id' => $d->id]) }}">
  @csrf
  <div style="display:grid;grid-template-columns:repeat(auto-fit,minmax(320px,1fr));gap:16px">
    <div class="card">
      <div class="card-header"><span class="card-title">👤 Identité</span></div>
      <div class="card-body" style="display:grid;grid-template-columns:1fr 1fr;gap:10px">
        <div class="form-group"><label class="form-label" for="nom">Nom</label><input type="text" name="nom" id="nom" class="form-control" value="{{ $d->nom }}" required maxlength="100"></div>
        <div class="form-group"><label class="form-label" for="prenom">Prénom</label><input type="text" name="prenom" id="prenom" class="form-control" value="{{ $d->prenom }}" required maxlength="150"></div>
        <div class="form-group"><label class="form-label" for="sexe">Sexe</label><select name="sexe" id="sexe" class="form-control"><option value="M" @selected($d->sexe === 'M')>Masculin</option><option value="F" @selected($d->sexe === 'F')>Féminin</option></select></div>
        <div class="form-group"><label class="form-label" for="date_naissance">Date de naissance</label><input type="date" name="date_naissance" id="date_naissance" class="form-control" value="{{ $d->date_naissance }}"></div>
        <div class="form-group"><label class="form-label" for="lieu_naissance">Lieu de naissance</label><input type="text" name="lieu_naissance" id="lieu_naissance" class="form-control" value="{{ $d->lieu_naissance }}" maxlength="200"></div>
        <div class="form-group"><label class="form-label" for="nationalite">Nationalité</label><input type="text" name="nationalite" id="nationalite" class="form-control" value="{{ $d->nationalite }}" maxlength="100"></div>
        <div class="form-group"><label class="form-label" for="email">Email</label><input type="email" name="email" id="email" class="form-control" value="{{ $d->email }}" maxlength="150"></div>
        <div class="form-group"><label class="form-label" for="telephone">Téléphone</label><input type="text" name="telephone" id="telephone" class="form-control" value="{{ $d->telephone }}" maxlength="30"></div>
        <div class="form-group" style="grid-column:1/-1"><label class="form-label" for="adresse">Adresse</label><input type="text" name="adresse" id="adresse" class="form-control" value="{{ $d->adresse }}" maxlength="1000"></div>
        @if ($d->cand_email && $d->cand_email !== $d->email)<div style="grid-column:1/-1;font-size:11px;color:var(--text-muted)">Compte du portail : {{ $d->cand_email }} · {{ $d->cand_tel }}</div>@endif
      </div>
    </div>
    <div class="card">
      <div class="card-header"><span class="card-title">🎓 Baccalauréat</span></div>
      <div class="card-body" style="display:grid;grid-template-columns:1fr 1fr;gap:10px">
        <div class="form-group"><label class="form-label" for="bac_type">Type</label><input type="text" name="bac_type" id="bac_type" class="form-control" value="{{ $d->bac_type }}" maxlength="20"></div>
        <div class="form-group"><label class="form-label" for="bac_mention">Mention</label><input type="text" name="bac_mention" id="bac_mention" class="form-control" value="{{ $d->bac_mention }}" maxlength="50"></div>
        <div class="form-group"><label class="form-label" for="bac_lieu">Lieu</label><input type="text" name="bac_lieu" id="bac_lieu" class="form-control" value="{{ $d->bac_lieu }}" maxlength="100"></div>
        <div class="form-group"><label class="form-label" for="bac_annee">Année</label><input type="text" name="bac_annee" id="bac_annee" class="form-control" value="{{ $d->bac_annee }}" maxlength="20"></div>
        <div style="grid-column:1/-1;font-size:12px;color:var(--text-muted)">Établissement : {{ $d->bac_etablissement ?: '—' }}</div>
      </div>
    </div>
    <div class="card">
      <div class="card-header"><span class="card-title">📚 Choix de filières</span></div>
      <div class="card-body">
        @foreach ([1 => 'Priorité 1', 2 => 'Priorité 2', 3 => 'Priorité 3'] as $n => $lbl)
        <div class="form-group"><label class="form-label" for="choix{{ $n }}_id">{{ $lbl }}</label>
          <select name="choix{{ $n }}_id" id="choix{{ $n }}_id" class="form-control"><option value="">—</option>
            @foreach ($filieres as $f)<option value="{{ $f->id }}" @selected((int) $d->{'choix'.$n.'_id'} === (int) $f->id)>{{ $f->libelle }}{{ $f->actif ? '' : ' (inactive)' }}</option>@endforeach
          </select></div>
        @endforeach
      </div>
    </div>
  </div>
  <div style="margin:12px 0 16px"><button type="submit" class="btn btn-primary">💾 Enregistrer les modifications</button></div>
</form>

<div style="display:grid;grid-template-columns:repeat(auto-fit,minmax(320px,1fr));gap:16px">
  <div class="card">
    <div class="card-header"><span class="card-title">📎 Documents</span></div>
    <div class="card-body">
      @foreach (Candidatures::PIECES as $type => [$col, $icone, $libelle])
      <div style="display:flex;justify-content:space-between;align-items:center;padding:8px 0;border-bottom:1px solid var(--border)">
        <span>{{ $icone }} {{ $libelle }}</span>
        @if ($d->$col)<a href="{{ route('portail_candidats_admin.piece', ['id' => $d->id, 'type' => $type]) }}" target="_blank" rel="noopener" class="btn btn-sm btn-secondary">⬇️ Ouvrir</a>
        @else<span style="font-size:11px;color:var(--text-muted)">Non fourni</span>@endif
      </div>
      @endforeach
    </div>
  </div>
  <div class="card">
    <div class="card-header"><span class="card-title">📝 Note administrative (interne)</span></div>
    <div class="card-body">
      <form method="post" action="{{ route('portail_candidats_admin.note') }}">
        @csrf
        <input type="hidden" name="dos_id" value="{{ $d->id }}"><input type="hidden" name="depuis_dossier" value="1">
        <textarea name="note" class="form-control" rows="4" maxlength="5000" aria-label="Note administrative" placeholder="Note visible uniquement par l'administration...">{{ $d->notes_admin }}</textarea>
        <button type="submit" class="btn btn-primary btn-sm" style="margin-top:8px">💾 Enregistrer la note</button>
      </form>
    </div>
  </div>
</div>
@endsection
