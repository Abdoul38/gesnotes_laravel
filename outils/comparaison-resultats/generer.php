<?php
// Ajoute un jeu synthétique varié dans la base gesnotes_cmp_source (copie de
// la base locale) pour comparer l'ancien et le nouveau moteur de résultats.
$pdo = new PDO('mysql:host=127.0.0.1;dbname=gesnotes_cmp_source;charset=utf8mb4', 'root', '', [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
mt_srand(42);
$q = fn ($sql, $p = []) => tap($pdo->prepare($sql), fn ($s) => $s->execute($p));
function tap($v, $f) { $f($v); return $v; }
$id = fn () => (int) $pdo->lastInsertId();

$q("INSERT INTO annees_academiques (libelle, active) VALUES ('CMP-2029', 0)"); $anPrec = $id();
$q("INSERT INTO annees_academiques (libelle, active) VALUES ('CMP-2030', 0)"); $an = $id();
$q("INSERT INTO filieres (code, libelle, departement) VALUES ('CMP', 'Filière comparaison', 'Dépt')"); $fil = $id();
$niv = (int) $pdo->query("SELECT id FROM niveaux WHERE code='L1' LIMIT 1")->fetchColumn();
$sem = (int) $pdo->query("SELECT id FROM semestres WHERE code='S1' LIMIT 1")->fetchColumn();
// Règles figées pour l'année précédente : seuil 12, pas de note éliminatoire, pas de compensation d'UE
foreach (['seuil_validation' => '12', 'note_eliminatoire' => '0', 'note_eliminatoire_ue' => '0', 'compensation_ue' => 'non', 'compensation_semestre' => 'oui'] as $c => $v) {
    $q('INSERT INTO parametres_annee (cle, annee_id, valeur) VALUES (?, ?, ?)', [$c, $anPrec, $v]);
}

$structure = [ // code UE => [credits_total, nb_options, [ [code, exam, cc, credits, type] ]]
    'UE1' => [6, 0, [['M11', 40, 20, 3, 'obligatoire'], ['M12', 40, 20, 3, 'obligatoire']]],
    'UE2' => [6, 0, [['M21', 20, 0, 4, 'obligatoire'], ['M22', 30, 10, 2, 'obligatoire']]],
    'UE3' => [4, 1, [['M31', 20, 0, 2, 'obligatoire'], ['M32', 20, 0, 2, 'optionnelle'], ['M33', 20, 0, 2, 'optionnelle']]],
];
$promos = [];
$matieres = [];
foreach ([$anPrec, $an] as $a) {
    $q('INSERT INTO promotions (filiere_id, niveau_id, annee_id) VALUES (?, ?, ?)', [$fil, $niv, $a]); $p = $id(); $promos[$a] = $p;
    $o = 0;
    foreach ($structure as $ue => [$cr, $opt, $mats]) {
        $q('INSERT INTO unites_enseignement (promotion_id, semestre_id, code, libelle, credits_total, nb_options_a_choisir, ordre) VALUES (?,?,?,?,?,?,?)', [$p, $sem, $ue, "UE $ue", $cr, $opt, ++$o]);
        $ueId = $id();
        foreach ($mats as $i => [$code, $ex, $cc, $mc, $type]) {
            $q('INSERT INTO matieres (ue_id, code, libelle, note_max_examen, note_max_cc, note_max_totale, credits, type_matiere, ordre) VALUES (?,?,?,?,?,?,?,?,?)', [$ueId, $code, "Mat $code", $ex, $cc, $ex + $cc, $mc, $type, $i]);
            $matieres[$a][] = ['id' => $id(), 'ue' => $ueId, 'ex' => $ex, 'cc' => $cc, 'type' => $type];
        }
    }
}
$q("INSERT INTO pv_sessions (promotion_id, semestre_id, type_session, libelle) VALUES (?, ?, 'normale', 'PV prec')", [$promos[$anPrec], $sem]); $pvPrec = $id();
$q("INSERT INTO pv_sessions (promotion_id, semestre_id, type_session, libelle) VALUES (?, ?, 'normale', 'PV normale')", [$promos[$an], $sem]); $pvN = $id();
$q("INSERT INTO pv_sessions (promotion_id, semestre_id, type_session, pv_normale_id, libelle) VALUES (?, ?, 'rattrapage', ?, 'PV ratt')", [$promos[$an], $sem, $pvN]); $pvR = $id();

$note = function (array $m) {
    $ex = mt_rand(1, 100) <= 5 ? null : round(mt_rand(0, $m['ex'] * 4) / 4, 2);
    $cc = $m['cc'] > 0 ? (mt_rand(1, 100) <= 6 ? null : round(mt_rand(0, $m['cc'] * 4) / 4, 2)) : null;
    $fin = ($ex !== null && ($m['cc'] == 0 || $cc !== null) && mt_rand(0, 1)) ? $ex + (float) $cc : null;
    return [$ex, $cc, $fin];
};
$ins = $pdo->prepare('INSERT INTO notes (pv_id, etudiant_id, matiere_id, note_examen, note_cc, note_finale) VALUES (?,?,?,?,?,?)');
for ($k = 1; $k <= 400; $k++) {
    $q('INSERT INTO etudiants (nom, prenoms, numero) VALUES (?, ?, ?)', ["CMP$k", 'Test', 'C'.str_pad((string) $k, 5, '0', STR_PAD_LEFT)]); $et = $id();
    $q("INSERT INTO inscriptions (etudiant_id, promotion_id, statut, date_inscription) VALUES (?, ?, 'inscrit', '2030-10-01')", [$et, $promos[$an]]);
    if ($k % 2 === 0) { // la moitié a déjà composé l'année précédente (notes à reporter)
        $q("INSERT INTO inscriptions (etudiant_id, promotion_id, statut, date_inscription) VALUES (?, ?, 'inscrit', '2029-10-01')", [$et, $promos[$anPrec]]);
        foreach ($matieres[$anPrec] as $m) {
            if (mt_rand(1, 100) > 8) { $ins->execute([$pvPrec, $et, $m['id'], ...$note($m)]); }
        }
    }
    foreach ($matieres[$an] as $m) {
        if (mt_rand(1, 100) > 8) { $ins->execute([$pvN, $et, $m['id'], ...$note($m)]); }
    }
    if (mt_rand(1, 100) <= 50) { // rattrapage : quelques matières seulement
        foreach ($matieres[$an] as $m) {
            if (mt_rand(1, 100) <= 60) { $ins->execute([$pvR, $et, $m['id'], ...$note($m)]); }
        }
    }
    if (mt_rand(1, 100) <= 80) { // choix d'option
        $opts = array_values(array_filter($matieres[$an], fn ($m) => $m['type'] === 'optionnelle'));
        $c = $opts[mt_rand(0, 1)];
        $q('INSERT INTO choix_options_etudiants (etudiant_id, matiere_id, ue_id, annee_id, promotion_id, semestre_id) VALUES (?,?,?,?,?,?)', [$et, $c['id'], $c['ue'], $an, $promos[$an], $sem]);
    }
}
file_put_contents(__DIR__.'/synthese.json', json_encode(['pvPrec' => $pvPrec, 'pvN' => $pvN, 'pvR' => $pvR]));
echo "Synthèse : pv $pvPrec / $pvN / $pvR\n";
