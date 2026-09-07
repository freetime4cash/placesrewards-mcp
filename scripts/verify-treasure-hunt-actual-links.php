<?php

declare(strict_types=1);

$agentRoot='/home/placevle/placesrewards-agent-server';
$out=$agentRoot.'/results/campaigns/treasure-hunt-actual-link-verification.json';
$base='https://app.placesrewards.com';
$card='95cbd0bf-8bbb-436d-b7c6-a2e1e558db25';
$links=[
1=>['page'=>"$base/demo/treasure-hunt/loyalty",'actual'=>"$base/en-us/card/$card"],
2=>['page'=>"$base/demo/treasure-hunt/stamps",'actual'=>"$base/en-us/stamp-card/a9566430-b6b0-434d-ae3c-f3ba85421c5f"],
3=>['page'=>"$base/demo/treasure-hunt/directory",'actual'=>"$base/en-us"],
4=>['page'=>"$base/demo/treasure-hunt/reward",'actual'=>"$base/en-us/card/$card/29304849-3c10-4a06-8f8f-4bad776b79f9"],
5=>['page'=>"$base/demo/treasure-hunt/check-in",'actual'=>"$base/en-us/stamp-card/5738988e-265f-422f-9b41-5828790af3c0"],
6=>['page'=>"$base/demo/treasure-hunt/prize",'actual'=>"$base/en-us/card/$card/13085fc2-2a5d-43ee-92b5-441bc368c55b"],
7=>['page'=>"$base/demo/treasure-hunt/referrals",'actual'=>"$base/r/65CRHW"],
8=>['page'=>"$base/demo/treasure-hunt/vip",'actual'=>"$base/en-us/card/$card"],
9=>['page'=>"$base/demo/treasure-hunt/scratch",'actual'=>"$base/demo/scratch-win"],
10=>['page'=>"$base/demo/treasure-hunt/voucher",'actual'=>"$base/en-us/voucher/14788f52-438e-4293-bd6b-c82b8e448983"],
11=>['page'=>"$base/demo/treasure-hunt/retention",'actual'=>"$base/en-us/partner/email-campaigns/b1ea6974-d61c-41d4-a1ce-0c0a27ffa5bd"],
12=>['page'=>"$base/demo/treasure-hunt/analytics",'actual'=>"$base/en-us/partner/loyalty-card-analytics/card/$card"],
];
function getUrl(string $url,bool $follow=true): array{
 $ch=curl_init($url);curl_setopt_array($ch,[CURLOPT_RETURNTRANSFER=>true,CURLOPT_FOLLOWLOCATION=>$follow,CURLOPT_TIMEOUT=>20,CURLOPT_CONNECTTIMEOUT=>8,CURLOPT_USERAGENT=>'PlacesRewards-Link-Audit/2.0',CURLOPT_COOKIEFILE=>'']);$body=(string)curl_exec($ch);$status=(int)curl_getinfo($ch,CURLINFO_RESPONSE_CODE);$effective=(string)curl_getinfo($ch,CURLINFO_EFFECTIVE_URL);$redirect=(string)curl_getinfo($ch,CURLINFO_REDIRECT_URL);$error=(string)curl_error($ch);curl_close($ch);return compact('body','status','effective','redirect','error');
}
$index=getUrl("$base/demo/treasure-hunt");$result=['status'=>'running','verified_at'=>gmdate('c'),'index'=>[],'modules'=>[]];$all=$index['status']===200;$indexMissing=[];
foreach($links as $seq=>$item){if(stripos($index['body'],$item['actual'])===false)$indexMissing[]=$seq;}
$result['index']=['http_status'=>$index['status'],'missing_actual_links'=>$indexMissing,'passed'=>$index['status']===200&&!$indexMissing];if($indexMissing)$all=false;
foreach($links as $seq=>$item){
 $page=getUrl($item['page']);$present=stripos($page['body'],$item['actual'])!==false;
 if($seq===9){
   $probe=getUrl($item['actual'],true);
   $nativeScratch=(bool)preg_match('#/en-us/scratch-cards/[0-9a-f-]{36}$#i,parse_url($probe['effective'],PHP_URL_PATH)??'');
   $valid=$probe['status']===200&&$nativeScratch&&stripos($probe['body'],'scratch')!==false;
 }else{
   $probe=getUrl($item['actual'],false);
   $nativeScratch=null;
   $valid=in_array($probe['status'],[200,301,302,303,307,308],true);
 }
 $passed=$page['status']===200&&$present&&$valid;
 $result['modules'][]=['sequence'=>$seq,'page'=>$item['page'],'page_status'=>$page['status'],'actual_url'=>$item['actual'],'actual_link_present'=>$present,'actual_status'=>$probe['status'],'actual_effective_url'=>$probe['effective'],'actual_redirect'=>$probe['redirect']?:null,'native_scratch_destination'=>$nativeScratch,'passed'=>$passed];if(!$passed)$all=false;
}
$result['status']=$all?'passed':'failed';@mkdir(dirname($out),0755,true);file_put_contents($out,json_encode($result,JSON_PRETTY_PRINT|JSON_UNESCAPED_SLASHES),LOCK_EX);echo json_encode(['status'=>$result['status'],'index'=>$result['index'],'modules'=>$result['modules']]),"\n";exit($all?0:1);
