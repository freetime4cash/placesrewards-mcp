<?php

declare(strict_types=1);

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

$agentRoot='/home/placevle/placesrewards-agent-server';
$appRoot='/home/placevle/app.placesrewards.com';
$out=$agentRoot.'/results/campaigns/treasure-hunt-native-repair-verification.json';
$base='https://app.placesrewards.com';
$cardId='95cbd0bf-8bbb-436d-b7c6-a2e1e558db25';
$clue='29304849-3c10-4a06-8f8f-4bad776b79f9';
$prize='13085fc2-2a5d-43ee-92b5-441bc368c55b';
$gameId='1fefb288-a8cc-46d4-a4a3-04fe56f91329';

require $appRoot.'/vendor/autoload.php';
$app=require $appRoot.'/bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();

function probe(string $url,bool $follow=true):array{
    $cookie=tempnam(sys_get_temp_dir(),'pr-th-cookie-');
    $ch=curl_init($url);
    curl_setopt_array($ch,[CURLOPT_RETURNTRANSFER=>true,CURLOPT_FOLLOWLOCATION=>$follow,CURLOPT_TIMEOUT=>25,CURLOPT_CONNECTTIMEOUT=>8,CURLOPT_USERAGENT=>'PlacesRewards-NativeRepairVerifier/1.1',CURLOPT_COOKIEJAR=>$cookie,CURLOPT_COOKIEFILE=>$cookie]);
    $body=(string)curl_exec($ch);$status=(int)curl_getinfo($ch,CURLINFO_RESPONSE_CODE);$effective=(string)curl_getinfo($ch,CURLINFO_EFFECTIVE_URL);$type=(string)curl_getinfo($ch,CURLINFO_CONTENT_TYPE);$error=(string)curl_error($ch);curl_close($ch);@unlink($cookie);
    return ['status'=>$status,'effective'=>$effective,'content_type'=>$type,'body'=>$body,'bytes'=>strlen($body),'error'=>$error?:null];
}
function cleanProbe(array $p):array{return ['status'=>$p['status'],'effective'=>$p['effective'],'content_type'=>$p['content_type'],'bytes'=>$p['bytes'],'error'=>$p['error']];}

$result=['status'=>'running','verified_at'=>now()->toIso8601String(),'checks'=>[]];
$all=true;

foreach([$clue=>'04 — Clue Activity Bonus',$prize=>'06 — Local Business Bonus Prize'] as $rewardId=>$marker){
    $reward=Schema::hasTable('rewards')?DB::table('rewards')->where('id',$rewardId)->first():null;
    $pivot=Schema::hasTable('card_reward')?DB::table('card_reward')->where('card_id',$cardId)->where('reward_id',$rewardId)->exists():false;
    $future=$reward && !empty($reward->expiration_date) && strtotime((string)$reward->expiration_date)>time();
    $active=$reward && (int)$reward->is_active===1;
    $url="$base/en-us/card/$cardId/$rewardId";
    $page=probe($url,true);
    $visible=$page['status']===200 && stripos($page['body'],$marker)!==false && stripos($page['body'],'Reward Not Found')===false && stripos($page['body'],'Page Not Found')===false;
    $passed=$pivot&&$future&&$active&&$visible;
    $result['checks']['reward_'.$rewardId]=['passed'=>$passed,'pivot_attached'=>$pivot,'active'=>$active,'future_expiration'=>$future,'expiration_date'=>$reward->expiration_date??null,'max_number_to_redeem'=>$reward->max_number_to_redeem??null,'url'=>$url,'page'=>cleanProbe($page),'expected_marker'=>$marker,'has_reward_not_found'=>stripos($page['body'],'Reward Not Found')!==false,'has_page_not_found'=>stripos($page['body'],'Page Not Found')!==false];
    if(!$passed)$all=false;
}

