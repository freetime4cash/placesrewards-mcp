<?php

declare(strict_types=1);

use Illuminate\Support\Facades\DB;

$agentRoot='/home/placevle/placesrewards-agent-server';
$appRoot='/home/placevle/app.placesrewards.com';
$out=$agentRoot.'/results/campaigns/treasure-hunt-card-content-verification.json';

require $appRoot.'/vendor/autoload.php';
$app=require $appRoot.'/bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();

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
    CURLOPT_USERAGENT=>'PlacesRewardsTreasureHuntVerifier/2.0',
    CURLOPT_HTTPHEADER=>['Accept: text/html'],
  ]);
  $body=(string)curl_exec($ch);
  $status=(int)curl_getinfo($ch,CURLINFO_RESPONSE_CODE);
  $effective=(string)curl_getinfo($ch,CURLINFO_EFFECTIVE_URL);
  $error=(string)curl_error($ch);
  curl_close($ch);
  $text=preg_replace('/\s+/u',' ',html_entity_decode(strip_tags($body),ENT_QUOTES|ENT_HTML5,'UTF-8')) ?? '';
  return ['status'=>$status,'effective_url'=>$effective,'body'=>$body,'text'=>$text,'error'=>$error];
}

$result=['status'=>'running','verified_at'=>gmdate('c'),'index'=>null,'modules'=>[],'native'=>[]];
$all=true;

// Dedicated 12-step presentation pages.
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
if($index['status']!==200 || count($indexMissing)!==0) $all=false;

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
if(!$result['all_module_pages_distinct']) $all=false;

// Native Hunter Passport page must render the data stored in the card model.
$cardId='95cbd0bf-8bbb-436d-b7c6-a2e1e558db25';
$card=\App\Models\Card::query()->find($cardId);
$cardPage=fetchPage('https://app.placesrewards.com/en-us/card/'.$cardId);
$cardNeedles=[
  'Northeast Ohio Treasure Hunt Hunter Passport',
  '100 demo welcome points',
  'Explorer Trail',
];
$cardMissing=[];
foreach($cardNeedles as $needle) if(stripos($cardPage['text'],$needle)===false) $cardMissing[]=$needle;
$result['native']['hunter_passport']=[
  'http_status'=>$cardPage['status'],
  'model_head'=>$card?->head,
  'model_title'=>$card?->title,
  'model_description'=>$card?->description,
  'missing_rendered_markers'=>$cardMissing,
  'passed'=>$cardPage['status']===200 && !empty($card?->head) && !empty($card?->title) && !empty($card?->description) && !$cardMissing,
];
if(!$result['native']['hunter_passport']['passed']) $all=false;

// Both native stamp cards must be active, visible, unexpired and translated for en_US.
foreach([
  'explorer'=>'a9566430-b6b0-434d-ae3c-f3ba85421c5f',
  'merchant_checkin'=>'5738988e-265f-422f-9b41-5828790af3c0',
] as $key=>$id){
  $row=DB::table('stamp_cards')->where('id',$id)->first();
  $page=fetchPage('https://app.placesrewards.com/en-us/stamp-card/'.$id);
  $title=$row?json_decode((string)$row->title,true)['en_US']??null:null;
  $description=$row?json_decode((string)$row->description,true)['en_US']??null:null;
  $passed=$row && (int)$row->is_active===1 && (int)$row->is_visible_by_default===1 && strtotime((string)$row->valid_until)>time() && $title && $description && $page['status']===200 && stripos($page['text'],$title)!==false;
  $result['native'][$key]=[
    'http_status'=>$page['status'],
    'title'=>$title,
    'description'=>$description,
    'valid_until'=>$row?->valid_until,
    'is_active'=>$row?->is_active,
    'is_visible_by_default'=>$row?->is_visible_by_default,
    'passed'=>(bool)$passed,
  ];
  if(!$passed) $all=false;
}

