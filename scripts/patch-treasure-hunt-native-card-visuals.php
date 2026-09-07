<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Artisan;

$agentRoot='/home/placevle/placesrewards-agent-server';
$appRoot='/home/placevle/app.placesrewards.com';
$out=$agentRoot.'/results/campaigns/treasure-hunt-native-card-visuals.json';
$backupDir=$agentRoot.'/data/backups/treasure-hunt-native-visuals-'.date('Ymd-His');
@mkdir($backupDir,0755,true);

$result=['status'=>'running','started_at'=>date(DATE_ATOM),'files'=>[]];

function injectAfterOuterDiv(string $source, string $variable, string $block): array {
    $needle='<div {{ $attributes->except(\'class\') }}';
    $start=strpos($source,$needle);
    if($start===false) return [$source,false,'outer_div_not_found'];
    $end=strpos($source,'>',$start);
    if($end===false) return [$source,false,'outer_div_end_not_found'];
    if(str_contains($source,'TREASURE_HUNT_STRATEGIC_CARD_CONTENT')) return [$source,false,'already_patched'];
    $patched=substr($source,0,$end+1)."\n\n".$block.substr($source,$end+1);
    return [$patched,true,'patched'];
}

try {
    $targets=[
        'loyalty'=>[
            'path'=>$appRoot.'/resources/views/components/member/card.blade.php',
            'block'=><<<'BLADE'
    {{-- TREASURE_HUNT_STRATEGIC_CARD_CONTENT --}}
    @php
        $isTreasureHuntDemo = str_starts_with((string)($card->name ?? ''), '[TOM DEMO');
    @endphp
    @if($isTreasureHuntDemo)
        <div class="absolute inset-x-4 top-4 z-40 pointer-events-none max-w-[78%]">
            <div class="inline-flex items-center rounded-full bg-black/30 backdrop-blur-sm border border-white/20 px-2.5 py-1 text-[8px] @[400px]:text-[9px] @[500px]:text-[10px] font-black uppercase tracking-[0.14em] text-white mb-2">
                Northeast Ohio Treasure Hunt
            </div>
            <div class="rounded-xl bg-black/28 backdrop-blur-sm border border-white/15 px-3 py-2.5 shadow-lg">
                <div class="text-[12px] @[400px]:text-[14px] @[500px]:text-[17px] leading-tight font-black text-white line-clamp-2">{{ $card->title }}</div>
                <div class="mt-1 text-[9px] @[400px]:text-[10px] @[500px]:text-[12px] leading-snug font-semibold text-white/95 line-clamp-2">{{ $card->head }}</div>
                <div class="mt-1.5 text-[8px] @[400px]:text-[9px] @[500px]:text-[10px] leading-snug text-white/85 line-clamp-3">{{ $card->description }}</div>
            </div>
        </div>
    @endif
BLADE
        ],
        'stamp'=>[
            'path'=>$appRoot.'/resources/views/components/member/stamp-card.blade.php',
            'block'=><<<'BLADE'
    {{-- TREASURE_HUNT_STRATEGIC_CARD_CONTENT --}}
    @php
        $isTreasureHuntDemo = str_starts_with((string)($stampCard->name ?? ''), '[TOM DEMO');
    @endphp
    @if($isTreasureHuntDemo)
        <div class="absolute inset-x-4 top-4 z-40 pointer-events-none max-w-[78%]">
            <div class="inline-flex items-center rounded-full bg-black/30 backdrop-blur-sm border border-white/20 px-2.5 py-1 text-[8px] @[400px]:text-[9px] @[500px]:text-[10px] font-black uppercase tracking-[0.14em] text-white mb-2">
                Treasure Hunt Module
            </div>
            <div class="rounded-xl bg-black/28 backdrop-blur-sm border border-white/15 px-3 py-2.5 shadow-lg">
                <div class="text-[12px] @[400px]:text-[14px] @[500px]:text-[17px] leading-tight font-black text-white line-clamp-2">{{ $stampCard->title }}</div>
                <div class="mt-1 text-[8px] @[400px]:text-[9px] @[500px]:text-[10px] leading-snug text-white/90 line-clamp-3">{{ $stampCard->description }}</div>
                @if(!empty($stampCard->reward_title))
                    <div class="mt-1.5 text-[8px] @[400px]:text-[9px] @[500px]:text-[10px] font-bold text-white">Reward: {{ $stampCard->reward_title }}</div>
                @endif
            </div>
        </div>
    @endif
BLADE
        ],
        'voucher'=>[
            'path'=>$appRoot.'/resources/views/components/member/voucher-card.blade.php',
            'block'=><<<'BLADE'
    {{-- TREASURE_HUNT_STRATEGIC_CARD_CONTENT --}}
    @php
        $isTreasureHuntDemo = str_starts_with((string)($voucher->name ?? ''), '[TOM DEMO');
    @endphp
    @if($isTreasureHuntDemo)
        <div class="absolute inset-x-4 top-4 z-40 pointer-events-none max-w-[70%]">
            <div class="inline-flex items-center rounded-full bg-black/30 backdrop-blur-sm border border-white/20 px-2.5 py-1 text-[8px] @[400px]:text-[9px] @[500px]:text-[10px] font-black uppercase tracking-[0.14em] text-white mb-2">
                Treasure Hunt Comeback
            </div>
            <div class="rounded-xl bg-black/28 backdrop-blur-sm border border-white/15 px-3 py-2.5 shadow-lg">
                <div class="text-[12px] @[400px]:text-[14px] @[500px]:text-[17px] leading-tight font-black text-white line-clamp-2">{{ $voucher->title }}</div>
                <div class="mt-1 text-[8px] @[400px]:text-[9px] @[500px]:text-[10px] leading-snug text-white/90 line-clamp-3">{{ $voucher->description }}</div>
            </div>
        </div>
    @endif
BLADE
        ],
    ];

    foreach($targets as $name=>$config){
        $path=$config['path'];
        if(!is_file($path)){
            $result['files'][$name]=['status'=>'missing','path'=>$path];
            continue;
        }
        $source=(string)file_get_contents($path);
        [$patched,$changed,$reason]=injectAfterOuterDiv($source,$name,$config['block']);
        if($changed){
            @copy($path,$backupDir.'/'.basename($path));
            file_put_contents($path,$patched,LOCK_EX);
        }
        $result['files'][$name]=['status'=>$reason,'path'=>$path,'changed'=>$changed,'marker_present'=>str_contains($patched,'TREASURE_HUNT_STRATEGIC_CARD_CONTENT')];
    }

    try{Artisan::call('view:clear');}catch(Throwable $e){}
    try{Artisan::call('cache:clear');}catch(Throwable $e){}
    try{Artisan::call('optimize:clear');}catch(Throwable $e){}

    $all=true;
    foreach($result['files'] as $file){ if(empty($file['marker_present'])) $all=false; }
    $result['status']=$all?'completed':'partial';
} catch(Throwable $e){
    $result['status']='failed';
    $result['error']=$e->getMessage();
}
$result['completed_at']=date(DATE_ATOM);
file_put_contents($out,json_encode($result,JSON_PRETTY_PRINT|JSON_UNESCAPED_SLASHES),LOCK_EX);
echo json_encode(['status'=>$result['status'],'files'=>$result['files']]),"\n";
exit($result['status']==='failed'?1:0);
