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

$convert=trim((string)shell_exec('command -v convert 2>/dev/null'));

function decodedWebpFile(string $path,string $convert): array {
    if(!is_file($path)) return ['ok'=>false,'error'=>'missing'];
    $info=@getimagesize($path);
    $basic=(bool)$info && ($info['mime']??'')==='image/webp';
    $decoded=$basic;
    $output='';$exit=null;
    if($convert!==''){
        $lines=[];$cmd=escapeshellarg($convert).' '.escapeshellarg($path).' -format "%m %w %h" info: 2>&1';
        exec($cmd,$lines,$exit);$output=implode("\n",$lines);$decoded=$basic&&$exit===0;
    }
    return ['ok'=>$decoded,'basic'=>$basic,'width'=>$info[0]??null,'height'=>$info[1]??null,'mime'=>$info['mime']??null,'decoder_exit'=>$exit,'decoder_output'=>$output];
}

function webpProbe(string $url,string $convert): array {
    $ch=curl_init($url);
    curl_setopt_array($ch,[CURLOPT_RETURNTRANSFER=>true,CURLOPT_FOLLOWLOCATION=>true,CURLOPT_TIMEOUT=>20,CURLOPT_CONNECTTIMEOUT=>8,CURLOPT_USERAGENT=>'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 Chrome/152 Safari/537.36',CURLOPT_HTTPHEADER=>['Accept: image/avif,image/webp,image/apng,image/svg+xml,image/*,*/*;q=0.8','Cache-Control: no-cache']]);
    $body=(string)curl_exec($ch);$status=(int)curl_getinfo($ch,CURLINFO_RESPONSE_CODE);$type=(string)curl_getinfo($ch,CURLINFO_CONTENT_TYPE);$error=(string)curl_error($ch);curl_close($ch);
    $riff=strlen($body)>=12 && substr($body,0,4)==='RIFF' && substr($body,8,4)==='WEBP';
    $decodeOk=$riff;
    $decoderExit=null;$decoderOutput='';
    if($riff && $convert!==''){
        $tmp=tempnam(sys_get_temp_dir(),'pr-webp-');@file_put_contents($tmp,$body,LOCK_EX);
        $lines=[];$cmd=escapeshellarg($convert).' '.escapeshellarg($tmp).' -format "%m %w %h" info: 2>&1';exec($cmd,$lines,$decoderExit);$decoderOutput=implode("\n",$lines);@unlink($tmp);$decodeOk=$decoderExit===0;
    }
    return ['status'=>$status,'content_type'=>$type,'bytes'=>strlen($body),'riff_webp'=>$riff,'decoder_exit'=>$decoderExit,'decoder_output'=>$decoderOutput,'error'=>$error?:null,'valid_webp'=>$status===200&&str_starts_with(strtolower($type),'image/webp')&&strlen($body)>1000&&$riff&&$decodeOk];
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

    // COVER: never copy the legacy cover.webp again. It was a truncated WebP that
    // returned HTTP 200 but failed real browser/ImageMagick decoding. Render a new,
    // standards-compliant WebP from the clean SVG source every deployment.
    $coverSvg=$srcDir.'/cover.svg';
    $coverStorage=$storageDir.'/cover.webp';
    $coverPublic=$filesDir.'/cover.webp';
    if(!is_file($coverSvg)) throw new RuntimeException('Missing clean cover SVG source');
    if($convert==='') throw new RuntimeException('ImageMagick convert is required for clean cover generation');
    $lines=[];$cmd=escapeshellarg($convert).' '.escapeshellarg($coverSvg).' -strip -colorspace sRGB -resize 1200x900! -quality 92 '.escapeshellarg($coverStorage).' 2>&1';
    exec($cmd,$lines,$coverExit);
    if($coverExit!==0) throw new RuntimeException('Cover SVG to WebP conversion failed: '.implode(" | ",$lines));
    $coverDecode=decodedWebpFile($coverStorage,$convert);
    if(empty($coverDecode['ok']) || ($coverDecode['width']??0)!==1200 || ($coverDecode['height']??0)!==900) throw new RuntimeException('Generated cover failed decoder/dimension verification');
    if(!copy($coverStorage,$coverPublic)) throw new RuntimeException('Could not mirror generated cover');
    @chmod($coverStorage,0644);@chmod($coverPublic,0644);@touch($coverStorage);@touch($coverPublic);
    $result['files']['cover']=['source'=>$coverSvg,'storage'=>$coverStorage,'public_mirror'=>$coverPublic,'bytes'=>filesize($coverStorage),'sha1'=>sha1_file($coverStorage),'width'=>$coverDecode['width'],'height'=>$coverDecode['height'],'mime'=>$coverDecode['mime'],'generated_from_svg'=>true,'decoder'=>$coverDecode];

    // WINNER / LOSER: preserve the approved artwork, but normalize the WebP encoding.
    foreach(['winner'=>'winner.webp','loser'=>'loser.webp'] as $key=>$file){
        $src=$srcDir.'/'.$file;$storage=$storageDir.'/'.$file;$public=$filesDir.'/'.$file;
        if(!is_file($src)) throw new RuntimeException("Missing source asset: $src");
        if(!copy($src,$storage)) throw new RuntimeException("Could not copy $file to Laravel public storage");
        $reencoded=false;
        if(function_exists('imagecreatefromwebp') && function_exists('imagewebp')){
            $img=@imagecreatefromwebp($storage);
            if($img){$tmp=$storage.'.tmp.webp';if(@imagewebp($img,$tmp,92)&&is_file($tmp)&&filesize($tmp)>1000){@rename($tmp,$storage);$reencoded=true;}@imagedestroy($img);@unlink($tmp);}
        }
        $decoded=decodedWebpFile($storage,$convert);
        if(empty($decoded['ok'])) throw new RuntimeException("Invalid/undecodable WebP asset: $file");
        if(!copy($storage,$public)) throw new RuntimeException("Could not mirror $file to public/files");
        @chmod($storage,0644);@chmod($public,0644);@touch($storage);@touch($public);
        $result['files'][$key]=['source'=>$src,'storage'=>$storage,'public_mirror'=>$public,'bytes'=>filesize($storage),'sha1'=>sha1_file($storage),'width'=>$decoded['width'],'height'=>$decoded['height'],'mime'=>$decoded['mime'],'reencoded'=>$reencoded,'decoder'=>$decoded];
    }

    $noCache="<IfModule mod_headers.c>\nHeader set Cache-Control \"no-store, no-cache, must-revalidate, max-age=0\"\nHeader set Pragma \"no-cache\"\nHeader set Expires \"0\"\n</IfModule>\n";
    @file_put_contents($storageDir.'/.htaccess',$noCache,LOCK_EX);@file_put_contents($filesDir.'/.htaccess',$noCache,LOCK_EX);

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
    foreach($storageUrls as $key=>$url)$probes['storage'][$key]=webpProbe($url.'?v='.time(),$convert);
    foreach($mirrorUrls as $key=>$url)$probes['public_mirror'][$key]=webpProbe($url.'?v='.time(),$convert);

    $result['database']=['before'=>['cover_image'=>$game->cover_image,'win_image'=>$game->win_image,'loss_image'=>$game->loss_image],'after'=>['cover_image'=>$after->cover_image,'win_image'=>$after->win_image,'loss_image'=>$after->loss_image]];
    $result['public_urls']=$storageUrls;$result['fallback_urls']=$mirrorUrls;$result['http_probes']=$probes;
    $result['storage_link']=['path'=>$publicStorage,'is_link'=>is_link($publicStorage),'is_dir'=>is_dir($publicStorage)];
    $httpOk=true;foreach($probes['storage'] as $p)if(empty($p['valid_webp']))$httpOk=false;foreach($probes['public_mirror'] as $p)if(empty($p['valid_webp']))$httpOk=false;
    $result['verified']=is_file($coverStorage)&&is_file($storageDir.'/winner.webp')&&is_file($storageDir.'/loser.webp')&&is_file($coverPublic)&&is_file($filesDir.'/winner.webp')&&is_file($filesDir.'/loser.webp')&&$after->cover_image==='treasure-hunt/scratch/cover.webp'&&$after->win_image==='treasure-hunt/scratch/winner.webp'&&$after->loss_image==='treasure-hunt/scratch/loser.webp'&&$httpOk;
    $result['status']=$result['verified']?'completed':'failed';
} catch(Throwable $e) {
    $result['status']='failed';$result['verified']=false;$result['error']=$e->getMessage();
}
$result['completed_at']=now()->toIso8601String();
@mkdir(dirname($out),0755,true);file_put_contents($out,json_encode($result,JSON_PRETTY_PRINT|JSON_UNESCAPED_SLASHES),LOCK_EX);
echo json_encode(['status'=>$result['status'],'verified'=>$result['verified']??false,'cover_bytes'=>$result['files']['cover']['bytes']??null,'error'=>$result['error']??null]),"\n";
exit($result['status']==='completed'?0:1);
