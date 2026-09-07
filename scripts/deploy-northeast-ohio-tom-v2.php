<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

$agentRoot = '/home/placevle/placesrewards-agent-server';
$appRoot = '/home/placevle/app.placesrewards.com';
$assetRoot = $agentRoot.'/assets/northeast-ohio-tom';
$resultPath = $agentRoot.'/results/campaigns/northeast-ohio-tom-v2-result.json';
$linkPath = $agentRoot.'/results/campaigns/northeast-ohio-tom-v2-links.json';

require $appRoot.'/vendor/autoload.php';
$app = require $appRoot.'/bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();

function tomCols(string $table): array {
    return Schema::hasTable($table) ? Schema::getColumnListing($table) : [];
}
function tomOwnerQ(string $table, string $partnerId) {
    $c = tomCols($table);
    $q = DB::table($table);
    if (in_array('created_by',$c,true)) $q->where('created_by',$partnerId);
    elseif (in_array('partner_id',$c,true)) $q->where('partner_id',$partnerId);
    elseif (in_array('owner_id',$c,true)) $q->where('owner_id',$partnerId);
    return $q;
}
function tomTr(string $s): string {
    return json_encode(['en'=>$s], JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE);
}
function tomClone(string $table,string $partnerId,array $overrides,?string $templateId=null): array {
    if (!Schema::hasTable($table)) return ['status'=>'skipped','table'=>$table,'reason'=>'table_missing'];
    $c=tomCols($table);
    $key=null;
    foreach(['name','title','subject'] as $candidate) {
        if (in_array($candidate,$c,true) && array_key_exists($candidate,$overrides)) { $key=$candidate; break; }
    }
    if (!$key) return ['status'=>'skipped','table'=>$table,'reason'=>'no_identity_field'];
    $wanted=$overrides[$key];
    $existing=tomOwnerQ($table,$partnerId)->where($key,$wanted)->first();
    if ($existing) {
        $u=[];
        foreach($overrides as $k=>$v) if(in_array($k,$c,true) && $k!=='id') $u[$k]=$v;
        if(in_array('updated_at',$c,true))$u['updated_at']=now();
        DB::table($table)->where('id',$existing->id)->update($u);
        return ['status'=>'updated','table'=>$table,'id'=>$existing->id,'name'=>$wanted];
    }
    $tpl=null;
    if($templateId && in_array('id',$c,true))$tpl=DB::table($table)->where('id',$templateId)->first();
    if(!$tpl)$tpl=tomOwnerQ($table,$partnerId)->first();
    if(!$tpl)return ['status'=>'skipped','table'=>$table,'reason'=>'no_template'];
    $d=(array)$tpl;
    if(in_array('id',$c,true))$d['id']=(string)Str::uuid();
    foreach(['deleted_at','deleted_by','updated_by','played_at','winner_id','claimed_at','claimed_by_member_id','started_at','completed_at','scheduled_at'] as $k)
        if(in_array($k,$c,true))$d[$k]=null;
    foreach(['views','times_used','total_discount_given','unique_members_used','total_stamps_issued','total_completions','total_redemptions','number_of_times_redeemed','recipient_count','sent_count','failed_count','number_of_points_issued','number_of_points_redeemed','number_of_rewards_redeemed','total_amount_purchased'] as $k)
        if(in_array($k,$c,true))$d[$k]=0;
    if(in_array('created_at',$c,true))$d['created_at']=now();
    if(in_array('updated_at',$c,true))$d['updated_at']=now();
    if(in_array('created_by',$c,true))$d['created_by']=$partnerId;
    if(in_array('partner_id',$c,true))$d['partner_id']=$partnerId;
    if(in_array('owner_id',$c,true))$d['owner_id']=$partnerId;
    foreach(['unique_identifier','identifier','slug'] as $k)
        if(in_array($k,$c,true) && array_key_exists($k,$d))$d[$k]='TOM-'.strtoupper(Str::random(12));
    foreach($overrides as $k=>$v)if(in_array($k,$c,true))$d[$k]=$v;
    DB::table($table)->insert($d);
    return ['status'=>'created','table'=>$table,'id'=>$d['id']??null,'name'=>$wanted];
}
function tomDeactivateStale(string $table,string $partnerId,array $keep): int {
    if(!Schema::hasTable($table)) return 0;
    $c=tomCols($table);
    if(!in_array('name',$c,true) || !in_array('is_active',$c,true)) return 0;
    $q=tomOwnerQ($table,$partnerId)->where('name','like','[TOM DEMO %]');
    if($keep)$q->whereNotIn('name',$keep);
    return $q->update(['is_active'=>0,'updated_at'=>in_array('updated_at',$c,true)?now():null]);
}
function tomLogoSvg(string $path,array $m): void {
    $accent=htmlspecialchars((string)($m['accent']??'#0F9D67'),ENT_QUOTES);
    $label=htmlspecialchars((string)($m['label']??'Places Rewards'),ENT_QUOTES);
    $key=(string)($m['module_key']??'loyalty');
    $navy='#0B1F3A'; $gold='#F4B942'; $white='#FFFFFF';
    $icon='';
    switch($key){
        case 'loyalty':
            $icon='<rect x="315" y="290" width="330" height="390" rx="40" fill="none" stroke="'.$white.'" stroke-width="34"/><line x1="385" y1="290" x2="385" y2="680" stroke="'.$white.'" stroke-width="24"/><circle cx="610" cy="360" r="88" fill="'.$gold.'"/><path d="M610 304 L632 338 L672 344 L644 374 L650 414 L610 395 L570 414 L576 374 L548 344 L588 338 Z" fill="'.$navy.'"/>';
            break;
        case 'stamp_card':
            $icon='<path d="M260 560 C340 300 520 690 755 390" fill="none" stroke="'.$white.'" stroke-width="30" stroke-linecap="round" stroke-dasharray="24 30"/><circle cx="290" cy="535" r="46" fill="'.$gold.'"/><circle cx="425" cy="420" r="46" fill="'.$gold.'"/><circle cx="560" cy="525" r="46" fill="'.$gold.'"/><circle cx="690" cy="430" r="46" fill="'.$gold.'"/><path d="M742 310 v170 h34 v-70 h120 l-34-50 34-50 h-154" fill="'.$white.'"/>';
            break;
        case 'discovery':
            $icon='<rect x="270" y="395" width="480" height="285" rx="26" fill="none" stroke="'.$white.'" stroke-width="32"/><path d="M250 395 h520 l-58-105 h-404 z" fill="'.$gold.'"/><line x1="370" y1="395" x2="370" y2="680" stroke="'.$white.'" stroke-width="26"/><circle cx="680" cy="320" r="88" fill="'.$white.'"/><circle cx="680" cy="308" r="28" fill="'.$accent.'"/><path d="M680 400 L635 335 h90 z" fill="'.$white.'"/>';
            break;
        case 'reward':
            $icon='<rect x="275" y="340" width="470" height="300" rx="28" fill="none" stroke="'.$white.'" stroke-width="32"/><path d="M300 375 L510 520 L720 375" fill="none" stroke="'.$gold.'" stroke-width="32"/><path d="M760 300 L785 345 L835 352 L798 386 L808 436 L760 412 L712 436 L722 386 L685 352 L735 345 Z" fill="'.$gold.'"/>';
            break;
        case 'checkin':
            $icon='<rect x="265" y="285" width="390" height="390" rx="36" fill="none" stroke="'.$white.'" stroke-width="30"/><rect x="315" y="335" width="85" height="85" fill="'.$gold.'"/><rect x="510" y="335" width="95" height="95" fill="'.$white.'"/><rect x="315" y="515" width="95" height="95" fill="'.$white.'"/><rect x="485" y="495" width="55" height="55" fill="'.$gold.'"/><rect x="565" y="555" width="55" height="55" fill="'.$gold.'"/><circle cx="730" cy="585" r="115" fill="'.$gold.'"/><path d="M675 585 l38 42 80-92" fill="none" stroke="'.$navy.'" stroke-width="30" stroke-linecap="round" stroke-linejoin="round"/>';
            break;
        case 'prize':
            $icon='<rect x="290" y="410" width="440" height="260" rx="25" fill="'.$white.'"/><rect x="260" y="340" width="500" height="105" rx="22" fill="'.$gold.'"/><rect x="480" y="340" width="60" height="330" fill="'.$accent.'"/><path d="M510 340 C430 260 365 255 360 315 C355 365 430 380 510 340 Z" fill="'.$gold.'"/><path d="M510 340 C590 260 655 255 660 315 C665 365 590 380 510 340 Z" fill="'.$gold.'"/>';
            break;
        case 'referral':
            $icon='<circle cx="390" cy="390" r="82" fill="'.$gold.'"/><circle cx="650" cy="390" r="82" fill="'.$white.'"/><path d="M270 650 C300 515 480 515 510 650" fill="none" stroke="'.$gold.'" stroke-width="44" stroke-linecap="round"/><path d="M510 650 C540 515 720 515 750 650" fill="none" stroke="'.$white.'" stroke-width="44" stroke-linecap="round"/><path d="M380 285 C470 220 575 220 665 285" fill="none" stroke="'.$white.'" stroke-width="26"/><path d="M640 250 l48 36-55 20" fill="none" stroke="'.$white.'" stroke-width="24" stroke-linecap="round" stroke-linejoin="round"/>';
            break;
        case 'tier':
            $icon='<path d="M260 570 L310 330 L455 455 L512 260 L570 455 L715 330 L765 570 Z" fill="'.$gold.'"/><rect x="285" y="570" width="455" height="90" rx="24" fill="'.$white.'"/><path d="M512 365 L535 405 L580 412 L547 443 L555 488 L512 467 L469 488 L477 443 L444 412 L489 405 Z" fill="'.$navy.'"/>';
            break;
        case 'scratch':
            $icon='<rect x="275" y="295" width="470" height="360" rx="34" fill="'.$white.'"/><rect x="335" y="365" width="350" height="130" rx="24" fill="'.$gold.'"/><path d="M350 390 l300 80 M350 435 l300 80 M390 355 l240 140" stroke="'.$navy.'" stroke-width="16" opacity=".45"/><path d="M760 270 l18 38 42 7-31 29 8 42-37-20-37 20 8-42-31-29 42-7z" fill="'.$gold.'"/>';
            break;
        case 'voucher':
            $icon='<path d="M290 345 h330 l120 120-260 260-190-190z" fill="'.$white.'"/><circle cx="380" cy="440" r="34" fill="'.$accent.'"/><path d="M690 340 C785 375 805 485 750 565" fill="none" stroke="'.$gold.'" stroke-width="30" stroke-linecap="round"/><path d="M735 525 l20 70 65-32" fill="none" stroke="'.$gold.'" stroke-width="28" stroke-linecap="round" stroke-linejoin="round"/>';
            break;
        case 'automation':
            $icon='<circle cx="512" cy="490" r="190" fill="none" stroke="'.$white.'" stroke-width="34" stroke-dasharray="260 70"/><path d="M665 335 l70 5-30 64" fill="none" stroke="'.$white.'" stroke-width="28" stroke-linecap="round" stroke-linejoin="round"/><path d="M512 590 C455 545 410 505 410 450 C410 405 445 380 482 380 C505 380 525 392 512 410 C530 392 550 380 575 380 C615 380 650 408 650 450 C650 505 595 550 512 610 Z" fill="'.$gold.'"/>';
            break;
        case 'analytics':
            $icon='<rect x="285" y="535" width="85" height="150" rx="12" fill="'.$white.'"/><rect x="410" y="455" width="85" height="230" rx="12" fill="'.$gold.'"/><rect x="535" y="365" width="85" height="320" rx="12" fill="'.$white.'"/><rect x="660" y="285" width="85" height="400" rx="12" fill="'.$gold.'"/><path d="M285 430 C420 390 555 330 745 215" fill="none" stroke="'.$white.'" stroke-width="28" stroke-linecap="round"/><path d="M700 200 l70 5-28 65" fill="none" stroke="'.$white.'" stroke-width="26" stroke-linecap="round" stroke-linejoin="round"/>';
            break;
        default:
            $icon='<circle cx="512" cy="470" r="200" fill="'.$gold.'"/><path d="M512 330 L555 420 L655 435 L582 505 L600 605 L512 558 L424 605 L442 505 L369 435 L469 420 Z" fill="'.$navy.'"/>';
    }
    $svg='<svg xmlns="http://www.w3.org/2000/svg" width="1024" height="1024" viewBox="0 0 1024 1024">'
      .'<rect width="1024" height="1024" rx="120" fill="#FFFFFF"/>'
      .'<rect x="38" y="38" width="948" height="948" rx="100" fill="'.$navy.'"/>'
      .'<circle cx="512" cy="465" r="330" fill="'.$accent.'" opacity=".30"/>'
      .'<text x="512" y="125" text-anchor="middle" fill="#FFFFFF" font-family="Arial,sans-serif" font-size="34" font-weight="800" letter-spacing="3">NORTHEAST OHIO TREASURE HUNT</text>'
      .$icon
      .'<text x="512" y="850" text-anchor="middle" fill="#FFFFFF" font-family="Arial,sans-serif" font-size="54" font-weight="900">'.$label.'</text>'
      .'<text x="512" y="905" text-anchor="middle" fill="#B9D6E8" font-family="Arial,sans-serif" font-size="27" font-weight="700" letter-spacing="2">PLACES REWARDS DEMO</text>'
      .'</svg>';
    file_put_contents($path,$svg,LOCK_EX);
}

