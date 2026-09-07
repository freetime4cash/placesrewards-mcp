<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Artisan;

$agentRoot='/home/placevle/placesrewards-agent-server';
$appRoot='/home/placevle/app.placesrewards.com';
$out=$agentRoot.'/results/campaigns/treasure-hunt-native-card-visuals.json';

$result=['status'=>'running','started_at'=>date(DATE_ATOM),'action'=>'rollback_unsafe_shared_component_patch','files'=>[]];
try {
    $backupDirs=glob($agentRoot.'/data/backups/treasure-hunt-native-visuals-*',GLOB_ONLYDIR) ?: [];
    rsort($backupDirs,SORT_STRING);
    $targets=[
      'loyalty'=>$appRoot.'/resources/views/components/member/card.blade.php',
      'stamp'=>$appRoot.'/resources/views/components/member/stamp-card.blade.php',
      'voucher'=>$appRoot.'/resources/views/components/member/voucher-card.blade.php',
    ];
    foreach($targets as $name=>$target){
        $current=is_file($target)?(string)file_get_contents($target):'';
        if(!str_contains($current,'TREASURE_HUNT_STRATEGIC_CARD_CONTENT')){
            $result['files'][$name]=['status'=>'clean','path'=>$target];
            continue;
        }
        $restored=false;
        foreach($backupDirs as $dir){
            $candidate=$dir.'/'.basename($target);
            if(!is_file($candidate)) continue;
            $backup=(string)file_get_contents($candidate);
            if(str_contains($backup,'TREASURE_HUNT_STRATEGIC_CARD_CONTENT')) continue;
            copy($candidate,$target);
            $restored=true;
            $result['files'][$name]=['status'=>'restored','path'=>$target,'backup'=>$candidate];
            break;
        }
        if(!$restored) $result['files'][$name]=['status'=>'backup_not_found','path'=>$target];
    }
    try{Artisan::call('view:clear');}catch(Throwable $e){}
    try{Artisan::call('cache:clear');}catch(Throwable $e){}
    try{Artisan::call('optimize:clear');}catch(Throwable $e){}
    $ok=true; foreach($targets as $target){ $src=is_file($target)?(string)file_get_contents($target):''; if(str_contains($src,'TREASURE_HUNT_STRATEGIC_CARD_CONTENT')) $ok=false; }
    $result['status']=$ok?'completed':'failed';
    $result['verified_clean']=$ok;
} catch(Throwable $e){
    $result['status']='failed'; $result['error']=$e->getMessage();
}
$result['completed_at']=date(DATE_ATOM);
@mkdir(dirname($out),0755,true);
file_put_contents($out,json_encode($result,JSON_PRETTY_PRINT|JSON_UNESCAPED_SLASHES),LOCK_EX);
echo json_encode(['status'=>$result['status'],'verified_clean'=>$result['verified_clean']??false]),"\n";
exit($result['status']==='completed'?0:1);
