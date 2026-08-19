<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Route;

$agentRoot='/home/placevle/placesrewards-agent-server';
$appRoot='/home/placevle/app.placesrewards.com';
$out=$agentRoot.'/results/campaigns/363-demo-route-links.json';

require $appRoot.'/vendor/autoload.php';
$app=require $appRoot.'/bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();

$keywords=['card','reward','stamp','voucher','scratch','club'];
$routes=[];
foreach(Route::getRoutes() as $route){
    $uri=$route->uri();
    if(str_starts_with($uri,'api/')) continue;
    $lower=strtolower($uri);
    $match=false; foreach($keywords as $k){if(str_contains($lower,$k)){$match=true;break;}}
    if(!$match)continue;
    $methods=$route->methods();
    if(!in_array('GET',$methods,true)&&!in_array('HEAD',$methods,true))continue;
    $routes[]=[
      'methods'=>$methods,
      'uri'=>$uri,
      'name'=>$route->getName(),
      'action'=>$route->getActionName(),
      'middleware'=>$route->gatherMiddleware(),
    ];
}
file_put_contents($out,json_encode(['generated_at'=>now()->toIso8601String(),'base_url'=>'https://app.placesrewards.com','routes'=>$routes],JSON_PRETTY_PRINT|JSON_UNESCAPED_SLASHES));
echo "WROTE $out\n";
