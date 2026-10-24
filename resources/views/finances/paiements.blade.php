{{-- Suivi des paiements — portage de pages/finances/paiements.php --}}
@extends('layouts.app', ['titre' => 'Suivi des paiements', 'fil' => 'Scolarité › Paiements'])
@inject('navigation', 'App\Navigation\Menu')

@section('contenu')
@php
    $couleurs = ['en_attente' => 'var(--amber-600)', 'partiel' => 'var(--blue-600)', 'complet' => 'var(--green-700)', 'rembourse' => 'var(--gray-500)'];
    $icones = ['especes' => '💵', 'virement' => '🏦', 'mobile_money' => '📱', 'cheque' => '📝'];
    $fcfa = fn ($v) => number_format((float) $v, 0, '.', ' ');
    $reste = $totalDu - $totalPaye;
    $actifs = $filtres['annee_id'] !== $anneeActive || $filtres['type_paiement'] || $filtres['statut'] || $filtres['q'] !== '' || $filtres['date_debut'] || $filtres['date_fin'];
@endphp

@if ($etudiant)
<div class="card mb-16" style="background:linear-gradient(135deg,#1A3A5C,#2C5F9E);color:#fff">
  <div class="card-body" style="display:flex;align-items:center;gap:16px;padding:14px 20px">
    <span style="font-size:36px">👤</span>
    <div style="flex:1">
      <div style="font-size:16px;font-weight:800">{{ $etudiant->nom }} {{ $etudiant->prenoms }}</div>
      <div style="opacity:.8;font-size:12px">Matricule : {{ $etudiant->numero }}</div>
    </div>
    <a href="{{ $navigation->lien('dossier_etudiant', ['id' => $etudiant->id]) }}" class="btn btn-sm" style="background:rgba(255,255,255,.2);color:#fff;border:1px solid rgba(255,255,255,.4)">← Dossier</a>
  </div>
</div>
@endif

<div style="display:grid;grid-template-columns:repeat(4,1fr);gap:16px;margin-bottom:20px">
  @foreach ([['💰', 'Total dû', $fcfa($totalDu).' FCFA', 'var(--text-primary)'], ['✅', 'Total payé', $fcfa($totalPaye).' FCFA', 'var(--green-700)'],
             ['⏳', 'Reste à payer', $fcfa($reste).' FCFA', $reste > 0 ? 'var(--red-600)' : 'var(--green-700)']] as [$ic, $lb, $vl, $co])
  <div class="card" style="text-align:center;padding:16px">
    <div style="font-size:24px">{{ $ic }}</div>
    <div style="font-size:18px;font-weight:800;color:{{ $co }};margin:4px 0">{{ $vl }}</div>
    <div style="font-size:11px;color:var(--text-muted);text-transform:uppercase">{{ $lb }}</div>
  </div>
  @endforeach
  <a href="{{ $navigation->lien('relances') }}" class="card" style="text-align:center;padding:16px;text-decoration:none;border:1.5px solid var(--red-300);background:var(--red-50)">
    <div style="font-size:24px">📨</div>
    <div style="font-size:18px;font-weight:800;color:var(--red-600);margin:4px 0">{{ $nbRetard }}</div>
    <div style="font-size:11px;color:var(--red-600);text-transform:uppercase;font-weight:700">En retard ⟹ Relances</div>
  </a>
</div>

