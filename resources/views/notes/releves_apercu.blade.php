{{-- Aperçu d'un relevé — portage de releves.php (et_id) --}}
@extends('layouts.app', ['titre' => 'Relevés de notes individuels', 'fil' => 'Rapports'])

@section('contenu')
@php
    $couleur = $sr->defaillant ? '#b71c1c' : ($sr->valide ? '#1b5e20' : '#e65100');
@endphp
<div class="no-print flex gap-8 mb-16">
  <a href="{{ route('releves', ['pv_id' => $pv->id]) }}" class="btn btn-secondary">← Retour à la liste</a>
  <button type="button" data-print="1" class="btn btn-primary">🖨️ Imprimer le relevé</button>
  <a href="{{ route('releve_notes', ['pv_id' => $pv->id, 'et_id' => $et->id]) }}" target="_blank" rel="noopener" class="btn btn-secondary">📋 Format officiel</a>
</div>

<div class="card" id="releve-print">
  <div style="padding:24px">
    <div style="text-align:center;border-bottom:2px solid #000;padding-bottom:12px;margin-bottom:16px">
      <div style="font-weight:bold;font-size:13px;letter-spacing:1px">{{ $republique }}</div>
      <div style="font-size:12px">{{ $institution }}</div>
      <div style="font-size:11px;color:var(--text-muted)">{{ $faculte }}</div>
      <div style="font-size:20px;font-weight:700;margin:10px 0 4px">RELEVÉ DE NOTES</div>
      <div style="font-size:13px">{{ mb_strtoupper($pv->sem_libelle) }} — {{ \App\Support\Format::session($pv->type_session) }}</div>
      <div style="font-size:12px;color:var(--text-muted)">Année Académique {{ $pv->annee }}</div>
    </div>

    <div style="display:grid;grid-template-columns:1fr 1fr;gap:16px;margin-bottom:20px;padding:12px;background:var(--gray-50);border-radius:8px">
      <div>
        <div style="font-size:11px;color:var(--text-muted)">Nom &amp; Prénoms</div>
        <div style="font-weight:700;font-size:16px">{{ $et->nom }} {{ $et->prenoms }}</div>
      </div>
      <div>
        <div style="font-size:11px;color:var(--text-muted)">Numéro étudiant</div>
        <div style="font-weight:600;font-size:14px">{{ $et->numero }}</div>
      </div>
      <div>
        <div style="font-size:11px;color:var(--text-muted)">Né(e) le</div>
        <div>{{ \App\Support\Format::dateFR($et->date_naissance) }} à {{ $et->lieu_naissance ?? '—' }}</div>
      </div>
      <div>
        <div style="font-size:11px;color:var(--text-muted)">Filière / Niveau</div>
        <div>{{ $pv->niveau_libelle }} — {{ $pv->filiere }}</div>
      </div>
    </div>

    @foreach ($lignes as $l)
    <div style="margin-bottom:16px">
      <div style="background:var(--blue-900);color:#fff;padding:6px 12px;border-radius:6px 6px 0 0;font-size:13px;font-weight:600">
        {{ $l['ue']->code }} — {{ $l['ue']->libelle }} ({{ $l['ue']->credits_total }} crédits)
      </div>
      <table style="width:100%;border-collapse:collapse;font-size:13px">
        <thead style="background:var(--blue-50)">
          <tr>
            <th style="padding:6px 10px;text-align:left;border:1px solid var(--border)">Matière</th>
            <th style="padding:6px;text-align:center;border:1px solid var(--border)">Examen</th>
            <th style="padding:6px;text-align:center;border:1px solid var(--border)">CC</th>
            <th style="padding:6px;text-align:center;border:1px solid var(--border)">Total</th>
            <th style="padding:6px;text-align:center;border:1px solid var(--border)">Moy./20</th>
            <th style="padding:6px;text-align:center;border:1px solid var(--border)">Crédits</th>
            <th style="padding:6px;text-align:center;border:1px solid var(--border)">Obs.</th>
          </tr>
        </thead>
        <tbody>
        @foreach ($l['matieres'] as $ligne)
        @php
            $m = $ligne['m'];
            $n = $ligne['n'];
            $def = $ligne['obs'] === 'Déf.';
            $acquis = in_array($ligne['obs'], ['AV', 'AC'], true);
            $teinte = $def ? '#b71c1c' : ($acquis ? '#1b5e20' : '#e65100');
        @endphp
        <tr style="{{ $def ? 'background:#FFEBEE' : ($acquis ? 'background:#E8F5E9' : '') }}">
          <td style="padding:6px 10px;border:1px solid var(--border)">{{ $m->libelle }}</td>
          <td style="padding:6px;text-align:center;border:1px solid var(--border)">
            {{ ! $def && $n && $n->note_examen !== null ? number_format((float) $n->note_examen, 2).' / '.$m->note_max_examen : '—' }}
          </td>
          <td style="padding:6px;text-align:center;border:1px solid var(--border)">
            {{ $m->note_max_cc > 0 && ! $def && $n && $n->note_cc !== null ? number_format((float) $n->note_cc, 2).' / '.$m->note_max_cc : '—' }}
          </td>
          <td style="padding:6px;text-align:center;border:1px solid var(--border);font-weight:600">
            @if ($def)<em style="color:#b71c1c">Défaillant</em>@else{{ $ligne['finale'] !== null ? number_format((float) $ligne['finale'], 2).' / '.$m->note_max_totale : '—' }}@endif
          </td>
          <td style="padding:6px;text-align:center;border:1px solid var(--border);font-weight:700;color:{{ $teinte }}">
            {{ $ligne['moy20'] !== null ? number_format($ligne['moy20'], 2) : '—' }}
          </td>
          <td style="padding:6px;text-align:center;border:1px solid var(--border)">{{ $m->credits }}</td>
          <td style="padding:6px;text-align:center;border:1px solid var(--border);font-size:11px;font-weight:600;color:{{ $teinte }}">{{ $ligne['obs'] }}</td>
        </tr>
        @endforeach
        </tbody>
      </table>
    </div>
    @endforeach

    <div style="background:{{ $sr->defaillant ? '#FFEBEE' : ($sr->valide ? '#E8F5E9' : '#FFF3E0') }};border:2px solid {{ $couleur }};border-radius:8px;padding:16px 20px;display:grid;grid-template-columns:repeat(4,1fr);gap:12px;margin-top:8px">
      <div style="text-align:center">
        <div style="font-size:11px;color:var(--text-muted)">Moyenne générale</div>
        <div style="font-size:22px;font-weight:700;color:{{ $couleur }}">{{ ! $sr->defaillant && $sr->moyenne_generale !== null ? number_format((float) $sr->moyenne_generale, 2) : '—' }}/20</div>
      </div>
      <div style="text-align:center">
        <div style="font-size:11px;color:var(--text-muted)">Crédits obtenus</div>
        <div style="font-size:18px;font-weight:700">{{ $sr->credits_obtenus }} / {{ $sr->credits_total }}</div>
      </div>
      <div style="text-align:center">
        <div style="font-size:11px;color:var(--text-muted)">Décision</div>
        <div style="font-size:16px;font-weight:700;color:{{ $couleur }}">{{ $sr->decision ?? '—' }}</div>
      </div>
      <div style="text-align:center">
        <div style="font-size:11px;color:var(--text-muted)">Mention</div>
        <div style="font-size:16px;font-weight:700">{{ $sr->mention ?? '—' }}</div>
      </div>
    </div>

    <div style="text-align:right;margin-top:32px;font-style:italic;font-size:12px;color:var(--text-muted)">
      {{ $ville }}, le {{ date('d/m/Y') }}<br>
      <span style="display:inline-block;margin-top:32px">Le Président du Jury</span>
    </div>
  </div>
</div>
@endsection
