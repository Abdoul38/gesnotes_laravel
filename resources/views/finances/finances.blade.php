{{-- Finances — portage de pages/finances/finances.php --}}
@extends('layouts.app', ['titre' => 'Finances', 'fil' => 'Scolarité › Finances'])

@section('contenu')
@php
    $fcfa = fn ($v) => number_format((float) $v, 0, '.', ' ');
    $icones = ['especes' => '💵', 'virement' => '🏦', 'mobile_money' => '📱', 'cheque' => '📝'];
@endphp

<div style="display:flex;justify-content:space-between;align-items:flex-end;margin-bottom:0;flex-wrap:wrap;gap:12px">
  <div style="display:flex;gap:4px;border-bottom:2px solid var(--border)">
    @foreach (['apercu' => '📈 Aperçu', 'tarifs' => '🏷️ Grille tarifaire', 'depenses' => '📤 Dépenses'] as $cle => $libelle)
    <a href="{{ route('finances', ['tab' => $cle, 'annee_id' => $annee]) }}"
       style="padding:10px 18px;text-decoration:none;font-weight:600;font-size:13px;color:{{ $onglet === $cle ? 'var(--blue-700)' : 'var(--text-muted)' }};border-bottom:2.5px solid {{ $onglet === $cle ? 'var(--blue-700)' : 'transparent' }};margin-bottom:-2px">{{ $libelle }}</a>
    @endforeach
  </div>
  <form method="get" action="{{ route('finances') }}" style="margin-bottom:8px">
    <input type="hidden" name="tab" value="{{ $onglet }}">
    <label class="form-label" for="annee_id" style="margin-bottom:2px">📅 Année académique</label>
    <select name="annee_id" id="annee_id" class="form-control" data-autosubmit="1" style="min-width:160px">
      @foreach ($annees as $a)<option value="{{ $a->id }}" @selected($a->id == $annee)>{{ $a->libelle }}</option>@endforeach
    </select>
  </form>
</div>
<div style="margin-bottom:20px"></div>

@if ($onglet === 'apercu')
@php $solde = $totalRecettes - $totalDepenses; @endphp
<div style="display:grid;grid-template-columns:repeat(4,1fr);gap:16px;margin-bottom:20px">
  @foreach ([['📥', $fcfa($totalRecettes).' FCFA', 'var(--green-700)', 'Recettes totales'], ['📤', $fcfa($totalDepenses).' FCFA', 'var(--red-600)', 'Dépenses totales'],
             [$solde >= 0 ? '⚖️' : '⚠️', $fcfa($solde).' FCFA', $solde >= 0 ? 'var(--blue-700)' : 'var(--red-600)', 'Solde net'], ['⏳', $fcfa($resteAPercevoir).' FCFA', 'var(--amber-600)', 'Reste à percevoir']] as [$ic, $val, $co, $lb])
  <div class="card" style="text-align:center;padding:18px">
    <div style="font-size:24px">{{ $ic }}</div>
    <div style="font-size:19px;font-weight:800;color:{{ $co }};margin:4px 0">{{ $val }}</div>
    <div style="font-size:11px;color:var(--text-muted);text-transform:uppercase">{{ $lb }}</div>
  </div>
  @endforeach
</div>

<div style="display:grid;grid-template-columns:repeat(2,1fr);gap:16px;margin-bottom:20px">
  <div class="card" style="padding:14px;text-align:center">
    <div style="font-size:12px;color:var(--text-muted);text-transform:uppercase">Recettes — ce mois</div>
    <div style="font-size:20px;font-weight:800;color:var(--green-700)">{{ $fcfa($recettesMois) }} FCFA</div>
  </div>
  <div class="card" style="padding:14px;text-align:center">
    <div style="font-size:12px;color:var(--text-muted);text-transform:uppercase">Dépenses — ce mois</div>
    <div style="font-size:20px;font-weight:800;color:var(--red-600)">{{ $fcfa($depensesMois) }} FCFA</div>
  </div>
</div>

<div class="card mb-16">
  <div class="card-header"><span class="card-title">📈 Évolution sur 12 mois</span></div>
  <div class="card-body"><canvas id="chartEvolution" height="90" aria-label="Évolution des recettes et dépenses sur 12 mois"></canvas></div>
</div>

