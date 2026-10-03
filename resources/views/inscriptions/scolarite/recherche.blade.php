{{-- Inscription scolarité : recherche par matricule — portage de inscription_scolarite.php --}}
@extends('layouts.app', ['titre' => 'Inscription Scolarité', 'fil' => 'Inscriptions › Scolarité'])
@inject('navigation', 'App\Navigation\Menu')

@section('contenu')
<div class="card mb-20">
  <div class="card-body">
    <form method="get" action="{{ route('inscription_scolarite') }}" style="display:flex;gap:10px;align-items:flex-end">
      <div class="form-group" style="margin:0;flex:1">
        <label class="form-label" for="matricule" style="font-weight:700">🔍 Matricule ou N° dossier</label>
        <input type="text" name="matricule" id="matricule" class="form-control" placeholder="Ex: 00001 ou D915839"
               value="{{ $recherche }}" style="height:44px;font-size:15px" autocomplete="off" autofocus>
      </div>
      <button type="submit" class="btn btn-primary" style="height:44px;padding:0 24px">🔍 Rechercher</button>
      @if ($recherche !== '')
      <a href="{{ route('inscription_scolarite') }}" class="btn btn-secondary" style="height:44px;padding:0 16px">↺ Effacer</a>
      @endif
    </form>
  </div>
</div>

@if ($recherche !== '' && ! $etudiant)
<div class="alert alert-error">❌ Aucun étudiant trouvé pour le matricule <strong>{{ $recherche }}</strong>.</div>

@elseif ($etudiant)
<div class="card mb-20">
  <div class="card-body" style="display:flex;align-items:center;gap:20px">
    @include('inscriptions.scolarite._photo')
    <div style="flex:1">
      <div style="font-size:18px;font-weight:800;color:var(--dark)">{{ $etudiant->nom }} {{ $etudiant->prenoms }}</div>
      <div style="display:flex;gap:16px;margin-top:6px;flex-wrap:wrap">
        <span style="font-size:13px;color:var(--text-muted)">Matricule : <strong style="color:var(--blue-700);font-family:monospace">{{ $etudiant->numero }}</strong></span>
        @if ($etudiant->numero_dossier)
        <span style="font-size:13px;color:var(--text-muted)">N° Dossier : <strong style="color:var(--green-700);font-family:monospace">{{ $etudiant->numero_dossier }}</strong></span>
        @endif
        @if ($etudiant->filiere_lib)
        <span style="font-size:13px;color:var(--text-muted)">Filière : <strong style="color:#5b21b6">{{ $etudiant->filiere_lib }}</strong></span>
        @endif
        <span style="font-size:13px;color:var(--text-muted)">Inscriptions :
          <strong style="background:var(--blue-50);color:var(--blue-700);padding:1px 8px;border-radius:10px;font-size:12px;font-family:monospace">{{ $inscriptions->count() }}</strong>
        </span>
      </div>
    </div>
    <div style="display:flex;gap:8px">
      <a href="{{ route('dossier_etudiant', ['id' => $etudiant->id]) }}" class="btn btn-secondary btn-sm">📁 Dossier</a>
      @if ($inscriptions->isNotEmpty())
      <a href="{{ $navigation->lien('certificat_scolarite', ['et_id' => $etudiant->id]) }}" class="btn btn-secondary btn-sm" target="_blank" rel="noopener">📜 Certificat</a>
      @endif
      @if ($peutInscrire)
      <a href="{{ route('inscription_scolarite', ['id' => $etudiant->id]) }}" class="btn btn-primary btn-sm">➕ Nouvelle inscription</a>
      @endif
    </div>
  </div>
</div>

