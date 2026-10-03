{{-- Tarifs d'inscription par niveau — portage de tarifs_niveaux.php --}}
@extends('layouts.app', ['titre' => "Tarifs d'inscription par niveau", 'fil' => 'Administration › Tarifs'])

@section('contenu')
<div class="card" style="max-width:720px">
  <div class="card-header">
    <span class="card-title">💰 Montant de l'inscription académique en ligne par niveau</span>
  </div>
  <div class="card-body">
    <div class="alert alert-info" style="font-size:13px;margin-bottom:16px">
      ℹ️ Ce montant est appliqué automatiquement lors de l'inscription académique en ligne (portail candidats),
      selon le niveau dans lequel l'étudiant s'inscrit (L1, L2, L3, M1, M2...). Il vaut pour toute l'université.
    </div>

    @unless ($peutModifier)
    <div class="alert alert-warning" style="font-size:13px">🔒 Vous n'avez pas les droits pour modifier les tarifs. Affichage en lecture seule.</div>
    @endunless

    <form method="post" action="{{ route('tarifs_niveaux.enregistrer') }}">
      @csrf
      <table class="table" style="width:100%">
        <thead><tr><th>Niveau</th><th>Libellé</th><th style="text-align:right;width:220px">Montant (FCFA)</th></tr></thead>
        <tbody>
        @foreach ($niveaux as $n)
        <tr>
          <td><strong>{{ $n->code }}</strong></td>
          <td style="color:var(--text-muted)">{{ $n->libelle }}</td>
          <td style="text-align:right">
            <input type="number" name="montant[{{ $n->id }}]" value="{{ (int) $n->montant }}" min="0" max="{{ \App\Services\Paiements::MONTANT_MAX }}" step="any"
                   class="form-control form-control-sm" style="text-align:right;max-width:180px;margin-left:auto" aria-label="Montant {{ $n->code }}" @disabled(! $peutModifier)>
          </td>
        </tr>
        @endforeach
        </tbody>
      </table>
      @if ($peutModifier)
      <div style="margin-top:16px;text-align:right"><button type="submit" class="btn btn-primary">💾 Enregistrer les tarifs</button></div>
      @endif
    </form>
  </div>
</div>
@endsection
