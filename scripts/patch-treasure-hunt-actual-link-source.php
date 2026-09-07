<?php

declare(strict_types=1);

$agentRoot='/home/placevle/placesrewards-agent-server';
$appRoot='/home/placevle/app.placesrewards.com';
$old='https://app.placesrewards.com/demo/scratch-win';
$new='https://app.placesrewards.com/demo/treasure-hunt/scratch/play';
$targets=[
    $agentRoot.'/assets/treasure-hunt-v4/TreasureHuntDemoController.php',
    $appRoot.'/app/Http/Controllers/Demo/TreasureHuntDemoController.php',
];

$result=['status'=>'running','patched_at'=>gmdate('c'),'targets'=>[]];
$all=true;
foreach($targets as $path){
    if(!is_file($path)){
        $result['targets'][]=['path'=>$path,'status'=>'missing'];
        $all=false;
        continue;
    }
    $content=(string)file_get_contents($path);
    $before=substr_count($content,$old);
    if($before>0){
        $content=str_replace($old,$new,$content,$count);
        file_put_contents($path,$content,LOCK_EX);
    }else{
        $count=0;
    }
    $afterContent=(string)file_get_contents($path);
    $passed=str_contains($afterContent,$new) && !str_contains($afterContent,$old);
    $result['targets'][]=['path'=>$path,'old_occurrences_before'=>$before,'replacements'=>$count,'new_link_present'=>str_contains($afterContent,$new),'passed'=>$passed];
    if(!$passed)$all=false;
}
$result['status']=$all?'passed':'failed';
$out=$agentRoot.'/results/campaigns/treasure-hunt-actual-link-source-patch.json';
@mkdir(dirname($out),0755,true);
file_put_contents($out,json_encode($result,JSON_PRETTY_PRINT|JSON_UNESCAPED_SLASHES),LOCK_EX);
echo json_encode($result),"\n";
exit($all?0:1);
