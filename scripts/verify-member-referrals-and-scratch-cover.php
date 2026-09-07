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
    curl_setopt_array($ch,[CURLOPT_RETURNTRANSFER=>true,CURLOPT_FOLLOWLOCATION=>true,CURLOPT_TIMEOUT=>25,CURLOPT_CONNECTTIMEOUT=>8,CURLOPT_COOKIEJAR=>$cookie,CURLOPT_COOKIEFILE=>$cookie,CURLOPT_USERAGENT=>'PlacesRewards-ReferralCoverVerifier/1.0',CURLOPT_HTTPHEADER=>['Cache-Control: no-cache']]);
    $body=(string)curl_exec($ch);$status=(int)curl_getinfo($ch,CURLINFO_RESPONSE_CODE);$effective=(string)curl_getinfo($ch,CURLINFO_EFFECTIVE_URL);$type=(string)curl_getinfo($ch,CURLINFO_CONTENT_TYPE);$error=(string)curl_error($ch);curl_close($ch);@unlink($cookie);
    return ['status'=>$status,'effective'=>$effective,'content_type'=>$type,'bytes'=>strlen($body),'error'=>$error?:null,'body'=>$body];
}

$result=['status'=>'running','verified_at'=>now()->toIso8601String(),'checks'=>[]];$all=true;

$ref=probe('https://app.placesrewards.com/en-us/referrals');
$referralSpecific=$ref['status']===200 && stripos($ref['body'],'Your Referral Program')!==false && stripos($ref['body'],'BRING ANOTHER HUNTER')!==false && stripos($ref['body'],'Grow the Hunt by Referring a Friend')!==false && stripos($ref['body'],'tracks who invited whom')!==false;
$result['checks']['referrals_page']=['passed'=>$referralSpecific,'status'=>$ref['status'],'effective'=>$ref['effective'],'content_type'=>$ref['content_type'],'bytes'=>$ref['bytes'],'has_referral_heading'=>stripos($ref['body'],'Your Referral Program')!==false,'has_bring_another_hunter'=>stripos($ref['body'],'BRING ANOTHER HUNTER')!==false,'has_growth_copy'=>stripos($ref['body'],'Grow the Hunt by Referring a Friend')!==false,'has_attribution_copy'=>stripos($ref['body'],'tracks who invited whom')!==false,'error'=>$ref['error']];
if(!$referralSpecific)$all=false;

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
