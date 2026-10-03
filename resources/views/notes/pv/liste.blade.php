{{-- Liste des PV — portage de pv.php --}}
@extends('layouts.app', ['titre' => "Procès-Verbaux d'examens", 'fil' => 'Gestion académique'])
@inject('navigation', 'App\Navigation\Menu')

@section('contenu')
@if ($faculte)
<div style="display:flex;align-items:center;gap:10px;margin-bottom:12px;padding:10px 16px;border-radius:8px;background:{{ $faculte->couleur ?? '#1A3A5C' }}15;border:1px solid {{ $faculte->couleur ?? '#1A3A5C' }}30">
  <span style="font-size:18px">{{ auth()->user()->role === 'doyen' ? '🎓' : '🏛️' }}</span>
  <span style="font-weight:700;color:{{ $faculte->couleur ?? '#1A3A5C' }}">{{ $faculte->libelle }}</span>
  <span style="font-size:11px;color:var(--text-muted)">Vous ne voyez que les PV de cette faculté{{ auth()->user()->role === 'doyen' ? ' (tous départements)' : '' }}</span>
</div>
@endif
@if ($departement)
<div style="display:flex;align-items:center;gap:10px;margin-bottom:12px;padding:10px 16px;border-radius:8px;background:#dbeafe;border:1px solid #93c5fd">
  <span style="font-size:18px">📘</span><span style="font-weight:700;color:#1d4ed8">{{ $departement }}</span>
  <span style="font-size:11px;color:var(--text-muted)">Vous ne voyez que les PV de ce département</span>
</div>
@endif

<div class="flex-between mb-16">
  <div style="font-size:13px;color:var(--text-muted)">{{ $total }} PV trouvé(s)</div>
  @if ($gestionnaire)<a href="{{ route('pv_creer') }}" class="btn btn-primary">➕ Nouveau PV</a>@endif
</div>

<div class="card mb-16 no-print">
  <div class="card-body" style="padding:14px 16px">
    <form method="get" action="{{ route('pv') }}" class="flex gap-8" style="flex-wrap:wrap;align-items:flex-end">
      <div style="flex:2;min-width:180px">
        <label class="form-label" for="q" style="font-size:11px;margin-bottom:3px">Recherche</label>
        <input type="text" name="q" id="q" value="{{ $filtres['q'] }}" class="form-control form-control-sm" placeholder="Intitulé du PV...">
      </div>
      <div style="min-width:120px">
        <label class="form-label" for="annee_id" style="font-size:11px;margin-bottom:3px">Année</label>
        <select name="annee_id" id="annee_id" class="form-control form-control-sm">
          <option value="">Toutes</option>
          @foreach ($annees as $a)<option value="{{ $a->id }}" @selected($filtres['annee_id'] == $a->id)>{{ $a->libelle }}</option>@endforeach
        </select>
      </div>
      <div style="min-width:150px">
        <label class="form-label" for="filiere_id" style="font-size:11px;margin-bottom:3px">Filière</label>
        <select name="filiere_id" id="filiere_id" class="form-control form-control-sm">
          <option value="">Toutes{{ $faculte ? ' (faculté)' : '' }}</option>
          @foreach ($filieres as $f)<option value="{{ $f->id }}" @selected($filtres['filiere_id'] == $f->id)>{{ $f->code }}</option>@endforeach
        </select>
      </div>
      <div style="min-width:90px">
        <label class="form-label" for="niveau_id" style="font-size:11px;margin-bottom:3px">Niveau</label>
        <select name="niveau_id" id="niveau_id" class="form-control form-control-sm">
          <option value="">Tous</option>
          @foreach ($niveaux as $n)<option value="{{ $n->id }}" @selected($filtres['niveau_id'] == $n->id)>{{ $n->code }}</option>@endforeach
        </select>
      </div>
      <div style="min-width:110px">
        <label class="form-label" for="statut" style="font-size:11px;margin-bottom:3px">Statut</label>
        <select name="statut" id="statut" class="form-control form-control-sm">
          <option value="">Tous</option>
          @foreach (\App\Services\Pv::STATUTS as $v => $l)<option value="{{ $v }}" @selected($filtres['statut'] === $v)>{{ $l }}</option>@endforeach
        </select>
      </div>
      <button type="submit" class="btn btn-secondary btn-sm">🔍 Filtrer</button>
      <a href="{{ route('pv') }}" class="btn btn-secondary btn-sm" title="Réinitialiser">↺</a>
    </form>
  </div>
</div>

