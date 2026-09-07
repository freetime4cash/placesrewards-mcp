<?php

declare(strict_types=1);

use Illuminate\Support\Facades\DB;

$agentRoot='/home/placevle/placesrewards-agent-server';
$appRoot='/home/placevle/app.placesrewards.com';
$out=$agentRoot.'/results/campaigns/treasure-hunt-native-render-inspection.json';
@mkdir(dirname($out),0755,true);

$result=['status'=>'running','generated_at'=>date(DATE_ATOM)];
try {
    require $appRoot.'/vendor/autoload.php';
    $app=require $appRoot.'/bootstrap/app.php';
    $app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();
    $result['generated_at']=now()->toIso8601String();

    $clip=static function(string $s,int $n=16000): string { return function_exists('mb_substr')?mb_substr($s,0,$n):substr($s,0,$n); };
    $fetch=static function(string $url) use ($clip): array {
        $ctx=stream_context_create(['http'=>['method'=>'GET','timeout'=>20,'ignore_errors'=>true,'follow_location'=>1,'max_redirects'=>5,'header'=>"User-Agent: PlacesRewards-Native-Render-Audit/1.0\r\nAccept: text/html\r\n"]]);
        $body=@file_get_contents($url,false,$ctx);
        $headers=$http_response_header??[];
        $status=0;
        foreach($headers as $h){ if(preg_match('#^HTTP/\S+\s+(\d{3})#',$h,$m)) $status=(int)$m[1]; }
        $body=is_string($body)?$body:'';
        $text=html_entity_decode(strip_tags($body),ENT_QUOTES|ENT_HTML5,'UTF-8');
        $text=preg_replace('/\s+/u',' ',(string)$text);
        return ['url'=>$url,'status'=>$status,'text'=>$clip(trim((string)$text),12000),'body_length'=>strlen($body),'headers'=>$headers];
    };

    $cardId='95cbd0bf-8bbb-436d-b7c6-a2e1e558db25';
    $row=DB::table('cards')->where('id',$cardId)->first();
    $result['card_row']=$row?(array)$row:null;
    $result['public_card']=$fetch('https://app.placesrewards.com/en-us/card/'.$cardId);
    $result['public_home']=$fetch('https://app.placesrewards.com/en-us');

    $controller=$appRoot.'/app/Http/Controllers/Member/CardController.php';
    $result['card_controller_source']=is_file($controller)?$clip((string)file_get_contents($controller),40000):null;

    $matches=[];
    $root=$appRoot.'/resources/views';
    if(is_dir($root)){
        $it=new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root,FilesystemIterator::SKIP_DOTS));
        foreach($it as $file){
            if(!$file->isFile()) continue;
            $path=$file->getPathname();
            if(!str_ends_with($path,'.php')) continue;
            $src=(string)@file_get_contents($path);
            $needles=['$card->description','$card->title','$card->head','card.description','card.title','card.head','showCard'];
            $hit=false; foreach($needles as $needle){ if(str_contains($src,$needle)){ $hit=true; break; } }
            if(!$hit) continue;
            $rel=str_replace($appRoot.'/','',$path);
            $matches[$rel]=$clip($src,20000);
            if(count($matches)>=20) break;
        }
    }
    $result['candidate_views']=$matches;

    $result['expected_markers']=[
      'hunter_passport'=>'Northeast Ohio Treasure Hunt Hunter Passport',
      'welcome'=>'100 demo welcome points',
      'next'=>'Explorer Trail',
    ];
    $pageText=(string)($result['public_card']['text']??'');
    $result['render_checks']=[];
    foreach($result['expected_markers'] as $key=>$marker) $result['render_checks'][$key]=stripos($pageText,$marker)!==false;
    $result['status']='completed';
} catch(Throwable $e) {
    $result['status']='failed';
    $result['error']=$e->getMessage();
    $result['trace']=substr($e->getTraceAsString(),0,6000);
}
$result['completed_at']=date(DATE_ATOM);
file_put_contents($out,json_encode($result,JSON_PRETTY_PRINT|JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE),LOCK_EX);
echo json_encode(['status'=>$result['status'],'output'=>$out]),"\n";
