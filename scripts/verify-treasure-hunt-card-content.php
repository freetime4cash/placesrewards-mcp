<?php

declare(strict_types=1);

$agentRoot='/home/placevle/placesrewards-agent-server';
$out=$agentRoot.'/results/campaigns/treasure-hunt-card-content-verification.json';

$checks=[
  1=>['url'=>'https://app.placesrewards.com/demo/treasure-hunt/loyalty','needle'=>'HUNTER PASSPORT'],
  2=>['url'=>'https://app.placesrewards.com/demo/treasure-hunt/stamps','needle'=>'EXPLORER TRAIL'],
  3=>['url'=>'https://app.placesrewards.com/demo/treasure-hunt/directory','needle'=>'FEATURED PARTICIPATING BUSINESS'],
  4=>['url'=>'https://app.placesrewards.com/demo/treasure-hunt/reward','needle'=>'CLUE ACTIVITY BONUS'],
  5=>['url'=>'https://app.placesrewards.com/demo/treasure-hunt/check-in','needle'=>'MERCHANT CHECK-IN'],
  6=>['url'=>'https://app.placesrewards.com/demo/treasure-hunt/prize','needle'=>'LOCAL BUSINESS BONUS PRIZE'],
  7=>['url'=>'https://app.placesrewards.com/demo/treasure-hunt/referrals','needle'=>'BRING ANOTHER HUNTER'],
  8=>['url'=>'https://app.placesrewards.com/demo/treasure-hunt/vip','needle'=>'HUNTER VIP'],
  9=>['url'=>'https://app.placesrewards.com/demo/treasure-hunt/scratch','needle'=>'MYSTERY BONUS'],
  10=>['url'=>'https://app.placesrewards.com/demo/treasure-hunt/voucher','needle'=>'HUNTER COMEBACK'],
  11=>['url'=>'https://app.placesrewards.com/demo/treasure-hunt/retention','needle'=>'POST-HUNT FOLLOW-UP'],
  12=>['url'=>'https://app.placesrewards.com/demo/treasure-hunt/analytics','needle'=>'TREASURE HUNT ROI'],
];

function fetchPage(string $url): array {
  $ch=curl_init($url);
  curl_setopt_array($ch,[
    CURLOPT_RETURNTRANSFER=>true,
    CURLOPT_FOLLOWLOCATION=>true,
    CURLOPT_CONNECTTIMEOUT=>10,
    CURLOPT_TIMEOUT=>20,
    CURLOPT_USERAGENT=>'PlacesRewardsTreasureHuntVerifier/1.0',
    CURLOPT_HTTPHEADER=>['Accept: text/html'],
  ]);
  $body=(string)curl_exec($ch);
  $status=(int)curl_getinfo($ch,CURLINFO_RESPONSE_CODE);
  $effective=(string)curl_getinfo($ch,CURLINFO_EFFECTIVE_URL);
  $error=(string)curl_error($ch);
  curl_close($ch);
  return ['status'=>$status,'effective_url'=>$effective,'body'=>$body,'error'=>$error];
}

$result=['status'=>'running','verified_at'=>gmdate('c'),'index'=>null,'modules'=>[]];
$index=fetchPage('https://app.placesrewards.com/demo/treasure-hunt');
$indexNeedles=array_column($checks,'needle');
$indexMissing=[];
foreach($indexNeedles as $needle) if(stripos($index['body'],$needle)===false) $indexMissing[]=$needle;
$result['index']=[
  'url'=>'https://app.placesrewards.com/demo/treasure-hunt',
  'http_status'=>$index['status'],
  'all_12_card_content_markers_present'=>count($indexMissing)===0,
  'missing_markers'=>$indexMissing,
  'body_length'=>strlen($index['body']),
];

$all=true;
$hashes=[];
foreach($checks as $seq=>$check){
  $page=fetchPage($check['url']);
  $present=stripos($page['body'],$check['needle'])!==false;
  $hash=sha1(preg_replace('/\s+/',' ',strip_tags($page['body'])) ?? $page['body']);
  $hashes[]=$hash;
  $entry=[
    'sequence'=>$seq,
    'url'=>$check['url'],
    'effective_url'=>$page['effective_url'],
    'http_status'=>$page['status'],
    'expected_card_marker'=>$check['needle'],
    'card_content_present'=>$present,
    'body_length'=>strlen($page['body']),
    'content_hash'=>$hash,
    'error'=>$page['error'] ?: null,
  ];
  if($page['status']!==200 || !$present) $all=false;
  $result['modules'][]=$entry;
}
$result['distinct_page_content_hashes']=count(array_unique($hashes));
$result['all_module_pages_distinct']=count(array_unique($hashes))===12;
$result['status']=($all && ($result['index']['http_status']===200) && $result['index']['all_12_card_content_markers_present'])?'passed':'failed';
@mkdir(dirname($out),0755,true);
file_put_contents($out,json_encode($result,JSON_PRETTY_PRINT|JSON_UNESCAPED_SLASHES),LOCK_EX);
echo json_encode(['status'=>$result['status'],'distinct_pages'=>$result['distinct_page_content_hashes'],'index_markers_ok'=>$result['index']['all_12_card_content_markers_present']]),"\n";
exit($result['status']==='passed'?0:1);
