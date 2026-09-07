<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

$agentRoot='/home/placevle/placesrewards-agent-server';
$appRoot='/home/placevle/app.placesrewards.com';
$out=$agentRoot.'/results/campaigns/treasure-hunt-scratch-images.json';
$gameId='1fefb288-a8cc-46d4-a4a3-04fe56f91329';

require $appRoot.'/vendor/autoload.php';
$app=require $appRoot.'/bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();

function webpProbe(string $url): array {
    $ch=curl_init($url);
    curl_setopt_array($ch,[CURLOPT_RETURNTRANSFER=>true,CURLOPT_FOLLOWLOCATION=>true,CURLOPT_TIMEOUT=>20,CURLOPT_CONNECTTIMEOUT=>8,CURLOPT_USERAGENT=>'PlacesRewards-ScratchAssetVerifier/2.0',CURLOPT_HTTPHEADER=>['Cache-Control: no-cache']]);
    $body=(string)curl_exec($ch);$status=(int)curl_getinfo($ch,CURLINFO_RESPONSE_CODE);$type=(string)curl_getinfo($ch,CURLINFO_CONTENT_TYPE);$error=(string)curl_error($ch);curl_close($ch);
    return ['status'=>$status,'content_type'=>$type,'bytes'=>strlen($body),'error'=>$error?:null,'valid_webp'=>$status===200&&str_starts_with(strtolower($type),'image/webp')&&strlen($body)>1000];
}

