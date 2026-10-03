{{-- Dossier soumis — portage de candidature/pages/succes.php --}}
@extends('layouts.candidature', ['titre' => 'Dossier soumis', 'largeur' => '900px'])

@section('contenu')
@php $d = $dossier; $quitus = route('candidature.quitus', ['id' => $d->id]); @endphp
<div style="text-align:center;padding:40px 20px 32px;background:#fff;border-radius:var(--r16);border:1px solid var(--gris-br);box-shadow:var(--ombre);margin-bottom:24px">
  <div style="width:72px;height:72px;background:#dcfce7;border-radius:50%;display:flex;align-items:center;justify-content:center;font-size:36px;margin:0 auto 16px">✅</div>
  <h1 style="font-family:'Playfair Display',serif;font-size:28px;font-weight:700;color:var(--gris-f);margin-bottom:8px">Dossier soumis avec succès !</h1>
  <p style="color:var(--gris-m);font-size:15px;margin-bottom:20px">N° Dossier : <strong style="color:var(--vert);font-family:monospace;font-size:16px">{{ $d->numero_dossier }}</strong></p>
  <a href="{{ $quitus }}" target="_blank" rel="noopener" class="pbtn pbtn-primary pbtn-lg" style="display:inline-flex;margin:0 auto">📄 Télécharger mon Quitus de préinscription</a>
</div>

<div style="display:grid;grid-template-columns:repeat(auto-fit,minmax(300px,1fr));gap:20px;margin-bottom:24px">
  <div class="pcard">
    <div class="pcard-header"><h2>👤 Informations</h2></div>
    <div class="pcard-body">
      <div style="display:grid;grid-template-columns:1fr 1fr;gap:10px;margin-bottom:16px">
        @foreach (['Nom complet' => $d->prenom.' '.$d->nom, 'N° Dossier' => $d->numero_dossier, 'Email' => $d->email ?: '—', 'Téléphone' => $d->telephone ?: '—'] as $l => $v)
        <div style="background:var(--gris-b);border-radius:var(--r8);padding:10px;text-align:center"><div style="font-size:10px;color:var(--gris-m);font-weight:600;text-transform:uppercase;margin-bottom:4px">{{ $l }}</div><div style="font-weight:700;font-size:13px;color:var(--gris-f)">{{ $v }}</div></div>
        @endforeach
      </div>
      @foreach (['ch1_lib' => ['1er choix', 'var(--vert)', 'var(--vert-l)'], 'ch2_lib' => ['2e choix', 'var(--or)', 'var(--or-l)'], 'ch3_lib' => ['3e choix', '#dc2626', '#fee2e2']] as $k => [$lbl, $fg, $bg])
      @if ($d->$k)<div style="background:{{ $bg }};border-left:3px solid {{ $fg }};border-radius:6px;padding:10px 14px;margin-bottom:8px"><div style="font-size:10px;font-weight:700;color:{{ $fg }};text-transform:uppercase">{{ $lbl }}</div><div style="font-weight:600;font-size:13px;margin-top:3px">{{ $d->$k }}</div></div>@endif
      @endforeach
    </div>
  </div>
  <div class="pcard">
    <div class="pcard-header"><h2>📎 Documents déposés</h2></div>
    <div class="pcard-body" style="padding:16px">
      @foreach (\App\Services\Candidatures::PIECES as $type => [$col, $ic, $lbl])
      @if ($d->$col)
      <div style="display:flex;align-items:center;gap:10px;padding:10px 12px;background:var(--gris-b);border-radius:var(--r8);margin-bottom:8px">
        <span style="font-size:20px">{{ $ic }}</span>
        <div style="flex:1;min-width:0"><div style="font-size:12px;font-weight:600;color:var(--gris-f)">{{ $lbl }}</div><div style="font-size:10px;color:var(--gris-m)">{{ strtoupper(pathinfo($d->$col, PATHINFO_EXTENSION)) }}</div></div>
        <a href="{{ route('candidature.piece', ['id' => $d->id, 'type' => $type]) }}" target="_blank" rel="noopener" class="pbtn pbtn-primary pbtn-sm" aria-label="Ouvrir {{ $lbl }}">⬇️</a>
      </div>
      @endif
      @endforeach
    </div>
  </div>
</div>

<div class="pcard" style="margin-bottom:24px">
  <div class="pcard-header"><h2>🎯 Prochaines étapes</h2></div>
  <div class="pcard-body"><div style="display:grid;grid-template-columns:repeat(auto-fit,minmax(160px,1fr));gap:16px">
    @foreach ([['1', '📄', 'Téléchargez', 'Téléchargez et imprimez votre quitus de préinscription'], ['2', '🖨️', 'Imprimez', 'Imprimez également tous vos documents justificatifs'],
               ['3', '🏛️', 'Déposez', 'Déposez votre dossier physique au Service Central de la Scolarité'], ['4', '✅', 'Confirmation', "Recevez la confirmation d'acceptation de votre dossier"]] as [$n, $ic, $t, $texte])
    <div style="text-align:center;padding:16px 8px">
      <div style="width:40px;height:40px;border-radius:50%;background:var(--vert);color:#fff;font-weight:700;font-size:16px;display:flex;align-items:center;justify-content:center;margin:0 auto 10px">{{ $n }}</div>
      <div style="font-size:20px;margin-bottom:6px">{{ $ic }}</div><div style="font-weight:700;font-size:13px;color:var(--gris-f);margin-bottom:4px">{{ $t }}</div><div style="font-size:11px;color:var(--gris-m);line-height:1.5">{{ $texte }}</div>
    </div>
    @endforeach
  </div></div>
</div>

<div style="display:flex;gap:12px;flex-wrap:wrap;justify-content:center">
  <a href="{{ $quitus }}" target="_blank" rel="noopener" class="pbtn pbtn-primary pbtn-lg">📄 Imprimer / Télécharger mon Quitus</a>
  <a href="{{ route('candidature.dossiers') }}" class="pbtn pbtn-secondary pbtn-lg">📋 Voir mes dossiers</a>
  <a href="{{ route('candidature.tableau') }}" class="pbtn pbtn-secondary pbtn-lg">🏠 Tableau de bord</a>
</div>
@endsection
