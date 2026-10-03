{{-- Portail étudiant — portage de pages/etudiants/portail_etudiant.php --}}
@extends('layouts.app', ['titre' => 'Mon espace étudiant', 'fil' => 'Portail étudiant'])

@section('contenu')
@php
    $parametres = fn (array $p = []) => $p + ($personnel && $et ? ['et_id' => $et->id] : []);
    $couleurDecision = fn ($d) => $d === 'Admis' ? 'var(--green-700)' : ($d === 'Défaillant' ? 'var(--red-600)' : 'var(--amber-600)');
@endphp

@if ($personnel)
<div class="card mb-16">
  <div class="card-body" style="display:flex;gap:12px;align-items:center">
    <span style="font-size:20px">🔍</span>
    <form method="get" action="{{ route('portail_etudiant') }}" style="display:flex;gap:8px;flex:1;align-items:center">
      <input type="text" name="q" value="{{ $q }}" placeholder="Rechercher un étudiant (nom, matricule...)" class="form-control" style="flex:1" aria-label="Rechercher un étudiant" autofocus>
      <button type="submit" class="btn btn-primary">Rechercher</button>
    </form>
    @if ($et)<span style="font-size:13px;font-weight:600;color:var(--green-700)">✅ {{ $et->nom }} {{ $et->prenoms }} — {{ $et->numero }}</span>@endif
  </div>
  @if ($resultats->isNotEmpty())
  <div style="padding:0 16px 12px">
    @foreach ($resultats as $r)
    <a href="{{ route('portail_etudiant', ['et_id' => $r->id]) }}" style="display:flex;align-items:center;gap:10px;padding:8px;border-radius:6px;text-decoration:none;border-bottom:1px solid var(--border)">
      <span style="font-size:18px">🎓</span>
      <div><div style="font-weight:700;font-size:13px;color:var(--dark)">{{ $r->nom }} {{ $r->prenoms }}</div><div style="font-size:11px;color:var(--text-muted)">{{ $r->numero }}</div></div>
    </a>
    @endforeach
  </div>
  @elseif ($q !== '' && ! $et)
  <div style="padding:0 16px 12px;font-size:13px;color:var(--text-muted)">Aucun étudiant trouvé dans votre périmètre.</div>
  @endif
</div>
@endif

@if (! $et)
  @if ($personnel)
  <div class="card"><div class="empty-state" style="padding:60px">
    <div style="font-size:48px;margin-bottom:12px">🔍</div><h3>Rechercher un étudiant</h3>
    <p>Utilisez la barre de recherche ci-dessus pour sélectionner un étudiant.</p>
  </div></div>
  @else
  <div class="card"><div class="empty-state" style="padding:60px">
    <div style="font-size:48px;margin-bottom:12px">⚠️</div><h3>Aucun dossier étudiant lié à votre compte</h3>
    <p>Contactez le service de la scolarité.</p>
  </div></div>
  @endif
@else
<div class="card mb-20" style="background:linear-gradient(135deg,#1A3A5C,#2C5F9E);color:#fff">
  <div class="card-body" style="display:flex;align-items:center;gap:20px;padding:20px">
    <div style="width:64px;height:64px;border-radius:50%;background:rgba(255,255,255,.2);display:flex;align-items:center;justify-content:center;font-size:28px">🎓</div>
    <div style="flex:1">
      <div style="font-size:20px;font-weight:800">{{ $et->nom }} {{ $et->prenoms }}</div>
      <div style="opacity:.8;font-size:13px">Matricule : {{ $et->numero }}</div>
      <div style="opacity:.7;font-size:12px">Né(e) le {{ \App\Support\Format::dateFR($et->date_naissance) }}@if ($et->lieu_naissance) à {{ $et->lieu_naissance }}@endif</div>
    </div>
    <div style="text-align:right">
      <div style="font-size:28px;font-weight:800">{{ $nbSemestres }}</div>
      <div style="font-size:11px;opacity:.8;text-transform:uppercase">Semestres</div>
    </div>
  </div>
