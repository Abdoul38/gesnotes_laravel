{{-- Coins ornementaux des attestations (repris des anciennes pages) --}}
@foreach (['tl', 'tr', 'bl', 'br'] as $coin)
<div class="corner {{ $coin }}"><svg viewBox="0 0 40 40"><path d="M0,20 L0,4 L4,4 L4,20 L20,20 L20,4" stroke="#B8860B" stroke-width="1" fill="none" opacity="0.7"/><path d="M0,14 L0,0 L14,0" stroke="#B8860B" stroke-width="2" fill="none"/></svg></div>
@endforeach
