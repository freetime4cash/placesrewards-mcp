<?php

declare(strict_types=1);

$agentRoot = '/home/placevle/placesrewards-agent-server';
$appRoot = '/home/placevle/app.placesrewards.com';
$out = $agentRoot . '/results/campaigns/363-schema-inspection.json';
$partnerId = '019dbfc5-e395-7082-9214-20859f344cce';

require $appRoot . '/vendor/autoload.php';
$app = require $appRoot . '/bootstrap/app.php';
$kernel = $app->make(Illuminate\Contracts\Console\Kernel::class);
$kernel->bootstrap();

use Illuminate\Support\Facades\DB;

$keywords = ['club','card','reward','stamp','voucher','tier','scratch','giveaway','contest','referral','review','segment','campaign','email'];
$dbName = DB::connection()->getDatabaseName();
$tables = DB::select('SELECT TABLE_NAME FROM information_schema.TABLES WHERE TABLE_SCHEMA = ? ORDER BY TABLE_NAME', [$dbName]);
$result = ['generated_at' => now()->toIso8601String(), 'partner_id' => $partnerId, 'tables' => []];

foreach ($tables as $t) {
    $table = $t->TABLE_NAME;
    $lower = strtolower($table);
    $matched = false;
    foreach ($keywords as $k) { if (str_contains($lower, $k)) { $matched = true; break; } }
    if (!$matched) continue;

    $columns = DB::select('SELECT COLUMN_NAME, DATA_TYPE, IS_NULLABLE, COLUMN_DEFAULT, COLUMN_KEY FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = ? AND TABLE_NAME = ? ORDER BY ORDINAL_POSITION', [$dbName, $table]);
    $columnNames = array_map(fn($c) => $c->COLUMN_NAME, $columns);
    $sample = [];
    $ownerCols = array_values(array_intersect($columnNames, ['partner_id','created_by','owner_id','user_id']));
    try {
        if ($ownerCols) {
            $q = DB::table($table);
            $q->where(function($w) use ($ownerCols, $partnerId) {
                foreach ($ownerCols as $i => $col) $i === 0 ? $w->where($col, $partnerId) : $w->orWhere($col, $partnerId);
            });
            $sample = $q->limit(25)->get()->map(fn($r) => (array)$r)->all();
        } elseif (in_array('name', $columnNames, true)) {
            $sample = DB::table($table)->where('name', 'like', '%363%')->orWhere('name', 'like', '%EMPIRE%')->limit(25)->get()->map(fn($r) => (array)$r)->all();
        }
    } catch (Throwable $e) { $sample = [['inspection_error' => $e->getMessage()]]; }

    $result['tables'][$table] = [
        'columns' => array_map(fn($c) => ['name'=>$c->COLUMN_NAME,'type'=>$c->DATA_TYPE,'nullable'=>$c->IS_NULLABLE,'default'=>$c->COLUMN_DEFAULT,'key'=>$c->COLUMN_KEY], $columns),
        'sample_rows' => $sample,
    ];
}

file_put_contents($out, json_encode($result, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
echo "WROTE $out\n";