$source=json_decode((string)file_get_contents($assetRoot.'/modules.json'),true);
if(!is_array($source) || empty($source['modules'])) throw new RuntimeException('Tom demo module source is invalid.');

$legacyCardId='01a000d3-701c-728e-b0c6-7d3a5754428c';
$legacyStampId='01a000f1-4a6e-719a-a541-d9c4f2b359f3';
$legacyVoucherId='01a000f1-4dc8-71b9-8e9c-0efb561d6e80';
$legacyScratchId='f9e3a56d-9cf8-4d16-a78a-3bf731f93557';

$legacy=Schema::hasTable('cards')?DB::table('cards')->where('id',$legacyCardId)->first():null;
$partnerId=(string)($legacy->created_by??'019dbfc9-ddf9-7136-951a-124574cf7b3e');
$clubId=(string)($legacy->club_id??'');
if(!$clubId && Schema::hasTable('clubs')) {
    $club=tomOwnerQ('clubs',$partnerId)->where('name','like','%Treasure%')->first();
    $clubId=(string)($club->id??'');
}
if(!$clubId) throw new RuntimeException('Could not resolve the existing Treasure Hunt club.');

$result=['status'=>'running','partner_id'=>$partnerId,'club_id'=>$clubId,'records'=>[],'files'=>[],'started_at'=>now()->toIso8601String()];
$generatedDir=$appRoot.'/public/files/demo/northeast-ohio-tom/generated';
$uploadDir=$appRoot.'/public/files/demo/northeast-ohio-tom/uploads';
@mkdir($generatedDir,0755,true); @mkdir($uploadDir,0755,true);

