<?php

declare(strict_types=1);

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

$agentRoot='/home/placevle/placesrewards-agent-server';
$appRoot='/home/placevle/app.placesrewards.com';
$out=$agentRoot.'/results/campaigns/treasure-hunt-broken-native-pages.json';
$cardId='95cbd0bf-8bbb-436d-b7c6-a2e1e558db25';
$rewardIds=['29304849-3c10-4a06-8f8f-4bad776b79f9','13085fc2-2a5d-43ee-92b5-441bc368c55b'];
$scratchGameId='1fefb288-a8cc-46d4-a4a3-04fe56f91329';

require $appRoot.'/vendor/autoload.php';
$app=require $appRoot.'/bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();

function curlProbe(string $url, bool $follow=true): array {
    $ch=curl_init($url);
    curl_setopt_array($ch,[CURLOPT_RETURNTRANSFER=>true,CURLOPT_FOLLOWLOCATION=>$follow,CURLOPT_TIMEOUT=>20,CURLOPT_CONNECTTIMEOUT=>8,CURLOPT_USERAGENT=>'PlacesRewards-TreasureHunt-RepairAudit/1.1',CURLOPT_COOKIEFILE=>'']);
    $body=(string)curl_exec($ch);
    $status=(int)curl_getinfo($ch,CURLINFO_RESPONSE_CODE);
    $effective=(string)curl_getinfo($ch,CURLINFO_EFFECTIVE_URL);
    $contentType=(string)curl_getinfo($ch,CURLINFO_CONTENT_TYPE);
    $error=(string)curl_error($ch);
    curl_close($ch);
    return ['status'=>$status,'effective'=>$effective,'content_type'=>$contentType,'bytes'=>strlen($body),'error'=>$error?:null,'body_excerpt'=>substr((string)preg_replace('/\s+/',' ',$body),0,12000)];
}

function fileExcerpt(string $path, int $limit=30000): ?string {
    if(!is_file($path)) return null;
    return substr((string)file_get_contents($path),0,$limit);
}

function methodSource(string $action): ?array {
    if(!str_contains($action,'@')) return null;
    [$class,$method]=explode('@',$action,2);
    if(!class_exists($class) || !method_exists($class,$method)) return null;
    $r=new ReflectionMethod($class,$method);
    $file=$r->getFileName();
    if(!$file || !is_file($file)) return null;
    $lines=file($file);
    $start=max(0,$r->getStartLine()-1);
    $len=$r->getEndLine()-$r->getStartLine()+1;
    return ['class'=>$class,'method'=>$method,'file'=>$file,'source'=>implode('',array_slice($lines,$start,$len))];
}

$result=['status'=>'running','generated_at'=>now()->toIso8601String()];
$routes=[];
foreach(app('router')->getRoutes() as $route){
    $uri=$route->uri();
    if(str_contains($uri,'card/{card}') || str_contains($uri,'scratch-cards/{scratchCard}')){
        $action=$route->getActionName();
        $routes[]=['uri'=>$uri,'methods'=>$route->methods(),'name'=>$route->getName(),'action'=>$action,'middleware'=>$route->gatherMiddleware(),'method_source'=>methodSource($action)];
    }
}
$result['routes']=$routes;
$result['card']=Schema::hasTable('cards') ? DB::table('cards')->where('id',$cardId)->first() : null;
$result['rewards']=Schema::hasTable('rewards') ? DB::table('rewards')->whereIn('id',$rewardIds)->get()->all() : [];
$result['relationship_tables']=[];
$tables=DB::select('SHOW TABLES');
$dbName=DB::getDatabaseName();
$tableKey='Tables_in_'.$dbName;
foreach($tables as $t){
    $table=$t->$tableKey ?? array_values((array)$t)[0] ?? null;
    if(!$table) continue;
    $cols=Schema::getColumnListing($table);
    $hasCard=in_array('card_id',$cols,true);
    $hasReward=in_array('reward_id',$cols,true);
    if($hasCard || $hasReward){
        $q=DB::table($table);
        $q->where(function($qq) use($hasCard,$hasReward,$cardId,$rewardIds){
            if($hasCard) $qq->orWhere('card_id',$cardId);
            if($hasReward) $qq->orWhereIn('reward_id',$rewardIds);
        });
        $rows=$q->limit(50)->get()->all();
        if($rows) $result['relationship_tables'][$table]=['columns'=>$cols,'rows'=>$rows];
    }
}
$viewCandidates=['resources/views/member/card/index.blade.php','resources/views/member/card/show.blade.php','resources/views/member/card/reward.blade.php','resources/views/member/reward/show.blade.php','resources/views/member/rewards/show.blade.php','resources/views/member/scratch-cards/show.blade.php','resources/views/components/member/premium-card.blade.php'];
$result['views']=[];
foreach($viewCandidates as $relative){$full=$appRoot.'/'.$relative;if(is_file($full))$result['views'][$relative]=fileExcerpt($full);}
$result['view_files']=[];
$rii=new RecursiveIteratorIterator(new RecursiveDirectoryIterator($appRoot.'/resources/views',FilesystemIterator::SKIP_DOTS));
foreach($rii as $file){
    if(!$file->isFile() || !str_ends_with($file->getFilename(),'.blade.php')) continue;
    $rel=str_replace($appRoot.'/','',$file->getPathname());$low=strtolower($rel);
    if(str_contains($low,'reward') || str_contains($low,'scratch')) $result['view_files'][]=$rel;
}
$base='https://app.placesrewards.com';
$result['live_pages']=[];
foreach($rewardIds as $rid){$url="$base/en-us/card/$cardId/$rid";$result['live_pages'][$rid]=curlProbe($url,true);}
$result['scratch_game']=Schema::hasTable('scratch_games') ? DB::table('scratch_games')->where('id',$scratchGameId)->first() : null;
$result['scratch_assets']=[];
foreach(['cover'=>'cover.webp','winner'=>'winner.webp','loser'=>'loser.webp'] as $key=>$file){
    $path=$appRoot.'/public/files/demo/treasure-hunt/scratch/'.$file;$info=is_file($path)?@getimagesize($path):false;$url="$base/files/demo/treasure-hunt/scratch/$file";
    $result['scratch_assets'][$key]=['path'=>$path,'exists'=>is_file($path),'bytes'=>is_file($path)?filesize($path):0,'mime'=>$info['mime']??null,'width'=>$info[0]??null,'height'=>$info[1]??null,'url'=>$url,'probe'=>curlProbe($url,true)];
}
$result['scratch_bridge']=curlProbe("$base/demo/treasure-hunt/scratch/play",true);
$result['status']='completed';
@mkdir(dirname($out),0755,true);
$json=json_encode($result,JSON_PRETTY_PRINT|JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE|JSON_INVALID_UTF8_SUBSTITUTE|JSON_PARTIAL_OUTPUT_ON_ERROR);
if($json===false){$json=json_encode(['status'=>'failed','json_error'=>json_last_error_msg()]);}
file_put_contents($out,(string)$json,LOCK_EX);
echo json_encode(['status'=>'completed','routes'=>count($routes),'reward_pages'=>count($result['live_pages']),'json_error'=>json_last_error_msg()]),"\n";
