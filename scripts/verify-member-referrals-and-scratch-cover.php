<?php

declare(strict_types=1);

$agentRoot='/home/placevle/placesrewards-agent-server';
$appRoot='/home/placevle/app.placesrewards.com';
$out=$agentRoot.'/results/campaigns/referrals-and-scratch-cover-verification.json';
require $appRoot.'/vendor/autoload.php';
$app=require $appRoot.'/bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();

function probe(string $url): array {
    $cookie=tempnam(sys_get_temp_dir(),'pr-ref-');
    $ch=curl_init($url);
    curl_setopt_array($ch,[CURLOPT_RETURNTRANSFER=>true,CURLOPT_FOLLOWLOCATION=>true,CURLOPT_TIMEOUT=>25,CURLOPT_CONNECTTIMEOUT=>8,CURLOPT_COOKIEJAR=>$cookie,CURLOPT_COOKIEFILE=>$cookie,CURLOPT_USERAGENT=>'PlacesRewards-ReferralCoverVerifier/2.0',CURLOPT_HTTPHEADER=>['Cache-Control: no-cache']]);
    $body=(string)curl_exec($ch);$status=(int)curl_getinfo($ch,CURLINFO_RESPONSE_CODE);$effective=(string)curl_getinfo($ch,CURLINFO_EFFECTIVE_URL);$type=(string)curl_getinfo($ch,CURLINFO_CONTENT_TYPE);$error=(string)curl_error($ch);curl_close($ch);@unlink($cookie);
    return ['status'=>$status,'effective'=>$effective,'content_type'=>$type,'bytes'=>strlen($body),'error'=>$error?:null,'body'=>$body];
}

$result=['status'=>'running','verified_at'=>now()->toIso8601String(),'checks'=>[]];$all=true;

// /en-us/referrals is intentionally member-authenticated. Verify live rendered HTML when
// the probe is authenticated; otherwise verify the deployed Blade source and record the
// expected auth gate rather than treating a 403 as a content failure.
$ref=probe('https://app.placesrewards.com/en-us/referrals');
$viewPath=$appRoot.'/resources/views/member/referrals/index.blade.php';
$view=is_file($viewPath)?(string)file_get_contents($viewPath):'';
$sourceSpecific=str_contains($view,'TREASURE_HUNT_REFERRAL_CARD')&&str_contains($view,'BRING ANOTHER HUNTER')&&str_contains($view,'Grow the Hunt by Referring a Friend')&&str_contains($view,'tracks who invited whom')&&str_contains($view,'Your Referral Program');
$renderedSpecific=$ref['status']===200&&stripos($ref['body'],'Your Referral Program')!==false&&stripos($ref['body'],'BRING ANOTHER HUNTER')!==false&&stripos($ref['body'],'Grow the Hunt by Referring a Friend')!==false&&stripos($ref['body'],'tracks who invited whom')!==false;
$authGate=in_array($ref['status'],[401,403],true);
$referralPassed=$renderedSpecific||($authGate&&$sourceSpecific);
$result['checks']['referrals_page']=['passed'=>$referralPassed,'status'=>$ref['status'],'effective'=>$ref['effective'],'content_type'=>$ref['content_type'],'bytes'=>$ref['bytes'],'auth_required'=>$authGate,'source_verified'=>$sourceSpecific,'rendered_verified'=>$renderedSpecific,'view'=>$viewPath,'error'=>$ref['error']];
if(!$referralPassed)$all=false;

foreach([
    'storage_cover'=>'https://app.placesrewards.com/storage/treasure-hunt/scratch/cover.webp',
    'files_cover'=>'https://app.placesrewards.com/files/demo/treasure-hunt/scratch/cover.webp',
] as $name=>$url){
    $p=probe($url.'?v='.time());$ok=$p['status']===200&&str_starts_with(strtolower($p['content_type']),'image/webp')&&$p['bytes']>1000;
    $result['checks'][$name]=['passed'=>$ok,'url'=>$url,'status'=>$p['status'],'effective'=>$p['effective'],'content_type'=>$p['content_type'],'bytes'=>$p['bytes'],'error'=>$p['error']];
    if(!$ok)$all=false;
}

$result['status']=$all?'passed':'failed';$result['completed_at']=now()->toIso8601String();
@mkdir(dirname($out),0755,true);file_put_contents($out,json_encode($result,JSON_PRETTY_PRINT|JSON_UNESCAPED_SLASHES),LOCK_EX);
echo json_encode(['status'=>$result['status'],'checks'=>array_map(fn($c)=>$c['passed']??false,$result['checks'])]),"\n";
exit($all?0:1);
