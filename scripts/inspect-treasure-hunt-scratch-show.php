<?php

declare(strict_types=1);
$agentRoot='/home/placevle/placesrewards-agent-server';$appRoot='/home/placevle/app.placesrewards.com';$out=$agentRoot.'/results/campaigns/treasure-hunt-scratch-show.json';
require $appRoot.'/vendor/autoload.php';$app=require $appRoot.'/bootstrap/app.php';$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();
function methodSource(string $class,string $method):?string{if(!class_exists($class)||!method_exists($class,$method))return null;$r=new ReflectionMethod($class,$method);$f=$r->getFileName();if(!$f)return null;$lines=file($f);return implode('',array_slice($lines,$r->getStartLine()-1,$r->getEndLine()-$r->getStartLine()+1));}
$controller='App\\Http\\Controllers\\Member\\ScratchCardController';$model='App\\Models\\ScratchCard';
$result=['status'=>'completed','generated_at'=>now()->toIso8601String(),'show_method'=>methodSource($controller,'show'),'index_method'=>methodSource($controller,'index'),'model_file'=>null];
if(class_exists($model)){$r=new ReflectionClass($model);$f=$r->getFileName();if($f){$src=file_get_contents($f);$result['model_file']=strlen($src)<20000?$src:substr($src,0,20000);}}
@mkdir(dirname($out),0755,true);file_put_contents($out,json_encode($result,JSON_PRETTY_PRINT|JSON_UNESCAPED_SLASHES),LOCK_EX);echo json_encode(['status'=>'completed','output'=>$out]),"\n";
