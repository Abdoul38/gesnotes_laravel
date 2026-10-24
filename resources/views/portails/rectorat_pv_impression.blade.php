{{-- PV complet ou liste des admis, imprimables (remplace l'export html2pdf de rectorat.php) --}}
@php
  $sessions = \App\Http\Controllers\Portails\RectoratController::SESSIONS;
  $total = $resultats->count();
  $taux = $total > 0 ? round($admis / $total * 100, 1) : 0;
  $lignes = $type === 'admis' ? $resultats->where('valide', 1) : $resultats;
@endphp
<!DOCTYPE html>
<html lang="fr">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>{{ $type === 'admis' ? 'Admis' : 'PV' }} — {{ $pv->filiere }} {{ $pv->niveau }} {{ $pv->sem_libelle }}</title>
<style>
* { box-sizing: border-box; margin: 0; padding: 0; -webkit-print-color-adjust: exact; print-color-adjust: exact; }
body { font-family: Arial, sans-serif; color: #111; background: #e5e7eb; }
.no-print { background: #1A3A5C; padding: 12px 20px; display: flex; gap: 12px; align-items: center; }
.no-print button { background: #fff; color: #1A3A5C; border: none; padding: 8px 18px; border-radius: 6px; font-weight: 700; cursor: pointer; }
.no-print a { color: #fff; font-size: 13px; text-decoration: none; }
.page { width: 210mm; min-height: 297mm; margin: 20px auto; background: #fff; padding: 15mm; }
.infos { width: 100%; font-size: 12px; margin: 16px 0; }
.infos td { padding: 3px 0; border: none; text-align: left; }
table.res { width: 100%; border-collapse: collapse; font-size: 10px; }
.res th { background: {{ $type === 'admis' ? '#166534' : '#1A3A5C' }}; color: #fff; border: 1px solid #999; padding: 4px; }
.res td { border: 1px solid #999; padding: 3px; text-align: center; }
.res td.g { text-align: left; }
@media print { body { background: #fff; } .no-print { display: none; } .page { margin: 0; } @page { size: A4 portrait; margin: 0; } }
</style>
</head>
<body>
<div class="no-print">
  <button type="button" data-print="1">🖨️ Imprimer / Enregistrer PDF</button>
  <a href="#" data-window-close="1">✕ Fermer</a>
</div>
<div class="page">
  @isset($entete)
  {{-- Extrait depuis le tableau de bord national du ministère --}}
  <div style="text-align:center;margin-bottom:14px">
    <div style="font-weight:700;font-size:13px">MINISTÈRE DE L'ENSEIGNEMENT SUPÉRIEUR, DE LA RECHERCHE ET DE L'INNOVATION</div>
    <div style="font-size:11px;margin-top:2px">République du Niger — {{ $entete }}</div>
  </div>
  @endisset
  <div style="text-align:center;font-weight:700;font-size:16px;text-decoration:underline">{{ $type === 'admis' ? 'LISTE DES ÉTUDIANTS ADMIS' : 'PROCÈS-VERBAL DE DÉLIBÉRATION' }}</div>
  <table class="infos">
    <tr><td><strong>Faculté :</strong> {{ $pv->faculte ?: '—' }}</td><td><strong>Filière :</strong> {{ $pv->filiere }}</td></tr>
    <tr><td><strong>Niveau :</strong> {{ $pv->niveau_libelle }}</td><td><strong>Semestre :</strong> {{ $pv->sem_libelle }}</td></tr>
    <tr><td><strong>Session :</strong> {{ $sessions[$pv->type_session] ?? $pv->type_session }}</td><td><strong>Année académique :</strong> {{ $pv->annee }}</td></tr>
    <tr><td colspan="2">@if ($type === 'admis')<strong>Admis :</strong> {{ $admis }} / {{ $total }} ({{ $taux }}%)@else<strong>Effectif :</strong> {{ $total }} étudiant(s) ({{ $admis }} admis — {{ $taux }}%)@endif</td></tr>
  </table>
  <table class="res">
    <thead><tr><th>N°</th><th>Nom</th><th>Prénoms</th>@if ($type === 'global')<th>Crédits</th>@endif<th>Moyenne</th>@if ($type === 'global')<th>Décision</th>@endif<th>Mention</th></tr></thead>
    <tbody>
    @foreach ($lignes as $r)
    <tr><td>{{ $r->numero }}</td><td class="g">{{ $r->nom }}</td><td class="g">{{ $r->prenoms }}</td>
      @if ($type === 'global')<td>{{ $r->credits_obtenus ?? '—' }}</td>@endif
      <td>{{ $r->moyenne_generale !== null ? number_format($r->moyenne_generale, 2) : '—' }}</td>
      @if ($type === 'global')<td>{{ $r->decision ?? '—' }}</td>@endif
      <td>{{ $r->mention ?? '—' }}</td></tr>
    @endforeach
    </tbody>
  </table>
  @if ($type === 'global')
  <div style="display:flex;justify-content:space-between;margin-top:50px;font-size:12px;text-align:center">
    <div>Le Président du Jury<br><br><br>______________________</div>
    <div>Le Doyen / Directeur<br><br><br>______________________</div>
  </div>
  @endif
  @isset($entete)<div style="margin-top:30px;font-size:9px;color:#6b7280;text-align:center">Document extrait du tableau de bord national MESR — consultation en lecture seule</div>@endisset
</div>
<script src="{{ \App\Support\Ressource::url('assets/js/impression.js') }}"></script>
</body>
</html>
