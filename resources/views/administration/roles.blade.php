{{-- Rôles & permissions — portage de pages/comptes/roles.php --}}
@extends('layouts.app', ['titre' => 'Gestion des rôles', 'fil' => 'Admin › Rôles'])

@use('App\Http\Controllers\Administration\RolesController', 'RC')

@section('contenu')
@php [$ic, $lb, $bg, $fg] = RC::ROLES[$roleActif]; $nbUsers = $nbParRole[$roleActif] ?? 0; @endphp
<div style="display:grid;grid-template-columns:240px minmax(0,1fr);gap:20px" class="grille-admin">
  <div>
    <div class="card">
      <div class="card-header"><span class="card-title">🎭 Rôles</span></div>
      <div class="card-body" style="padding:8px">
        @foreach (RC::ROLES as $r => [$ric, $rlb, $rbg, $rfg])
        <a href="{{ route('roles', ['role' => $r]) }}" style="display:flex;align-items:center;justify-content:space-between;gap:10px;padding:10px 14px;border-radius:8px;text-decoration:none;margin-bottom:4px;background:{{ $r === $roleActif ? $rbg : 'transparent' }};border:1.5px solid {{ $r === $roleActif ? $rfg : 'transparent' }}">
          <div style="display:flex;align-items:center;gap:8px"><span style="font-size:16px">{{ $ric }}</span>
            <div><div style="font-weight:700;font-size:13px;color:{{ $r === $roleActif ? $rfg : 'var(--dark)' }}">{{ $rlb }}</div>
              <div style="font-size:10px;color:var(--text-muted)">{{ count($parRole[$r] ?? []) }} permission(s)</div></div></div>
          <span style="background:{{ $rfg }}20;color:{{ $rfg }};padding:2px 8px;border-radius:10px;font-size:11px;font-weight:700">{{ $nbParRole[$r] ?? 0 }} user</span>
        </a>
        @endforeach
      </div>
    </div>
    <div class="card mt-16" style="background:var(--blue-50);border-color:var(--blue-200)">
      <div class="card-body" style="font-size:12px;color:var(--blue-700);line-height:1.6">
        <strong>💡 Comment ça marche :</strong><br>Les permissions définies ici s'appliquent à <strong>tous les utilisateurs</strong> ayant ce rôle.<br><br>Le rôle <strong>Admin</strong> a toujours accès à tout.
      </div>
    </div>
  </div>

  <div class="card">
    <div class="card-header" style="background:{{ $bg }};border-color:{{ $fg }}20">
      <div>
        <span class="card-title" style="color:{{ $fg }}">{{ $ic }} Permissions — {{ $lb }}</span>
        <div style="font-size:12px;color:{{ $fg }}99;margin-top:2px">{{ count($actuelles) }} permission(s) active(s) · {{ $nbUsers }} utilisateur(s)@if ($roleActif === 'admin') · <em>Le rôle Admin a toujours toutes les permissions</em>@endif</div>
      </div>
      @if ($roleActif !== 'admin')
      <div style="display:flex;gap:8px">
        <button type="button" data-tout-cocher="1" style="background:#fff;color:{{ $fg }};border:1px solid {{ $fg }};padding:5px 12px;border-radius:6px;font-size:12px;font-weight:600;cursor:pointer">✅ Tout cocher</button>
        <button type="button" data-tout-cocher="0" style="background:#fff;color:var(--text-muted);border:1px solid var(--border);padding:5px 12px;border-radius:6px;font-size:12px;cursor:pointer">☐ Tout décocher</button>
      </div>
      @endif
    </div>

    @if ($roleActif === 'admin')
    <div class="card-body" style="text-align:center;padding:40px;color:var(--text-muted)">
      <div style="font-size:40px;margin-bottom:12px">🔴</div>
      <div style="font-size:15px;font-weight:700;color:var(--dark)">L'Admin a toutes les permissions</div>
      <div style="font-size:13px;margin-top:8px">Le rôle Administrateur ne peut pas être restreint.</div>
    </div>
    @else
    <form method="post" action="{{ route('roles.enregistrer') }}" id="form-roles" data-fond="{{ $bg }}" data-couleur="{{ $fg }}">
      @csrf
      <input type="hidden" name="role" value="{{ $roleActif }}">
      <div class="card-body">
        @php $pct = $nbPermissions > 0 ? round(count($actuelles) / $nbPermissions * 100) : 0; @endphp
        <div style="margin-bottom:20px">
          <div style="display:flex;justify-content:space-between;font-size:12px;margin-bottom:6px"><span style="color:var(--text-muted)">Niveau d'accès</span><span style="font-weight:700;color:{{ $fg }}" data-pourcentage>{{ $pct }}%</span></div>
          <div style="background:var(--gray-100);border-radius:10px;height:8px"><div data-barre style="background:{{ $fg }};height:8px;border-radius:10px;width:{{ $pct }}%;transition:.5s"></div></div>
        </div>
        @foreach ($parCategorie as $categorie => $perms)
        @php $nbActifs = $perms->filter(fn ($p) => in_array($p->code, $actuelles, true))->count(); @endphp
        <div style="margin-bottom:20px">
          <div style="display:flex;align-items:center;justify-content:space-between;margin-bottom:10px">
            <div style="font-size:11px;font-weight:700;color:var(--text-muted);text-transform:uppercase;letter-spacing:.5px">{{ $categorie }}</div>
            <span style="font-size:10px;background:{{ $nbActifs > 0 ? $bg : 'var(--gray-100)' }};color:{{ $nbActifs > 0 ? $fg : 'var(--text-muted)' }};padding:1px 8px;border-radius:8px;font-weight:700">{{ $nbActifs }}/{{ count($perms) }}</span>
          </div>
          <div style="display:grid;grid-template-columns:repeat(auto-fill,minmax(200px,1fr));gap:8px">
            @foreach ($perms as $perm)
            @php $a = in_array($perm->code, $actuelles, true); @endphp
            <label style="display:flex;align-items:flex-start;gap:8px;padding:10px 12px;border-radius:8px;cursor:pointer;border:1.5px solid {{ $a ? $fg : 'var(--border)' }};background:{{ $a ? $bg : '#fff' }};transition:.2s">
              <input type="checkbox" name="permissions[]" value="{{ $perm->code }}" @checked($a) style="width:16px;height:16px;accent-color:{{ $fg }};margin-top:1px;flex-shrink:0">
              <div><div style="font-weight:600;font-size:12px;color:{{ $a ? $fg : 'var(--dark)' }}" class="perm-text">{{ $perm->libelle }}</div>
                @if ($perm->description)<div style="font-size:10px;color:var(--text-muted);margin-top:2px;line-height:1.4">{{ $perm->description }}</div>@endif</div>
            </label>
            @endforeach
          </div>
        </div>
        @endforeach
      </div>
      <div class="card-footer" style="display:flex;justify-content:space-between;align-items:center;gap:10px;flex-wrap:wrap">
        <div style="font-size:12px;color:var(--text-muted)">⚠️ Ces permissions s'appliquent à tous les <strong>{{ $nbUsers }}</strong> utilisateur(s) avec le rôle <strong>{{ $lb }}</strong></div>
        <button type="submit" class="btn btn-primary" style="padding:10px 24px">💾 Enregistrer les permissions</button>
      </div>
    </form>
    @endif
  </div>
</div>
@endsection

@push('scripts')
<script src="{{ \App\Support\Ressource::url('assets/js/roles.js') }}"></script>
@endpush