$result=['status'=>'running','game_id'=>$gameId,'started_at'=>now()->toIso8601String(),'files'=>[]];
try {
    if(!Schema::hasTable('scratch_games')) throw new RuntimeException('scratch_games table not found');
    foreach(['cover_image','win_image','loss_image'] as $column) if(!Schema::hasColumn('scratch_games',$column)) throw new RuntimeException("scratch_games.$column not found");
    $game=DB::table('scratch_games')->where('id',$gameId)->first();
    if(!$game) throw new RuntimeException('Treasure Hunt scratch game not found');

    $srcDir=$agentRoot.'/assets/treasure-hunt-v4/scratch';
    $storageDir=$appRoot.'/storage/app/public/treasure-hunt/scratch';
    $filesDir=$appRoot.'/public/files/demo/treasure-hunt/scratch';
    @mkdir($storageDir,0755,true);@mkdir($filesDir,0755,true);
    $map=['cover'=>'cover.webp','winner'=>'winner.webp','loser'=>'loser.webp'];

    foreach($map as $key=>$file){
        $src=$srcDir.'/'.$file;$storage=$storageDir.'/'.$file;$public=$filesDir.'/'.$file;
        if(!is_file($src)) throw new RuntimeException("Missing source asset: $src");
        if(!copy($src,$storage)) throw new RuntimeException("Could not copy $file to Laravel public storage");

        // Normalize the WebP encoding when GD supports it. This removes browser-specific
        // decode problems while preserving the approved artwork.
        $reencoded=false;
        if(function_exists('imagecreatefromwebp') && function_exists('imagewebp')){
            $img=@imagecreatefromwebp($storage);
            if($img){
                $tmp=$storage.'.tmp.webp';
                if(@imagewebp($img,$tmp,92) && is_file($tmp) && filesize($tmp)>1000){@rename($tmp,$storage);$reencoded=true;}
                @imagedestroy($img);@unlink($tmp);
            }
        }
        if(!copy($storage,$public)) throw new RuntimeException("Could not mirror $file to public/files");
        @chmod($storage,0644);@chmod($public,0644);@touch($storage);@touch($public);
        $info=@getimagesize($storage);
        if(!$info || ($info['mime']??'')!=='image/webp') throw new RuntimeException("Invalid WebP asset: $file");
        $result['files'][$key]=['source'=>$src,'storage'=>$storage,'public_mirror'=>$public,'bytes'=>filesize($storage),'sha1'=>sha1_file($storage),'width'=>$info[0]??null,'height'=>$info[1]??null,'mime'=>$info['mime']??null,'reencoded'=>$reencoded];
    }

    $publicStorage=$appRoot.'/public/storage';$storageTarget=$appRoot.'/storage/app/public';
    if(!is_link($publicStorage) && !is_dir($publicStorage)){try{Artisan::call('storage:link');}catch(Throwable $e){}}
    if(!is_link($publicStorage) && !is_dir($publicStorage)) @symlink($storageTarget,$publicStorage);
    if(!is_link($publicStorage) && !is_dir($publicStorage)) throw new RuntimeException('Laravel public/storage link is unavailable');

    $values=['cover_image'=>'treasure-hunt/scratch/cover.webp','win_image'=>'treasure-hunt/scratch/winner.webp','loss_image'=>'treasure-hunt/scratch/loser.webp','updated_at'=>now()];
    DB::table('scratch_games')->where('id',$gameId)->update($values);
    $after=DB::table('scratch_games')->where('id',$gameId)->first();

    $storageUrls=['cover'=>'https://app.placesrewards.com/storage/treasure-hunt/scratch/cover.webp','winner'=>'https://app.placesrewards.com/storage/treasure-hunt/scratch/winner.webp','loser'=>'https://app.placesrewards.com/storage/treasure-hunt/scratch/loser.webp'];
    $mirrorUrls=['cover'=>'https://app.placesrewards.com/files/demo/treasure-hunt/scratch/cover.webp','winner'=>'https://app.placesrewards.com/files/demo/treasure-hunt/scratch/winner.webp','loser'=>'https://app.placesrewards.com/files/demo/treasure-hunt/scratch/loser.webp'];
    $probes=['storage'=>[],'public_mirror'=>[]];
    foreach($storageUrls as $key=>$url)$probes['storage'][$key]=webpProbe($url.'?v='.time());
    foreach($mirrorUrls as $key=>$url)$probes['public_mirror'][$key]=webpProbe($url.'?v='.time());

    $result['database']=['before'=>['cover_image'=>$game->cover_image,'win_image'=>$game->win_image,'loss_image'=>$game->loss_image],'after'=>['cover_image'=>$after->cover_image,'win_image'=>$after->win_image,'loss_image'=>$after->loss_image]];
    $result['public_urls']=$storageUrls;$result['fallback_urls']=$mirrorUrls;$result['http_probes']=$probes;
    $result['storage_link']=['path'=>$publicStorage,'is_link'=>is_link($publicStorage),'is_dir'=>is_dir($publicStorage)];
    $httpOk=true;foreach($probes['storage'] as $p)if(empty($p['valid_webp']))$httpOk=false;foreach($probes['public_mirror'] as $p)if(empty($p['valid_webp']))$httpOk=false;
    $result['verified']=is_file($storageDir.'/cover.webp')&&is_file($storageDir.'/winner.webp')&&is_file($storageDir.'/loser.webp')&&is_file($filesDir.'/cover.webp')&&is_file($filesDir.'/winner.webp')&&is_file($filesDir.'/loser.webp')&&$after->cover_image==='treasure-hunt/scratch/cover.webp'&&$after->win_image==='treasure-hunt/scratch/winner.webp'&&$after->loss_image==='treasure-hunt/scratch/loser.webp'&&$httpOk;
    $result['status']=$result['verified']?'completed':'failed';
} catch(Throwable $e) {
    $result['status']='failed';$result['verified']=false;$result['error']=$e->getMessage();
}
$result['completed_at']=now()->toIso8601String();
@mkdir(dirname($out),0755,true);file_put_contents($out,json_encode($result,JSON_PRETTY_PRINT|JSON_UNESCAPED_SLASHES),LOCK_EX);
echo json_encode(['status'=>$result['status'],'verified'=>$result['verified']??false,'error'=>$result['error']??null]),"\n";
exit($result['status']==='completed'?0:1);
