<?php
// Exécute le plan avec les fonctions de l'ANCIENNE application sur gesnotes_cmp_ancien.
class Database {
    private static ?PDO $pdo = null;
    public static function getInstance(): PDO {
        return self::$pdo ??= new PDO('mysql:host=127.0.0.1;dbname=gesnotes_cmp_ancien;charset=utf8mb4', 'root', '', [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC, PDO::ATTR_EMULATE_PREPARES => false]);
    }
}
require rtrim(getenv('ANCIENNE_APP_CHEMIN') ?: 'C:/xampp00/htdocs/gesnotes', '/\\').'/includes/functions.php';
$pdo = Database::getInstance();
$n = 0;
foreach (json_decode(file_get_contents(__DIR__.'/plan.json'), true) as [$op, $pv, $et]) {
    $op === 'report' ? reporterNotes($pdo, $et, $pv) : recalculerResultats($pdo, $pv, $et);
    $n++;
}
echo "ancien : $n opérations\n";
