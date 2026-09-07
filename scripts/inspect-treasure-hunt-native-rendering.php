<?php

declare(strict_types=1);

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Route;

$agentRoot='/home/placevle/placesrewards-agent-server';
$appRoot='/home/placevle/app.placesrewards.com';
$out=$agentRoot.'/results/campaigns/treasure-hunt-native-render-inspection.json';

require $appRoot.'/vendor/autoload.php';
$app=require $appRoot.'/bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();

function clip(string $s, int $n=12000): string { return mb_substr($s,0,$n); }
function getText(string $url): array {
    $ch=curl_init($url);
    curl_setopt_array($ch,[CURLOPT_RETURNTRANSFER=>true,CURLOPT_FOLLOWLOCATION=>true,CURLOPT_TIMEOUT=>20,CURLOPT_USERAGENT=>'PlacesRewards-Render-Audit/1.0']);
    $body=(string)curl_exec($ch);
    $code=(int)curl_getinfo($ch,CURLINFO_HTTP_CODE);
    $effective=(string)curl_getinfo($ch,CURLINFO_EFFECTIVE_URL);
    $error=curl_error($ch)?:null;
    curl_close($ch);
    $text=html_entity_decode(strip_tags($body));
    $text=preg_replace('/\s+/u',' ',(string)$text);
    return ['url'=>$url,'effective_url'=>$effective,'status'=>$code,'error'=>$error,'text'=>clip(trim((string)$text),8000),'body_length'=>strlen($body)];
}

$result=['status'=>'completed','generated_at'=>now()->toIso8601String()];
$result['card_row']=(array)DB::table('cards')->where('id','95cbd0bf-8bbb-436d-b7c6-a2e1e558db25')->first();
$result['public_card']=getText('https://app.placesrewards.com/en-us/card/95cbd0bf-8bbb-436d-b7c6-a2e1e558db25');

$controller=$appRoot.'/app/Http/Controllers/Member/CardController.php';
$result['card_controller_source']=is_file($controller)?clip((string)file_get_contents($controller),30000):null;

$matches=[];
$roots=[$appRoot.'/resources/views',$appRoot.'/app/View'];
foreach($roots as $root){
    if(!is_dir($root)) continue;
    $it=new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root,FilesystemIterator::SKIP_DOTS));
    foreach($it as $file){
        if(!$file->isFile()) continue;
        $path=$file->getPathname();
        if(!preg_match('/\.(php|blade\.php)$/',$path)) continue;
        $src=(string)@file_get_contents($path);
        if(str_contains($src,'card->description') || str_contains($src,"['description']") || str_contains($src,'$card->title') || str_contains($src,'$card->head')){
            $rel=str_replace($appRoot.'/','',$path);
            $matches[$rel]=clip($src,16000);
            if(count($matches)>=12) break;
        }
    }
}
$result['candidate_views']=$matches;

@mkdir(dirname($out),0755,true);
file_put_contents($out,json_encode($result,JSON_PRETTY_PRINT|JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE),LOCK_EX);
echo json_encode(['status'=>'completed','candidate_views'=>count($matches),'public_status'=>$result['public_card']['status']]),"\n";
