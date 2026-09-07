<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Artisan;

$agentRoot='/home/placevle/placesrewards-agent-server';
$appRoot='/home/placevle/app.placesrewards.com';
$assetRoot=$agentRoot.'/assets/treasure-hunt-v4';
$resultPath=$agentRoot.'/results/campaigns/treasure-hunt-native-demo-v4.json';

require $appRoot.'/vendor/autoload.php';
$app=require $appRoot.'/bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();

$backupDir=$agentRoot.'/data/backups/treasure-hunt-v4-'.date('Ymd-His');
@mkdir($backupDir,0755,true);

$controllerTarget=$appRoot.'/app/Http/Controllers/Demo/TreasureHuntDemoController.php';
$indexTarget=$appRoot.'/resources/views/demo/treasure-hunt/index.blade.php';
$moduleTarget=$appRoot.'/resources/views/demo/treasure-hunt/module.blade.php';
$routeTarget=$appRoot.'/routes/treasure-hunt-v4.php';
@mkdir(dirname($controllerTarget),0755,true);
@mkdir(dirname($indexTarget),0755,true);

foreach([$controllerTarget,$indexTarget,$moduleTarget,$routeTarget] as $target){
    if(is_file($target)) @copy($target,$backupDir.'/'.basename($target));
}

copy($assetRoot.'/TreasureHuntDemoController.php',$controllerTarget);
copy($assetRoot.'/index.blade.php',$indexTarget);
copy($assetRoot.'/module.blade.php',$moduleTarget);
copy($assetRoot.'/routes.php',$routeTarget);

$webPath=$appRoot.'/routes/web.php';
$marker="require __DIR__.'/treasure-hunt-v4.php';";
$web=(string)file_get_contents($webPath);
if(!str_contains($web,$marker)){
    @copy($webPath,$backupDir.'/web.php');
    if(str_contains($web,'?>')){
        $pos=strrpos($web,'?>');
        $web=substr($web,0,$pos)."\n\n// Treasure Hunt native demo v4\n".$marker."\n".substr($web,$pos);
    }else{
        $web=rtrim($web)."\n\n// Treasure Hunt native demo v4\n".$marker."\n";
    }
    file_put_contents($webPath,$web,LOCK_EX);
}

$runScript=static function(string $path): string {
    if(!is_file($path)) return 'not_run';
    $command=escapeshellarg(PHP_BINARY).' '.escapeshellarg($path);
    passthru($command,$exit);
    return $exit===0?'completed':'failed';
};

$localeBypassStatus=$runScript($agentRoot.'/scripts/patch-demo-locale-bypass.php');
$nativeContentStatus=$runScript($agentRoot.'/scripts/apply-treasure-hunt-native-card-content.php');
$nativeVisualStatus=$runScript($agentRoot.'/scripts/patch-treasure-hunt-native-card-visuals.php');

try{Artisan::call('route:clear');}catch(Throwable $e){}
try{Artisan::call('view:clear');}catch(Throwable $e){}
try{Artisan::call('config:clear');}catch(Throwable $e){}
try{Artisan::call('cache:clear');}catch(Throwable $e){}
try{Artisan::call('optimize:clear');}catch(Throwable $e){}

$runtimeInspection=$runScript($agentRoot.'/scripts/inspect-treasure-hunt-live-card-runtime.php');
$destinationInspection=$runScript($agentRoot.'/scripts/inspect-treasure-hunt-native-destinations.php');
$middlewareInspection=$runScript($agentRoot.'/scripts/inspect-web-middleware.php');
$actualLinkVerification=$runScript($agentRoot.'/scripts/verify-treasure-hunt-actual-links.php');

$links=[
  1=>'https://app.placesrewards.com/demo/treasure-hunt/loyalty',
  2=>'https://app.placesrewards.com/demo/treasure-hunt/stamps',
  3=>'https://app.placesrewards.com/demo/treasure-hunt/directory',
  4=>'https://app.placesrewards.com/demo/treasure-hunt/reward',
  5=>'https://app.placesrewards.com/demo/treasure-hunt/check-in',
  6=>'https://app.placesrewards.com/demo/treasure-hunt/prize',
  7=>'https://app.placesrewards.com/demo/treasure-hunt/referrals',
  8=>'https://app.placesrewards.com/demo/treasure-hunt/vip',
  9=>'https://app.placesrewards.com/demo/treasure-hunt/scratch',
  10=>'https://app.placesrewards.com/demo/treasure-hunt/voucher',
  11=>'https://app.placesrewards.com/demo/treasure-hunt/retention',
  12=>'https://app.placesrewards.com/demo/treasure-hunt/analytics',
];
$overall=$actualLinkVerification==='completed'?'completed':'verification_failed';
$result=[
  'status'=>$overall,
  'workflow_url'=>'https://app.placesrewards.com/demo/treasure-hunt',
  'links'=>$links,
  'locale_demo_bypass'=>$localeBypassStatus,
  'native_card_content'=>$nativeContentStatus,
  'native_card_visuals'=>$nativeVisualStatus,
  'runtime_inspection'=>$runtimeInspection,
  'destination_inspection'=>$destinationInspection,
  'middleware_inspection'=>$middlewareInspection,
  'actual_link_verification'=>$actualLinkVerification,
  'deployed_at'=>now()->toIso8601String(),
];
@mkdir(dirname($resultPath),0755,true);
file_put_contents($resultPath,json_encode($result,JSON_PRETTY_PRINT|JSON_UNESCAPED_SLASHES),LOCK_EX);
echo json_encode(['status'=>$overall,'module_count'=>12,'actual_link_verification'=>$actualLinkVerification,'workflow_url'=>$result['workflow_url']]),"\n";
exit($overall==='completed'?0:1);
