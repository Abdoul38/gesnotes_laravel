<?php
// Exécute le plan avec le service Laravel App\Services\Resultats sur gesnotes_cmp_nouveau.
chdir(__DIR__.'/../..');
require 'vendor/autoload.php';
$app = require 'bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();
config(['database.connections.universite.database' => 'gesnotes_cmp_nouveau']);
Illuminate\Support\Facades\DB::purge('universite');
Illuminate\Support\Facades\DB::setDefaultConnection('universite');
$r = $app->make(App\Services\Resultats::class);
$n = 0;
foreach (json_decode(file_get_contents(__DIR__.'/plan.json'), true) as [$op, $pv, $et]) {
    $op === 'report' ? $r->reporterNotes($et, $pv) : $r->recalculer($pv, $et);
    $n++;
}
echo "nouveau : $n opérations (base ".Illuminate\Support\Facades\DB::connection()->getDatabaseName().")\n";
