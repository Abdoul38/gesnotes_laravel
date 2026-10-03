<option value="0" @selected($filtre === 0)>Toutes les années</option>
@foreach ($annees as $a)
<option value="{{ $a->id }}" @selected((int) $a->id === $filtre)>{{ $a->libelle }}{{ $a->active ? ' (active)' : '' }}</option>
@endforeach