<div style="display:grid;grid-template-columns:1fr 1fr;gap:16px">
  <div class="card">
    <div class="card-header"><span class="card-title">📊 Dépenses par catégorie</span></div>
    <div class="card-body">
      @if ($depensesParCategorie->isEmpty())
      <div class="empty-state" style="padding:24px"><p>Aucune dépense enregistrée.</p></div>
      @else
      <canvas id="chartCategories" height="220" aria-label="Dépenses par catégorie"></canvas>
      <table class="table" style="margin-top:12px;font-size:13px">
        <thead><tr><th>Catégorie</th><th style="text-align:right">Montant</th></tr></thead>
        <tbody>
          @foreach ($depensesParCategorie as $dc)
          <tr><td>{{ $dc->libelle }}</td><td style="text-align:right;font-weight:600">{{ $fcfa($dc->total) }} FCFA</td></tr>
          @endforeach
        </tbody>
      </table>
      @endif
    </div>
  </div>
  <div class="card">
    <div class="card-header"><span class="card-title">🎓 Recettes par filière</span></div>
    <div class="card-body">
      @if ($recettesParFiliere->isEmpty())
      <div class="empty-state" style="padding:24px"><p>Aucune donnée disponible.</p></div>
      @else
      <canvas id="chartFilieres" height="220" aria-label="Recettes par filière"></canvas>
      @endif
    </div>
  </div>
</div>
@php
    $graphiques = [
        'labels' => $serie['labels'], 'recettes' => $serie['recettes'], 'depenses' => $serie['depenses'],
        'categories' => ['labels' => $depensesParCategorie->pluck('libelle'), 'valeurs' => $depensesParCategorie->pluck('total')->map(fn ($v) => (float) $v)],
        'filieres' => ['labels' => $recettesParFiliere->pluck('libelle'), 'valeurs' => $recettesParFiliere->pluck('total')->map(fn ($v) => (float) $v)],
    ];
@endphp
<script type="application/json" id="donnees-finances">@json($graphiques)</script>

@elseif ($onglet === 'tarifs')
<div class="alert alert-info" style="margin-bottom:20px;font-size:12px">
  💡 Un tarif défini ici est proposé automatiquement lors de la saisie d'un paiement pour un étudiant de cette filière et ce niveau — le montant reste modifiable au cas par cas. Ces tarifs sont fixes : le sélecteur d'année ci-dessus n'a pas d'effet sur cet onglet.
</div>
<div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:16px">
  <span style="font-size:13px;color:var(--text-muted)">{{ $tarifs->flatten(1)->count() }} tarif(s) défini(s)</span>
  @if ($peutGerer)<button type="button" class="btn btn-primary" data-tarif-nouveau="1">➕ Ajouter un tarif</button>@endif
</div>

@forelse ($filieres as $f)
@php $lignes = $tarifs->get($f->id, collect()); @endphp
<div class="card mb-16">
  <div class="card-header"><span class="card-title">🎓 {{ $f->libelle }}</span></div>
  <div class="card-body" style="padding:0">
    @if ($lignes->isEmpty())
    <div style="padding:16px;font-size:12px;color:var(--text-muted)">Aucun tarif défini pour cette filière.</div>
    @else
    <table class="table" style="font-size:13px">
      <thead><tr><th>Niveau</th><th>Type de paiement</th><th style="text-align:right">Montant</th>@if ($peutGerer)<th style="text-align:center">Action</th>@endif</tr></thead>
      <tbody>
        @foreach ($lignes as $t)
        <tr>
          <td style="font-weight:600">{{ $t->niveau_code }}</td>
          <td>{{ \App\Services\Paiements::TYPES[$t->type_paiement] ?? $t->type_paiement }}</td>
          <td style="text-align:right;font-weight:700;color:var(--blue-700)">{{ $fcfa($t->montant) }} FCFA</td>
          @if ($peutGerer)
          <td style="text-align:center">
            <button type="button" class="btn btn-sm btn-secondary" aria-label="Modifier" data-tarif="{{ json_encode(['filiere_id' => $t->filiere_id, 'niveau_id' => $t->niveau_id, 'type_paiement' => $t->type_paiement, 'montant' => (float) $t->montant]) }}">✏️</button>
            <form method="post" action="{{ route('finances.tarif.supprimer') }}" style="display:inline" data-confirm="Supprimer ce tarif ?">
              @csrf
              <input type="hidden" name="annee_id_filtre" value="{{ $annee }}">
              <input type="hidden" name="id" value="{{ $t->id }}">
              <button type="submit" class="btn btn-sm btn-danger" aria-label="Supprimer">🗑️</button>
            </form>
          </td>
          @endif
        </tr>
        @endforeach
      </tbody>
    </table>
    @endif
  </div>
