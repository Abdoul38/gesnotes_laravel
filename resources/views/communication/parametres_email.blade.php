{{-- Configuration du serveur SMTP — portage de pages/admin/parametres_email.php --}}
@extends('layouts.app', ['titre' => 'Notifications Email', 'fil' => 'Paramètres › Email'])

@section('contenu')
@php
    $actif = $p('email_actif', '0') === '1';
    $chiffrement = $p('email_chiffrement', 'tls');
@endphp
<div class="alert alert-info" style="margin-bottom:16px;font-size:12.5px">
  💡 Fonctionne avec n'importe quel serveur SMTP standard : Gmail, Alwaysdata, un serveur d'entreprise...
  Pour Gmail par exemple : hôte <code>smtp.gmail.com</code>, port <code>587</code>, chiffrement <strong>TLS</strong>,
  et un <a href="https://myaccount.google.com/apppasswords" target="_blank" rel="noopener noreferrer">mot de passe d'application</a> (pas votre mot de passe habituel).
</div>

<div style="display:grid;grid-template-columns:2fr 1fr;gap:16px;align-items:start">
  <div class="card">
    <div class="card-header"><span class="card-title">⚙️ Configuration du serveur SMTP</span></div>
    <div class="card-body">
      <form method="post" action="{{ route('parametres_email.enregistrer') }}" autocomplete="off">
        @csrf
        <div class="form-group">
          <label class="form-label"><input type="checkbox" name="email_actif" value="1" @checked($actif) style="vertical-align:middle"> Activer l'envoi réel des emails</label>
          <div style="font-size:11px;color:var(--text-muted);margin-top:2px">Décoché : les messages sont journalisés mais jamais réellement envoyés (mode simulation).</div>
        </div>
        <div style="display:grid;grid-template-columns:2fr 1fr;gap:10px">
          <div class="form-group"><label class="form-label" for="email_smtp_host">Serveur SMTP (hôte)</label>
            <input type="text" name="email_smtp_host" id="email_smtp_host" class="form-control" value="{{ $p('email_smtp_host') }}" maxlength="190" placeholder="smtp.gmail.com"></div>
          <div class="form-group"><label class="form-label" for="email_smtp_port">Port</label>
            <input type="number" name="email_smtp_port" id="email_smtp_port" class="form-control" value="{{ $p('email_smtp_port', '587') }}" min="1" max="65535" placeholder="587"></div>
        </div>
        <div class="form-group"><label class="form-label" for="email_chiffrement">Chiffrement</label>
          <select name="email_chiffrement" id="email_chiffrement" class="form-control">
            <option value="tls" @selected($chiffrement === 'tls')>TLS (recommandé — port 587)</option>
            <option value="ssl" @selected($chiffrement === 'ssl')>SSL (port 465)</option>
            <option value="none" @selected($chiffrement === 'none')>Aucun (non recommandé)</option>
          </select></div>
        <div class="form-group"><label class="form-label" for="email_smtp_user">Nom d'utilisateur SMTP</label>
          <input type="text" name="email_smtp_user" id="email_smtp_user" class="form-control" value="{{ $p('email_smtp_user') }}" maxlength="190" placeholder="votre-compte@gmail.com"></div>
        <div class="form-group"><label class="form-label" for="email_smtp_pass">Mot de passe SMTP</label>
          <input type="password" name="email_smtp_pass" id="email_smtp_pass" class="form-control" maxlength="255" autocomplete="new-password"
            placeholder="{{ $motDePasseDefini ? '••••••••  (laisser vide pour ne pas changer)' : "Mot de passe ou mot de passe d'application" }}"></div>
        <div class="form-group"><label class="form-label" for="email_from_adresse">Adresse d'expédition affichée</label>
          <input type="email" name="email_from_adresse" id="email_from_adresse" class="form-control" value="{{ $p('email_from_adresse') }}" maxlength="190" placeholder="Souvent identique au nom d'utilisateur"></div>
        <div class="form-group"><label class="form-label" for="email_from_nom">Nom d'expéditeur affiché</label>
          <input type="text" name="email_from_nom" id="email_from_nom" class="form-control" value="{{ $p('email_from_nom', 'EDUFILE') }}" maxlength="100" placeholder="EDUFILE"></div>
        <button type="submit" class="btn btn-primary">💾 Enregistrer</button>
      </form>

      <hr style="margin:20px 0;border:none;border-top:1px solid var(--border-color,#e5e5e5)">

      <form method="post" action="{{ route('parametres_email.tester') }}" style="display:flex;gap:10px;align-items:flex-end;flex-wrap:wrap">
        @csrf
        <div class="form-group" style="margin:0;flex:1;min-width:220px"><label class="form-label" for="email_test">Envoyer un email de test</label>
          <input type="email" name="email_test" id="email_test" class="form-control" placeholder="votre@email.com" required></div>
        <button type="submit" class="btn btn-secondary">📤 Tester</button>
      </form>
    </div>
  </div>
  @include('communication._resume_envois')
</div>
@include('communication._journal_envois', ['sms' => false])
@endsection
