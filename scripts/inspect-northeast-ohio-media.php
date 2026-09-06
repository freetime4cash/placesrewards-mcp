<?php

declare(strict_types=1);

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

$agentRoot = '/home/placevle/placesrewards-agent-server';
$appRoot = '/home/placevle/app.placesrewards.com';
$out = $agentRoot.'/results/campaigns/northeast-ohio-media-support.json';

require $appRoot.'/vendor/autoload.php';
$app = require $appRoot.'/bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();

$knownIds = [
  '01a000d3-701c-728e-b0c6-7d3a5754428c', // loyalty
  '01a000f1-4a6e-719a-a541-d9c4f2b359f3', // stamp
  '01a000f1-4dc8-71b9-8e9c-0efb561d6e80', // voucher
  'f9e3a56d-9cf8-4d16-a78a-3bf731f93557', // scratch
];

$result = [
  'generated_at' => now()->toIso8601String(),
  'media_table' => null,
  'known_demo_media' => [],
  'relevant_tables' => [],
];

if (Schema::hasTable('media')) {
  $result['media_table'] = [
    'columns' => Schema::getColumnListing('media'),
    'sample_collections' => DB::table('media')
      ->select(['model_type','collection_name','disk'])
      ->distinct()->limit(100)->get()->map(fn($r)=>(array)$r)->all(),
  ];
  $result['known_demo_media'] = DB::table('media')
    ->whereIn('model_id', $knownIds)
    ->orderBy('model_id')->orderBy('order_column')
    ->get(['id','model_type','model_id','collection_name','name','file_name','mime_type','disk','conversions_disk','order_column'])
    ->map(fn($r)=>(array)$r)->all();
}

foreach (['clubs','cards','rewards','stamp_cards','vouchers','scratch_games','giveaways','referral_programs','tiers','segments','email_campaigns','review_campaigns'] as $table) {
  if (!Schema::hasTable($table)) continue;
  $result['relevant_tables'][$table] = [
    'columns' => Schema::getColumnListing($table),
  ];
}

file_put_contents($out, json_encode($result, JSON_PRETTY_PRINT|JSON_UNESCAPED_SLASHES));
echo json_encode($result, JSON_PRETTY_PRINT|JSON_UNESCAPED_SLASHES), "\n";
