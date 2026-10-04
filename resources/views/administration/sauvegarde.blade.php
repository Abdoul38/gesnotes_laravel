{{-- Sauvegarde de la base — portage de pages/admin/sauvegarde.php --}}
@extends('layouts.app', ['titre' => 'Sauvegarde', 'fil' => 'Système › Sauvegarde'])

@section('contenu')
<div style="display:grid;grid-template-columns:repeat(auto-fit,minmax(180px,1fr));gap:16px;margin-bottom:24px;align-items:stretch">
  <div style="background:#eff6ff;border:1px solid #bfdbfe;border-radius:12px;padding:18px;text-align:center">
    <div style="font-size:32px;margin-bottom:6px">🗄️</div><div style="font-size:18px;font-weight:800;color:#1e40af;word-break:break-all">{{ $base }}</div><div style="font-size:12px;color:#3b82f6">Base de données</div></div>
  <div style="background:#f0fdf4;border:1px solid #86efac;border-radius:12px;padding:18px;text-align:center">
    <div style="font-size:32px;margin-bottom:6px">📋</div><div style="font-size:22px;font-weight:800;color:#166534">{{ $nbTables }}</div><div style="font-size:12px;color:#16a34a">Tables</div></div>
  <div style="background:#fef9c3;border:1px solid #fde047;border-radius:12px;padding:18px;text-align:center">
    <div style="font-size:32px;margin-bottom:6px">💾</div><div style="font-size:22px;font-weight:800;color:#854d0e">{{ $tailleMo ?? '—' }} Mo</div><div style="font-size:12px;color:#a16207">Taille totale</div></div>
  <form method="post" action="{{ route('sauvegarde.creer') }}" data-confirm="Lancer une nouvelle sauvegarde ?" style="display:flex">
    @csrf
    <button type="submit" class="btn btn-primary" style="padding:18px 28px;font-size:16px;font-weight:800;display:flex;flex-direction:column;align-items:center;justify-content:center;gap:6px;width:100%;border-radius:12px">
      <span style="font-size:32px">💾</span>Sauvegarder maintenant</button>
  </form>
</div>

<div class="card">
  <div class="card-header"><span class="card-title">📋 Historique des sauvegardes</span><span style="font-size:12px;color:var(--text-muted)">{{ $nbFichiers }} fichier(s) sur disque</span></div>
  <div class="card-body" style="padding:0;overflow-x:auto">
    @if ($historique->isEmpty())
    <div style="text-align:center;padding:50px;color:var(--text-muted)"><div style="font-size:48px;margin-bottom:12px">📭</div><div style="font-weight:600">Aucune sauvegarde</div><div style="font-size:13px">Cliquez sur "Sauvegarder maintenant" pour créer votre première sauvegarde</div></div>
    @else
    <table class="table" style="font-size:13px">
      <thead><tr><th>Fichier</th><th style="text-align:center">Taille</th><th style="text-align:center">Tables</th><th>Créé par</th><th style="text-align:center">Date</th><th style="text-align:center">Statut</th><th style="text-align:center">Actions</th></tr></thead>
      <tbody>
      @foreach ($historique as $s)
      <tr>
        <td style="font-family:monospace;font-size:12px;color:var(--blue-700)">{{ $s->nom_fichier }}</td>
        <td style="text-align:center;font-weight:600">{{ $s->taille > 0 ? number_format($s->taille / 1024, 1, ',', ' ').' Ko' : '—' }}</td>
        <td style="text-align:center">{{ $s->nb_tables }}</td>
        <td>@if (trim($s->createur_nom.' '.$s->createur_prenom) !== ''){{ trim($s->createur_nom.' '.$s->createur_prenom) }}@else<em style="color:var(--text-muted)">Système</em>@endif</td>
        <td style="text-align:center;font-size:12px">{{ \Illuminate\Support\Carbon::parse($s->created_at)->format('d/m/Y H:i') }}</td>
        <td style="text-align:center"><span style="padding:3px 12px;border-radius:12px;font-size:11px;font-weight:700;background:{{ $s->statut === 'ok' ? '#dcfce7' : '#fee2e2' }};color:{{ $s->statut === 'ok' ? '#166534' : '#991b1b' }}">{{ $s->statut === 'ok' ? '✅ OK' : '❌ Erreur' }}</span></td>
        <td style="text-align:center">
          <div style="display:flex;gap:6px;justify-content:center">
            @if ($s->present)
            <form method="post" action="{{ route('sauvegarde.telecharger') }}" style="display:inline">@csrf<input type="hidden" name="id" value="{{ $s->id }}">
              <button type="submit" class="btn btn-sm btn-primary">⬇️ Télécharger</button></form>
            @else
            <span style="font-size:11px;color:var(--text-muted);font-style:italic">Fichier absent</span>
            @endif
            <form method="post" action="{{ route('sauvegarde.supprimer') }}" style="display:inline" data-confirm="Supprimer cette sauvegarde ?">@csrf<input type="hidden" name="id" value="{{ $s->id }}">
              <button type="submit" class="btn btn-sm" style="background:#fee2e2;color:#991b1b;border:none" aria-label="Supprimer">🗑</button></form>
          </div>
        </td>
      </tr>
      @endforeach
      </tbody>
    </table>
    @endif
  </div>
</div>

<div style="background:#f9fafb;border:1px solid var(--border);border-radius:8px;padding:14px 18px;margin-top:16px;font-size:12px;color:var(--text-muted)">
  💡 Les fichiers de sauvegarde sont conservés sur le serveur, hors du dossier public, séparément pour chaque université.<br>
  🔒 Recommandation : téléchargez régulièrement vos sauvegardes et stockez-les sur un support externe.
</div>
@endsection
