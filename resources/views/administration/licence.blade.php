{{-- Licence — portage de pages/admin/parametres_licence.php --}}
@extends('layouts.app', ['titre' => 'Licence', 'fil' => 'Paramètres › Licence'])

@section('contenu')
<div class="card mb-16">
  <div class="card-header"><span class="card-title">📜 Statut de la licence</span></div>
  <div class="card-body">
    @if ($statut['valide'])
    <div style="display:flex;align-items:center;gap:16px;padding:16px;background:#dcfce7;border-radius:10px;margin-bottom:16px">
      <span style="font-size:32px">✅</span>
      <div><div style="font-size:16px;font-weight:800;color:#166534">Licence active</div><div style="font-size:13px;color:#166534">Établissement : <strong>{{ $statut['institution'] }}</strong></div></div>
    </div>
    @else
    <div style="display:flex;align-items:center;gap:16px;padding:16px;background:#fee2e2;border-radius:10px;margin-bottom:16px">
      <span style="font-size:32px">⚠️</span>
      <div><div style="font-size:16px;font-weight:800;color:#991b1b">Licence invalide</div><div style="font-size:13px;color:#991b1b">{{ $statut['erreur'] }}</div></div>
    </div>
    @endif
    @if ($statut['institution'])
    <table style="width:100%;font-size:13px">
      <tr><td style="padding:6px 0;color:var(--text-muted)">Établissement</td><td style="font-weight:700">{{ $statut['institution'] }}</td></tr>
      <tr><td style="padding:6px 0;color:var(--text-muted)">Expiration</td><td style="font-weight:700">{{ $statut['expiration'] ?? 'Illimitée' }}</td></tr>
      <tr><td style="padding:6px 0;color:var(--text-muted)">Limite d'étudiants</td>
        <td style="font-weight:700">{{ $statut['max_etudiants'] ?: 'Illimitée' }} <span style="color:var(--text-muted);font-weight:400">(actuellement {{ $nbEtudiants }} inscrit(s) au total)</span></td></tr>
    </table>
    @endif
  </div>
</div>

<div class="card">
  <div class="card-header"><span class="card-title">🔑 Activer / mettre à jour la licence</span></div>
  <div class="card-body">
    <form method="post" action="{{ route('parametres_licence.activer') }}" autocomplete="off">
      @csrf
      <div class="form-group"><label class="form-label" for="cle_licence">Clé de licence</label>
        <textarea name="cle_licence" id="cle_licence" class="form-control" rows="3" maxlength="1000" placeholder="Collez ici la clé fournie par votre prestataire" style="font-family:monospace;font-size:12px" required></textarea></div>
      <button type="submit" class="btn btn-primary">✅ Activer cette licence</button>
    </form>
  </div>
</div>
@endsection
