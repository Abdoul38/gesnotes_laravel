{{-- E-mails aux étudiants — portage de pages/communication/notifications_email.php --}}
@extends('layouts.app', ['titre' => 'Notifications par Email', 'fil' => 'Communication › Email'])

@section('contenu')
<div style="display:grid;grid-template-columns:1fr 1fr;gap:20px">

<div class="card">
  <div class="card-header"><span class="card-title">✉️ Composer un email</span></div>
  <div class="card-body">
    <div style="background:#eff6ff;border:1px solid #bfdbfe;border-radius:8px;padding:12px 16px;margin-bottom:16px;font-size:12px;color:#1e40af">
      📊 <strong>{{ $nbAvecEmail }}</strong> étudiants ont un email sur <strong>{{ $nbTotal }}</strong> au total
      @if ($nbAvecEmail < $nbTotal) — <span style="color:#dc2626">{{ $nbTotal - $nbAvecEmail }} sans email</span>@endif
    </div>

    <form method="post" action="{{ route('notifications_email.envoyer') }}" id="form-email" data-confirm="Confirmer l'envoi de cet email ?">
      @csrf
      <div class="form-group">
        <label class="form-label" for="sel-dest">Destinataires <span class="req">*</span></label>
        <select name="destinataires" class="form-control" id="sel-dest">
          <option value="tous">Tous les étudiants ({{ $nbAvecEmail }} emails)</option>
          <option value="filiere">Par filière</option>
          <option value="niveau">Par niveau</option>
          <option value="individuel">Étudiant individuel</option>
        </select>
      </div>
      <div id="zone-filiere" style="display:none" class="form-group"><label class="form-label" for="filiere_id">Filière</label>
        <select name="filiere_id" id="filiere_id" class="form-control"><option value="">— Sélectionner —</option>
          @foreach ($filieres as $f)<option value="{{ $f->id }}">{{ $f->libelle }}</option>@endforeach
        </select></div>
      <div id="zone-niveau" style="display:none" class="form-group"><label class="form-label" for="niveau_id">Niveau</label>
        <select name="niveau_id" id="niveau_id" class="form-control"><option value="">— Sélectionner —</option>
          @foreach ($niveaux as $n)<option value="{{ $n->id }}">{{ $n->code }}</option>@endforeach
        </select></div>
      <div id="zone-individuel" style="display:none" class="form-group">
        <label class="form-label" for="srch-et">Matricule ou nom de l'étudiant</label>
        <input type="text" id="srch-et" class="form-control" autocomplete="off" placeholder="Taper pour rechercher..." data-url="{{ route('api_recherche_etudiants') }}">
        <input type="hidden" name="etudiant_id" id="et-id">
        <div id="et-results" role="listbox" style="border:1px solid var(--border);border-radius:6px;background:#fff;max-height:200px;overflow-y:auto;display:none"></div>
      </div>
      <div class="form-group"><label class="form-label" for="sujet">Sujet <span class="req">*</span></label>
        <input type="text" name="sujet" id="sujet" class="form-control" required maxlength="300" placeholder="Objet de l'email"></div>
      <div class="form-group">
        <label class="form-label" for="corps">Corps du message <span class="req">*</span></label>
        <div style="font-size:11px;color:var(--text-muted);margin-bottom:4px">Variables : <code>[NOM]</code> <code>[PRENOMS]</code> <code>[NOM_COMPLET]</code> — remplacées automatiquement</div>
        <textarea name="corps" id="corps" class="form-control" rows="8" required maxlength="20000" placeholder="Cher(e) [PRENOMS] [NOM],&#10;&#10;..."></textarea>
      </div>
      <div style="background:#f9fafb;border:1px solid var(--border);border-radius:8px;padding:12px;margin-bottom:16px">
        <label style="display:flex;align-items:center;gap:8px;cursor:pointer;margin-bottom:8px">
          <input type="checkbox" name="test_email" value="1" id="chk-test" style="width:16px;height:16px">
          <span style="font-weight:600;font-size:13px">🧪 Mode test — envoyer à une seule adresse</span>
        </label>
        <div id="zone-test" style="display:none"><input type="email" name="test_addr" class="form-control" maxlength="190" placeholder="votre@email.com" aria-label="Adresse de test"></div>
      </div>
      <button type="submit" class="btn btn-primary w-100" style="padding:12px;font-size:15px">📤 Envoyer l'email</button>
    </form>
  </div>
</div>

<div class="card">
  <div class="card-header"><span class="card-title">📋 Historique des envois</span></div>
  <div class="card-body" style="padding:0;max-height:600px;overflow-y:auto">
    @forelse ($historique as $h)
    <div style="padding:14px 16px;border-bottom:1px solid var(--border)">
      <div style="display:flex;justify-content:space-between;align-items:flex-start;gap:8px">
        <div style="flex:1">
          <div style="font-weight:700;font-size:13px;color:var(--dark);margin-bottom:4px">{{ $h->sujet }}</div>
          <div style="font-size:11px;color:var(--text-muted)">{{ date('d/m/Y H:i', strtotime($h->created_at)) }} — {{ $h->envoyeur ?? 'Système' }}@if ($h->filiere) | {{ $h->filiere }}@endif @if ($h->niveau) | {{ $h->niveau }}@endif</div>
        </div>
        <div style="text-align:right;flex-shrink:0">
          <div style="font-size:12px;font-weight:700;color:{{ $h->nb_envoyes > 0 ? '#166534' : '#991b1b' }}">✅ {{ $h->nb_envoyes }} envoyé(s)</div>
          @if ($h->nb_echecs > 0)<div style="font-size:11px;color:#dc2626">❌ {{ $h->nb_echecs }} échec(s)</div>@endif
          <span style="font-size:10px;padding:2px 8px;border-radius:8px;background:{{ match ($h->statut) { 'envoye' => '#dcfce7', 'partiel' => '#fef9c3', default => '#f3f4f6' } }};color:{{ match ($h->statut) { 'envoye' => '#166534', 'partiel' => '#854d0e', default => '#374151' } }}">{{ ucfirst($h->statut) }}</span>
        </div>
      </div>
    </div>
    @empty
    <div style="text-align:center;padding:40px;color:var(--text-muted)"><div style="font-size:40px;margin-bottom:8px">📭</div>Aucun email envoyé</div>
    @endforelse
  </div>
</div>

</div>
@endsection

@push('scripts')
<script src="{{ \App\Support\Ressource::url('assets/js/notifications-email.js') }}"></script>
@endpush