$runtimePath=$appRoot.'/storage/app/northeast-ohio-tom/modules-runtime.json';
$oldRuntime=is_file($runtimePath)?json_decode((string)file_get_contents($runtimePath),true):[];
$merchantBySequence=[];
foreach(($oldRuntime['modules']??[]) as $m)$merchantBySequence[(int)($m['sequence']??0)]=[
    'merchant_image'=>$m['merchant_image']??null,
    'merchant_image_updated_at'=>$m['merchant_image_updated_at']??null,
];

$recordByKey=[];
foreach($source['modules'] as $m) {
    $key=$m['module_key'];
    $seq=(int)$m['sequence'];
    $record=null;
    switch($key) {
        case 'loyalty':
            $record=tomClone('cards',$partnerId,[
                'club_id'=>$clubId,'name'=>'[TOM DEMO 01] Hunter Passport',
                'head'=>tomTr('Join once. Keep earning across the hunt.'),
                'title'=>tomTr('Hunter Passport — Join Once, Keep Earning'),
                'description'=>tomTr($m['description']),
                'currency'=>'USD','initial_bonus_points'=>100,'points_expiration_months'=>24,'currency_unit_amount'=>1,'points_per_currency'=>1,
                'min_points_per_purchase'=>0,'max_points_per_purchase'=>1000000,'is_active'=>1,'is_visible_by_default'=>1,'bg_color'=>'#0F9D67','text_color'=>'#FFFFFF',
                'meta'=>json_encode(['tom_demo_sequence'=>$seq,'purpose'=>$m['cta']],JSON_UNESCAPED_SLASHES),
            ],$legacyCardId);
            break;
        case 'stamp_card':
            $record=tomClone('stamp_cards',$partnerId,[
                'club_id'=>$clubId,'name'=>'[TOM DEMO 02] 5-Stop Explorer Trail','title'=>tomTr('5-Stop Explorer Trail — Move Traffic Across Town'),'description'=>tomTr($m['description']),
                'stamps_required'=>5,'stamps_per_purchase'=>1,'max_stamps_per_day'=>5,'max_stamps_per_transaction'=>1,'min_purchase_amount'=>0,
                'reward_title'=>tomTr('Explorer Trail Completion Reward'),'reward_description'=>tomTr('Complete all five participating stops to unlock a local reward.'),
                'currency'=>'USD','requires_physical_claim'=>0,'is_active'=>1,'is_visible_by_default'=>1,'bg_color'=>'#059669','text_color'=>'#FFFFFF',
                'meta'=>json_encode(['tom_demo_sequence'=>$seq,'purpose'=>$m['cta']],JSON_UNESCAPED_SLASHES),
            ],$legacyStampId);
            break;
        case 'discovery':
            $record=['status'=>'virtual_live_module','table'=>'discovery','id'=>null,'name'=>$m['title']];
            break;
        case 'reward':
            $record=tomClone('rewards',$partnerId,[
                'name'=>'[TOM DEMO 04] Clue Completion Reward','title'=>tomTr('Clue Completion Reward — Reward the Visit, Not the Answer'),'description'=>tomTr($m['description']),
                'points'=>250,'is_active'=>1,'meta'=>json_encode(['tom_demo_sequence'=>$seq,'official_clue_separate'=>true],JSON_UNESCAPED_SLASHES),
            ]);
            break;
        case 'checkin':
            $record=tomClone('stamp_cards',$partnerId,[
                'club_id'=>$clubId,'name'=>'[TOM DEMO 05] Merchant Check-In Verification','title'=>tomTr('Merchant Check-In — Prove the Foot Traffic'),'description'=>tomTr($m['description']),
                'stamps_required'=>1,'stamps_per_purchase'=>1,'max_stamps_per_day'=>1,'max_stamps_per_transaction'=>1,'min_purchase_amount'=>0,
                'reward_title'=>tomTr('Verified Merchant Visit'),'reward_description'=>tomTr('This one-stop verification demonstrates a measurable Treasure Hunt business visit.'),
                'currency'=>'USD','requires_physical_claim'=>0,'is_active'=>1,'is_visible_by_default'=>0,'bg_color'=>'#14B8A6','text_color'=>'#FFFFFF',
                'meta'=>json_encode(['tom_demo_sequence'=>$seq,'purpose'=>'merchant_checkin'],JSON_UNESCAPED_SLASHES),
            ],$legacyStampId);
            break;
        case 'prize':
            $record=tomClone('rewards',$partnerId,[
                'name'=>'[TOM DEMO 06] Local Prize & Giveaway Reward','title'=>tomTr('Local Prize & Giveaway — Give Every Business a Win Moment'),'description'=>tomTr($m['description']),
                'points'=>0,'is_active'=>1,'meta'=>json_encode(['tom_demo_sequence'=>$seq,'purpose'=>'local_prize'],JSON_UNESCAPED_SLASHES),
            ]);
            break;
        case 'referral':
            $record=['status'=>'virtual_live_module','table'=>'referral','id'=>null,'name'=>$m['title']];
            break;
        case 'tier':
            $record=tomClone('tiers',$partnerId,[
                'club_id'=>$clubId,'name'=>'[TOM DEMO 08] Hunter VIP Progression','display_name'=>tomTr('Hunter VIP'),'description'=>tomTr($m['description']),
                'level'=>3,'points_threshold'=>2500,'points_multiplier'=>1.25,'is_default'=>0,'is_active'=>1,'color'=>'#0EA5E9',
                'benefits'=>json_encode(['Early hunt previews','Premium merchant perks','VIP local experiences'],JSON_UNESCAPED_SLASHES),
                'meta'=>json_encode(['tom_demo_sequence'=>$seq],JSON_UNESCAPED_SLASHES),
            ]);
            break;
        case 'scratch':
            $record=tomClone('scratch_games',$partnerId,[
                'name'=>'[TOM DEMO 09] Mystery Bonus Scratch & Win','description'=>$m['description'],'win_rate'=>25,'is_active'=>1,
            ],$legacyScratchId);
            break;
        case 'voucher':
            $record=tomClone('vouchers',$partnerId,[
                'club_id'=>$clubId,'code'=>'TOMHUNTRETURN','name'=>'[TOM DEMO 10] Hunter Comeback $5 Off $25',
                'title'=>tomTr('Hunter Comeback Offer — Turn the First Visit Into the Second'),'description'=>tomTr($m['description']),'value'=>5,'currency'=>'USD','min_purchase_amount'=>25,
                'max_uses_per_member'=>1,'is_active'=>1,'is_public'=>1,'is_visible_by_default'=>1,'is_single_use'=>1,'stackable'=>0,
                'meta'=>json_encode(['tom_demo_sequence'=>$seq,'purpose'=>'return_visit'],JSON_UNESCAPED_SLASHES),
            ],$legacyVoucherId);
            break;
        case 'automation':
            $record=tomClone('email_campaigns',$partnerId,[
                'subject'=>tomTr('[TOM DEMO 11] Keep the Treasure Hunt relationship alive'),
                'body'=>tomTr('Thanks for exploring Northeast Ohio. Your rewards, unfinished trail progress and participating-business offers can keep the relationship going after the hunt. This is a draft demonstration and is not sent automatically.'),
                'segment_type'=>'custom','segment_config'=>json_encode(['tom_demo_sequence'=>$seq,'audience'=>'inactive_or_near_complete_hunters'],JSON_UNESCAPED_SLASHES),'status'=>'draft',
            ]);
            break;
        case 'analytics':
            $record=['status'=>'virtual_live_module','table'=>'analytics','id'=>null,'name'=>$m['title']];
            break;
    }
    if($record){$result['records'][]=$record;$recordByKey[$key]=$record;}
}

