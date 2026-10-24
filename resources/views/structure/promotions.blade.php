{{-- Promotions — portage de pages/structure/promotions.php --}}
@extends('layouts.app', ['titre' => 'Promotions', 'fil' => 'Gestion académique'])
@inject('navigation', 'App\Navigation\Menu')

@section('contenu')
<div style="display:grid;grid-template-columns:1fr 360px;gap:20px">

  <div class="card">
    <div class="card-header" style="display:flex;justify-content:space-between;align-items:center;flex-wrap:wrap;gap:10px">
      <span class="card-title">Promotions ({{ count($promos) }})</span>
      <form method="get" action="{{ route('promotions') }}" style="display:flex;gap:8px;align-items:center">
        <label class="form-label" for="filtre-annee" style="margin:0;font-size:12px">Année :</label>
        <select id="filtre-annee" name="annee_id" class="form-control" style="width:auto" data-autosubmit="1">
          <option value="0" @selected($anneeFiltre === 0)>Toutes les années</option>
          @foreach ($annees as $a)
            <option value="{{ $a->id }}" @selected($anneeFiltre == $a->id)>{{ $a->libelle }}{{ $a->active ? ' (active)' : '' }}</option>
          @endforeach
        </select>
      </form>
    </div>
    <div class="table-wrap">
      <table class="table">
        <thead><tr><th>Filière</th><th>Niveau</th><th>Année</th><th>Inscrits</th><th>UE</th><th>PV</th><th>Actions</th></tr></thead>
        <tbody>
        @forelse ($promos as $p)
        <tr>
          <td><span class="badge badge-blue">{{ $p->fcode }}</span><br><span class="text-xs text-muted">{{ mb_substr($p->filiere, 0, 28) }}</span></td>
          <td><strong>{{ $p->niveau }}</strong><br><span class="text-xs text-muted">{{ $p->niv_libelle }}</span></td>
          <td>{{ $p->annee }} @if ($p->annee_active)<span class="badge badge-green" style="font-size:9px">Active</span>@endif</td>
          <td style="font-weight:600;color:var(--blue-600)">{{ $p->nb_inscrits }}
            @if ($p->effectif_prevu)<span class="text-xs text-muted">/ {{ $p->effectif_prevu }}</span>@endif
          </td>
          <td>{{ $p->nb_ue }}</td>
          <td>{{ $p->nb_pv }}</td>
          <td>
            <div class="flex gap-8">
              <a href="{{ $navigation->lien('etudiants', ['promo' => $p->id]) }}" class="btn btn-sm btn-secondary" title="Voir étudiants" aria-label="Voir les étudiants">👥</a>
              <a href="{{ $navigation->lien('filieres', ['tab' => 'structure', 'promo' => $p->id]) }}" class="btn btn-sm btn-secondary" title="Structure UE" aria-label="Structure des UE">🏗️</a>
              @if (auth()->user()->aRole('admin'))
              <form method="post" action="{{ route('promotions.supprimer') }}" style="display:inline" data-confirm="Supprimer cette promotion et toutes ses données ?">
                @csrf
                <input type="hidden" name="id" value="{{ $p->id }}">
                <button type="submit" class="btn btn-sm btn-danger" title="Supprimer" aria-label="Supprimer la promotion">🗑️</button>
              </form>
              @endif
            </div>
          </td>
        </tr>
        @empty
          <tr><td colspan="7"><div class="empty-state"><div class="icon">🎓</div>Aucune promotion créée.</div></td></tr>
        @endforelse
        </tbody>
      </table>
    </div>
  </div>

  <div class="card mb-16">
    <div class="card-header"><span class="card-title">⚡ Générer toutes les promotions</span></div>
    <div class="card-body">
      <div class="alert alert-info" style="font-size:12px;margin-bottom:14px">
        Génère automatiquement une promotion pour chaque combinaison filière × niveau. Les doublons sont ignorés.
      </div>

      <div style="border-bottom:1px solid var(--border);margin-bottom:14px;padding-bottom:14px">
        <div style="font-weight:700;font-size:13px;margin-bottom:10px">📋 Copier depuis une année existante</div>
        <form method="post" action="{{ route('promotions.copier') }}" style="display:flex;gap:8px;align-items:flex-end;flex-wrap:wrap">
          @csrf
          <div class="form-group" style="margin:0;flex:1;min-width:130px">
            <label class="form-label" for="annee_source">Année source</label>
            <select id="annee_source" name="annee_source" class="form-control" required>
              <option value="">—</option>
              @foreach ($annees as $a)<option value="{{ $a->id }}">{{ $a->libelle }}</option>@endforeach
            </select>
          </div>
          <div style="font-size:20px;padding-bottom:6px" aria-hidden="true">→</div>
          <div class="form-group" style="margin:0;flex:1;min-width:130px">
            <label class="form-label" for="annee_dest">Vers l'année</label>
            <select id="annee_dest" name="annee_dest" class="form-control" required>
              <option value="">—</option>
              @foreach ($annees as $a)<option value="{{ $a->id }}">{{ $a->libelle }}</option>@endforeach
            </select>
          </div>
          <button type="submit" class="btn btn-primary btn-sm" style="margin-bottom:1px">📋 Copier</button>
        </form>
      </div>

      <form method="post" action="{{ route('promotions.generer') }}" data-confirm="Générer les promotions sélectionnées ?">
        @csrf
        <div class="form-group">
          <label class="form-label" for="annee_id">Année académique <span class="req">*</span></label>
          <select id="annee_id" name="annee_id" class="form-control" required>
            <option value="">— Sélectionner —</option>
            @foreach ($annees as $a)<option value="{{ $a->id }}">{{ $a->libelle }}</option>@endforeach
          </select>
        </div>
        <div class="form-group">
          <span class="form-label">Filières <span style="font-size:11px;color:var(--text-muted)">(vide = toutes)</span></span>
          <div style="max-height:130px;overflow-y:auto;border:1px solid var(--border);border-radius:6px;padding:8px">
            @foreach ($filieres as $f)
            <label style="display:flex;align-items:center;gap:8px;padding:2px 0;cursor:pointer;font-size:12px">
              <input type="checkbox" name="filieres_ids[]" value="{{ $f->id }}" checked> {{ $f->libelle }}
            </label>
            @endforeach
          </div>
          <div style="display:flex;gap:6px;margin-top:5px">
            <button type="button" data-onclick="toggleCheck" data-args='["filieres_ids",true]' class="btn btn-secondary" style="padding:2px 10px;font-size:11px">Tout</button>
            <button type="button" data-onclick="toggleCheck" data-args='["filieres_ids",false]' class="btn btn-secondary" style="padding:2px 10px;font-size:11px">Aucun</button>
          </div>
        </div>
        <div class="form-group">
          <span class="form-label">Niveaux <span style="font-size:11px;color:var(--text-muted)">(vide = tous)</span></span>
          <div style="display:flex;flex-wrap:wrap;gap:6px">
            @foreach ($niveaux as $n)
            <label style="display:flex;align-items:center;gap:6px;padding:4px 10px;border:1px solid var(--border);border-radius:6px;cursor:pointer;font-size:12px">
              <input type="checkbox" name="niveaux_ids[]" value="{{ $n->id }}" checked> {{ $n->code }}
            </label>
            @endforeach
          </div>
          <div style="display:flex;gap:6px;margin-top:6px">
            <button type="button" data-onclick="toggleCheck" data-args='["niveaux_ids",true]' class="btn btn-secondary" style="padding:2px 10px;font-size:11px">Tout</button>
            <button type="button" data-onclick="toggleCheck" data-args='["niveaux_ids",false]' class="btn btn-secondary" style="padding:2px 10px;font-size:11px">Aucun</button>
          </div>
        </div>
        <button type="submit" class="btn btn-primary w-100">⚡ Générer les promotions</button>
      </form>
    </div>
  </div>
</div>
@endsection

@push('scripts')
<script src="{{ \App\Support\Ressource::url('assets/js/promotions.js') }}"></script>
@endpush
