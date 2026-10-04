{{-- Génération des comptes étudiants — portage de generer_comptes_etudiants.php --}}
@extends('layouts.app', ['titre' => 'Comptes étudiants', 'fil' => 'Administration › Génération comptes'])

@section('contenu')
<div style="display:grid;grid-template-columns:1fr 1fr;gap:20px;margin-bottom:20px">

<div class="card">
  <div class="card-header"><span class="card-title">⚡ Générer les comptes</span></div>
  <div class="card-body">

    <div style="background:#eff6ff;border:1px solid #bfdbfe;border-radius:8px;padding:12px 16px;margin-bottom:16px;font-size:13px">
      <strong>ℹ️ Règles de génération :</strong><br>
      • <strong>Email</strong> : prenom.nom.matricule@{{ $domaine }} (ex: <code>abdou.hamadou.12345@{{ $domaine }}</code>)<br>
      • <strong>Mot de passe provisoire</strong> : aléatoire, affiché <strong>une seule fois</strong> après la génération — imprimez la liste à ce moment-là. L'étudiant doit le changer à sa première connexion.<br>
      • L'étudiant peut changer son mot de passe après connexion
    </div>

    <form method="post" action="{{ route('generer_comptes_etudiants.generer') }}" style="display:flex;gap:16px;flex-wrap:wrap;align-items:flex-end"
          data-confirm="Générer les comptes pour les étudiants sélectionnés ?">
      @csrf
      <input type="hidden" name="annee_id" value="{{ $anneeId }}">

      <div class="form-group" style="margin:0">
        <label class="form-label" for="annee_id_sel">Année académique</label>
        <select id="annee_id_sel" class="form-control" data-navigate="{{ route('generer_comptes_etudiants') }}?annee_id=">
          @foreach ($annees as $a)<option value="{{ $a->id }}" @selected($a->id == $anneeId)>{{ $a->libelle }}</option>@endforeach
        </select>
      </div>

      <div class="form-group" style="margin:0;min-width:250px">
        <label class="form-label" for="promo_id">Promotion</label>
        <select name="promo_id" id="promo_id" class="form-control">
          <option value="">-- Toutes les promotions --</option>
          @foreach ($promotions as $p)<option value="{{ $p->id }}">{{ $p->filiere }} {{ $p->niveau }} ({{ $p->nb_inscrits }} inscrits)</option>@endforeach
        </select>
      </div>

      <div class="form-group" style="margin:0">
        <span class="form-label">&nbsp;</span>
        <div style="display:flex;flex-direction:column;gap:6px">
          <label style="font-size:12px;display:flex;align-items:center;gap:6px;cursor:pointer">
            <input type="checkbox" name="tous" value="1"> Toutes les promotions
          </label>
          <label style="font-size:12px;display:flex;align-items:center;gap:6px;cursor:pointer">
            <input type="checkbox" name="ecraser" value="1"> Écraser les comptes existants
          </label>
        </div>
      </div>

      <button type="submit" class="btn btn-primary">⚡ Générer les comptes</button>
    </form>
  </div>
</div>

<div class="card">
  <div class="card-header"><span class="card-title">👁️ Comptes déjà générés</span></div>
  <div class="card-body">
    <div class="form-group" style="margin-bottom:12px">
      <label class="form-label" for="voir_promo">Choisir une promotion</label>
      <select id="voir_promo" class="form-control" data-navigate="{{ route('generer_comptes_etudiants') }}?annee_id={{ $anneeId }}&voir_promo=">
        <option value="">-- Sélectionner --</option>
        @foreach ($promotions as $p)
        <option value="{{ $p->id }}" @selected($voir && $p->id == $voir->id)>{{ $p->filiere }} {{ $p->niveau }} ({{ $p->nb_inscrits }} inscrits)</option>
        @endforeach
      </select>
    </div>

    @if ($voir && $comptes->isNotEmpty())
    <div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:8px">
      <span style="font-size:12px;color:var(--text-muted)">{{ $comptes->count() }} compte(s) trouvé(s)</span>
      <a href="{{ route('generer_comptes_etudiants.imprimer', ['voir_promo' => $voir->id]) }}" target="_blank" rel="noopener" class="btn btn-sm btn-secondary">🖨️ Imprimer / PDF</a>
    </div>
    <div style="max-height:380px;overflow-y:auto;font-size:11px">
      <table style="width:100%;border-collapse:collapse">
        <thead style="position:sticky;top:0;background:#f8fafc">
          <tr>
            <th style="padding:5px 8px;text-align:left;border-bottom:1px solid var(--border)">Nom</th>
            <th style="padding:5px 8px;text-align:left;border-bottom:1px solid var(--border)">Email</th>
            <th style="padding:5px 8px;text-align:center;border-bottom:1px solid var(--border)">MDP</th>
            <th style="padding:5px 8px;text-align:center;border-bottom:1px solid var(--border)">Connexion</th>
          </tr>
        </thead>
        <tbody>
        @foreach ($comptes as $c)
        <tr style="border-bottom:1px solid var(--border)">
          <td style="padding:4px 8px;font-weight:600">{{ $c->nom }} {{ $c->prenoms }}</td>
          <td style="padding:4px 8px"><code style="font-size:10px">{{ $c->email }}</code></td>
          <td style="padding:4px 8px;text-align:center"><code style="background:#fef3c7;padding:1px 5px;border-radius:3px">{{ $c->numero }}</code></td>
          <td style="padding:4px 8px;text-align:center">
            @if ($c->derniere_connexion)<span style="color:var(--green-700);font-size:10px">✅ Connecté</span>@else<span style="color:#9ca3af;font-size:10px">Jamais</span>@endif
          </td>
        </tr>
        @endforeach
        </tbody>
      </table>
    </div>
    @elseif ($voir)
    <div style="text-align:center;padding:24px;color:var(--text-muted);font-size:13px">Aucun compte généré pour cette promotion.</div>
    @else
    <div style="text-align:center;padding:24px;color:var(--text-muted);font-size:13px">Sélectionnez une promotion pour voir les comptes.</div>
    @endif
  </div>