$result['stale_demo_records_deactivated']=[
    'cards'=>tomDeactivateStale('cards',$partnerId,['[TOM DEMO 01] Hunter Passport']),
    'stamp_cards'=>tomDeactivateStale('stamp_cards',$partnerId,['[TOM DEMO 02] 5-Stop Explorer Trail','[TOM DEMO 05] Merchant Check-In Verification']),
    'rewards'=>tomDeactivateStale('rewards',$partnerId,['[TOM DEMO 04] Clue Completion Reward','[TOM DEMO 06] Local Prize & Giveaway Reward']),
    'scratch_games'=>tomDeactivateStale('scratch_games',$partnerId,['[TOM DEMO 09] Mystery Bonus Scratch & Win']),
    'tiers'=>tomDeactivateStale('tiers',$partnerId,['[TOM DEMO 08] Hunter VIP Progression']),
    'vouchers'=>tomDeactivateStale('vouchers',$partnerId,['[TOM DEMO 10] Hunter Comeback $5 Off $25']),
];

$viewPublic=$appRoot.'/resources/views/demo/northeast-ohio-tom.blade.php';
$viewEditor=$appRoot.'/resources/views/partner/northeast-ohio-tom-editor.blade.php';
$routeFile=$appRoot.'/routes/northeast-ohio-tom.php';
@mkdir(dirname($viewPublic),0755,true); @mkdir(dirname($viewEditor),0755,true);
copy($assetRoot.'/public.blade.php',$viewPublic);
copy($assetRoot.'/editor.blade.php',$viewEditor);
copy($assetRoot.'/routes.php',$routeFile);
$result['files'][]=['asset'=>'views_and_routes','status'=>'copied'];