</div>
@empty
<div class="card"><div class="empty-state" style="padding:40px"><div class="icon">🎓</div><p>Aucune filière trouvée.</p></div></div>
@endforelse

@if ($peutGerer)
<div id="modal-tarif" class="modal-overlay" style="display:none">
  <div class="modal" style="max-height:90vh;display:flex;flex-direction:column">
    <div class="modal-header"><span class="modal-title" id="modal-titre-tarif">➕ Nouveau tarif</span><button type="button" data-onclick="closeModal" data-arg="modal-tarif" class="btn btn-icon" aria-label="Fermer">✕</button></div>
    <form method="post" action="{{ route('finances.tarif') }}" style="display:flex;flex-direction:column;min-height:0">
      <div class="modal-body" style="overflow-y:auto">
        @csrf
        <input type="hidden" name="annee_id_filtre" value="{{ $annee }}">
        <div class="form-group"><label class="form-label" for="tarif-filiere">Filière *</label>
          <select name="filiere_id" id="tarif-filiere" class="form-control" required>
            @foreach ($filieres as $f)<option value="{{ $f->id }}">{{ $f->libelle }}</option>@endforeach
          </select></div>
        <div class="form-group"><label class="form-label" for="tarif-niveau">Niveau *</label>
          <select name="niveau_id" id="tarif-niveau" class="form-control" required>
            @foreach ($niveaux as $n)<option value="{{ $n->id }}">{{ $n->code }} — {{ $n->libelle }}</option>@endforeach
          </select></div>
        <div class="form-group"><label class="form-label" for="tarif-type">Type de paiement *</label>
          <select name="type_paiement" id="tarif-type" class="form-control" required>
            @foreach (\App\Services\Paiements::TYPES as $k => $v)<option value="{{ $k }}">{{ $v }}</option>@endforeach
          </select></div>
        <div class="form-group"><label class="form-label" for="tarif-montant">Montant (FCFA) *</label>
          <input type="number" name="montant" id="tarif-montant" class="form-control" min="0" max="{{ \App\Services\Paiements::MONTANT_MAX }}" step="any" required></div>
        <div class="form-hint">Si un tarif existe déjà pour cette combinaison filière/niveau/type, il sera remplacé.</div>
      </div>
      <div class="modal-footer">
        <button type="button" data-onclick="closeModal" data-arg="modal-tarif" class="btn btn-secondary">Annuler</button>
        <button type="submit" class="btn btn-primary">💾 Enregistrer</button>
      </div>
    </form>
  </div>
</div>
@endif

@else
<div style="display:grid;grid-template-columns:repeat(2,1fr);gap:16px;margin-bottom:20px">
  <div class="card" style="text-align:center;padding:16px">
    <div style="font-size:24px">📤</div>
    <div style="font-size:18px;font-weight:800;color:var(--red-600);margin:4px 0">{{ $fcfa($totalFiltre) }} FCFA</div>
    <div style="font-size:11px;color:var(--text-muted);text-transform:uppercase">Total affiché ({{ $depensesListe->count() }} ligne(s))</div>
  </div>
  <div class="card" style="text-align:center;padding:16px">
    <div style="font-size:24px">📅</div>
    <div style="font-size:18px;font-weight:800;color:var(--amber-600);margin:4px 0">{{ $fcfa($totalMoisCourant) }} FCFA</div>
    <div style="font-size:11px;color:var(--text-muted);text-transform:uppercase">Ce mois-ci</div>
  </div>
</div>

