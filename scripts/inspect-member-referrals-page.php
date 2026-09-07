<?php

declare(strict_types=1);

$agentRoot='/home/placevle/placesrewards-agent-server';
$appRoot='/home/placevle/app.placesrewards.com';
$out=$agentRoot.'/results/campaigns/member-referrals-page-inspection.json';
require $appRoot.'/vendor/autoload.php';
$app=require $appRoot.'/bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();

function methodSource(string $action): ?array {
    if(!str_contains($action,'@')) return null;
    [$class,$method]=explode('@',$action,2);
    if(!class_exists($class)||!method_exists($class,$method)) return null;
    $r=new ReflectionMethod($class,$method);$file=$r->getFileName();
    if(!$file||!is_file($file)) return null;
    $lines=file($file);$start=max(0,$r->getStartLine()-1);$len=$r->getEndLine()-$r->getStartLine()+1;
    return ['class'=>$class,'method'=>$method,'file'=>$file,'source'=>implode('',array_slice($lines,$start,$len))];
}
$result=['status'=>'running','generated_at'=>now()->toIso8601String(),'routes'=>[],'views'=>[]];
foreach(app('router')->getRoutes() as $route){
    $uri=$route->uri();
    if($uri==='{locale}/referrals' || str_contains($uri,'referral')){
        $action=$route->getActionName();
        $result['routes'][]=['uri'=>$uri,'methods'=>$route->methods(),'name'=>$route->getName(),'action'=>$action,'middleware'=>$route->gatherMiddleware(),'method_source'=>methodSource($action)];
    }
}
$root=$appRoot.'/resources/views';
$rii=new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root,FilesystemIterator::SKIP_DOTS));
foreach($rii as $f){
    if(!$f->isFile()||!str_ends_with($f->getFilename(),'.blade.php')) continue;
    $rel=str_replace($appRoot.'/','',$f->getPathname());
    if(str_contains(strtolower($rel),'referral')){
        $content=(string)file_get_contents($f->getPathname());
        $result['views'][$rel]=substr($content,0,60000);
    }
}
$result['status']='completed';
@mkdir(dirname($out),0755,true);file_put_contents($out,json_encode($result,JSON_PRETTY_PRINT|JSON_UNESCAPED_SLASHES|JSON_INVALID_UTF8_SUBSTITUTE),LOCK_EX);
echo json_encode(['status'=>'completed','routes'=>count($result['routes']),'views'=>count($result['views'])]),"\n";