<div class="card mb-16">
  <div class="card-body">
    <form method="get" action="{{ route('paiements') }}" style="display:flex;gap:10px;align-items:end;flex-wrap:wrap">
      @if ($filtres['et_id'])<input type="hidden" name="et_id" value="{{ $filtres['et_id'] }}">@endif
      <div class="form-group" style="margin:0">
        <label class="form-label" for="q">🔍 Étudiant / réf.</label>
        <input type="text" name="q" id="q" class="form-control" value="{{ $filtres['q'] }}" placeholder="Nom, matricule, référence..." style="min-width:180px">
      </div>
      <div class="form-group" style="margin:0">
        <label class="form-label" for="f-annee">Année académique</label>
        <select name="annee_id" id="f-annee" class="form-control">
          <option value="0">Toutes</option>
          @foreach ($annees as $a)<option value="{{ $a->id }}" @selected($filtres['annee_id'] == $a->id)>{{ $a->libelle }}</option>@endforeach
        </select>
      </div>
      <div class="form-group" style="margin:0">
        <label class="form-label" for="f-type">Type</label>
        <select name="type_paiement" id="f-type" class="form-control">
          <option value="">Tous</option>
          @foreach (\App\Services\Paiements::TYPES as $k => $v)<option value="{{ $k }}" @selected($filtres['type_paiement'] === $k)>{{ $v }}</option>@endforeach
        </select>
      </div>
      <div class="form-group" style="margin:0">
        <label class="form-label" for="f-statut">Statut</label>
        <select name="statut" id="f-statut" class="form-control">
          <option value="">Tous</option>
          @foreach (\App\Services\Paiements::STATUTS as $k => $v)<option value="{{ $k }}" @selected($filtres['statut'] === $k)>{{ $v }}</option>@endforeach
        </select>
      </div>
      <div class="form-group" style="margin:0">
        <label class="form-label" for="date_debut">Du</label>
        <input type="date" name="date_debut" id="date_debut" class="form-control" value="{{ $filtres['date_debut'] }}">
      </div>
      <div class="form-group" style="margin:0">
        <label class="form-label" for="date_fin">Au</label>
        <input type="date" name="date_fin" id="date_fin" class="form-control" value="{{ $filtres['date_fin'] }}">
      </div>
      <button type="submit" class="btn btn-primary">Filtrer</button>
      @if ($actifs)<a href="{{ route('paiements', array_filter(['et_id' => $filtres['et_id']])) }}" class="btn btn-secondary">✕ Effacer</a>@endif
      <a href="{{ request()->fullUrlWithQuery(['export' => 'csv']) }}" class="btn btn-secondary" style="margin-left:auto">📥 Exporter CSV</a>
    </form>
  </div>
</div>

<div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:12px">
  <span style="font-size:14px;font-weight:700">💳 Paiements ({{ $total }})</span>
  @if ($peutGerer)<button type="button" id="btn-nouveau-paiement" class="btn btn-sm btn-primary">➕ Nouveau paiement</button>@endif
</div>

@if ($paiements->isEmpty())
<div class="card"><div class="empty-state" style="padding:32px"><div class="icon">💰</div><p>Aucun paiement pour ces critères.</p></div></div>
@else
<div class="card">
  <div class="card-body" style="padding:0">
    <table class="table" style="font-size:12px">
      <thead>
        <tr>
          @unless ($filtres['et_id'])<th>Étudiant</th>@endunless
          <th>Année</th><th>Filière</th><th>Niveau</th><th>Type</th><th style="text-align:right">Dû</th><th style="text-align:right">Payé</th>
          <th>Date</th><th>Mode</th><th style="text-align:center">Statut</th><th style="text-align:center">Reçu</th>
          @if ($peutGerer)<th>Action</th>@endif
        </tr>
      </thead>
      <tbody>
      @foreach ($paiements as $p)
      <tr>
        @unless ($filtres['et_id'])
        <td><a href="{{ route('paiements', ['et_id' => $p->etudiant_id]) }}" style="font-weight:600;text-decoration:none;color:var(--blue-700)">{{ $p->nom }} {{ $p->prenoms }}</a><div style="font-size:10px;color:var(--text-muted)">{{ $p->numero }}</div></td>
        @endunless
        <td style="white-space:nowrap">{{ $p->annee ?? '—' }}</td>
        <td>@if ($p->filiere)<span title="{{ $p->filiere }}">{{ $p->filiere_code ?: $p->filiere }}</span>@else—@endif</td>
        <td>@if ($p->niveau)<span class="badge badge-blue">{{ $p->niveau }}</span>@else—@endif</td>
        <td style="font-weight:600">{{ $p->type_paiement }}</td>
        <td style="text-align:right">{{ $fcfa($p->montant_du) }}</td>
        <td style="text-align:right;color:var(--green-700);font-weight:700">{{ $fcfa($p->montant_paye) }}</td>
        <td>{{ $p->date_paiement ? date('d/m/Y', strtotime($p->date_paiement)) : '—' }}</td>
        <td>{{ $icones[$p->mode_paiement] ?? '' }} {{ $p->mode_paiement }}</td>
        <td style="text-align:center"><span style="font-size:10px;font-weight:600;color:{{ $couleurs[$p->statut] ?? '#999' }}">{{ $p->statut }}</span></td>
        <td style="text-align:center">
          <a href="{{ route('recu_paiement', ['id' => $p->id]) }}" target="_blank" rel="noopener" class="btn btn-sm btn-secondary" style="padding:2px 8px;font-size:10px" aria-label="Reçu">🧾</a>
        </td>
        @if ($peutGerer)
        <td>
          <button type="button" class="btn btn-sm btn-secondary" aria-label="Modifier"
            data-paiement="{{ json_encode(['id' => $p->id, 'etudiant_id' => $p->etudiant_id, 'type_paiement' => $p->type_paiement, 'annee_id' => $p->annee_id,
              'montant_du' => (float) $p->montant_du, 'montant_paye' => (float) $p->montant_paye, 'date_paiement' => $p->date_paiement,
              'mode_paiement' => $p->mode_paiement, 'reference' => $p->reference, 'notes' => $p->notes]) }}">✏️</button>
          <details class="annulation-paiement" style="display:inline-block;vertical-align:top">
            <summary class="btn btn-sm btn-danger" aria-label="Annuler ce paiement" style="list-style:none;display:inline-block">🚫</summary>
            <form method="post" action="{{ route('paiements.annuler') }}" style="margin-top:6px;display:flex;gap:4px;flex-wrap:wrap;max-width:260px" data-confirm="Annuler ce paiement de {{ $fcfa($p->montant_paye) }} FCFA ? Il sortira des totaux et restera dans l'historique des annulations.">
              @csrf
              <input type="hidden" name="id" value="{{ $p->id }}">
              <input type="hidden" name="retour_et_id" value="{{ $filtres['et_id'] }}">
              <input type="text" name="motif" class="form-control" style="font-size:11px;padding:3px 6px;flex:1;min-width:140px" placeholder="Motif (obligatoire)" minlength="5" maxlength="500" required>
              <button type="submit" class="btn btn-sm btn-danger" style="font-size:11px">Annuler</button>
            </form>
          </details>
        </td>
        @endif
      </tr>
      @endforeach
      </tbody>
    </table>
  </div>
