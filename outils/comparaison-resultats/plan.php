<?php
// Liste ordonnée des opérations, identique pour les deux moteurs.
$pdo = new PDO('mysql:host=127.0.0.1;dbname=gesnotes_cmp_source;charset=utf8mb4', 'root', '', [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
$s = json_decode(file_get_contents(__DIR__.'/synthese.json'), true);
$plan = [];
// Données réelles : chaque couple (PV, étudiant), sessions normales d'abord
$reels = $pdo->query("
    SELECT x.pv_id, x.etudiant_id FROM (
        SELECT pv_id, etudiant_id FROM notes UNION SELECT pv_id, etudiant_id FROM resultats_semestre
    ) x JOIN pv_sessions ps ON ps.id = x.pv_id
    WHERE x.pv_id NOT IN ({$s['pvPrec']}, {$s['pvN']}, {$s['pvR']})
    ORDER BY ps.type_session = 'rattrapage', x.pv_id, x.etudiant_id")->fetchAll(PDO::FETCH_NUM);
foreach ($reels as [$pv, $et]) { $plan[] = ['recalc', (int) $pv, (int) $et]; }
// Données synthétiques
$etPrec = $pdo->query("SELECT DISTINCT etudiant_id FROM notes WHERE pv_id = {$s['pvPrec']} ORDER BY etudiant_id")->fetchAll(PDO::FETCH_COLUMN);
$tous = $pdo->query("SELECT id FROM etudiants WHERE nom LIKE 'CMP%' ORDER BY id")->fetchAll(PDO::FETCH_COLUMN);
$ratt = $pdo->query("SELECT DISTINCT etudiant_id FROM notes WHERE pv_id = {$s['pvR']} ORDER BY etudiant_id")->fetchAll(PDO::FETCH_COLUMN);
foreach ($etPrec as $et) { $plan[] = ['recalc', $s['pvPrec'], (int) $et]; }
foreach ($tous as $et) { $plan[] = ['report', $s['pvN'], (int) $et]; }
foreach ($tous as $et) { $plan[] = ['recalc', $s['pvN'], (int) $et]; }
foreach ($ratt as $et) { $plan[] = ['recalc', $s['pvR'], (int) $et]; }
file_put_contents(__DIR__.'/plan.json', json_encode($plan));
echo count($plan)." opérations (".count($reels)." sur données réelles)\n";
