{{-- Résumé des envois (e-mail ou SMS) --}}
<div class="card">
  <div class="card-header"><span class="card-title">📊 Résumé</span></div>
  <div class="card-body" style="display:flex;flex-direction:column;gap:10px">
    <div style="display:flex;justify-content:space-between"><span style="color:var(--text-muted)">✅ Envoyés</span><strong style="color:var(--green-700)">{{ (int) ($stats->envoyes ?? 0) }}</strong></div>
    <div style="display:flex;justify-content:space-between"><span style="color:var(--text-muted)">❌ Échecs</span><strong style="color:var(--red-600)">{{ (int) ($stats->echecs ?? 0) }}</strong></div>
    <div style="display:flex;justify-content:space-between"><span style="color:var(--text-muted)">🧪 Simulés</span><strong style="color:var(--amber-600)">{{ (int) ($stats->simules ?? 0) }}</strong></div>
    <div style="border-top:1px solid var(--border-color,#e5e5e5);padding-top:8px;margin-top:4px">
      <span style="font-size:12px;color:var(--text-muted)">Statut :
        @if ($actif)<strong style="color:var(--green-700)">Actif</strong>@else<strong style="color:var(--amber-600)">Mode simulation</strong>@endif
      </span>
    </div>
  </div>
</div>
