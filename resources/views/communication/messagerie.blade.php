{{-- Messagerie interne — portage de pages/communication/messagerie.php --}}
@extends('layouts.app', ['titre' => 'Messagerie interne', 'fil' => 'Communication › Messagerie'])

@section('contenu')
@php
    $actif = 'flex:1;padding:10px;border:none;background:#fff;font-weight:700;font-size:13px;color:#5b21b6;border-bottom:2px solid #5b21b6;text-align:center;text-decoration:none';
    $inactif = 'flex:1;padding:10px;border:none;background:#f9fafb;font-weight:600;font-size:13px;color:#6b7280;text-align:center;text-decoration:none';
@endphp
<div style="display:grid;grid-template-columns:300px 1fr;gap:20px;height:calc(100vh - 180px)">

<div style="display:flex;flex-direction:column;gap:0;overflow:hidden">
  <div style="display:flex;border-bottom:2px solid var(--border)">
    <a href="{{ route('messagerie', ['onglet' => 'recus']) }}" style="{{ $onglet === 'recus' ? $actif : $inactif }}">
      📥 Reçus @if ($nbNonLus > 0)<span style="background:#dc2626;color:#fff;padding:1px 7px;border-radius:10px;font-size:11px">{{ $nbNonLus }}</span>@endif
    </a>
    <a href="{{ route('messagerie', ['onglet' => 'envoyes']) }}" style="{{ $onglet === 'envoyes' ? $actif : $inactif }}">📤 Envoyés</a>
  </div>

  <div style="overflow-y:auto;flex:1;background:#fff;border:1px solid var(--border);border-top:none">
    @if ($onglet === 'recus')
      @forelse ($recus as $m)
      <a href="{{ route('messagerie', ['msg' => $m->id]) }}"
         style="display:block;padding:12px 14px;border-bottom:1px solid var(--border);text-decoration:none;background:{{ $msgId == $m->id ? '#f5f3ff' : ($m->lu ? '#fff' : '#eff6ff') }}">
        <div style="display:flex;justify-content:space-between;align-items:flex-start;gap:6px">
          <div style="flex:1;min-width:0">
            <div style="font-weight:{{ $m->lu ? '600' : '800' }};font-size:13px;color:{{ $m->lu ? '#374151' : '#1e40af' }};white-space:nowrap;overflow:hidden;text-overflow:ellipsis">{{ $m->lu ? '' : '🔵 ' }}{{ $m->prenoms }} {{ $m->nom }}</div>
            <div style="font-size:12px;color:var(--text-muted);white-space:nowrap;overflow:hidden;text-overflow:ellipsis">{{ $m->sujet }}</div>
          </div>
          <div style="font-size:10px;color:var(--text-muted);white-space:nowrap">{{ date('d/m', strtotime($m->created_at)) }}</div>
        </div>
      </a>
      @empty
      <div style="text-align:center;padding:30px;color:var(--text-muted);font-size:13px">📭 Aucun message reçu</div>
      @endforelse
    @else
      @forelse ($envoyes as $m)
      <div style="padding:12px 14px;border-bottom:1px solid var(--border)">
        <div style="font-weight:600;font-size:13px;color:#374151">{{ $m->prenoms }} {{ $m->nom }}</div>
        <div style="font-size:12px;color:var(--text-muted)">{{ $m->sujet }}</div>
        <div style="font-size:10px;color:var(--text-muted);margin-top:4px">{{ date('d/m/Y H:i', strtotime($m->created_at)) }}</div>
      </div>
      @empty
      <div style="text-align:center;padding:30px;color:var(--text-muted);font-size:13px">📭 Aucun message envoyé</div>
      @endforelse
    @endif
  </div>
</div>

<div style="display:flex;flex-direction:column;gap:16px;overflow:hidden">
  @if ($detail)
  <div class="card" style="flex:1;overflow-y:auto">
    <div class="card-header" style="border-bottom:1px solid var(--border)">
      <div>
        <div style="font-weight:800;font-size:15px">{{ $detail->sujet }}</div>
        <div style="font-size:12px;color:var(--text-muted);margin-top:3px">
          {{ $detail->expediteur_type === 'etudiant' ? 'De' : 'À' }} : <strong>{{ $detail->prenoms }} {{ $detail->nom }}</strong>
          (Matricule : {{ $detail->numero ?? '—' }}) — {{ date('d/m/Y à H:i', strtotime($detail->created_at)) }}
        </div>
      </div>
      <form method="post" action="{{ route('messagerie.supprimer') }}" style="display:inline" data-confirm="Supprimer ce message ?">
        @csrf
        <input type="hidden" name="id" value="{{ $detail->id }}">
        <button type="submit" class="btn btn-sm" style="background:#fee2e2;color:#991b1b;border:none" aria-label="Supprimer">🗑</button>
      </form>
    </div>
    <div class="card-body" style="font-size:13px;line-height:1.8;white-space:pre-wrap">{{ $detail->corps }}</div>
  </div>

  @if ($detail->expediteur_type === 'etudiant')
  <div class="card">
    <div class="card-header"><span class="card-title">↩️ Répondre</span></div>
    <div class="card-body">
      <form method="post" action="{{ route('messagerie.envoyer') }}">
        @csrf
        <input type="hidden" name="dest_id" value="{{ $detail->expediteur_id }}">
        <input type="hidden" name="parent_id" value="{{ $detail->id }}">
        <input type="hidden" name="sujet" value="Re: {{ $detail->sujet }}">
        <div class="form-group"><textarea name="corps" class="form-control" rows="4" required maxlength="10000" placeholder="Votre réponse..." aria-label="Réponse"></textarea></div>
        <button type="submit" class="btn btn-primary">📤 Envoyer la réponse</button>
      </form>
    </div>
  </div>
  @endif
  @else
  <div class="card" style="flex:1">
    <div class="card-header"><span class="card-title">✉️ Nouveau message</span></div>
    <div class="card-body">
      <form method="post" action="{{ route('messagerie.envoyer') }}" id="form-message">
        @csrf
        <div class="form-group" style="position:relative">
          <label class="form-label" for="srch-msg">Destinataire <span class="req">*</span></label>
          <input type="text" id="srch-msg" class="form-control" autocomplete="off" placeholder="Rechercher étudiant (nom ou matricule)..."
                 data-url="{{ route('api_recherche_etudiants') }}">
          <input type="hidden" name="dest_id" id="dest-id">
          <div id="msg-results" role="listbox" style="border:1px solid var(--border);border-radius:6px;background:#fff;max-height:180px;overflow-y:auto;display:none;box-shadow:0 4px 12px rgba(0,0,0,.1)"></div>
        </div>
        <div class="form-group"><label class="form-label" for="sujet">Sujet <span class="req">*</span></label>
          <input type="text" name="sujet" id="sujet" class="form-control" required maxlength="200" placeholder="Objet du message"></div>
        <div class="form-group"><label class="form-label" for="corps">Message <span class="req">*</span></label>
          <textarea name="corps" id="corps" class="form-control" rows="6" required maxlength="10000" placeholder="Votre message..."></textarea></div>
        <button type="submit" class="btn btn-primary">📤 Envoyer</button>
      </form>
    </div>
  </div>
  @endif
</div>

</div>
@endsection

@push('scripts')
<script src="{{ asset('assets/js/messagerie.js') }}"></script>
@endpush