</div>

@if ($pagination['totalPages'] > 1)
<div style="display:flex;justify-content:center;gap:6px;margin-top:16px;flex-wrap:wrap">
  @if ($pagination['page'] > 1)<a href="{{ request()->fullUrlWithQuery(['pg' => $pagination['page'] - 1]) }}" class="btn btn-sm btn-secondary">← Précédent</a>@endif
  <span style="padding:6px 12px;font-size:12px;color:var(--text-muted)">Page {{ $pagination['page'] }} / {{ $pagination['totalPages'] }}</span>
  @if ($pagination['page'] < $pagination['totalPages'])<a href="{{ request()->fullUrlWithQuery(['pg' => $pagination['page'] + 1]) }}" class="btn btn-sm btn-secondary">Suivant →</a>@endif
</div>
@endif
@endif

@if ($annules->isNotEmpty())
<div class="card" style="margin-top:16px">
  <div style="padding:10px 14px;font-size:13px;font-weight:700">🚫 Paiements annulés ({{ $annules->count() }})</div>
  <div class="card-body" style="padding:0">
    <table class="table" style="font-size:12px">
      <thead><tr><th>Type</th><th style="text-align:right">Dû</th><th style="text-align:right">Payé</th><th>Date</th><th>Mode</th><th>Annulé le</th><th>Par</th><th>Motif</th></tr></thead>
      <tbody>
      @foreach ($annules as $a)
      <tr style="color:var(--text-muted)">
        <td>{{ $a->type_paiement }}</td>
        <td style="text-align:right">{{ $fcfa($a->montant_du) }}</td>
        <td style="text-align:right;text-decoration:line-through">{{ $fcfa($a->montant_paye) }}</td>
        <td>{{ $a->date_paiement ? date('d/m/Y', strtotime($a->date_paiement)) : '—' }}</td>
        <td>{{ $a->mode_paiement }}</td>
        <td>{{ date('d/m/Y H:i', strtotime($a->annule_le)) }}</td>
        <td>{{ trim(($a->agent_prenom ?? '').' '.($a->agent_nom ?? '')) ?: '—' }}</td>
        <td>{{ $a->motif }}</td>
      </tr>
      @endforeach
      </tbody>
    </table>
  </div>
</div>
@endif

