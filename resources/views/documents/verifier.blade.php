{{-- Vérification publique d'un document — portage de verifier_diplome.php --}}
@php
    $typesAttestation = ['validation_l2' => 'Attestation de validation (4 sem.)', 'licence' => 'Attestation de licence (6 sem.)',
        'master1' => 'Attestation de M1 validé', 'master_complet' => 'Attestation de Master (diplôme)'];
    $niveaux = ['dut' => 'DUT', 'licence' => 'Licence', 'master' => 'Master'];
    $r = $resultat;
    $lignes = [];
    if ($r) {
        $lignes['Titulaire'] = $r->nom.' '.$r->prenoms;
        switch ($type) {
            case 'diplome':
                $lignes += ['Document' => 'Diplôme de '.($niveaux[$r->niveau] ?? $r->niveau), 'Spécialité' => $r->specialite,
                    'Mention' => $r->mention_grade, 'Session' => $r->session_libelle];
                break;
            case 'attestation':
                $lignes += ['Document' => $typesAttestation[$r->type] ?? $r->type, 'Filière' => $r->filiere,
                    'Mention' => $r->mention_grade, 'Année académique' => $r->annee_academique];
                break;
            case 'releve':
                $lignes += ['Document' => 'Relevé de notes', 'Semestre' => $r->semestre, 'Mention' => $r->mention_grade,
                    'Année académique' => $r->annee_academique];
                break;
            case 'certificat':
                $lignes += ['Document' => 'Certificat de scolarité', 'Filière' => $r->filiere, 'Niveau' => $r->niveau,
                    'Année académique' => $r->annee_academique];
                break;
            case 'carte':
                $lignes += ['Document' => "Carte d'étudiant", 'Filière' => $r->filiere, 'Année académique' => $r->annee_academique];
                break;
        }
        $lignes["N° d'enregistrement"] = $r->numero;
        $lignes = array_filter($lignes, fn ($v) => $v !== null && $v !== '');
    }
@endphp
<!DOCTYPE html>
<html lang="fr">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<meta name="robots" content="noindex, nofollow">
<title>Vérification de diplôme — {{ $institution }}</title>
<style>
* { box-sizing:border-box; margin:0; padding:0; }
body { font-family:Georgia,'Times New Roman',serif; background:#f0ede4; color:#2C2C2C; min-height:100vh; display:flex; align-items:center; justify-content:center; padding:20px; }
.carte-verif { background:#fff; border-radius:10px; box-shadow:0 8px 30px rgba(0,0,0,.12); max-width:480px; width:100%; overflow:hidden; }
.entete-v { background:#0F2942; color:#fff; padding:24px 28px; text-align:center; }
.entete-v .inst { font-size:13px; letter-spacing:1px; text-transform:uppercase; opacity:.75; }
.entete-v .titre { font-size:20px; font-weight:bold; margin-top:6px; }
.corps-v { padding:28px; }
.form-verif { display:flex; gap:8px; margin-bottom:20px; }
.form-verif input { flex:1; min-width:0; padding:10px 12px; border:1.5px solid #ddd; border-radius:6px; font-size:14px; font-family:inherit; }
.form-verif button { background:#0F2942; color:#fff; border:none; padding:10px 18px; border-radius:6px; font-weight:600; cursor:pointer; font-family:inherit; }
.resultat-ok { background:#eafaf0; border:1.5px solid #16a34a; border-radius:8px; padding:20px; text-align:center; }
.resultat-ok .icone, .resultat-ko .icone { font-size:36px; margin-bottom:8px; }
.resultat-ok .msg { color:#16a34a; font-weight:bold; font-size:15px; margin-bottom:16px; }
.resultat-ko { background:#fdecec; border:1.5px solid #dc2626; border-radius:8px; padding:20px; text-align:center; }
.resultat-ko .msg { color:#dc2626; font-weight:bold; font-size:15px; }
.details-v { text-align:left; margin-top:4px; }
.details-v .ligne { display:flex; justify-content:space-between; gap:12px; padding:8px 0; border-bottom:1px dotted #ddd; font-size:13.5px; }
.details-v .ligne:last-child { border-bottom:none; }
.details-v .lbl { color:#777; }
.details-v .val { font-weight:bold; color:#0F2942; text-align:right; }
.pied-v { text-align:center; padding:16px; font-size:11px; color:#999; border-top:1px solid #eee; }
</style>
</head>
<body>

<div class="carte-verif">
  <div class="entete-v">
    <div class="inst">{{ $institution }}</div>
    <div class="titre">🔍 Vérification de diplôme</div>
  </div>

  <div class="corps-v">
    <form method="get" action="{{ route('verifier_diplome') }}" class="form-verif">
      <input type="text" name="num" value="{{ $numero }}" maxlength="30" placeholder="Numéro du diplôme (ex: D26-LI-000001)" aria-label="Numéro du document" autofocus>
      <button type="submit">Vérifier</button>
    </form>

    @if ($attente)
      <div class="resultat-ko">
        <div class="icone">⏳</div>
        <div class="msg">Trop de vérifications</div>
        <p style="color:#991b1b;font-size:12.5px;margin-top:6px">Réessayez dans {{ $attente }} minute(s).</p>
      </div>
    @elseif ($numero === '')
      <p style="color:#777;font-size:13.5px;text-align:center">Saisissez le numéro inscrit sur le diplôme, ou scannez son QR code directement.</p>
    @elseif ($resultat)
      <div class="resultat-ok">
        <div class="icone">✅</div>
        <div class="msg">Ce document est authentique</div>
        <div class="details-v">
          @foreach ($lignes as $libelle => $valeur)
          <div class="ligne"><span class="lbl">{{ $libelle }}</span><span class="val">{{ $valeur }}</span></div>
          @endforeach
        </div>
      </div>
    @else
      <div class="resultat-ko">
        <div class="icone">❌</div>
        <div class="msg">Numéro introuvable</div>
        <p style="color:#991b1b;font-size:12.5px;margin-top:6px">
          @if ($invalide)
            Le numéro saisi n'a pas un format valide.
          @else
            Aucun diplôme enregistré ne correspond à « {{ $numero }} ». Vérifiez le numéro, ou contactez l'établissement.
          @endif
        </p>
      </div>
    @endif
  </div>

  <div class="pied-v">Service de vérification en ligne — {{ $institution }}</div>
</div>

</body>
</html>
