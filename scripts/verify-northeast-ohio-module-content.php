<?php

declare(strict_types=1);

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

$agentRoot = '/home/placevle/placesrewards-agent-server';
$appRoot = '/home/placevle/app.placesrewards.com';
$runtimePath = $appRoot.'/storage/app/northeast-ohio-tom/modules-runtime.json';
$outPath = $agentRoot.'/results/campaigns/northeast-ohio-module-content-verification.json';

require $appRoot.'/vendor/autoload.php';
$app = require $appRoot.'/bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();

$runtime = is_file($runtimePath) ? json_decode((string)file_get_contents($runtimePath), true) : null;
if (!is_array($runtime)) {
    file_put_contents($outPath, json_encode(['status'=>'failed','reason'=>'runtime_missing_or_invalid'], JSON_PRETTY_PRINT));
    exit(1);
}

$required = ['title','workflow_phase','description','hunter_action','system_action','merchant_value','example_content','success_signal','why_tom'];
$modules = [];
$examples = [];
$descriptions = [];
$titles = [];
$allFieldsPresent = true;
$allSequencesPresent = true;

foreach (($runtime['modules'] ?? []) as $m) {
    $seq = (int)($m['sequence'] ?? 0);
    $missing = [];
    foreach ($required as $field) {
        if (!isset($m[$field]) || trim((string)$m[$field]) === '') $missing[] = $field;
    }
    if ($missing) $allFieldsPresent = false;
    if ($seq < 1 || $seq > 12) $allSequencesPresent = false;

    $native = ['table'=>$m['record_table'] ?? null,'id'=>$m['record_id'] ?? null,'found'=>false,'description_present'=>false];
    if (!empty($native['table']) && !empty($native['id']) && Schema::hasTable((string)$native['table'])) {
        $row = DB::table((string)$native['table'])->where('id',(string)$native['id'])->first();
        if ($row) {
            $native['found'] = true;
            $cols = Schema::getColumnListing((string)$native['table']);
            foreach (['description','body','title','head','name'] as $field) {
                if (!in_array($field,$cols,true)) continue;
                $value = $row->{$field} ?? null;
                if ($value !== null && trim((string)$value) !== '') {
                    $native[$field] = mb_substr((string)$value,0,240);
                    if (in_array($field,['description','body'],true)) $native['description_present'] = true;
                }
            }
        }
    } else {
        $native['route_backed_demo'] = true;
    }

    $examples[] = trim((string)($m['example_content'] ?? ''));
    $descriptions[] = trim((string)($m['description'] ?? ''));
    $titles[] = trim((string)($m['title'] ?? ''));
    $modules[] = [
        'sequence'=>$seq,
        'module'=>$m['module_key'] ?? null,
        'title'=>$m['title'] ?? null,
        'workflow_phase'=>$m['workflow_phase'] ?? null,
        'missing_fields'=>$missing,
        'module_url'=>'https://app.placesrewards.com/demo/northeast-ohio-treasure-hunt/tom/module/'.str_pad((string)$seq,2,'0',STR_PAD_LEFT),
        'native'=>$native,
    ];
}

sort($modules);
$sequences = array_map(fn($m)=>(int)$m['sequence'],$modules);
$expected = range(1,12);
$sequencePass = $sequences === $expected;
$uniqueExamples = count(array_unique($examples));
$uniqueDescriptions = count(array_unique($descriptions));
$uniqueTitles = count(array_unique($titles));

$result = [
    'status'=>($sequencePass && $allFieldsPresent && count($modules)===12 && $uniqueExamples===12 && $uniqueDescriptions===12 && $uniqueTitles===12) ? 'passed' : 'failed',
    'workflow_version'=>$runtime['version'] ?? null,
    'module_count'=>count($modules),
    'sequence_01_to_12'=>$sequencePass,
    'all_required_content_fields_present'=>$allFieldsPresent,
    'unique_titles'=>$uniqueTitles,
    'unique_descriptions'=>$uniqueDescriptions,
    'unique_example_card_content'=>$uniqueExamples,
    'modules'=>$modules,
    'verified_at'=>now()->toIso8601String(),
];

file_put_contents($outPath, json_encode($result, JSON_PRETTY_PRINT|JSON_UNESCAPED_SLASHES), LOCK_EX);
echo json_encode(['status'=>$result['status'],'module_count'=>$result['module_count'],'unique_example_card_content'=>$uniqueExamples], JSON_UNESCAPED_SLASHES),"\n";
exit($result['status']==='passed' ? 0 : 2);
