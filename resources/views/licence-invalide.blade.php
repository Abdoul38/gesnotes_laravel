{{-- Licence invalide ou expirée — repris de l'ancien index.php --}}
@extends('layouts.app', ['titre' => 'Licence invalide'])

@section('contenu')
<div style="display:flex;align-items:center;justify-content:center;min-height:100vh;background:#f5f5f5;font-family:Georgia,serif">
  <div style="max-width:480px;text-align:center;background:#fff;padding:40px;border-radius:12px;box-shadow:0 4px 20px rgba(0,0,0,.1)">
    <div style="font-size:48px">🔒</div>
    <h2 style="color:#991b1b;margin:16px 0 8px">Accès temporairement indisponible</h2>
    <p style="color:#6b7280">{{ $erreur }}</p>
    @if ($lienLicence)
      <p style="margin-top:16px"><a href="{{ $lienLicence }}" class="btn btn-primary">🔑 Activer une licence</a></p>
    @else
      <p style="color:#6b7280;font-size:13px;margin-top:12px">Merci de contacter l'administrateur de votre établissement.</p>
    @endif
  </div>
</div>
@endsection