$game=Schema::hasTable('scratch_games')?DB::table('scratch_games')->where('id',$gameId)->first():null;
$expected=['cover_image'=>'treasure-hunt/scratch/cover.webp','win_image'=>'treasure-hunt/scratch/winner.webp','loss_image'=>'treasure-hunt/scratch/loser.webp'];
$dbPaths=$game?['cover_image'=>$game->cover_image,'win_image'=>$game->win_image,'loss_image'=>$game->loss_image]:[];
$dbPathOk=$game && $dbPaths===$expected;
$result['checks']['scratch_database_paths']=['passed'=>$dbPathOk,'expected'=>$expected,'actual'=>$dbPaths];if(!$dbPathOk)$all=false;

$assetUrls=['cover'=>"$base/storage/treasure-hunt/scratch/cover.webp",'winner'=>"$base/storage/treasure-hunt/scratch/winner.webp",'loser'=>"$base/storage/treasure-hunt/scratch/loser.webp"];
foreach($assetUrls as $kind=>$url){$p=probe($url,true);$passed=$p['status']===200&&str_starts_with(strtolower((string)$p['content_type']),'image/webp')&&$p['bytes']>1000;$result['checks']['scratch_asset_'.$kind]=['passed'=>$passed,'url'=>$url,'probe'=>cleanProbe($p)];if(!$passed)$all=false;}

$viewPath=$appRoot.'/resources/views/member/scratch-cards/show.blade.php';$view=is_file($viewPath)?(string)file_get_contents($viewPath):'';
$viewChecks=['cover_config'=>str_contains($view,'coverImage:'),'cover_state'=>str_contains($view,'coverImage: config.coverImage'),'cover_renderer'=>str_contains($view,'TREASURE_HUNT_COVER_RENDERER'),'cover_storage_helper'=>str_contains($view,"asset('storage/' . \$scratchCard->scratchGame->cover_image)"),'winner_storage_helper'=>str_contains($view,"asset('storage/' . \$scratchCard->scratchGame->win_image)"),'loser_storage_helper'=>str_contains($view,"asset('storage/' . \$scratchCard->scratchGame->loss_image)")];
$viewOk=!in_array(false,$viewChecks,true);$result['checks']['scratch_native_renderer']=['passed'=>$viewOk,'checks'=>$viewChecks,'view'=>$viewPath];if(!$viewOk)$all=false;

$scratch=probe("$base/demo/treasure-hunt/scratch/play",true);
$nativePath=(string)(parse_url($scratch['effective'],PHP_URL_PATH)??'');
$nativeDestination=(bool)preg_match('#^/en-us/scratch-cards/[0-9a-f-]{36}$#i',$nativePath);
$renderedUrls=stripos($scratch['body'],'/storage/treasure-hunt/scratch/cover.webp')!==false&&stripos($scratch['body'],'/storage/treasure-hunt/scratch/winner.webp')!==false&&stripos($scratch['body'],'/storage/treasure-hunt/scratch/loser.webp')!==false;
$noOldPaths=stripos($scratch['body'],'storage//files/demo/treasure-hunt')===false&&stripos($scratch['body'],'storage/files/demo/treasure-hunt')===false;
$scratchPassed=$scratch['status']===200&&$nativeDestination&&$renderedUrls&&$noOldPaths;
$result['checks']['scratch_native_page']=['passed'=>$scratchPassed,'bridge'=>"$base/demo/treasure-hunt/scratch/play",'probe'=>cleanProbe($scratch),'native_destination'=>$nativeDestination,'rendered_storage_urls'=>$renderedUrls,'no_old_storage_files_paths'=>$noOldPaths];if(!$scratchPassed)$all=false;

$result['status']=$all?'passed':'failed';$result['completed_at']=now()->toIso8601String();@mkdir(dirname($out),0755,true);file_put_contents($out,json_encode($result,JSON_PRETTY_PRINT|JSON_UNESCAPED_SLASHES),LOCK_EX);echo json_encode(['status'=>$result['status'],'checks'=>array_map(fn($c)=>$c['passed']??false,$result['checks'])]),"\n";exit($all?0:1);
