{{-- Formulaire de copie des UE depuis une autre promotion (même filière et niveau) --}}
<form method="post" action="{{ route('ue.copier') }}" style="margin:0;display:flex;gap:8px;align-items:center;flex-wrap:wrap">
  @csrf
  <input type="hidden" name="promo_id" value="{{ $selPromo }}">
  <input type="hidden" name="tab" value="structure">
  <select name="source_promo_id" class="form-control form-control-sm" style="flex:1;min-width:220px" aria-label="Promotion source">
    <option value="">— Année précédente (automatique) —</option>
    @foreach ($sources as $ps)
      <option value="{{ $ps->id }}">{{ $ps->filiere }} {{ $ps->niveau }} — {{ $ps->annee }} ({{ $ps->nb_ue }} UE)</option>
    @endforeach
  </select>
  @if ($avecForcer)
    <label style="font-size:12px;display:flex;align-items:center;gap:4px;white-space:nowrap">
      <input type="checkbox" name="forcer_copie" value="1"> Forcer (écraser UE existantes)
    </label>
    <button type="submit" class="btn btn-warning btn-sm" data-confirm-click="⚠️ Attention : cela va SUPPRIMER toutes les UE actuelles de cette promotion et les remplacer. Continuer ?">📋 Copier les UE</button>
  @else
    <button type="submit" class="btn btn-primary btn-sm" data-confirm-click="Copier les UE et matières depuis la promotion sélectionnée ?">📋 Copier les UE</button>
  @endif
</form>