</div>

</div>

@if ($rapport)
<div style="display:grid;grid-template-columns:repeat(3,1fr);gap:16px;margin-bottom:20px">
  <div class="card" style="text-align:center;padding:20px">
    <div style="font-size:36px;font-weight:800;color:var(--green-700)">{{ $rapport['crees'] }}</div>
    <div style="font-size:12px;color:var(--text-muted)">✅ Comptes créés / mis à jour</div>
  </div>
  <div class="card" style="text-align:center;padding:20px">
    <div style="font-size:36px;font-weight:800;color:var(--amber-600)">{{ $rapport['existants'] }}</div>
    <div style="font-size:12px;color:var(--text-muted)">⏭️ Déjà existants (ignorés)</div>
  </div>
  <div class="card" style="text-align:center;padding:20px">
    <div style="font-size:36px;font-weight:800;color:var(--red-600)">{{ $rapport['erreurs'] }}</div>
    <div style="font-size:12px;color:var(--text-muted)">❌ Erreurs</div>
  </div>
</div>

<div class="card" id="fiche-identifiants">
  <div class="card-header"><span class="card-title">📋 Détail des comptes générés</span>
    <button type="button" class="btn btn-primary btn-sm" data-print="1">🖨️ Imprimer les identifiants</button></div>
  <div class="alert alert-warning" style="margin:12px 16px;font-size:12px">⚠️ Les mots de passe provisoires ne sont affichés qu'ici, une seule fois : imprimez cette liste maintenant. Un mot de passe perdu se remplace en régénérant le compte (option « écraser »).</div>
  <div class="card-body" style="padding:0">
    <table class="table" style="font-size:12px">
      <thead><tr><th>Nom & Prénoms</th><th>Matricule</th><th>Email généré</th><th>Mot de passe initial</th><th>Statut</th></tr></thead>
      <tbody>
      @foreach ($rapport['details'] as $d)
      @php
        [$libelle, $fond, $texte] = match ($d['statut']) {
            'cree' => ['✅ Créé', '#dcfce7', '#166534'],
            'mis_a_jour' => ['🔄 Mis à jour', '#dbeafe', '#1e40af'],
            'existant' => ['⏭️ Existant', '#fef3c7', '#92400e'],
            'erreur' => ['❌ Erreur', '#fee2e2', '#dc2626'],
            default => ['—', '#f3f4f6', '#6b7280'],
        };
      @endphp
      <tr>
        <td style="font-weight:600">{{ $d['nom'] }}</td>
        <td style="color:var(--text-muted)">{{ $d['matricule'] ?: '—' }}</td>
        <td>@if ($d['login'])<code>{{ $d['login'] }}</code>@else—@endif</td>
        <td>@if ($d['statut'] !== 'erreur')<code style="background:#fef3c7;padding:2px 6px;border-radius:4px">{{ $d['motdepasse'] ?? 'inchangé' }}</code>@else—@endif</td>
        <td>
          <span style="background:{{ $fond }};color:{{ $texte }};padding:2px 8px;border-radius:8px;font-size:11px;font-weight:700">{{ $libelle }}</span>
          @if ($d['msg'])<span style="font-size:10px;color:var(--red-600)">{{ $d['msg'] }}</span>@endif
        </td>
      </tr>
      @endforeach
      </tbody>
    </table>
  </div>
</div>
@endif
@endsection

@push('head')
<style>@media print{body *{visibility:hidden}#fiche-identifiants,#fiche-identifiants *{visibility:visible}#fiche-identifiants{position:absolute;left:0;top:0;width:100%}#fiche-identifiants .btn,#fiche-identifiants .alert{display:none}}</style>
@endpush