<div class="card">
  <div class="table-wrap">
    <table class="table">
      <thead><tr>
        <th>Intitulé du PV</th><th>Filière</th><th>Niveau</th><th>Semestre</th><th>Année</th><th>Session</th>
        <th>Inscrits (pédago.)</th><th>Notes saisies</th><th>Statut</th><th>Actions</th>
      </tr></thead>
      <tbody>
      @forelse ($liste as $pv)
      <tr>
        <td>
          @if ($peutEditer)
          <form method="post" action="{{ route('pv.titre') }}" class="pv-titre-form" style="display:inline">
            @csrf
            <input type="hidden" name="pv_id" value="{{ $pv->id }}">
            <span class="pv-titre-display" title="Double-clic pour modifier" style="cursor:pointer;font-weight:700">{{ $pv->libelle }}</span>
            <input type="text" name="libelle" class="form-control form-control-sm pv-titre-input" value="{{ $pv->libelle }}" aria-label="Titre du PV" style="display:none;width:220px;font-weight:700">
          </form>
          @else
          <strong>{{ $pv->libelle }}</strong>
          @endif
          @if ($pv->verrouille)<span class="badge badge-red" style="font-size:10px">🔒 Verrouillé</span>@endif
        </td>
        <td><span class="badge badge-blue">{{ $pv->filiere_code }}</span><br><span class="text-xs text-muted">{{ mb_substr($pv->filiere, 0, 25) }}</span></td>
        <td><strong>{{ $pv->niveau }}</strong></td>
        <td>{{ $pv->sem_libelle }}</td>
        <td>{{ $pv->annee }}</td>
        <td>{{ $pv->type_session === 'rattrapage' ? '2ème Session' : '1ère Session' }}</td>
        <td><strong>{{ $pv->nb_inscrits }}</strong></td>
        <td>
          @php($attendues = $pv->nb_inscrits * max(1, $pv->nb_matieres))
          <div class="text-xs text-muted mb-4">{{ $pv->nb_notes }} notes</div>
          <div class="progress" style="width:80px"><div class="progress-bar progress-bar-blue" style="width:{{ $pv->nb_inscrits ? min(100, round($pv->nb_notes / $attendues * 100)) : 0 }}%"></div></div>
        </td>
        <td>
          @if ($peutEditer)
          <select class="form-control form-control-sm statut-select" data-id="{{ $pv->id }}" data-url="{{ route('pv.statut') }}" aria-label="Statut" style="font-size:11px;padding:2px 6px;min-width:100px">
            <option value="brouillon" @selected($pv->statut === 'brouillon')>⏸ Brouillon</option>
            <option value="finalise" @selected($pv->statut === 'finalise')>✅ Finalisé</option>
            <option value="publie" @selected($pv->statut === 'publie')>🌐 Publié</option>
          </select>
          @else
          <span class="badge {{ ['brouillon' => 'badge-amber', 'finalise' => 'badge-blue', 'publie' => 'badge-green'][$pv->statut] ?? 'badge-gray' }}">{{ \App\Services\Pv::STATUTS[$pv->statut] ?? $pv->statut }}</span>
          @endif
        </td>
        <td>
          <div class="flex gap-8">
            <a href="{{ route('pv_detail', ['id' => $pv->id]) }}" class="btn btn-sm btn-secondary" title="Ouvrir">📋</a>
            <a href="{{ $navigation->lien('pv_imprimer', ['id' => $pv->id]) }}" target="_blank" rel="noopener" class="btn btn-sm btn-secondary" title="Imprimer">🖨️</a>
            @if ($peutEditer)
            <form method="post" action="{{ route('pv_detail.action', ['id' => $pv->id]) }}" style="display:inline" data-confirm="Supprimer ce PV ?">
              @csrf <input type="hidden" name="action_pv" value="supprimer">
              <button type="submit" class="btn btn-sm btn-danger" title="Supprimer">🗑️</button>
            </form>
            @endif
          </div>
        </td>
      </tr>
      @empty
      <tr><td colspan="10"><div class="empty-state"><div class="icon">📋</div>Aucun PV trouvé.</div></td></tr>
      @endforelse
      </tbody>
    </table>
  </div>
  @if ($pagination['totalPages'] > 1)
  <div class="card-footer">
    <div class="pagination">
      @for ($i = 1; $i <= $pagination['totalPages']; $i++)
      <a href="{{ request()->fullUrlWithQuery(['p' => $i]) }}" class="page-btn {{ $i == $pagination['page'] ? 'active' : '' }}">{{ $i }}</a>
      @endfor
    </div>
    <span class="text-muted text-sm" style="margin-left:auto">Page {{ $pagination['page'] }}/{{ $pagination['totalPages'] }} · {{ $total }} PV</span>
  </div>
  @endif
</div>
@endsection

@push('scripts')
<script src="{{ asset('assets/js/pv-liste.js') }}"></script>
@endpush