<div class="card mb-16">
  <div class="card-body" style="display:flex;gap:12px;align-items:end;flex-wrap:wrap">
    <form method="get" action="{{ route('finances') }}" style="display:flex;gap:10px;align-items:end;flex-wrap:wrap">
      <input type="hidden" name="tab" value="depenses">
      <input type="hidden" name="annee_id" value="{{ $annee }}">
      <div class="form-group" style="margin:0">
        <label class="form-label" for="categorie_id">Catégorie</label>
        <select name="categorie_id" id="categorie_id" class="form-control" data-autosubmit="1">
          <option value="0">Toutes</option>
          @foreach ($categories as $c)<option value="{{ $c->id }}" @selected($categorieFiltre == $c->id)>{{ $c->libelle }}</option>@endforeach
        </select>
      </div>
      <div class="form-group" style="margin:0">
        <label class="form-label" for="mois">Mois</label>
        <input type="month" name="mois" id="mois" class="form-control" value="{{ $moisFiltre }}" data-autosubmit="1">
      </div>
      @if ($categorieFiltre || $moisFiltre)<a href="{{ route('finances', ['tab' => 'depenses', 'annee_id' => $annee]) }}" class="btn btn-secondary">✕ Effacer</a>@endif
    </form>
    @if ($peutGererDepenses)
    <div style="margin-left:auto;display:flex;gap:8px">
      <button type="button" data-onclick="openModal" data-arg="modal-categorie" class="btn btn-secondary">🏷️ Nouvelle catégorie</button>
      <button type="button" class="btn btn-primary" data-depense-nouvelle="1">➕ Nouvelle dépense</button>
    </div>
    @endif
  </div>
</div>

<div class="card">
  <div class="card-body" style="padding:0">
    @if ($depensesListe->isEmpty())
    <div class="empty-state" style="padding:32px"><div class="icon">📤</div><p>Aucune dépense enregistrée.</p></div>
    @else
    <table class="table" style="font-size:12px">
      <thead><tr><th>Date</th><th>Catégorie</th><th>Libellé</th><th>Bénéficiaire</th><th style="text-align:right">Montant</th><th>Mode</th>@if ($peutGererDepenses)<th>Action</th>@endif</tr></thead>
      <tbody>
      @foreach ($depensesListe as $d)
      <tr>
        <td>{{ date('d/m/Y', strtotime($d->date_depense)) }}</td>
        <td><span style="font-size:10px;background:var(--gray-100);padding:2px 8px;border-radius:10px">{{ $d->categorie }}</span></td>
        <td style="font-weight:600">{{ $d->libelle }}</td>
        <td>{{ $d->beneficiaire ?? '—' }}</td>
        <td style="text-align:right;color:var(--red-600);font-weight:700">{{ $fcfa($d->montant) }}</td>
        <td>{{ $icones[$d->mode_paiement] ?? '' }}</td>
        @if ($peutGererDepenses)
        <td>
          <button type="button" class="btn btn-sm btn-secondary" aria-label="Modifier" data-depense="{{ json_encode(['id' => $d->id, 'categorie_id' => $d->categorie_id, 'annee_id' => $d->annee_id,
            'libelle' => $d->libelle, 'montant' => (float) $d->montant, 'date_depense' => $d->date_depense, 'mode_paiement' => $d->mode_paiement,
            'beneficiaire' => $d->beneficiaire, 'reference' => $d->reference, 'notes' => $d->notes]) }}">✏️</button>
          <form method="post" action="{{ route('finances.depense.supprimer') }}" style="display:inline" data-confirm="Supprimer cette dépense ?">
            @csrf
            <input type="hidden" name="annee_id_filtre" value="{{ $annee }}">
            <input type="hidden" name="id" value="{{ $d->id }}">
            <button type="submit" class="btn btn-sm btn-danger" aria-label="Supprimer">🗑️</button>
          </form>
        </td>
        @endif
      </tr>
      @endforeach
      </tbody>
    </table>
    @endif
  </div>
</div>

@if ($peutGererDepenses)
<div id="modal-categorie" class="modal-overlay" style="display:none">
  <div class="modal" style="max-height:90vh;display:flex;flex-direction:column">
    <div class="modal-header"><span class="modal-title">🏷️ Nouvelle catégorie</span><button type="button" data-onclick="closeModal" data-arg="modal-categorie" class="btn btn-icon" aria-label="Fermer">✕</button></div>
    <form method="post" action="{{ route('finances.categorie') }}" style="display:flex;flex-direction:column;min-height:0">
      <div class="modal-body" style="overflow-y:auto">
        @csrf
        <input type="hidden" name="annee_id_filtre" value="{{ $annee }}">
        <div class="form-group"><label class="form-label" for="libelle_categorie">Nom de la catégorie *</label>
          <input type="text" name="libelle_categorie" id="libelle_categorie" class="form-control" required maxlength="100" placeholder="Ex: Frais bancaires"></div>
      </div>
      <div class="modal-footer">
        <button type="button" data-onclick="closeModal" data-arg="modal-categorie" class="btn btn-secondary">Annuler</button>
        <button type="submit" class="btn btn-primary">💾 Ajouter</button>
      </div>
    </form>
  </div>
