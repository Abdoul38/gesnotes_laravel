{{-- Départements par faculté, pour la cascade du formulaire (lu par enseignants.js) --}}
<script type="application/json" id="donnees-enseignants">@json($departements->groupBy('faculte_id'))</script>
