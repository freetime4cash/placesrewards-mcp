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

    $convert=trim((string)shell_exec('command -v convert 2>/dev/null'));
    if($convert==='') throw new RuntimeException('ImageMagick convert is unavailable');

    // First prove the old source is actually decodable. A simple HTTP 200/getimagesize
    // is not enough: the previous file was truncated and browsers could not display it.
    $oldDecodable=false;
    if(is_file($src)){
        $lines=[];$cmd=escapeshellarg($convert).' '.escapeshellarg($src).' -format "%w x %h" info: 2>&1';
        exec($cmd,$lines,$code);$oldDecodable=$code===0;
        $result['attempts'][]=['tool'=>'decode-existing-cover','exit'=>$code,'output'=>implode("\n",$lines),'decodable'=>$oldDecodable];
    }

    // Build a clean Treasure Hunt cover independently of the corrupt source file.
    // This guarantees a fresh, standards-compliant WebP while preserving the intended
    // Treasure Hunt / Mystery Bonus presentation.
    $svg=<<<'SVG'
<svg xmlns="http://www.w3.org/2000/svg" width="1200" height="900" viewBox="0 0 1200 900">
  <defs>
    <linearGradient id="bg" x1="0" y1="0" x2="1" y2="1">
      <stop offset="0" stop-color="#07162F"/>
      <stop offset="0.55" stop-color="#123E54"/>
      <stop offset="1" stop-color="#0F9D67"/>
    </linearGradient>
    <radialGradient id="glow" cx="50%" cy="40%" r="60%">
      <stop offset="0" stop-color="#F6C453" stop-opacity="0.28"/>
      <stop offset="1" stop-color="#F6C453" stop-opacity="0"/>
    </radialGradient>
    <filter id="shadow" x="-20%" y="-20%" width="140%" height="140%">
      <feDropShadow dx="0" dy="16" stdDeviation="18" flood-color="#000000" flood-opacity="0.35"/>
    </filter>
  </defs>
  <rect width="1200" height="900" fill="url(#bg)"/>
  <rect width="1200" height="900" fill="url(#glow)"/>
  <path d="M115 690 C260 560 360 650 485 525 S760 420 865 505 S1015 450 1090 330" fill="none" stroke="#F6C453" stroke-width="10" stroke-linecap="round" stroke-dasharray="20 22" opacity="0.82"/>
  <circle cx="114" cy="690" r="24" fill="#F6C453"/>
  <circle cx="1088" cy="330" r="28" fill="#F6C453"/>
  <g transform="translate(910 160)" filter="url(#shadow)">
    <path d="M75 0C34 0 0 34 0 75c0 57 75 145 75 145s75-88 75-145C150 34 116 0 75 0z" fill="#F6C453"/>
    <circle cx="75" cy="75" r="33" fill="#07162F"/>
    <path d="M75 47l9 19 21 3-15 15 4 21-19-10-19 10 4-21-15-15 21-3z" fill="#FFFFFF"/>
  </g>
  <g transform="translate(150 130)" filter="url(#shadow)">
    <rect x="0" y="0" width="710" height="515" rx="48" fill="#FFFFFF" fill-opacity="0.97"/>
    <text x="62" y="88" font-family="Arial, Helvetica, sans-serif" font-size="30" font-weight="700" fill="#0F9D67" letter-spacing="3">NORTHEAST OHIO TREASURE HUNT</text>
    <text x="62" y="190" font-family="Arial, Helvetica, sans-serif" font-size="78" font-weight="900" fill="#07162F">MYSTERY BONUS</text>
    <text x="62" y="275" font-family="Arial, Helvetica, sans-serif" font-size="78" font-weight="900" fill="#07162F">SCRATCH &amp; WIN</text>
    <rect x="62" y="322" width="575" height="4" rx="2" fill="#E6EAF0"/>
    <text x="62" y="382" font-family="Arial, Helvetica, sans-serif" font-size="32" font-weight="700" fill="#2E4963">Complete a qualifying merchant visit.</text>
    <text x="62" y="430" font-family="Arial, Helvetica, sans-serif" font-size="32" font-weight="700" fill="#2E4963">Then scratch for a local bonus reward.</text>
    <rect x="62" y="462" width="330" height="72" rx="36" fill="#0F9D67"/>
    <text x="227" y="509" text-anchor="middle" font-family="Arial, Helvetica, sans-serif" font-size="30" font-weight="900" fill="#FFFFFF">SCRATCH TO REVEAL</text>
  </g>
  <text x="600" y="835" text-anchor="middle" font-family="Arial, Helvetica, sans-serif" font-size="27" font-weight="700" fill="#FFFFFF" opacity="0.92">PLACES REWARDS • LOCAL VISIT BONUS • SEPARATE FROM THE GRAND TREASURE</text>