</div>

<div style="display:flex;gap:4px;margin-bottom:20px;border-bottom:2px solid var(--border)">
  @foreach ([['notes', '📊', 'Notes'], ['dossier', '📁', 'Dossier'], ['carte', '🪪', 'Carte'], ['profil', '👤', 'Profil']] as [$cle, $ic, $lb])
  @continue(! $personnel && in_array($cle, ['dossier', 'carte'], true))
  <a href="{{ route('portail_etudiant', $parametres(['tab' => $cle])) }}"
     style="padding:10px 20px;text-decoration:none;font-weight:600;font-size:14px;border-bottom:2px solid {{ $onglet === $cle ? 'var(--blue-700,#0F2942)' : 'transparent' }};margin-bottom:-2px;color:{{ $onglet === $cle ? 'var(--blue-700,#0F2942)' : 'var(--text-muted)' }}">{{ $ic }} {{ $lb }}</a>
  @endforeach
</div>

@if ($onglet === 'profil')
<div style="max-width:620px">
  <div class="card">
    <div class="card-header"><span class="card-title">👤 Informations personnelles</span></div>
    <div class="card-body">
      <div style="display:grid;grid-template-columns:1fr 1fr;gap:10px;margin-bottom:20px">
        @foreach ([['Nom', $et->nom], ['Prénoms', $et->prenoms], ['Matricule', $et->numero], ['Date naissance', \App\Support\Format::dateFR($et->date_naissance)],
                   ['Lieu naissance', $et->lieu_naissance ?: '—'], ['Nationalité', $et->nationalite ?: '—'],
                   ['Sexe', $et->sexe === 'M' ? 'Masculin' : ($et->sexe === 'F' ? 'Féminin' : '—')]] as [$l, $v])
        <div style="background:var(--gray-50);border-radius:6px;padding:10px">
          <div style="font-size:10px;color:var(--text-muted);font-weight:600;text-transform:uppercase">{{ $l }}</div>
          <div style="font-weight:700;font-size:13px;margin-top:3px">{{ $v ?: '—' }}</div>
        </div>
        @endforeach
      </div>
    </div>
  </div>
  @unless ($personnel)
  <div class="card mt-16">
    <div class="card-header"><span class="card-title">✏️ Modifier mes coordonnées</span></div>
    <div class="card-body">
      <form method="post" action="{{ route('portail_etudiant.profil') }}" autocomplete="off">
        @csrf
        <div class="form-group"><label class="form-label" for="telephone">Téléphone</label>
          <input type="tel" name="telephone" id="telephone" class="form-control" value="{{ $et->telephone }}" maxlength="20" placeholder="+227..."></div>
        <div class="form-group"><label class="form-label" for="adresse">Adresse</label>
          <textarea name="adresse" id="adresse" class="form-control" rows="2" maxlength="255" placeholder="Quartier, ville...">{{ $et->adresse }}</textarea></div>
        <div class="form-group"><label class="form-label" for="email">E-mail de connexion</label>
          <input type="email" name="email" id="email" class="form-control" value="{{ $emailCompte }}" maxlength="150"></div>
        <div style="border-top:1px solid var(--border);padding-top:16px;margin-top:4px">
          <div style="font-weight:600;font-size:13px;color:var(--text-muted);margin-bottom:12px">🔒 Changer le mot de passe <small>(laisser vide pour ne pas changer)</small></div>
          <div style="display:grid;grid-template-columns:1fr 1fr;gap:12px">
            <div class="form-group"><label class="form-label" for="mdp">Nouveau mot de passe</label>
              <input type="password" name="mdp" id="mdp" class="form-control" autocomplete="new-password" placeholder="8 caractères min., lettres et chiffres"></div>
            <div class="form-group"><label class="form-label" for="mdp_confirmation">Confirmer</label>
              <input type="password" name="mdp_confirmation" id="mdp_confirmation" class="form-control" autocomplete="new-password" placeholder="Répéter"></div>
          </div>
        </div>
        <div class="form-group"><label class="form-label" for="mot_de_passe_actuel">Mot de passe actuel <small class="text-muted">(obligatoire pour changer l'e-mail ou le mot de passe)</small></label>
          <input type="password" name="mot_de_passe_actuel" id="mot_de_passe_actuel" class="form-control" autocomplete="current-password"></div>
        <button type="submit" class="btn btn-primary w-100">💾 Enregistrer</button>
      </form>
    </div>
  </div>
  @endunless
</div>

@elseif ($onglet === 'dossier')
<div style="text-align:center;padding:60px">
  <div style="font-size:64px;margin-bottom:16px">📁</div>
  <h3 style="margin-bottom:12px;font-size:20px">Dossier étudiant</h3>
  <p style="color:var(--text-muted);margin-bottom:24px">Consultez le dossier complet : inscriptions, notes, résultats par semestre.</p>
  <a href="{{ route('dossier_etudiant', ['id' => $et->id]) }}" class="btn btn-primary btn-lg">📁 Ouvrir le dossier</a>
</div>

@elseif ($onglet === 'carte')
<div style="text-align:center;padding:60px">
  <div style="font-size:64px;margin-bottom:16px">🪪</div>
  <h3 style="margin-bottom:12px;font-size:20px">Carte étudiante</h3>
  <p style="color:var(--text-muted);margin-bottom:24px">Téléchargez ou imprimez la carte étudiante officielle.</p>
  <a href="{{ route('carte_etudiant.imprimer', ['id' => $et->id]) }}" target="_blank" rel="noopener" class="btn btn-primary btn-lg">🪪 Voir la carte</a>
</div>

@else
<div style="display:grid;grid-template-columns:300px 1fr;gap:20px">
  <div>
    @forelse ($historique as $annee => $semestres)
    <div class="card mb-16">
      <div class="card-header" style="background:var(--blue-700,#0F2942);color:#fff"><span class="card-title" style="color:#fff;font-size:13px">📅 {{ $annee }}</span></div>
      <div class="card-body" style="padding:8px">
        @foreach ($semestres as $s)
        @php $choisi = $s->pv_id == $pvId; @endphp
        <a href="{{ route('portail_etudiant', $parametres(['tab' => 'notes', 'pv_id' => $s->pv_id])) }}"
           style="display:block;padding:10px;border-radius:6px;text-decoration:none;margin-bottom:4px;background:{{ $choisi ? 'var(--blue-50)' : 'transparent' }};border:1px solid {{ $choisi ? 'var(--blue-300)' : 'var(--border)' }}">
          <div style="font-weight:700;font-size:12px;color:var(--text-primary)">{{ $s->semestre }} <span style="font-size:10px;color:var(--text-muted);font-weight:400;margin-left:4px">{{ $s->annee }}</span></div>
          <div style="font-size:11px;color:var(--text-muted)">{{ \App\Support\Format::session($s->type_session) }}</div>
          <div style="display:flex;justify-content:space-between;margin-top:4px">
            <span style="font-size:12px;font-weight:700;color:{{ ($s->moyenne_generale ?? 0) >= 10 ? 'var(--green-700)' : 'var(--amber-600)' }}">{{ $s->moyenne_generale !== null ? number_format((float) $s->moyenne_generale, 2).'/ 20' : '—' }}</span>
            <span style="font-size:11px;font-weight:600;color:{{ $couleurDecision($s->decision) }}">{{ $s->decision ?? '—' }}</span>
          </div>
        </a>
        @endforeach
      </div>
    </div>
    @empty
    <div class="card"><div class="empty-state" style="padding:32px"><div class="icon">🔒</div>
      <p>Aucun résultat disponible.<br><span style="font-size:12px;color:var(--text-muted)">Les résultats sont disponibles après leur publication par la scolarité.</span></p>
    </div></div>
    @endforelse
  </div>

  <div>
    @if ($pv && $resultat)
    <div style="display:grid;grid-template-columns:repeat(4,1fr);gap:12px;margin-bottom:16px">
      @foreach ([[number_format((float) $resultat->moyenne_generale, 2).'/20', 'Moyenne générale', (float) $resultat->moyenne_generale >= 10 ? 'var(--green-700)' : 'var(--amber-600)'],
                 [$resultat->mention ?? '—', 'Mention', 'var(--blue-700,#0F2942)'], [$resultat->decision ?? '—', 'Décision', $couleurDecision($resultat->decision)],
                 [(int) $resultat->credits_obtenus.' crédits', 'Crédits obtenus', 'var(--green-700)']] as [$val, $lbl, $co])
      <div class="card" style="text-align:center;padding:14px">
        <div style="font-size:18px;font-weight:800;color:{{ $co }}">{{ $val }}</div>
        <div style="font-size:10px;color:var(--text-muted);text-transform:uppercase;margin-top:2px">{{ $lbl }}</div>
      </div>
      @endforeach
    </div>
    @foreach ($lignes as $l)
    <div class="card mb-12">
      <div class="card-header" style="background:var(--blue-700,#0F2942);color:#fff;padding:8px 16px"><span style="color:#fff;font-weight:700;font-size:12px">{{ $l['ue']->libelle }}</span></div>
      <div class="card-body" style="padding:0">
        <table class="table" style="font-size:12px">
          <thead><tr><th style="text-align:left">Matière</th><th style="text-align:center">Examen</th><th style="text-align:center">TD</th><th style="text-align:center">Note /20</th><th style="text-align:center">Crédits</th><th style="text-align:center">Obs.</th></tr></thead>
          <tbody>
          @foreach ($l['matieres'] as $ligne)
          @php
              $m = $ligne['m']; $n = $ligne['n'];
              $coulObs = match ($ligne['obs']) { 'AV' => 'var(--green-700)', 'AC' => 'var(--blue-600)', 'Déf.' => 'var(--red-600)', default => 'var(--amber-600)' };
          @endphp
          <tr>
            <td>{{ $m->libelle }}</td>
            <td style="text-align:center">{{ $n && $n->note_examen !== null ? number_format((float) $n->note_examen, 2) : '—' }}</td>
            <td style="text-align:center">{{ $n && $n->note_cc !== null ? number_format((float) $n->note_cc, 2) : ($m->note_max_cc > 0 ? '—' : '-') }}</td>
            <td style="text-align:center;font-weight:700;color:{{ $ligne['moy20'] !== null ? ($ligne['moy20'] >= 10 ? 'var(--green-700)' : 'var(--amber-600)') : 'var(--text-muted)' }}">{{ $ligne['moy20'] !== null ? number_format($ligne['moy20'], 2).'/ 20' : '—' }}</td>
            <td style="text-align:center;color:{{ $ligne['credits'] > 0 ? 'var(--green-700)' : 'var(--text-muted)' }}">{{ $ligne['credits'] }}/{{ $m->credits }}</td>
            <td style="text-align:center;font-weight:700;color:{{ $coulObs }}">{{ $ligne['obs'] }}</td>
          </tr>
          @endforeach
          </tbody>
        </table>
      </div>
    </div>
    @endforeach
    @elseif ($pvId)
    <div class="card"><div class="empty-state" style="padding:32px"><div class="icon">📊</div><p>Aucun résultat pour ce semestre.</p></div></div>
    @else
    <div class="card"><div class="empty-state" style="padding:60px"><div class="icon" style="font-size:48px">📊</div><h3>Notes détaillées</h3><p>Sélectionnez un semestre dans la liste pour voir les notes détaillées.</p></div></div>
    @endif
  </div>
</div>
@endif
@endif
@endsection
