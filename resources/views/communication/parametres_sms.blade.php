{{-- Configuration de la passerelle SMS — portage de pages/admin/parametres_sms.php --}}
@extends('layouts.app', ['titre' => 'Notifications SMS', 'fil' => 'Paramètres › SMS'])

@section('contenu')
@php $actif = $p('sms_actif', '0') === '1'; @endphp
<div class="alert alert-info" style="margin-bottom:16px;font-size:12.5px">
  💡 Cette page fonctionne avec <strong>n'importe quel fournisseur SMS</strong> acceptant une requête HTTP POST en JSON
  (Twilio, Orange SMS API, un service local nigérien...). Tant qu'aucun fournisseur n'est configuré ou activé,
  les messages sont simplement journalisés ci-dessous sans être réellement envoyés.
</div>

<div style="display:grid;grid-template-columns:2fr 1fr;gap:16px;align-items:start">
  <div class="card">
    <div class="card-header"><span class="card-title">⚙️ Configuration du fournisseur</span></div>
    <div class="card-body">
      <form method="post" action="{{ route('parametres_sms.enregistrer') }}" autocomplete="off">
        @csrf
        <div class="form-group">
          <label class="form-label"><input type="checkbox" name="sms_actif" value="1" @checked($actif) style="vertical-align:middle"> Activer l'envoi réel des SMS</label>
          <div style="font-size:11px;color:var(--text-muted);margin-top:2px">Décoché : les messages sont journalisés mais jamais réellement envoyés (mode simulation).</div>
        </div>
        <div class="form-group"><label class="form-label" for="sms_provider_url">URL de la passerelle SMS (https)</label>
          <input type="url" name="sms_provider_url" id="sms_provider_url" class="form-control" value="{{ $p('sms_provider_url') }}" maxlength="255" placeholder="https://api.mon-fournisseur-sms.com/v1/send"></div>
        <div class="form-group"><label class="form-label" for="sms_api_key">Clé API</label>
          <input type="password" name="sms_api_key" id="sms_api_key" class="form-control" maxlength="255" autocomplete="new-password"
            placeholder="{{ $cleDefinie ? '••••••••  (laisser vide pour ne pas changer)' : 'Clé fournie par votre fournisseur SMS' }}"></div>
        <div class="form-group"><label class="form-label" for="sms_sender_id">Nom de l'expéditeur (Sender ID)</label>
          <input type="text" name="sms_sender_id" id="sms_sender_id" class="form-control" value="{{ $p('sms_sender_id', 'EDUFILE') }}" maxlength="11" placeholder="EDUFILE">
          <div style="font-size:11px;color:var(--text-muted);margin-top:2px">11 caractères alphanumériques au plus.</div></div>
        <button type="submit" class="btn btn-primary">💾 Enregistrer</button>
      </form>

      <hr style="margin:20px 0;border:none;border-top:1px solid var(--border-color,#e5e5e5)">

      <form method="post" action="{{ route('parametres_sms.tester') }}" style="display:flex;gap:10px;align-items:flex-end;flex-wrap:wrap">
        @csrf
        <div class="form-group" style="margin:0;flex:1;min-width:220px"><label class="form-label" for="numero_test">Envoyer un SMS de test</label>
          <input type="text" name="numero_test" id="numero_test" class="form-control" placeholder="+227 90 12 34 56" required></div>
        <button type="submit" class="btn btn-secondary">📤 Tester</button>
      </form>
    </div>
  </div>
  @include('communication._resume_envois')
</div>
@include('communication._journal_envois', ['sms' => true])
@endsection
