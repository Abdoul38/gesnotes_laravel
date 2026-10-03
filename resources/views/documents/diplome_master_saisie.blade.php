{{-- Diplôme de Master : saisie manuelle (Master antérieur au logiciel) — portage de diplome.php --}}
@extends('layouts.app', ['titre' => 'Diplôme de Master', 'fil' => 'Diplômes › Saisie manuelle'])

@section('contenu')
<div class="card" style="max-width:600px;margin:0 auto">
  <div class="card-header"><span class="card-title">🎓 Informations du diplôme de Master</span></div>
  <div class="card-body">
    @if ($existant)
    <div class="alert alert-info" style="margin-bottom:16px;font-size:12px">
      💡 Diplôme déjà enregistré sous le N° <strong>{{ $existant->numero }}</strong> pour <strong>{{ $et->nom }} {{ $et->prenoms }}</strong>.
      Ses informations ne peuvent plus être modifiées : seuls le type de Master et les crédits affichés sont demandés pour la réimpression.
    </div>
    <div style="font-size:13px;line-height:1.8;margin-bottom:12px">
      Domaine : <strong>{{ $existant->domaine }}</strong><br>
      Mention : <strong>{{ $existant->mention_academique }}</strong><br>
      Spécialité : <strong>{{ $existant->specialite }}</strong><br>
      Session : <strong>{{ $existant->session_libelle }}</strong> · Mention obtenue : <strong>{{ $existant->mention_grade }}</strong>
    </div>
    @else
    <div class="alert alert-info" style="margin-bottom:16px;font-size:12px">
      💡 Aucun semestre de Master (M1+M2) n'a été trouvé validé pour <strong>{{ $et->nom }} {{ $et->prenoms }}</strong> dans le système — merci de renseigner ces informations manuellement, une seule fois.
      Elles ne pourront plus être modifiées ensuite.
    </div>
    @endif
    <form method="post" action="{{ route('diplome.master') }}" data-confirm="{{ $existant ? 'Réimprimer ce diplôme ?' : 'Enregistrer ce diplôme ? Ses informations ne pourront plus être modifiées.' }}">
      @csrf
      <input type="hidden" name="et_id" value="{{ $et->id }}">
      @unless ($existant)
      <div class="form-group"><label class="form-label" for="domaine">Domaine *</label>
        <input type="text" name="domaine" id="domaine" class="form-control" required maxlength="150" value="{{ old('domaine') }}" placeholder="Ex: Sciences et Technologies"></div>
      <div class="form-group"><label class="form-label" for="mention_master">Mention (filière du Master) *</label>
        <input type="text" name="mention_master" id="mention_master" class="form-control" required maxlength="150" value="{{ old('mention_master') }}" placeholder="Ex: Informatique et Gestion"></div>
      <div class="form-group"><label class="form-label" for="specialite">Spécialité *</label>
        <input type="text" name="specialite" id="specialite" class="form-control" required maxlength="200" value="{{ old('specialite') }}" placeholder="Ex: Informatique appliquée à la gestion"></div>
      @endunless
      <div class="form-grid form-grid-2">
        <div class="form-group"><label class="form-label" for="type_master">Type de Master *</label>
          <select name="type_master" id="type_master" class="form-control" required>
            @foreach (['Professionnel', 'Recherche'] as $t)<option value="{{ $t }}" @selected(old('type_master') === $t)>{{ $t }}</option>@endforeach
          </select></div>
        <div class="form-group"><label class="form-label" for="credits">Crédits capitalisés *</label>
          <input type="number" name="credits" id="credits" class="form-control" min="1" max="300" value="{{ old('credits', 120) }}" required></div>
        @unless ($existant)
        <div class="form-group"><label class="form-label" for="session">Session (mois année) *</label>
          <input type="text" name="session" id="session" class="form-control" required maxlength="100" value="{{ old('session') }}" placeholder="Ex: Juillet 2022"></div>
        <div class="form-group"><label class="form-label" for="mention_grade">Mention obtenue *</label>
          <select name="mention_grade" id="mention_grade" class="form-control" required>
            @foreach ($mentions as $m)<option value="{{ $m }}" @selected(old('mention_grade', 'Bien') === $m)>{{ $m }}</option>@endforeach
          </select></div>
        @endunless
      </div>
      <button type="submit" class="btn btn-primary">Continuer vers le diplôme →</button>
    </form>
  </div>
</div>
@endsection
