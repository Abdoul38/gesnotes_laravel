{{-- Demandes d'inscription — portage de pages/inscriptions/demandes_inscription.php --}}
@extends('layouts.app', ['titre' => "Demandes d'inscription", 'fil' => 'Inscriptions › Demandes'])

@section('contenu')
@if (session('recu_etudiant') && Route::has('recu_inscription'))
<div class="alert alert-success"><a href="{{ route('recu_inscription', ['et_id' => session('recu_etudiant')]) }}" target="_blank" rel="noopener">🧾 Imprimer le reçu d'inscription</a></div>
@endif
<div style="display:flex;gap:8px;margin-bottom:16px;flex-wrap:wrap">
  @foreach ($statuts as $s => $libelle)
  <a href="{{ route('demandes_inscription', ['statut' => $s]) }}" class="btn btn-sm {{ $statut === $s ? 'btn-primary' : 'btn-secondary' }}">{{ $libelle }} ({{ (int) ($compteurs[$s] ?? 0) }})</a>
  @endforeach
</div>
<div class="card"><div style="overflow-x:auto"><table class="table" style="font-size:12px">
  <thead><tr><th>Nom &amp; Prénoms</th><th>Naissance</th><th>Contact</th><th>Bac</th><th>Filière souhaitée</th><th>Date demande</th><th style="text-align:center">Actions</th></tr></thead>
  <tbody>
  @forelse ($demandes as $d)
  <tr>
    <td style="font-weight:700">{{ $d->nom }} {{ $d->prenoms }}</td>
    <td>{{ $d->date_naissance ? date('d/m/Y', strtotime($d->date_naissance)) : '—' }}@if ($d->lieu_naissance)<br><span style="font-size:10px;color:var(--text-muted)">{{ $d->lieu_naissance }}</span>@endif</td>
    <td style="font-size:11px">{{ $d->telephone ?? '—' }}@if ($d->email)<br>{{ $d->email }}@endif</td>
    <td style="font-size:11px">{{ $d->bac_serie ?? '—' }} {{ $d->bac_annee }}@if ($d->bac_mention)<br>{{ $d->bac_mention }}@endif</td>
    <td>{{ $d->filiere ?? '—' }}</td>
    <td style="font-size:11px">{{ date('d/m/Y', strtotime($d->created_at)) }}</td>
    <td style="text-align:center">
      @if ($d->statut === 'en_attente')
      <details style="display:inline-block;text-align:left">
        <summary class="btn btn-sm btn-primary">✅ Accepter</summary>
        <form method="post" action="{{ route('demandes_inscription.accepter') }}" style="margin-top:8px;min-width:240px" data-confirm="Créer la fiche étudiant de {{ $d->nom }} {{ $d->prenoms }} ?">
          @csrf
          <input type="hidden" name="id" value="{{ $d->id }}">
          <label class="form-label" for="promo-{{ $d->id }}">Inscrire dans la promotion</label>
          <select name="promotion_id" id="promo-{{ $d->id }}" class="form-control">
            <option value="">— Plus tard —</option>
            @foreach ($promotions as $p)<option value="{{ $p->id }}" @selected($p->filiere_id == $d->filiere_souhaitee && $loop->first)>{{ $p->filiere }} — {{ $p->niveau }} ({{ $p->annee }})</option>@endforeach
          </select>
          <div class="form-hint">Vous pouvez inscrire l'étudiant plus tard depuis son dossier.</div>
          <button type="submit" class="btn btn-sm btn-success" style="margin-top:6px">✅ Confirmer</button>
        </form>
      </details>
      <details style="display:inline-block;text-align:left">
        <summary class="btn btn-sm btn-danger">❌ Refuser</summary>
        <form method="post" action="{{ route('demandes_inscription.refuser') }}" style="margin-top:8px;min-width:240px">
          @csrf
          <input type="hidden" name="id" value="{{ $d->id }}">
          <label class="form-label" for="motif-{{ $d->id }}">Motif du refus</label>
          <textarea name="notes" id="motif-{{ $d->id }}" class="form-control" rows="3" maxlength="2000"></textarea>
          <button type="submit" class="btn btn-sm btn-danger" style="margin-top:6px">❌ Refuser</button>
        </form>
      </details>
      @elseif ($d->statut === 'acceptee' && $d->etudiant_id)
      <a href="{{ route('dossier_etudiant', ['id' => $d->etudiant_id]) }}" class="btn btn-sm btn-secondary">👤 Dossier</a>
      @else
      <span style="font-size:11px;color:var(--text-muted)">{{ $d->notes_admin ?: '—' }}</span>
      @endif
    </td>
  </tr>
  @empty
  <tr><td colspan="7" style="text-align:center;color:var(--text-muted);padding:24px">Aucune demande.</td></tr>
  @endforelse
  </tbody>
</table></div></div>
@endsection
