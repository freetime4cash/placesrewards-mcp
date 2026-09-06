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
function tomFindImage(array $files,int $sequence): ?string {
    $prefix=str_pad((string)$sequence,2,'0',STR_PAD_LEFT);
    foreach($files as $file) if(str_starts_with(basename($file),$prefix)) return $file;
    return $files[$sequence-1]??null;
}
function tomSvg(string $path,array $m): void {
    $accent=htmlspecialchars((string)($m['accent']??'#0F9D67'),ENT_QUOTES);
    $title=htmlspecialchars((string)$m['title'],ENT_QUOTES);
    $subtitle=htmlspecialchars((string)$m['subtitle'],ENT_QUOTES);
    $label=htmlspecialchars((string)$m['label'],ENT_QUOTES);
    $num=str_pad((string)$m['sequence'],2,'0',STR_PAD_LEFT);
    $svg='<svg xmlns="http://www.w3.org/2000/svg" width="1600" height="1000" viewBox="0 0 1600 1000">'
      .'<defs><linearGradient id="g" x1="0" y1="0" x2="1" y2="1"><stop stop-color="#071c34"/><stop offset="1" stop-color="'.$accent.'"/></linearGradient>'
      .'<pattern id="p" width="70" height="70" patternUnits="userSpaceOnUse"><circle cx="4" cy="4" r="2" fill="#fff" opacity=".14"/></pattern></defs>'
      .'<rect width="1600" height="1000" fill="url(#g)"/><rect width="1600" height="1000" fill="url(#p)"/>'
      .'<circle cx="1330" cy="170" r="330" fill="#fff" opacity=".06"/><circle cx="1400" cy="900" r="430" fill="#fff" opacity=".05"/>'
      .'<text x="90" y="100" fill="#9AE6C0" font-family="Arial,sans-serif" font-size="30" font-weight="700" letter-spacing="6">PLACES REWARDS • NORTHEAST OHIO TREASURE HUNT</text>'
      .'<text x="90" y="390" fill="#fff" opacity=".18" font-family="Arial,sans-serif" font-size="300" font-weight="900">'.$num.'</text>'
      .'<text x="100" y="535" fill="#fff" font-family="Arial,sans-serif" font-size="44" font-weight="800">'.$label.'</text>'
      .'<text x="100" y="640" fill="#fff" font-family="Arial,sans-serif" font-size="74" font-weight="900">'.$title.'</text>'
      .'<foreignObject x="100" y="690" width="1280" height="190"><div xmlns="http://www.w3.org/1999/xhtml" style="font-family:Arial,sans-serif;color:#eaf3f8;font-size:36px;line-height:1.35;font-weight:500">'.$subtitle.'</div></foreignObject>'
      .'<text x="100" y="930" fill="#fff" opacity=".78" font-family="Arial,sans-serif" font-size="26">Generated default • Merchant-replaceable image slot</text></svg>';
    file_put_contents($path,$svg);
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
$archive=$assetRoot.'/images.tar.gz';
if(is_file($archive)) {
    $cmd='tar -xzf '.escapeshellarg($archive).' -C '.escapeshellarg($generatedDir).' 2>&1';
    exec($cmd,$tarOut,$tarCode);
    $result['files'][]=['asset'=>'images.tar.gz','status'=>$tarCode===0?'extracted':'extract_failed','detail'=>implode("\n",$tarOut)];
}
$imageFiles=[];
$it=new RecursiveIteratorIterator(new RecursiveDirectoryIterator($generatedDir,FilesystemIterator::SKIP_DOTS));
foreach($it as $f) if($f->isFile() && preg_match('/\.(?:jpg|jpeg|png|webp|svg)$/i',$f->getFilename()))$imageFiles[]=$f->getPathname();
natsort($imageFiles); $imageFiles=array_values($imageFiles);

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
                'head'=>tomTr('The relationship starts here.'),
                'title'=>tomTr('Hunter Passport'),
                'description'=>tomTr($m['description']),
                'currency'=>'USD','initial_bonus_points'=>100,'points_expiration_months'=>24,'currency_unit_amount'=>1,'points_per_currency'=>1,
                'min_points_per_purchase'=>0,'max_points_per_purchase'=>1000000,'is_active'=>1,'is_visible_by_default'=>1,'bg_color'=>'#0F9D67','text_color'=>'#FFFFFF',
                'meta'=>json_encode(['tom_demo_sequence'=>$seq,'purpose'=>$m['cta']],JSON_UNESCAPED_SLASHES),
            ],$legacyCardId);
            break;
        case 'stamp_card':
            $record=tomClone('stamp_cards',$partnerId,[
                'club_id'=>$clubId,'name'=>'[TOM DEMO 02] 5-Stop Explorer Trail','title'=>tomTr('5-Stop Explorer Trail'),'description'=>tomTr($m['description']),
                'stamps_required'=>5,'stamps_per_purchase'=>1,'max_stamps_per_day'=>5,'max_stamps_per_transaction'=>1,'min_purchase_amount'=>0,
                'reward_title'=>tomTr('Explorer Trail Completion Reward'),'reward_description'=>tomTr('Complete all five participating stops to unlock a local reward.'),
                'currency'=>'USD','requires_physical_claim'=>0,'is_active'=>1,'is_visible_by_default'=>1,'bg_color'=>'#059669','text_color'=>'#FFFFFF',
                'meta'=>json_encode(['tom_demo_sequence'=>$seq,'purpose'=>$m['cta']],JSON_UNESCAPED_SLASHES),
            ],$legacyStampId);
            break;
        case 'reward':
            $record=tomClone('rewards',$partnerId,[
                'name'=>'[TOM DEMO 04] Clue Completion Bonus','title'=>tomTr('Clue Completion Bonus'),'description'=>tomTr($m['description']),
                'points'=>250,'is_active'=>1,'meta'=>json_encode(['tom_demo_sequence'=>$seq,'official_clue_separate'=>true],JSON_UNESCAPED_SLASHES),
            ]);
            break;
        case 'scratch':
            $record=tomClone('scratch_games',$partnerId,[
                'name'=>'[TOM DEMO 05] Mystery Bonus Scratch & Win','description'=>$m['description'],'win_rate'=>25,'is_active'=>1,
            ],$legacyScratchId);
            break;
        case 'milestone_reward':
            $record=tomClone('rewards',$partnerId,[
                'name'=>'[TOM DEMO 06] Community Explorer Milestone','title'=>tomTr('Community Explorer Milestone'),'description'=>tomTr($m['description']),
                'points'=>500,'is_active'=>1,'meta'=>json_encode(['tom_demo_sequence'=>$seq,'purpose'=>'participation_milestone'],JSON_UNESCAPED_SLASHES),
            ]);
            break;
        case 'referral':
            $record=tomClone('referral_programs',$partnerId,[
                'name'=>'[TOM DEMO 07] Bring Another Hunter','title'=>tomTr('Bring Another Hunter'),'description'=>tomTr($m['description']),'is_active'=>1,
            ]);
            break;
        case 'tier':
            $record=tomClone('tiers',$partnerId,[
                'club_id'=>$clubId,'name'=>'[TOM DEMO 08] Hunter VIP Progression','display_name'=>'Hunter VIP','description'=>$m['description'],
                'level'=>3,'points_threshold'=>2500,'points_multiplier'=>1.25,'is_default'=>0,'is_active'=>1,'color'=>'#0EA5E9',
                'benefits'=>json_encode(['Early hunt previews','Premium merchant perks','VIP local experiences'],JSON_UNESCAPED_SLASHES),
                'meta'=>json_encode(['tom_demo_sequence'=>$seq],JSON_UNESCAPED_SLASHES),
            ]);
            break;
        case 'voucher':
            $record=tomClone('vouchers',$partnerId,[
                'club_id'=>$clubId,'code'=>'TOMHUNTRETURN','name'=>'[TOM DEMO 09] Hunter Comeback $5 Off $25',
                'title'=>tomTr('Hunter Comeback — $5 Off $25'),'description'=>tomTr($m['description']),'value'=>5,'currency'=>'USD','min_purchase_amount'=>25,
                'max_uses_per_member'=>1,'is_active'=>1,'is_public'=>1,'is_visible_by_default'=>1,'is_single_use'=>1,'stackable'=>0,
                'meta'=>json_encode(['tom_demo_sequence'=>$seq,'purpose'=>'return_visit'],JSON_UNESCAPED_SLASHES),
            ],$legacyVoucherId);
            break;
        case 'review':
            $record=tomClone('review_campaigns',$partnerId,[
                'name'=>'[TOM DEMO 10] Community Voice & Social Proof','title'=>tomTr('Community Voice & Social Proof'),'description'=>tomTr($m['description']),'is_active'=>1,
            ]);
            break;
        case 'automation':
            $record=tomClone('email_campaigns',$partnerId,[
                'subject'=>'[TOM DEMO 11] Your Hunt rewards and trail progress are still waiting',
                'body'=>'Thanks for exploring Northeast Ohio. Your saved rewards, unfinished trail progress and local offers give you a reason to come back. This is a draft demonstration and is not sent automatically.',
                'segment_type'=>'custom','segment_config'=>json_encode(['tom_demo_sequence'=>$seq,'audience'=>'inactive_hunters'],JSON_UNESCAPED_SLASHES),'status'=>'draft',
            ]);
            break;
        case 'discovery':
        case 'analytics':
            $record=['status'=>'virtual_live_module','table'=>$key,'id'=>null,'name'=>$m['title']];
            break;
    }
    if($record){$result['records'][]=$record;$recordByKey[$key]=$record;}
}

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
    $file=tomFindImage($imageFiles,$seq);
    if(!$file || !is_file($file)) {
        $file=$generatedDir.'/'.str_pad((string)$seq,2,'0',STR_PAD_LEFT).'-'.$m['slug'].'.svg';
        tomSvg($file,$m);
        $imageFiles[]=$file;
    }
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
    if($m['module_key']==='voucher' && !empty($m['record_id']))$m['live_url']='/en-us/voucher/'.$m['record_id'];
}
unset($m);
@mkdir(dirname($runtimePath),0755,true);
file_put_contents($runtimePath,json_encode($runtime,JSON_PRETTY_PRINT|JSON_UNESCAPED_SLASHES),LOCK_EX);

$scratch=$recordByKey['scratch']??null;
if(!empty($scratch['id']) && Schema::hasTable('scratch_games') && in_array('cover_image',tomCols('scratch_games'),true)) {
    $m=$runtime['modules'][4]??null;
    if($m)DB::table('scratch_games')->where('id',$scratch['id'])->update(['cover_image'=>$m['default_image'],'updated_at'=>now()]);
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
