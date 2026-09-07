<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;

$agentRoot='/home/placevle/placesrewards-agent-server';
$appRoot='/home/placevle/app.placesrewards.com';
$out=$agentRoot.'/results/campaigns/treasure-hunt-cover-browser-repair.json';
$gameId='1fefb288-a8cc-46d4-a4a3-04fe56f91329';

require $appRoot.'/vendor/autoload.php';
$app=require $appRoot.'/bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();

$result=['status'=>'running','started_at'=>now()->toIso8601String(),'attempts'=>[]];
try {
    $src=$agentRoot.'/assets/treasure-hunt-v4/scratch/cover.webp';
    $storageDir=$appRoot.'/storage/app/public/treasure-hunt/scratch';
    $mirrorDir=$appRoot.'/public/files/demo/treasure-hunt/scratch';
    @mkdir($storageDir,0755,true); @mkdir($mirrorDir,0755,true);
    $target=$storageDir.'/cover.webp';
    $png=$storageDir.'/cover.png';

    if(!is_file($src)) throw new RuntimeException('Source cover asset missing');

    // Prefer external decoders because the existing WebP may be accepted by getimagesize()
    // while still failing in a real browser decoder.
    $commands=[];
    foreach(['magick','convert','ffmpeg'] as $bin){
        $path=trim((string)shell_exec('command -v '.escapeshellarg($bin).' 2>/dev/null'));
        if($path!=='') $commands[$bin]=$path;
    }

    $made=false;
    if(isset($commands['magick'])){
        $cmd=escapeshellarg($commands['magick']).' '.escapeshellarg($src).' -strip -colorspace sRGB -quality 90 '.escapeshellarg($target).' 2>&1';
        exec($cmd,$lines,$code);$result['attempts'][]=['tool'=>'magick','exit'=>$code,'output'=>implode("\n",$lines)];$made=$code===0&&is_file($target)&&filesize($target)>1000;
    }
    if(!$made && isset($commands['convert'])){
        $lines=[];$cmd=escapeshellarg($commands['convert']).' '.escapeshellarg($src).' -strip -colorspace sRGB -quality 90 '.escapeshellarg($target).' 2>&1';
        exec($cmd,$lines,$code);$result['attempts'][]=['tool'=>'convert','exit'=>$code,'output'=>implode("\n",$lines)];$made=$code===0&&is_file($target)&&filesize($target)>1000;
    }
    if(!$made && isset($commands['ffmpeg'])){
        $lines=[];$cmd=escapeshellarg($commands['ffmpeg']).' -y -i '.escapeshellarg($src).' -frames:v 1 '.escapeshellarg($png).' 2>&1';
        exec($cmd,$lines,$code);$result['attempts'][]=['tool'=>'ffmpeg-png','exit'=>$code,'output'=>implode("\n",array_slice($lines,-8))];
        if($code===0&&is_file($png)&&filesize($png)>1000){
            $cmd=escapeshellarg($commands['ffmpeg']).' -y -i '.escapeshellarg($png).' -frames:v 1 -c:v libwebp -quality 90 '.escapeshellarg($target).' 2>&1';
            $lines=[];exec($cmd,$lines,$code2);$result['attempts'][]=['tool'=>'ffmpeg-webp','exit'=>$code2,'output'=>implode("\n",array_slice($lines,-8))];$made=$code2===0&&is_file($target)&&filesize($target)>1000;
        }
    }
    if(!$made && function_exists('imagecreatefromstring') && function_exists('imagewebp')){
        $raw=(string)file_get_contents($src);$im=@imagecreatefromstring($raw);
        if($im){$made=@imagewebp($im,$target,90);@imagedestroy($im);$result['attempts'][]=['tool'=>'gd','success'=>(bool)$made];}
    }
    if(!$made) throw new RuntimeException('No available decoder could rebuild the cover WebP');

    @chmod($target,0644);
    if(!copy($target,$mirrorDir.'/cover.webp')) throw new RuntimeException('Could not mirror rebuilt cover');
    @chmod($mirrorDir.'/cover.webp',0644);

    // Validate actual decoded dimensions from the rebuilt file.
    $info=@getimagesize($target);
    if(!$info || ($info['mime']??'')!=='image/webp' || ($info[0]??0)<100 || ($info[1]??0)<100) throw new RuntimeException('Rebuilt cover failed image validation');

    DB::table('scratch_games')->where('id',$gameId)->update(['cover_image'=>'treasure-hunt/scratch/cover.webp','updated_at'=>now()]);

    try{Artisan::call('view:clear');}catch(Throwable $e){}
    try{Artisan::call('cache:clear');}catch(Throwable $e){}

    // Browser-style HTTP probe including headers and RIFF/WebP signature.
    $url='https://app.placesrewards.com/storage/treasure-hunt/scratch/cover.webp?v='.time();
    $headers=[];$body='';
    $ch=curl_init($url);
    curl_setopt_array($ch,[CURLOPT_RETURNTRANSFER=>true,CURLOPT_FOLLOWLOCATION=>true,CURLOPT_TIMEOUT=>20,CURLOPT_CONNECTTIMEOUT=>8,CURLOPT_USERAGENT=>'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 Chrome/152 Safari/537.36',CURLOPT_HEADERFUNCTION=>function($ch,$line)use(&$headers){$headers[]=trim($line);return strlen($line);},CURLOPT_HTTPHEADER=>['Accept: image/avif,image/webp,image/apng,image/svg+xml,image/*,*/*;q=0.8','Cache-Control: no-cache']]);
    $body=(string)curl_exec($ch);$status=(int)curl_getinfo($ch,CURLINFO_RESPONSE_CODE);$type=(string)curl_getinfo($ch,CURLINFO_CONTENT_TYPE);$error=(string)curl_error($ch);curl_close($ch);
    $signature=substr($body,0,12);
    $riff=str_starts_with($signature,'RIFF') && substr($signature,8,4)==='WEBP';

    $result['file']=['path'=>$target,'bytes'=>filesize($target),'sha1'=>sha1_file($target),'width'=>$info[0],'height'=>$info[1],'mime'=>$info['mime']];
    $result['http']=['url'=>$url,'status'=>$status,'content_type'=>$type,'bytes'=>strlen($body),'riff_webp'=>$riff,'headers'=>$headers,'error'=>$error?:null];
    $result['verified']=$status===200 && str_starts_with(strtolower($type),'image/webp') && $riff && strlen($body)>1000;
    $result['status']=$result['verified']?'completed':'failed';
} catch(Throwable $e) {
    $result['status']='failed';$result['verified']=false;$result['error']=$e->getMessage();
}
$result['completed_at']=now()->toIso8601String();
@mkdir(dirname($out),0755,true);file_put_contents($out,json_encode($result,JSON_PRETTY_PRINT|JSON_UNESCAPED_SLASHES),LOCK_EX);
echo json_encode(['status'=>$result['status'],'verified'=>$result['verified']??false,'error'=>$result['error']??null]),"\n";
exit($result['status']==='completed'?0:1);
