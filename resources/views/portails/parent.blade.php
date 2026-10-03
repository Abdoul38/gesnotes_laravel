{{-- Espace Parents — portage de pages/comptes/portail_parent.php --}}
@extends('layouts.app', ['titre' => 'Espace Parents'.($etudiant ? ' — '.$etudiant->nom : '')])

@push('head')
<style>
.esp-parent { font-family:Georgia,serif; background:#f5f5f5; min-height:100vh; }
.esp-parent .bandeau { background:#0F2942; color:#fff; padding:14px 20px; display:flex; justify-content:space-between; align-items:center; flex-wrap:wrap; gap:10px; }
.esp-parent .bandeau a, .esp-parent .bandeau button { color:#fff; background:rgba(255,255,255,.15); padding:6px 14px; border-radius:6px; text-decoration:none; font-size:12.5px; border:none; cursor:pointer; font-family:inherit; }
.esp-parent .bloc { background:#fff; border-radius:10px; margin-bottom:18px; box-shadow:0 1px 4px rgba(0,0,0,.08); overflow:hidden; }
.esp-parent .bloc-titre { padding:14px 18px; border-bottom:1px solid #eee; font-weight:700; color:#0F2942; }
.esp-parent table { width:100%; border-collapse:collapse; font-size:13px; }
.esp-parent th { background:#f8f5ec; padding:8px 12px; text-align:left; }
.esp-parent td { padding:8px 12px; border-top:1px solid #f0f0f0; }
.esp-parent .vide { padding:24px; text-align:center; color:#888; font-size:13px; }
.esp-parent .pastille { padding:3px 10px; border-radius:10px; font-size:11px; font-weight:700; }
.esp-parent .champ { width:100%; padding:9px 10px; border:1px solid #d8cfb8; border-radius:6px; font-size:13px; box-sizing:border-box; }
</style>
@endpush

@section('contenu')
<div class="esp-parent">
<div class="bandeau">
  <div style="font-size:16px;font-weight:700">👨‍👩‍👧 Espace Parents — {{ $parent->prenoms }} {{ $parent->nom }}</div>
  <div style="display:flex;gap:8px">
    @if ($etudiant)<a href="#mon-compte">⚙️ Mon compte</a>@endif
    <form method="post" action="{{ route('logout_parent') }}">@csrf<button type="submit">Déconnexion</button></form>
  </div>
</div>

@if (! $etudiant)
<div style="max-width:500px;margin:80px auto;text-align:center;background:#fff;padding:40px;border-radius:10px">
  <div style="font-size:40px">👨‍👩‍👧</div>
  <h2 style="margin:16px 0 8px;color:#0F2942">Aucun enfant lié à ce compte</h2>
  <p style="color:#888">Contactez l'établissement pour faire lier le dossier de votre enfant à ce compte.</p>
</div>
@else
@php $fcfa = fn ($v) => number_format((float) $v, 0, '.', ' '); @endphp
<div style="max-width:1000px;margin:20px auto;padding:0 16px">
  @if ($enfants->count() > 1)
  <div style="display:flex;gap:8px;margin-bottom:16px;flex-wrap:wrap">
    @foreach ($enfants as $e)
    <a href="{{ route('portail_parent', ['et_id' => $e->id]) }}" style="padding:8px 16px;border-radius:20px;text-decoration:none;font-size:13px;font-weight:600;background:{{ $e->id == $etudiant->id ? '#0F2942' : '#fff' }};color:{{ $e->id == $etudiant->id ? '#fff' : '#0F2942' }};border:1px solid #0F2942">{{ $e->prenoms }} {{ $e->nom }}</a>
    @endforeach
  </div>
  @endif

  <div style="background:#FAF6ED;border:1px solid #B8860B;border-radius:10px;padding:18px 22px;margin-bottom:18px">
    <div style="font-size:19px;font-weight:700;color:#0F2942">{{ $etudiant->nom }} {{ $etudiant->prenoms }}</div>
    <div style="font-size:12.5px;color:#8B6F1F;font-style:italic;margin-top:3px">Matricule : {{ $etudiant->numero }}</div>
  </div>

  <div style="display:grid;grid-template-columns:repeat(3,1fr);gap:14px;margin-bottom:20px">
    @foreach ([['📊', $resultats->count(), '#0F2942', 'Semestres évalués'], ['💰', $fcfa($totalPaye).' F', $totalPaye >= $totalDu ? '#166534' : '#dc2626', 'Total payé'],
               ['📅', $tauxPresence !== null ? $tauxPresence.'%' : '—', $tauxPresence === null ? '#888' : ($tauxPresence >= 80 ? '#166534' : '#dc2626'), 'Taux de présence']] as [$ic, $val, $co, $lb])
    <div style="background:#fff;border-radius:10px;padding:16px;text-align:center;box-shadow:0 1px 4px rgba(0,0,0,.08)">
      <div style="font-size:24px">{{ $ic }}</div>
      <div style="font-size:18px;font-weight:800;color:{{ $co }};margin:4px 0">{{ $val }}</div>
      <div style="font-size:11px;color:#888;text-transform:uppercase">{{ $lb }}</div>
    </div>
    @endforeach
  </div>

  <div class="bloc">
    <div class="bloc-titre">📊 Résultats par semestre</div>
    @if ($resultats->isEmpty())
    <div class="vide">Aucun résultat disponible pour l'instant.</div>
    @else
    <table>
      <thead><tr><th>Semestre</th><th>Filière / Niveau</th><th style="text-align:center">Moyenne</th><th style="text-align:center">Mention</th><th style="text-align:center">Résultat</th></tr></thead>
      <tbody>
      @foreach ($resultats as $r)
      <tr>
        <td>{{ $r->sem_code }} <span style="color:#888;font-size:11px">({{ $r->annee }})</span>
          @if ($r->type_session === 'rattrapage')<span style="background:#fef3c7;color:#92400e;padding:1px 6px;border-radius:6px;font-size:9.5px;font-weight:700;margin-left:4px">2ème session</span>@endif</td>
        <td>{{ $r->filiere }} {{ $r->niveau }}</td>
        <td style="text-align:center;font-weight:700">{{ number_format((float) $r->moyenne_generale, 2) }}/20</td>
        <td style="text-align:center">{{ $r->mention ?: '—' }}</td>
        <td style="text-align:center"><span class="pastille" style="background:{{ $r->valide ? '#dcfce7' : '#fee2e2' }};color:{{ $r->valide ? '#166534' : '#dc2626' }}">{{ $r->valide ? 'VALIDÉ' : 'NON VALIDÉ' }}</span></td>
      </tr>
      @endforeach
      </tbody>
    </table>
    @endif
  </div>

  <div class="bloc">
    <div class="bloc-titre">💰 Historique des paiements</div>
    @if ($paiements->isEmpty())
    <div class="vide">Aucun paiement enregistré.</div>
    @else
    @php $statuts = ['complet' => ['#dcfce7', '#166534', 'Complet'], 'partiel' => ['#fef3c7', '#92400e', 'Partiel'], 'en_attente' => ['#fee2e2', '#dc2626', 'En attente'], 'rembourse' => ['#f3f4f6', '#374151', 'Remboursé']]; @endphp
    <table>
      <thead><tr><th>Date</th><th>Type</th><th style="text-align:center">Montant payé</th><th style="text-align:center">Statut</th></tr></thead>
      <tbody>
      @foreach ($paiements as $p)
      @php $b = $statuts[$p->statut] ?? ['#eee', '#333', $p->statut]; @endphp
      <tr>
        <td>{{ $p->date_paiement ? date('d/m/Y', strtotime($p->date_paiement)) : '—' }}</td>
        <td>{{ ucfirst($p->type_paiement ?? '—') }}</td>
        <td style="text-align:center;font-weight:700">{{ $fcfa($p->montant_paye) }} FCFA</td>
        <td style="text-align:center"><span class="pastille" style="background:{{ $b[0] }};color:{{ $b[1] }}">{{ $b[2] }}</span></td>
      </tr>
      @endforeach
      </tbody>
    </table>
    @endif
  </div>

  <div class="bloc" style="margin-bottom:30px">
    <div class="bloc-titre" style="display:flex;justify-content:space-between;align-items:center">
      <span>📅 Présence aux cours (30 dernières séances)</span>
      <span style="font-size:11.5px;color:#888;font-weight:normal">✅ {{ $presences->where('statut', 'present')->count() }} · ❌ {{ $presences->where('statut', 'absent')->count() }} · ⏰ {{ $presences->where('statut', 'retard')->count() }}</span>
    </div>
    @if ($presences->isEmpty())
    <div class="vide">Aucune présence enregistrée pour l'instant.</div>
    @else
    @php $etats = ['present' => ['#dcfce7', '#166534', '✅ Présent'], 'absent' => ['#fee2e2', '#dc2626', '❌ Absent'], 'retard' => ['#fef3c7', '#92400e', '⏰ Retard'], 'excuse' => ['#e0f2fe', '#075985', '📝 Excusé']]; @endphp
    <table>
      <thead><tr><th>Date</th><th>Matière</th><th style="text-align:center">Statut</th><th>Motif</th></tr></thead>
      <tbody>
      @foreach ($presences as $p)
      @php $s = $etats[$p->statut] ?? ['#eee', '#333', $p->statut]; @endphp
      <tr>
        <td>{{ date('d/m/Y', strtotime($p->date_seance)) }}</td>
        <td>{{ $p->matiere }} <span style="color:#888;font-size:11px">({{ $p->type_seance }})</span></td>
        <td style="text-align:center"><span class="pastille" style="background:{{ $s[0] }};color:{{ $s[1] }}">{{ $s[2] }}</span></td>
        <td style="color:#888;font-size:12px">{{ $p->motif ?: '—' }}</td>
      </tr>
      @endforeach
      </tbody>
    </table>
    @endif
  </div>

  <div id="mon-compte" class="bloc" style="margin-bottom:30px">
    <div class="bloc-titre">⚙️ Mon compte</div>
    <div style="padding:20px">
      @if (session('succes_compte'))<div style="background:#dcfce7;color:#166534;padding:10px 14px;border-radius:8px;font-size:13px;margin-bottom:16px">{{ session('succes_compte') }}</div>@endif
      @if (session('erreur_compte'))<div style="background:#fee2e2;color:#dc2626;padding:10px 14px;border-radius:8px;font-size:13px;margin-bottom:16px">{{ session('erreur_compte') }}</div>@endif
      <div style="font-size:12.5px;color:#888;margin-bottom:16px">
        📞 Numéro de connexion : <strong style="color:#0F2942">{{ $parent->telephone }}</strong>
        <span style="font-style:italic"> (non modifiable — contactez l'établissement pour le changer)</span>
      </div>
      <form method="post" action="{{ route('portail_parent.compte') }}" style="max-width:400px" autocomplete="off">
        @csrf
        <div style="margin-bottom:14px"><label for="email" style="display:block;font-size:12px;font-weight:600;color:#444;margin-bottom:4px">Email</label>
          <input type="email" name="email" id="email" value="{{ $parent->email }}" maxlength="150" class="champ"></div>
        <div style="margin-bottom:14px"><label for="mot_de_passe_actuel" style="display:block;font-size:12px;font-weight:600;color:#444;margin-bottom:4px">Mot de passe actuel <span style="color:#dc2626">*</span></label>
          <input type="password" name="mot_de_passe_actuel" id="mot_de_passe_actuel" required class="champ" autocomplete="current-password"></div>
        <div style="margin-bottom:14px"><label for="nouveau_mot_de_passe" style="display:block;font-size:12px;font-weight:600;color:#444;margin-bottom:4px">Nouveau mot de passe (laisser vide pour ne pas changer)</label>
          <input type="password" name="nouveau_mot_de_passe" id="nouveau_mot_de_passe" class="champ" autocomplete="new-password" placeholder="8 caractères min., lettres et chiffres"></div>
        <div style="margin-bottom:18px"><label for="nouveau_mot_de_passe_confirmation" style="display:block;font-size:12px;font-weight:600;color:#444;margin-bottom:4px">Confirmer le nouveau mot de passe</label>
          <input type="password" name="nouveau_mot_de_passe_confirmation" id="nouveau_mot_de_passe_confirmation" class="champ" autocomplete="new-password"></div>
        <button type="submit" style="padding:10px 22px;background:#0F2942;color:#fff;border:none;border-radius:6px;font-size:13px;font-weight:700;cursor:pointer">💾 Enregistrer les modifications</button>
      </form>
    </div>
  </div>
</div>
@endif
</div>
@endsection
