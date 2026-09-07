<?php

declare(strict_types=1);

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Route;

$agentRoot='/home/placevle/placesrewards-agent-server';
$appRoot='/home/placevle/app.placesrewards.com';
$out=$agentRoot.'/results/campaigns/treasure-hunt-native-destinations.json';

require $appRoot.'/vendor/autoload.php';
$app=require $appRoot.'/bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();

$keywords=['card','stamp','reward','voucher','scratch','referral','tier','campaign','analytics','discover','directory','club','member'];
$routes=[];
foreach(Route::getRoutes() as $route){
    $methods=$route->methods();
    if(!in_array('GET',$methods,true) && !in_array('HEAD',$methods,true)) continue;
    $uri=$route->uri();
    $name=(string)($route->getName()??'');
    $action=(string)($route->getActionName()??'');
    $hay=strtolower($uri.' '.$name.' '.$action);
    $hit=false;
    foreach($keywords as $kw){ if(str_contains($hay,$kw)){ $hit=true; break; } }
    if(!$hit) continue;
    $routes[]=['methods'=>$methods,'uri'=>$uri,'name'=>$name?:null,'action'=>$action,'middleware'=>$route->gatherMiddleware()];
}

$ids=[
 'card'=>'95cbd0bf-8bbb-436d-b7c6-a2e1e558db25',
 'explorer_stamp'=>'a9566430-b6b0-434d-ae3c-f3ba85421c5f',
 'checkin_stamp'=>'5738988e-265f-422f-9b41-5828790af3c0',
 'clue_reward'=>'29304849-3c10-4a06-8f8f-4bad776b79f9',
 'prize_reward'=>'13085fc2-2a5d-43ee-92b5-441bc368c55b',
 'vip_tier'=>'8744df20-c34b-484c-9f8f-15140f8fc542',
 'scratch'=>'1fefb288-a8cc-46d4-a4a3-04fe56f91329',
 'voucher'=>'14788f52-438e-4293-bd6b-c82b8e448983',
 'retention_campaign'=>'b1ea6974-d61c-41d4-a1ce-0c0a27ffa5bd',
 'club'=>'01a000ce-0595-7254-8141-7411cd036ba4',
];

$tables=[];
foreach(['cards','stamp_cards','rewards','tiers','scratch_games','vouchers','email_campaigns','clubs'] as $table){
    $tables[$table]=DB::getSchemaBuilder()->hasTable($table)?array_map(fn($x)=>(array)$x,DB::table($table)->where(function($q) use($table,$ids){
        if($table==='cards') $q->where('id',$ids['card']);
        elseif($table==='stamp_cards') $q->whereIn('id',[$ids['explorer_stamp'],$ids['checkin_stamp']]);
        elseif($table==='rewards') $q->whereIn('id',[$ids['clue_reward'],$ids['prize_reward']]);
        elseif($table==='tiers') $q->where('id',$ids['vip_tier']);
        elseif($table==='scratch_games') $q->where('id',$ids['scratch']);
        elseif($table==='vouchers') $q->where('id',$ids['voucher']);
        elseif($table==='email_campaigns') $q->where('id',$ids['retention_campaign']);
        elseif($table==='clubs') $q->where('id',$ids['club']);
    })->get()->all()):[];
}

$result=['status'=>'completed','generated_at'=>now()->toIso8601String(),'ids'=>$ids,'routes'=>$routes,'records'=>$tables];
@mkdir(dirname($out),0755,true);
file_put_contents($out,json_encode($result,JSON_PRETTY_PRINT|JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE),LOCK_EX);
echo json_encode(['status'=>'completed','route_count'=>count($routes),'output'=>$out]),"\n";
