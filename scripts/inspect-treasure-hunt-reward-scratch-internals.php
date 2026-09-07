<?php

declare(strict_types=1);

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

$agentRoot='/home/placevle/placesrewards-agent-server';
$appRoot='/home/placevle/app.placesrewards.com';
$out=$agentRoot.'/results/campaigns/treasure-hunt-reward-scratch-internals.json';
$cardId='95cbd0bf-8bbb-436d-b7c6-a2e1e558db25';
$rewardIds=['29304849-3c10-4a06-8f8f-4bad776b79f9','13085fc2-2a5d-43ee-92b5-441bc368c55b'];

require $appRoot.'/vendor/autoload.php';
$app=require $appRoot.'/bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();

function txt(string $path,int $limit=60000):?string{return is_file($path)?substr((string)file_get_contents($path),0,$limit):null;}
function probe(string $url):array{$ch=curl_init($url);curl_setopt_array($ch,[CURLOPT_RETURNTRANSFER=>true,CURLOPT_FOLLOWLOCATION=>true,CURLOPT_TIMEOUT=>20,CURLOPT_CONNECTTIMEOUT=>8,CURLOPT_USERAGENT=>'PlacesRewards-InternalAudit/1.0',CURLOPT_COOKIEFILE=>'']);$body=(string)curl_exec($ch);$status=(int)curl_getinfo($ch,CURLINFO_RESPONSE_CODE);$effective=(string)curl_getinfo($ch,CURLINFO_EFFECTIVE_URL);$type=(string)curl_getinfo($ch,CURLINFO_CONTENT_TYPE);$error=(string)curl_error($ch);curl_close($ch);return ['status'=>$status,'effective'=>$effective,'content_type'=>$type,'bytes'=>strlen($body),'error'=>$error?:null,'has_page_not_found'=>stripos($body,'Page Not Found')!==false,'has_reward_not_found'=>stripos($body,'Reward Not Found')!==false,'has_scratch'=>stripos($body,'scratch')!==false,'img_srcs'=>preg_match_all('/<img[^>]+src=["\']([^"\']+)["\']/i',$body,$m)?array_slice($m[1],0,20):[],'excerpt'=>substr((string)preg_replace('/\s+/',' ',$body),0,8000)];}

$r=['status'=>'running','generated_at'=>now()->toIso8601String()];
$r['routes']=[];
foreach(app('router')->getRoutes() as $route){$uri=$route->uri();$low=strtolower($uri);if(str_contains($low,'card')||str_contains($low,'reward')||str_contains($low,'scratch')){$r['routes'][]=['uri'=>$uri,'methods'=>$route->methods(),'name'=>$route->getName(),'action'=>$route->getActionName(),'middleware'=>$route->gatherMiddleware()];}}
$r['files']=[
 'app/Http/Controllers/Member/CardController.php'=>txt($appRoot.'/app/Http/Controllers/Member/CardController.php'),
 'app/Http/Controllers/Member/ScratchCardController.php'=>txt($appRoot.'/app/Http/Controllers/Member/ScratchCardController.php'),
 'app/Models/Card.php'=>txt($appRoot.'/app/Models/Card.php'),
 'app/Models/Reward.php'=>txt($appRoot.'/app/Models/Reward.php'),
 'resources/views/member/card/reward.blade.php'=>txt($appRoot.'/resources/views/member/card/reward.blade.php'),
 'resources/views/member/card/reward-404.blade.php'=>txt($appRoot.'/resources/views/member/card/reward-404.blade.php'),
 'resources/views/member/scratch-cards/show.blade.php'=>txt($appRoot.'/resources/views/member/scratch-cards/show.blade.php'),
 'resources/views/components/member/reward-card.blade.php'=>txt($appRoot.'/resources/views/components/member/reward-card.blade.php'),
];
$r['relation_tables']=[];
$tables=DB::select('SHOW TABLES');$db=DB::getDatabaseName();$key='Tables_in_'.$db;
foreach($tables as $t){$table=$t->$key??array_values((array)$t)[0]??null;if(!$table)continue;$cols=Schema::getColumnListing($table);$hasCard=in_array('card_id',$cols,true);$hasReward=in_array('reward_id',$cols,true);if(!$hasCard&&!$hasReward)continue;$entry=['columns'=>$cols,'count'=>DB::table($table)->count(),'rows'=>[]];$q=DB::table($table);$q->where(function($qq)use($hasCard,$hasReward,$cardId,$rewardIds){if($hasCard)$qq->orWhere('card_id',$cardId);if($hasReward)$qq->orWhereIn('reward_id',$rewardIds);});$entry['rows']=$q->limit(100)->get()->all();$r['relation_tables'][$table]=$entry;}
$base='https://app.placesrewards.com';
$r['page_probes']=[];foreach($rewardIds as $id){$r['page_probes'][$id]=probe("$base/en-us/card/$cardId/$id");}
$r['scratch_probe']=probe("$base/demo/treasure-hunt/scratch/play");
foreach(['cover.webp','winner.webp','loser.webp'] as $f){$r['asset_probes'][$f]=probe("$base/files/demo/treasure-hunt/scratch/$f");}
$r['status']='completed';
@mkdir(dirname($out),0755,true);$json=json_encode($r,JSON_PRETTY_PRINT|JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE|JSON_INVALID_UTF8_SUBSTITUTE|JSON_PARTIAL_OUTPUT_ON_ERROR);file_put_contents($out,(string)$json,LOCK_EX);echo json_encode(['status'=>'completed','routes'=>count($r['routes']),'relation_tables'=>count($r['relation_tables'])]),"\n";