$webPath=$appRoot.'/routes/web.php';
$marker="require __DIR__.'/northeast-ohio-tom.php';";
$web=(string)file_get_contents($webPath);
if(!str_contains($web,$marker)) {
    @mkdir($agentRoot.'/data/backups',0755,true);
    copy($webPath,$agentRoot.'/data/backups/web.php.before-tom-demo-'.date('Ymd-His'));
    if(str_contains($web,'?>')) {
        $pos=strrpos($web,'?>');
        $web=substr($web,0,$pos)."\n\n// Northeast Ohio Treasure Hunt Tom demo\n".$marker."\n".substr($web,$pos);
    } else {
        $web=rtrim($web)."\n\n// Northeast Ohio Treasure Hunt Tom demo\n".$marker."\n";
    }
    file_put_contents($webPath,$web,LOCK_EX);
    $result['files'][]=['asset'=>'routes/web.php','status'=>'include_added'];
} else {
    $result['files'][]=['asset'=>'routes/web.php','status'=>'include_existing'];
}

$runtime=$source;
foreach($runtime['modules'] as &$m) {
    $seq=(int)$m['sequence'];
    $file=$generatedDir.'/'.str_pad((string)$seq,2,'0',STR_PAD_LEFT).'-'.$m['slug'].'.svg';
    tomLogoSvg($file,$m);
    $rel=str_replace($appRoot.'/public','',$file);
    $m['default_image']=$rel;
    $m['merchant_image']=$merchantBySequence[$seq]['merchant_image']??null;
    $m['merchant_image_updated_at']=$merchantBySequence[$seq]['merchant_image_updated_at']??null;
    $m['public_url']='https://app.placesrewards.com/demo/northeast-ohio-treasure-hunt/tom#module-'.str_pad((string)$seq,2,'0',STR_PAD_LEFT);
    $m['record_table']=$recordByKey[$m['module_key']]['table']??null;
    $m['record_id']=$recordByKey[$m['module_key']]['id']??null;
    $m['live_url']=null;
    if($m['module_key']==='loyalty' && !empty($m['record_id']))$m['live_url']='/en-us/card/'.$m['record_id'];
    if($m['module_key']==='stamp_card' && !empty($m['record_id']))$m['live_url']='/en-us/stamp-card/'.$m['record_id'];
    if($m['module_key']==='checkin' && !empty($m['record_id']))$m['live_url']='/en-us/stamp-card/'.$m['record_id'];
    if($m['module_key']==='referral')$m['live_url']='/r/65CRHW';
    if($m['module_key']==='scratch')$m['live_url']='/demo/scratch-win';
    if($m['module_key']==='voucher' && !empty($m['record_id']))$m['live_url']='/en-us/voucher/'.$m['record_id'];
}
unset($m);
@mkdir(dirname($runtimePath),0755,true);
file_put_contents($runtimePath,json_encode($runtime,JSON_PRETTY_PRINT|JSON_UNESCAPED_SLASHES),LOCK_EX);

