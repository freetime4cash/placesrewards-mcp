<?php

declare(strict_types=1);

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Route;

$agentRoot = '/home/placevle/placesrewards-agent-server';
$appRoot = '/home/placevle/app.placesrewards.com';
$out = $agentRoot.'/results/campaigns/referral-review-support.json';

require $appRoot.'/vendor/autoload.php';
$app = require $appRoot.'/bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();

$dbName = DB::connection()->getDatabaseName();
$tableRows = DB::select(
    "SELECT TABLE_NAME FROM information_schema.TABLES WHERE TABLE_SCHEMA = ? AND (TABLE_NAME LIKE '%referr%' OR TABLE_NAME LIKE '%review%' OR TABLE_NAME LIKE '%testimonial%' OR TABLE_NAME LIKE '%rating%') ORDER BY TABLE_NAME",
    [$dbName]
);

$tables = [];
foreach ($tableRows as $row) {
    $table = $row->TABLE_NAME;
    $columns = Schema::getColumnListing($table);
    $sample = null;
    try {
        $sample = DB::table($table)->first();
    } catch (Throwable $e) {
        $sample = ['error'=>$e->getMessage()];
    }
    $tables[$table] = [
        'columns'=>$columns,
        'sample'=>$sample ? (array)$sample : null,
    ];
}

$routes = [];
foreach (Route::getRoutes() as $route) {
    $uri = $route->uri();
    $name = $route->getName();
    $action = $route->getActionName();
    $hay = strtolower($uri.' '.$name.' '.$action);
    if (str_contains($hay, 'referr') || str_contains($hay, 'review') || str_contains($hay, 'rating') || str_contains($hay, 'testimonial')) {
        $routes[] = [
            'methods'=>$route->methods(),
            'uri'=>$uri,
            'name'=>$name,
            'action'=>$action,
            'middleware'=>$route->gatherMiddleware(),
        ];
    }
}

$data = [
    'generated_at'=>now()->toIso8601String(),
    'database'=>$dbName,
    'tables'=>$tables,
    'routes'=>$routes,
];
@mkdir(dirname($out), 0755, true);
file_put_contents($out, json_encode($data, JSON_PRETTY_PRINT|JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE), LOCK_EX);
echo json_encode(['status'=>'ok','tables'=>array_keys($tables),'route_count'=>count($routes)]).PHP_EOL;