<div class="card">
  <div class="card-header" style="display:flex;justify-content:space-between;align-items:center">
    <span class="card-title">
      📋 Inscriptions de {{ $etudiant->prenoms }} {{ $etudiant->nom }}
      <span style="background:var(--blue-100);color:var(--blue-700);padding:2px 10px;border-radius:12px;font-size:12px;font-weight:700;margin-left:8px">
        {{ $inscriptions->count() }} inscription{{ $inscriptions->count() > 1 ? 's' : '' }}
      </span>
    </span>
    @if ($peutInscrire)
    <a href="{{ route('inscription_scolarite', ['id' => $etudiant->id]) }}" class="btn btn-primary btn-sm">➕ Nouvelle inscription</a>
    @endif
  </div>
  <div class="card-body" style="padding:0">
    @if ($inscriptions->isEmpty())
    <div style="text-align:center;padding:40px;color:var(--text-muted)">
      <div style="font-size:40px;margin-bottom:12px">📭</div>
      Aucune inscription trouvée.<br>
      @if ($peutInscrire)
      <a href="{{ route('inscription_scolarite', ['id' => $etudiant->id]) }}" class="btn btn-primary" style="margin-top:16px">➕ Créer la première inscription</a>
      @endif
    </div>
    @else
    <table style="width:100%;border-collapse:collapse;font-size:13px">
      <thead>
        <tr style="background:var(--primary);color:#fff">
          <th style="padding:10px 14px">Année</th><th style="padding:10px 14px">Niveau</th><th style="padding:10px 14px">Date</th>
          <th style="padding:10px 14px;text-align:right">Montant</th><th style="padding:10px 14px">Mode</th>
          <th style="padding:10px 14px;text-align:center">Statut</th><th style="padding:10px 14px;text-align:center">Reçu</th>
        </tr>
      </thead>
      <tbody>
      @foreach ($inscriptions as $ins)
      <tr style="border-bottom:1px solid var(--border);background:{{ $ins->active ? '#f0fdf4' : '#fff' }}">
        <td style="padding:10px 14px;font-weight:700">
          {{ $ins->annee_lib ?? '—' }}
          @if ($ins->active)<span style="background:#dcfce7;color:#166534;padding:1px 6px;border-radius:8px;font-size:10px;margin-left:4px">En cours</span>@endif
        </td>
        <td style="padding:10px 14px">
          <span style="background:var(--blue-50);color:var(--blue-700);padding:2px 8px;border-radius:10px;font-size:12px;font-weight:600">{{ $ins->niveau_code ?? '—' }}</span>
          <span style="font-size:12px;color:var(--text-muted);margin-left:4px">{{ $ins->niveau_lib }}</span>
        </td>
        <td style="padding:10px 14px;font-size:12px;color:var(--text-muted)">{{ $ins->date_inscription ? date('d/m/Y', strtotime($ins->date_inscription)) : '—' }}</td>
        <td style="padding:10px 14px;text-align:right;font-weight:700;color:var(--green-700)">{{ number_format((float) $ins->montant_paye, 0, ',', ' ') }} FCFA</td>
        <td style="padding:10px 14px;font-size:12px;text-transform:capitalize">{{ str_replace('_', ' ', $ins->mode_paiement ?? '—') }}</td>
        <td style="padding:10px 14px;text-align:center">
          <span style="background:{{ $ins->statut === 'inscrit' ? '#dcfce7' : '#fee2e2' }};color:{{ $ins->statut === 'inscrit' ? '#166534' : '#991b1b' }};padding:3px 10px;border-radius:12px;font-size:11px;font-weight:700">{{ ucfirst($ins->statut ?? '—') }}</span>
        </td>
        <td style="padding:10px 14px;text-align:center">
          @if ($peutInscrire)
          <a href="{{ $navigation->lien('recu_inscription', ['et_id' => $etudiant->id, 'ins_id' => $ins->id]) }}" class="btn btn-secondary btn-sm" title="Reçu d'inscription" target="_blank" rel="noopener">🧾</a>
          @endif
          <a href="{{ $navigation->lien('certificat_scolarite', ['ins_id' => $ins->id]) }}" class="btn btn-secondary btn-sm" title="Certificat de scolarité" style="margin-left:4px" target="_blank" rel="noopener">📜</a>
        </td>
      </tr>
      @endforeach
      </tbody>
    </table>
    @endif
  </div>
</div>

@else
<div class="card">
  <div class="card-header"><span class="card-title">📋 Inscriptions</span></div>
  <div class="card-body" style="padding:0">
    <div style="padding:40px;text-align:center;color:var(--text-muted)">
      <div style="font-size:40px;margin-bottom:12px">🔍</div>
      Saisissez un matricule ou un N° dossier pour afficher les inscriptions
    </div>
  </div>
</div>
@endif
@endsection