$scratch=$recordByKey['scratch']??null;
if(!empty($scratch['id']) && Schema::hasTable('scratch_games') && in_array('cover_image',tomCols('scratch_games'),true)) {
    foreach($runtime['modules'] as $rm){
        if(($rm['module_key']??'')==='scratch'){
            DB::table('scratch_games')->where('id',$scratch['id'])->update(['cover_image'=>$rm['default_image'],'updated_at'=>now()]);
            break;
        }
    }
}

try { Artisan::call('route:clear'); } catch(Throwable $e) {}
try { Artisan::call('view:clear'); } catch(Throwable $e) {}
try { Artisan::call('config:clear'); } catch(Throwable $e) {}

$publicBase='https://app.placesrewards.com/demo/northeast-ohio-treasure-hunt/tom';
$editor='https://app.placesrewards.com/partner/demo/northeast-ohio-treasure-hunt/tom';
$links=[];
foreach($runtime['modules'] as $m)$links[]=[
    'sequence'=>$m['sequence'],'title'=>$m['title'],'module'=>$m['module_key'],
    'demo_url'=>$m['public_url'],'live_url'=>$m['live_url']?('https://app.placesrewards.com'.$m['live_url']):null,
    'default_image'=>'https://app.placesrewards.com'.$m['default_image'],
    'merchant_image_upload'=>$editor,
];

$result['status']='completed';
$result['presentation_url']=$publicBase;
$result['image_manager_url']=$editor;
$result['modules']=$links;
$result['completed_at']=now()->toIso8601String();
file_put_contents($resultPath,json_encode($result,JSON_PRETTY_PRINT|JSON_UNESCAPED_SLASHES));
file_put_contents($linkPath,json_encode(['presentation_url'=>$publicBase,'image_manager_url'=>$editor,'modules'=>$links],JSON_PRETTY_PRINT|JSON_UNESCAPED_SLASHES));
echo json_encode(['status'=>'completed','presentation_url'=>$publicBase,'image_manager_url'=>$editor,'module_count'=>count($links),'record_count'=>count($result['records'])],JSON_PRETTY_PRINT|JSON_UNESCAPED_SLASHES),"\n";
