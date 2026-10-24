{{-- Choix des matières optionnelles — portage de choix_options.php --}}
@extends('layouts.app', ['titre' => 'Choix des matières optionnelles', 'fil' => 'Inscriptions › Options'])

@section('contenu')
<div class="card mb-16">
  <div class="card-body" style="padding:14px 16px">
    <form method="get" action="{{ route('choix_options') }}" class="flex gap-8" style="flex-wrap:wrap;align-items:flex-end">
      <div style="min-width:140px">
        <label class="form-label" for="annee_id" style="font-size:11px;margin-bottom:3px">Année</label>
        <select name="annee_id" id="annee_id" class="form-control form-control-sm" data-autosubmit="1">
          @foreach ($annees as $a)<option value="{{ $a->id }}" @selected($anneeId == $a->id)>{{ $a->libelle }}</option>@endforeach
        </select>
      </div>
      <div style="min-width:220px">
        <label class="form-label" for="promo_id" style="font-size:11px;margin-bottom:3px">Promotion</label>
        <select name="promo_id" id="promo_id" class="form-control form-control-sm">
          <option value="">— Sélectionner —</option>
          @foreach ($promotions as $pr)<option value="{{ $pr->id }}" @selected($promoId == $pr->id)>{{ $pr->filiere_code }} {{ $pr->niveau }} — {{ $pr->annee }}</option>@endforeach
        </select>
      </div>
      <div style="min-width:120px">
        <label class="form-label" for="sem_id" style="font-size:11px;margin-bottom:3px">Semestre</label>
        <select name="sem_id" id="sem_id" class="form-control form-control-sm">
          <option value="">— Semestre —</option>
          @foreach ($semestres as $s)<option value="{{ $s->id }}" @selected($semId == $s->id)>{{ $s->libelle }}</option>@endforeach
        </select>
      </div>
      <button type="submit" class="btn btn-primary btn-sm">🔍 Charger</button>
    </form>
  </div>
</div>

@if ($promoId && $semId)
@if ($ues->isEmpty())
<div class="card">
  <div class="card-body" style="text-align:center;padding:40px;color:var(--text-muted)">
    <div style="font-size:40px;margin-bottom:12px">✅</div>
    Aucune UE à choix optionnel configurée pour cette promotion et ce semestre.<br>
    <span style="font-size:12px">Configurez les UE optionnelles dans <a href="{{ route('filieres', ['tab' => 'structure']) }}">Filières → Structure UE/Matières</a>.</span>
  </div>
</div>
@elseif ($etudiants->isEmpty())
<div class="card">
  <div class="card-body" style="text-align:center;padding:40px;color:var(--text-muted)">
    <div style="font-size:40px;margin-bottom:12px">👥</div>
    Aucun étudiant inscrit pédagogiquement pour ce semestre.
  </div>
</div>
@else
<form method="post" action="{{ route('choix_options.enregistrer') }}">
  @csrf
  <input type="hidden" name="promo_id" value="{{ $promoId }}">
  <input type="hidden" name="sem_id" value="{{ $semId }}">
  @foreach ($etudiants as $et)<input type="hidden" name="etudiants[]" value="{{ $et->id }}">@endforeach

  @foreach ($ues as $ue)
  @php($type = $ue->nb_options_a_choisir == 1 ? 'radio' : 'checkbox')
  <div class="card mb-16">
    <div class="card-header" style="background:linear-gradient(135deg,#1A3A5C,#2C5F9E);color:#fff">
      <div>
        <span class="card-title" style="color:#fff">{{ $ue->code }} — {{ $ue->libelle }}</span>
        <div style="font-size:11px;opacity:.8;margin-top:2px">
          Choisir <strong>{{ $ue->nb_options_a_choisir }}</strong>
          matière{{ $ue->nb_options_a_choisir > 1 ? 's' : '' }} optionnelle{{ $ue->nb_options_a_choisir > 1 ? 's' : '' }}
          parmi {{ $ue->optionnelles->count() }}
          · Obligatoires : {{ $ue->obligatoires->pluck('code')->implode(', ') }}
        </div>
      </div>
      <span style="background:rgba(255,255,255,.2);padding:3px 10px;border-radius:8px;font-size:11px">{{ $ue->optionnelles->count() }} option{{ $ue->optionnelles->count() > 1 ? 's' : '' }}</span>
    </div>
    <div class="table-wrap">
      <table class="table" style="font-size:12px">
        <thead>
          <tr>
            <th style="width:180px">Étudiant</th>
            @foreach ($ue->optionnelles as $opt)
            <th style="text-align:center;min-width:140px">{{ $opt->libelle }}<div style="font-size:10px;color:rgba(255,255,255,.7);font-weight:400">{{ $opt->credits }} cr.</div></th>
            @endforeach
            <th style="text-align:center;width:80px">Statut</th>
          </tr>
        </thead>
        <tbody>
        @foreach ($etudiants as $et)
          @php($deja = $choix->get($et->id.'-'.$ue->id, []))
          <tr style="background:{{ $deja ? '#f0fdf4' : '#fff' }}">
            <td>
              <div style="font-weight:600">{{ $et->nom }} {{ $et->prenoms }}</div>
              <div style="font-size:10px;color:var(--text-muted);font-family:monospace">{{ $et->numero }}</div>
            </td>
            @foreach ($ue->optionnelles as $opt)
            @php($coche = in_array((int) $opt->id, $deja, true))
            <td style="text-align:center">
              <label class="choix-option" style="display:inline-flex;align-items:center;justify-content:center;width:100%;cursor:pointer;padding:6px;border-radius:6px;background:{{ $coche ? '#dcfce7' : 'transparent' }};border:1.5px solid {{ $coche ? '#16a34a' : '#e5e7eb' }}">
                <input type="{{ $type }}" name="choix[{{ $ue->id }}][{{ $et->id }}]{{ $type === 'checkbox' ? '[]' : '' }}" value="{{ $opt->id }}" @checked($coche)
                       aria-label="{{ $opt->libelle }} — {{ $et->nom }}" style="accent-color:#16a34a;width:16px;height:16px">
              </label>
            </td>
            @endforeach
            <td style="text-align:center">
              @if ($deja)<span class="badge badge-green" style="font-size:10px">✅ Saisi</span>@else<span class="badge badge-amber" style="font-size:10px">⏳ À saisir</span>@endif
            </td>
          </tr>
        @endforeach
        </tbody>
      </table>
    </div>
  </div>
  @endforeach

  <div style="margin-top:16px;display:flex;gap:10px;align-items:center">
    <button type="submit" class="btn btn-primary" style="padding:12px 28px;font-size:14px">💾 Enregistrer tous les choix</button>
    <span style="font-size:12px;color:var(--text-muted)">{{ $etudiants->count() }} étudiant(s) · {{ $ues->count() }} UE optionnelle(s)</span>
  </div>
</form>
@endif
@endif
@endsection

@push('scripts')
<script src="{{ \App\Support\Ressource::url('assets/js/choix-options.js') }}"></script>
@endpush