</div>

<div id="modal-depense" class="modal-overlay" style="display:none">
  <div class="modal" style="max-height:90vh;display:flex;flex-direction:column">
    <div class="modal-header"><span class="modal-title" id="modal-titre-depense">➕ Nouvelle dépense</span><button type="button" data-onclick="closeModal" data-arg="modal-depense" class="btn btn-icon" aria-label="Fermer">✕</button></div>
    <form method="post" action="{{ route('finances.depense') }}" id="form-depense" style="display:flex;flex-direction:column;min-height:0"
      data-aujourdhui="{{ date('Y-m-d') }}" data-annee="{{ $annee }}">
      <div class="modal-body" style="overflow-y:auto">
        @csrf
        <input type="hidden" name="action" id="dep-action" value="ajouter">
        <input type="hidden" name="annee_id_filtre" value="{{ $annee }}">
        <input type="hidden" name="id" id="dep-id" value="">
        <div class="form-group"><label class="form-label" for="dep-categorie">Catégorie *</label>
          <select name="categorie_id" id="dep-categorie" class="form-control" required>
            @foreach ($categories as $c)<option value="{{ $c->id }}">{{ $c->libelle }}</option>@endforeach
          </select></div>
        <div class="form-group"><label class="form-label" for="dep-annee">Année académique</label>
          <select name="annee_id" id="dep-annee" class="form-control">
            @foreach ($annees as $a)<option value="{{ $a->id }}" @selected($a->id == $annee)>{{ $a->libelle }}</option>@endforeach
          </select></div>
        <div class="form-group"><label class="form-label" for="dep-libelle">Libellé *</label>
          <input type="text" name="libelle" id="dep-libelle" class="form-control" required maxlength="200" placeholder="Ex: Facture électricité janvier"></div>
        <div class="form-grid form-grid-2">
          <div class="form-group"><label class="form-label" for="dep-montant">Montant (FCFA) *</label>
            <input type="number" name="montant" id="dep-montant" class="form-control" min="0" max="{{ \App\Services\Paiements::MONTANT_MAX }}" step="any" required></div>
          <div class="form-group"><label class="form-label" for="dep-date">Date *</label>
            <input type="date" name="date_depense" id="dep-date" class="form-control" value="{{ date('Y-m-d') }}" required></div>
          <div class="form-group"><label class="form-label" for="dep-mode">Mode de paiement</label>
            <select name="mode_paiement" id="dep-mode" class="form-control">
              @foreach (\App\Services\Paiements::MODES as $k => $v)<option value="{{ $k }}">{{ $v }}</option>@endforeach
            </select></div>
          <div class="form-group"><label class="form-label" for="dep-beneficiaire">Bénéficiaire</label>
            <input type="text" name="beneficiaire" id="dep-beneficiaire" class="form-control" maxlength="150" placeholder="Ex: SONIDEP, M. X..."></div>
        </div>
        <div class="form-group"><label class="form-label" for="dep-reference">Référence</label>
          <input type="text" name="reference" id="dep-reference" class="form-control" maxlength="100"></div>
        <div class="form-group"><label class="form-label" for="dep-notes">Notes</label>
          <textarea name="notes" id="dep-notes" class="form-control" rows="2" maxlength="2000"></textarea></div>
      </div>
      <div class="modal-footer">
        <button type="button" data-onclick="closeModal" data-arg="modal-depense" class="btn btn-secondary">Annuler</button>
        <button type="submit" class="btn btn-primary">💾 Enregistrer</button>
      </div>
    </form>
  </div>
</div>
@endif
@endif
@endsection

@push('scripts')
@if ($onglet === 'apercu')<script src="{{ asset('assets/js/chart.min.js') }}"></script>@endif
<script src="{{ asset('assets/js/finances.js') }}"></script>
@endpush