@if ($peutGerer)
<div id="modal-paiement" class="modal-overlay" style="display:none">
  <div class="modal" style="max-height:90vh;display:flex;flex-direction:column">
    <div class="modal-header">
      <span class="modal-title" id="modal-titre-paiement">➕ Nouveau paiement</span>
      <button type="button" data-onclick="closeModal" data-arg="modal-paiement" class="btn btn-icon" aria-label="Fermer">✕</button>
    </div>
    <form method="post" action="{{ route('paiements.enregistrer') }}" id="form-paiement" style="display:flex;flex-direction:column;min-height:0"
      data-aujourdhui="{{ date('Y-m-d') }}" data-annee-defaut="{{ $anneeDefaut }}"
      data-filiere="{{ $inscriptionEtudiant->filiere_id ?? '' }}" data-niveau="{{ $inscriptionEtudiant->niveau_id ?? '' }}">
      <div class="modal-body" style="overflow-y:auto">
        @csrf
        <input type="hidden" name="action" id="inp-action" value="ajouter">
        <input type="hidden" name="id" id="inp-id" value="">
        <input type="hidden" name="retour_et_id" value="{{ $filtres['et_id'] }}">
        @if (! $filtres['et_id'])
        <div class="form-group"><label class="form-label" for="inp-etudiant">Étudiant *</label>
          <select name="etudiant_id" id="inp-etudiant" class="form-control" required>
            <option value="">— Sélectionner —</option>
            @foreach ($etudiants as $e)
            <option value="{{ $e->id }}" data-filiere="{{ (int) $e->filiere_id }}" data-niveau="{{ (int) $e->niveau_id }}">{{ $e->nom }} {{ $e->prenoms }} ({{ $e->numero }})</option>
            @endforeach
          </select></div>
        @else
        <input type="hidden" name="etudiant_id" value="{{ $filtres['et_id'] }}">
        @endif
        <div class="form-group"><label class="form-label" for="inp-type">Type</label>
          <select name="type_paiement" id="inp-type" class="form-control">
            @foreach (\App\Services\Paiements::TYPES as $k => $v)<option value="{{ $k }}">{{ $v }}</option>@endforeach
          </select></div>
        <div class="form-group"><label class="form-label" for="inp-annee">Année académique</label>
          <select name="annee_id" id="inp-annee" class="form-control">
            @foreach ($annees as $a)<option value="{{ $a->id }}" @selected($a->id == $anneeDefaut)>{{ $a->libelle }}</option>@endforeach
          </select></div>
        <div class="form-grid form-grid-2">
          <div class="form-group"><label class="form-label" for="inp-du">Montant dû (FCFA)</label>
            <input type="number" name="montant_du" id="inp-du" class="form-control" value="0" min="0" max="{{ \App\Services\Paiements::MONTANT_MAX }}" step="any" required></div>
          <div class="form-group"><label class="form-label" for="inp-paye">Montant payé (FCFA)</label>
            <input type="number" name="montant_paye" id="inp-paye" class="form-control" value="0" min="0" max="{{ \App\Services\Paiements::MONTANT_MAX }}" step="any" required></div>
          <div class="form-group"><label class="form-label" for="inp-date">Date paiement</label>
            <input type="date" name="date_paiement" id="inp-date" class="form-control" value="{{ date('Y-m-d') }}"></div>
          <div class="form-group"><label class="form-label" for="inp-mode">Mode</label>
            <select name="mode_paiement" id="inp-mode" class="form-control">
              @foreach (\App\Services\Paiements::MODES as $k => $v)<option value="{{ $k }}">{{ $v }}</option>@endforeach
            </select></div>
        </div>
        <div class="form-group"><label class="form-label" for="inp-ref">Référence / N° reçu</label>
          <input type="text" name="reference" id="inp-ref" class="form-control" maxlength="100" placeholder="Ex: REC-2024-001"></div>
        <div class="form-group"><label class="form-label" for="inp-notes">Notes</label>
          <textarea name="notes" id="inp-notes" class="form-control" rows="2" maxlength="2000"></textarea></div>
      </div>
      <div class="modal-footer">
        <button type="button" data-onclick="closeModal" data-arg="modal-paiement" class="btn btn-secondary">Annuler</button>
        <button type="submit" class="btn btn-primary">💾 Enregistrer</button>
      </div>
    </form>
  </div>
</div>
<script type="application/json" id="grille-tarifaire">@json($grille)</script>
@endif
@endsection

@push('scripts')
<script src="{{ \App\Support\Ressource::url('assets/js/paiements.js') }}"></script>
@endpush
