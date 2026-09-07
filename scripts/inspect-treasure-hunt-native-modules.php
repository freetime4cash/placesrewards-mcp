<?php

declare(strict_types=1);

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\Schema;

$agentRoot = '/home/placevle/placesrewards-agent-server';
$appRoot = '/home/placevle/app.placesrewards.com';
$out = $agentRoot.'/results/campaigns/treasure-hunt-native-module-inspection.json';

require $appRoot.'/vendor/autoload.php';
$app = require $appRoot.'/bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();

$terms = ['card','stamp','voucher','scratch','referr','reward','tier','analytic','campaign','club','giveaway','review','directory','member','offer','loyalty'];
$routes = [];
foreach (Route::getRoutes() as $route) {
    $methods = $route->methods();
    if (!in_array('GET', $methods, true)) continue;
    $uri = $route->uri();
    $name = (string)($route->getName() ?? '');
    $action = (string)$route->getActionName();
    $hay = strtolower($uri.' '.$name.' '.$action);
    $matched = false;
    foreach ($terms as $term) if (str_contains($hay, $term)) { $matched = true; break; }
    if (!$matched) continue;
    $routes[] = [
        'uri' => $uri,
        'name' => $name ?: null,
        'action' => $action,
        'middleware' => $route->gatherMiddleware(),
    ];
}

$tables = [];
$dbName = DB::connection()->getDatabaseName();
$rows = DB::select('SELECT table_name FROM information_schema.tables WHERE table_schema = ? ORDER BY table_name', [$dbName]);
foreach ($rows as $row) {
    $table = (string)($row->table_name ?? $row->TABLE_NAME ?? '');
    $hay = strtolower($table);
    $matched = false;
    foreach ($terms as $term) if (str_contains($hay, $term)) { $matched = true; break; }
    if (!$matched) continue;
    $tables[$table] = Schema::getColumnListing($table);
}

$result = [
    'status' => 'completed',
    'generated_at' => now()->toIso8601String(),
    'routes' => $routes,
    'tables' => $tables,
];
@mkdir(dirname($out), 0755, true);
file_put_contents($out, json_encode($result, JSON_PRETTY_PRINT|JSON_UNESCAPED_SLASHES), LOCK_EX);
echo json_encode(['status'=>'completed','route_count'=>count($routes),'table_count'=>count($tables)]), "\n";