// Module 10: $5 off $25 is stored in cents and must be the only active TOM comeback voucher.
$voucherId='14788f52-438e-4293-bd6b-c82b8e448983';
$voucher=DB::table('vouchers')->where('id',$voucherId)->first();
$oldVoucher=DB::table('vouchers')->where('id','358b51e6-189c-4b17-95b2-eb2794d5b763')->first();
$voucherPage=fetchPage('https://app.placesrewards.com/en-us/voucher/'.$voucherId);
$voucherTitle=$voucher?json_decode((string)$voucher->title,true)['en_US']??null:null;
$voucherPassed=$voucher && (int)$voucher->value===500 && (int)$voucher->min_purchase_amount===2500 && (int)$voucher->is_active===1 && (int)$voucher->is_public===1 && (int)$voucher->is_visible_by_default===1 && $voucherTitle && $voucherPage['status']===200;
$oldRetired=$oldVoucher && (int)$oldVoucher->is_active===0 && (int)$oldVoucher->is_public===0 && (int)$oldVoucher->is_visible_by_default===0;
$result['native']['comeback_voucher']=[
  'http_status'=>$voucherPage['status'],
  'title'=>$voucherTitle,
  'value_cents'=>$voucher?->value,
  'min_purchase_cents'=>$voucher?->min_purchase_amount,
  'canonical_passed'=>(bool)$voucherPassed,
  'obsolete_module_09_retired'=>(bool)$oldRetired,
  'passed'=>(bool)($voucherPassed && $oldRetired),
];
if(!$result['native']['comeback_voucher']['passed']) $all=false;

// Module 09 scratch images must be assigned to their correct fields and exist in public storage.
$game=DB::table('scratch_games')->where('id','1fefb288-a8cc-46d4-a4a3-04fe56f91329')->first();
$expected=[
  'cover_image'=>'/files/demo/treasure-hunt/scratch/cover.webp',
  'win_image'=>'/files/demo/treasure-hunt/scratch/winner.webp',
  'loss_image'=>'/files/demo/treasure-hunt/scratch/loser.webp',
];
$scratchChecks=[];
foreach($expected as $field=>$urlPath){
  $diskPath=$appRoot.'/public'.$urlPath;
  $scratchChecks[$field]=[
    'db_value'=>$game?->{$field},
    'expected'=>$urlPath,
    'file_exists'=>is_file($diskPath),
    'bytes'=>is_file($diskPath)?filesize($diskPath):0,
    'passed'=>$game && $game->{$field}===$urlPath && is_file($diskPath) && filesize($diskPath)>1000,
  ];
  if(!$scratchChecks[$field]['passed']) $all=false;
}
$result['native']['scratch']=[
  'name'=>$game?->name,
  'description'=>$game?->description,
  'is_active'=>$game?->is_active,
  'images'=>$scratchChecks,
  'passed'=>$game && (int)$game->is_active===1 && !empty($game->description) && !in_array(false,array_column($scratchChecks,'passed'),true),
];
if(!$result['native']['scratch']['passed']) $all=false;

// Public home is the highest-value regression check because these cards are listed there.
$home=fetchPage('https://app.placesrewards.com/en-us');
$homeNeedles=[
  'Northeast Ohio Treasure Hunt Hunter Passport',
  '5-Stop Northeast Ohio Explorer Trail',
  'Merchant Check-In Verification',
  'Hunter Comeback',
];
$homeMissing=[];
foreach($homeNeedles as $needle) if(stripos($home['text'],$needle)===false) $homeMissing[]=$needle;
$result['native']['public_home']=[
  'http_status'=>$home['status'],
  'missing_markers'=>$homeMissing,
  'obsolete_module_09_visible'=>stripos($home['text'],'[TOM DEMO 09] Hunter Comeback')!==false,
  'passed'=>$home['status']===200 && !$homeMissing && stripos($home['text'],'[TOM DEMO 09] Hunter Comeback')===false,
];
if(!$result['native']['public_home']['passed']) $all=false;

$result['status']=$all?'passed':'failed';
@mkdir(dirname($out),0755,true);
file_put_contents($out,json_encode($result,JSON_PRETTY_PRINT|JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE),LOCK_EX);
echo json_encode(['status'=>$result['status'],'native'=>$result['native']]),"\n";
exit($result['status']==='passed'?0:1);