</svg>
SVG;
    $svgPath=$storageDir.'/cover-source.svg';
    if(file_put_contents($svgPath,$svg,LOCK_EX)===false) throw new RuntimeException('Could not write clean cover SVG source');

    $lines=[];
    $cmd=escapeshellarg($convert).' '.escapeshellarg($svgPath).' -strip -colorspace sRGB -resize 1200x900! -quality 92 '.escapeshellarg($target).' 2>&1';
    exec($cmd,$lines,$code);
    $result['attempts'][]=['tool'=>'render-clean-cover','exit'=>$code,'output'=>implode("\n",$lines)];
    if($code!==0 || !is_file($target) || filesize($target)<5000) throw new RuntimeException('Could not render clean Treasure Hunt cover WebP');

    // Require ImageMagick to decode the newly-created WebP again before publishing it.
    $lines=[];$cmd=escapeshellarg($convert).' '.escapeshellarg($target).' -format "%m %w %h" info: 2>&1';
    exec($cmd,$lines,$code);
    $result['attempts'][]=['tool'=>'decode-new-cover','exit'=>$code,'output'=>implode("\n",$lines)];
    if($code!==0) throw new RuntimeException('New cover WebP failed decoder verification');

    @chmod($target,0644);
    if(!copy($target,$mirrorDir.'/cover.webp')) throw new RuntimeException('Could not mirror rebuilt cover');
    @chmod($mirrorDir.'/cover.webp',0644);

    // Prevent the previously-corrupted response from remaining stuck in browser/CDN cache.
    $htaccess="<IfModule mod_headers.c>\nHeader set Cache-Control \"no-store, no-cache, must-revalidate, max-age=0\"\nHeader set Pragma \"no-cache\"\nHeader set Expires \"0\"\n</IfModule>\n";
    @file_put_contents($storageDir.'/.htaccess',$htaccess,LOCK_EX);
    @file_put_contents($mirrorDir.'/.htaccess',$htaccess,LOCK_EX);

    $info=@getimagesize($target);
    if(!$info || ($info['mime']??'')!=='image/webp' || ($info[0]??0)!==1200 || ($info[1]??0)!==900) throw new RuntimeException('Rebuilt cover failed dimension/MIME validation');

    DB::table('scratch_games')->where('id',$gameId)->update(['cover_image'=>'treasure-hunt/scratch/cover.webp','updated_at'=>now()]);
    try{Artisan::call('view:clear');}catch(Throwable $e){}
    try{Artisan::call('cache:clear');}catch(Throwable $e){}

    $url='https://app.placesrewards.com/storage/treasure-hunt/scratch/cover.webp?v='.time();
    $headers=[];
    $ch=curl_init($url);
    curl_setopt_array($ch,[CURLOPT_RETURNTRANSFER=>true,CURLOPT_FOLLOWLOCATION=>true,CURLOPT_TIMEOUT=>20,CURLOPT_CONNECTTIMEOUT=>8,CURLOPT_USERAGENT=>'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 Chrome/152 Safari/537.36',CURLOPT_HEADERFUNCTION=>function($ch,$line)use(&$headers){$headers[]=trim($line);return strlen($line);},CURLOPT_HTTPHEADER=>['Accept: image/avif,image/webp,image/apng,image/svg+xml,image/*,*/*;q=0.8','Cache-Control: no-cache']]);
    $body=(string)curl_exec($ch);$status=(int)curl_getinfo($ch,CURLINFO_RESPONSE_CODE);$type=(string)curl_getinfo($ch,CURLINFO_CONTENT_TYPE);$error=(string)curl_error($ch);curl_close($ch);
    $signature=substr($body,0,12);$riff=str_starts_with($signature,'RIFF')&&substr($signature,8,4)==='WEBP';

    // Validate the HTTP response bytes with the image decoder too, not only the disk file.
    $httpTmp=$storageDir.'/cover-http-probe.webp';@file_put_contents($httpTmp,$body,LOCK_EX);
    $lines=[];$cmd=escapeshellarg($convert).' '.escapeshellarg($httpTmp).' -format "%m %w %h" info: 2>&1';exec($cmd,$lines,$httpDecodeCode);@unlink($httpTmp);

    $result['old_source_decodable']=$oldDecodable;
    $result['file']=['path'=>$target,'bytes'=>filesize($target),'sha1'=>sha1_file($target),'width'=>$info[0],'height'=>$info[1],'mime'=>$info['mime']];
    $result['http']=['url'=>$url,'status'=>$status,'content_type'=>$type,'bytes'=>strlen($body),'riff_webp'=>$riff,'decoder_exit'=>$httpDecodeCode,'decoder_output'=>implode("\n",$lines),'headers'=>$headers,'error'=>$error?:null];
    $result['verified']=$status===200&&str_starts_with(strtolower($type),'image/webp')&&$riff&&strlen($body)>5000&&$httpDecodeCode===0;
    $result['status']=$result['verified']?'completed':'failed';
} catch(Throwable $e) {
    $result['status']='failed';$result['verified']=false;$result['error']=$e->getMessage();
}
$result['completed_at']=now()->toIso8601String();
@mkdir(dirname($out),0755,true);file_put_contents($out,json_encode($result,JSON_PRETTY_PRINT|JSON_UNESCAPED_SLASHES),LOCK_EX);
echo json_encode(['status'=>$result['status'],'verified'=>$result['verified']??false,'error'=>$result['error']??null]),"\n";
exit($result['status']==='completed'?0:1);
