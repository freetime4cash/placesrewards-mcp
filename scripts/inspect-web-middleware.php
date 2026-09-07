<?php

declare(strict_types=1);

$agentRoot='/home/placevle/placesrewards-agent-server';
$appRoot='/home/placevle/app.placesrewards.com';
$out=$agentRoot.'/results/campaigns/web-middleware-inspection.json';

require $appRoot.'/vendor/autoload.php';
$app=require $appRoot.'/bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();
$router=$app->make('router');
$groups=$router->getMiddlewareGroups();
$aliases=$router->getMiddleware();
$web=$groups['web']??[];
$files=[];
foreach($web as $entry){
    $class=is_string($entry)?explode(':',$entry,2)[0]:'';
    if($class && class_exists($class)){
        try{
            $ref=new ReflectionClass($class);
            $file=$ref->getFileName();
            if($file && str_starts_with($file,$appRoot)){
                $text=(string)file_get_contents($file);
                $files[]=['class'=>$class,'file'=>str_replace($appRoot.'/','',$file),'content'=>substr($text,0,20000)];
            }
        }catch(Throwable $e){}
    }
}
$result=['status'=>'completed','generated_at'=>gmdate('c'),'web_group'=>$web,'aliases'=>$aliases,'application_middleware_sources'=>$files];
@mkdir(dirname($out),0755,true);
file_put_contents($out,json_encode($result,JSON_PRETTY_PRINT|JSON_UNESCAPED_SLASHES),LOCK_EX);
echo json_encode(['status'=>'completed','web_count'=>count($web),'source_count'=>count($files)]),"\n";
