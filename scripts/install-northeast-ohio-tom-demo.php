<?php

declare(strict_types=1);

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

$agentRoot = '/home/placevle/placesrewards-agent-server';
$appRoot = '/home/placevle/app.placesrewards.com';
$out = $agentRoot.'/results/campaigns/northeast-ohio-tom-demo-install-result.json';
$linkOut = $agentRoot.'/results/campaigns/northeast-ohio-tom-demo-links.json';
$publicRoot = $appRoot.'/public/demo/northeast-ohio-treasure-hunt/tom';
$assetRoot = $publicRoot.'/assets';

require $appRoot.'/vendor/autoload.php';
$app = require $appRoot.'/bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();

function trText(string $s): string {
    return json_encode(['en'=>$s], JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE);
}
function cols(string $table): array {
    return Schema::hasTable($table) ? Schema::getColumnListing($table) : [];
}
function setIf(array &$data, array $columns, string $key, mixed $value): void {
    if (in_array($key, $columns, true)) $data[$key] = $value;
}
function ownerQuery(string $table, string $partnerId) {
    $c = cols($table);
    $q = DB::table($table);
    if (in_array('created_by', $c, true)) $q->where('created_by', $partnerId);
    elseif (in_array('partner_id', $c, true)) $q->where('partner_id', $partnerId);
    elseif (in_array('owner_id', $c, true)) $q->where('owner_id', $partnerId);
    return $q;
}
function cloneOrUpdate(string $table, string $partnerId, array $overrides, ?string $templateId = null): array {
    if (!Schema::hasTable($table)) return ['status'=>'skipped','table'=>$table,'reason'=>'table_missing'];
    $c = cols($table);
    $nameCol = in_array('name',$c,true) ? 'name' : (in_array('title',$c,true) ? 'title' : (in_array('subject',$c,true) ? 'subject' : null));
    if (!$nameCol || !array_key_exists($nameCol,$overrides)) return ['status'=>'skipped','table'=>$table,'reason'=>'no_key'];
    $wanted = $overrides[$nameCol];

    $existing = ownerQuery($table,$partnerId)->where($nameCol,$wanted)->first();
    if ($existing) {
        $update = [];
        foreach ($overrides as $k=>$v) if (in_array($k,$c,true) && $k !== 'id') $update[$k]=$v;
        if (in_array('updated_at',$c,true)) $update['updated_at']=now();
        DB::table($table)->where('id',$existing->id)->update($update);
        return ['status'=>'updated','table'=>$table,'id'=>$existing->id,'name'=>$wanted];
    }

    $tpl = null;
    if ($templateId && in_array('id',$c,true)) $tpl = DB::table($table)->where('id',$templateId)->first();
    if (!$tpl) $tpl = ownerQuery($table,$partnerId)->first();
    if (!$tpl) return ['status'=>'skipped','table'=>$table,'reason'=>'no_template'];

    $data = (array)$tpl;
    if (in_array('id',$c,true)) $data['id']=(string)Str::uuid();
    foreach (['deleted_at','deleted_by','updated_by','played_at','winner_id','claimed_at','claimed_by_member_id','started_at','completed_at','scheduled_at'] as $k)
        if (in_array($k,$c,true)) $data[$k]=null;
    foreach (['views','times_used','total_discount_given','unique_members_used','total_stamps_issued','total_completions','total_redemptions','number_of_times_redeemed','recipient_count','sent_count','failed_count'] as $k)
        if (in_array($k,$c,true)) $data[$k]=0;
    if (in_array('created_at',$c,true)) $data['created_at']=now();
    if (in_array('updated_at',$c,true)) $data['updated_at']=now();
    if (in_array('created_by',$c,true)) $data['created_by']=$partnerId;
    if (in_array('partner_id',$c,true)) $data['partner_id']=$partnerId;
    if (in_array('owner_id',$c,true)) $data['owner_id']=$partnerId;
    foreach (['unique_identifier','identifier','slug'] as $k) {
        if (in_array($k,$c,true) && array_key_exists($k,$data)) $data[$k]='TOM-'.strtoupper(Str::random(12));
    }
    foreach ($overrides as $k=>$v) if (in_array($k,$c,true)) $data[$k]=$v;
    DB::table($table)->insert($data);
    return ['status'=>'created','table'=>$table,'id'=>$data['id']??null,'name'=>$wanted];
}

$legacyCardId = '01a000d3-701c-728e-b0c6-7d3a5754428c';
$legacyStampId = '01a000f1-4a6e-719a-a541-d9c4f2b359f3';
$legacyVoucherId = '01a000f1-4dc8-71b9-8e9c-0efb561d6e80';
$legacyScratchId = 'f9e3a56d-9cf8-4d16-a78a-3bf731f93557';

$legacyCard = Schema::hasTable('cards') ? DB::table('cards')->where('id',$legacyCardId)->first() : null;
$partnerId = $legacyCard->created_by ?? null;
$clubId = $legacyCard->club_id ?? null;

if (!$partnerId && Schema::hasTable('partners')) {
    $p = DB::table('partners')->where('email','placesrewards@gmail.com')->first();
    $partnerId = $p->id ?? null;
}
if (!$partnerId) throw new RuntimeException('Could not resolve Places Rewards partner.');
if (!$clubId && Schema::hasTable('clubs')) {
    $club = ownerQuery('clubs',$partnerId)->where('name','like','%Treasure%')->first();
    $clubId = $club->id ?? null;
}
if (!$clubId) {
    $c = cols('clubs');
    $clubId = (string)Str::uuid();
    $d = ['id'=>$clubId,'name'=>'Northeast Ohio Treasure Hunt Demo'];
    setIf($d,$c,'description','Tom Colosimo subscription demonstration for the Northeast Ohio / Newton Falls Treasure Hunt.');
    setIf($d,$c,'locale','en_US'); setIf($d,$c,'currency','USD'); setIf($d,$c,'time_zone','America/Detroit');
    setIf($d,$c,'is_active',1); setIf($d,$c,'is_primary',0); setIf($d,$c,'created_by',$partnerId);
    setIf($d,$c,'created_at',now()); setIf($d,$c,'updated_at',now());
    DB::table('clubs')->insert($d);
}

$sequence = [{"n":1,"slug":"welcome-to-the-hunt","title":"Welcome to the Hunt","stage":"DISCOVER","desc":"A clear starting point that turns curiosity into a trackable hunter relationship before the first merchant visit.","why":"Start the relationship before the hunt traffic disappears.","next":"Create Hunter Profile","color":"#0F766E","actual":"card"},{"n":2,"slug":"create-hunter-profile","title":"Create Your Hunter Profile","stage":"CAPTURE","desc":"Give every participant a simple identity, reward balance and VIP progression path that survives long after the hunt ends.","why":"Every anonymous visitor can become a permissioned repeat customer.","next":"Interactive Hunt Map","color":"#2563EB","actual":"tier"},{"n":3,"slug":"interactive-hunt-map","title":"Interactive Hunt Map","stage":"DISCOVER","desc":"Turn the hunt into a local discovery network that deliberately moves participants among participating businesses and community stops.","why":"Traffic is distributed across the town instead of concentrated at one clue.","next":"Digital Clue Card","color":"#059669","actual":"discover"},{"n":4,"slug":"digital-clue-card","title":"Digital Clue Stop Reward","stage":"ENGAGE","desc":"Create a branded digital moment around each clue stop while keeping the official treasure clues independent from Places Rewards.","why":"Every clue stop becomes a measurable merchant engagement opportunity.","next":"Merchant Check-In","color":"#7C3AED","actual":"reward"},{"n":5,"slug":"merchant-check-in","title":"Merchant Check-In Trail","stage":"VISIT","desc":"Verify real visits at participating businesses and reward progress toward completing a multi-stop local trail.","why":"This is the proof that the hunt generated foot traffic for participating merchants.","next":"Collect & Win Local Prizes","color":"#D97706","actual":"stamp"},{"n":6,"slug":"collect-and-win-prizes","title":"Collect & Win Local Prizes","stage":"REWARD","desc":"Create a prize layer funded by participating merchants and sponsors that rewards engagement without changing treasure-finding odds.","why":"Sponsors and merchants get visible value while hunters get more reasons to participate.","next":"Bring Another Hunter","color":"#C2410C","actual":"giveaway"},{"n":7,"slug":"bring-another-hunter","title":"Bring Another Hunter","stage":"REFER","desc":"Reward participants for bringing friends into the hunt and track who referred whom from first invite through first qualifying activity.","why":"The hunt can grow through participant-driven word of mouth instead of relying only on paid promotion.","next":"Treasure Trail Pass","color":"#6D28D9","actual":"referral"},{"n":8,"slug":"treasure-trail-pass","title":"Treasure Trail VIP Pass","stage":"PROGRESS","desc":"Reward hunters who complete more stops with higher-value perks, recognition and a reason to keep exploring the local business network.","why":"Progression turns one-time participation into repeated movement and higher-value engagement.","next":"Mystery Bonus Scratch & Win","color":"#0E7490","actual":"stamp"},{"n":9,"slug":"instant-win","title":"Mystery Bonus Scratch & Win","stage":"PLAY","desc":"Add instant-win excitement at participating stops with merchant-funded rewards that remain completely separate from the official treasure.","why":"It gives every stop an immediate engagement moment even when the hunter does not find the treasure.","next":"Hunter Comeback Offers","color":"#9333EA","actual":"scratch"},{"n":10,"slug":"comeback-offers","title":"Hunter Comeback Offers","stage":"RETURN","desc":"Deliver targeted return-visit vouchers after hunt activity so the original event visit can become a second and third purchase.","why":"This is where event traffic begins converting into post-hunt revenue.","next":"Business Impact Dashboard","color":"#B45309","actual":"voucher"},{"n":11,"slug":"business-impact-dashboard","title":"Business Impact Dashboard","stage":"MEASURE","desc":"Show participating businesses visits, offer usage, referrals, repeat activity and reactivation in one post-hunt performance story.","why":"Tom can prove merchant and sponsor value instead of relying on anecdotal traffic.","next":"Next Year's Hunt","color":"#0369A1","actual":"analytics"},{"n":12,"slug":"next-years-hunt","title":"Next Hunt Early-Access Engine","stage":"RETAIN","desc":"Keep the audience warm with post-hunt automation, early-access messaging and next-hunt reactivation instead of starting from zero next year.","why":"The next hunt launches with an existing audience, sponsor proof and retained customer relationships.","next":"Subscription","color":"#15803D","actual":"email"}];
$assetData = {};

@mkdir($assetRoot, 0755, true);
foreach ($assetData as $file=>$b64) {
    $path = $assetRoot.'/'.$file;
    if (!is_file($path) || filesize($path) < 1000) file_put_contents($path, base64_decode($b64, true));
}

$result = [
    'status'=>'running',
    'campaign'=>'Tom Colosimo - Northeast Ohio Treasure Hunt Subscription Demo',
    'partner_id'=>$partnerId,
    'club_id'=>$clubId,
    'records'=>[],
    'presentation_cards'=>[],
    'module_links'=>[],
    'started_at'=>now()->toIso8601String(),
];

$presentationIds = [];
foreach ($sequence as $step) {
    $n = str_pad((string)$step['n'],2,'0',STR_PAD_LEFT);
    $name = "[TOM {$n}/12] ".$step['title'];
    $meta = json_encode([
        'demo'=>'northeast-ohio-tom',
        'sequence'=>$step['n'],
        'stage'=>$step['stage'],
        'slug'=>$step['slug'],
        'presentation_only'=>true,
        'merchant_image_upload'=>[
            'enabled'=>true,
            'model'=>'App\\Models\\Card',
            'collection'=>'background',
            'instruction'=>'Replace this card background from the standard Places Rewards card editor.'
        ],
        'why_tom_cares'=>$step['why'],
        'next'=>$step['next'],
        'module_type'=>$step['actual'],
    ], JSON_UNESCAPED_SLASHES);

    $x = cloneOrUpdate('cards',$partnerId,[
        'club_id'=>$clubId,
        'name'=>$name,
        'head'=>trText("STEP {$n} OF 12 • ".$step['stage']),
        'title'=>trText($step['title']),
        'description'=>trText($step['desc']),
        'currency'=>'USD',
        'initial_bonus_points'=>0,
        'points_expiration_months'=>24,
        'currency_unit_amount'=>1,
        'points_per_currency'=>1,
        'min_points_per_purchase'=>0,
        'max_points_per_purchase'=>1000000,
        'bg_color'=>$step['color'],
        'text_color'=>'#FFFFFF',
        'is_active'=>1,
        'is_visible_by_default'=>0,
        'is_visible_when_logged_in'=>0,
        'custom_rule1'=>trText('Why Tom cares: '.$step['why']),
        'custom_rule2'=>trText('Merchant image: customizable from the standard card background upload.'),
        'custom_rule3'=>trText('Next in presentation: '.$step['next']),
        'meta'=>$meta,
    ], $legacyCardId);
    $result['records'][]=$x;
    if (!empty($x['id'])) {
        $presentationIds[$step['n']]=$x['id'];
        $result['presentation_cards'][]=[
            'sequence'=>$step['n'],'id'=>$x['id'],'title'=>$step['title'],
            'public_url'=>"https://app.placesrewards.com/en-us/card/{$x['id']}",
            'merchant_image_collection'=>'background',
        ];
    }
}

$result['records'][] = cloneOrUpdate('tiers',$partnerId,[
    'club_id'=>$clubId,
    'name'=>'[TOM DEMO] Hunter Explorer Tier',
    'display_name'=>'Hunter Explorer',
    'description'=>'Entry tier for registered hunters. Progress toward Trailblazer and Local Legend status through visits, referrals and participation.',
    'level'=>1,'points_threshold'=>0,'is_default'=>0,'is_active'=>1,
    'color'=>'#2563EB',
    'benefits'=>json_encode(['Early reward access','Trail progress recognition','Merchant bonus eligibility'],JSON_UNESCAPED_SLASHES),
    'meta'=>json_encode(['demo_sequence'=>2,'purpose'=>'hunter_profile_vip_progression'],JSON_UNESCAPED_SLASHES),
]);

$result['records'][] = cloneOrUpdate('rewards',$partnerId,[
    'name'=>'[TOM DEMO 04] Clue Stop Discovery Bonus',
    'title'=>trText('Clue Stop Discovery Bonus'),
    'description'=>trText('A merchant-funded reward attached to a clue-stop visit. Places Rewards does not reveal or alter the official treasure clue.'),
    'points'=>250,'is_active'=>1,
    'meta'=>json_encode(['demo_sequence'=>4,'official_clue_separate'=>true,'purpose'=>'clue_stop_engagement'],JSON_UNESCAPED_SLASHES),
]);

$stamp5 = cloneOrUpdate('stamp_cards',$partnerId,[
    'club_id'=>$clubId,
    'name'=>'[TOM DEMO 05] Merchant Check-In Trail',
    'title'=>trText('5-Stop Merchant Check-In Trail'),
    'description'=>trText('Visit five participating businesses, check in and collect a digital stamp at each stop.'),
    'stamps_required'=>5,'stamps_per_purchase'=>1,'max_stamps_per_day'=>5,'max_stamps_per_transaction'=>1,
    'min_purchase_amount'=>0,'reward_title'=>trText('Trail Finisher Bonus'),'reward_description'=>trText('Unlock a participating-merchant reward after completing all five stops.'),
    'currency'=>'USD','requires_physical_claim'=>0,'bg_color'=>'#D97706','text_color'=>'#FFFFFF','stamp_color'=>'#F59E0B','empty_stamp_color'=>'#FDE68A',
    'is_active'=>1,'is_visible_by_default'=>1,
    'meta'=>json_encode(['demo_sequence'=>5,'purpose'=>'verified_merchant_foot_traffic'],JSON_UNESCAPED_SLASHES),
], $legacyStampId);
$result['records'][]=$stamp5;

$result['records'][] = cloneOrUpdate('rewards',$partnerId,[
    'name'=>'[TOM DEMO 06] Local Prize Vault Reward',
    'title'=>trText('Local Prize Vault'),
    'description'=>trText('Merchant and sponsor-funded prizes that reward participation without changing official treasure odds.'),
    'points'=>500,'is_active'=>1,
    'meta'=>json_encode(['demo_sequence'=>6,'purpose'=>'sponsor_merchant_prize_value'],JSON_UNESCAPED_SLASHES),
]);
$result['records'][] = cloneOrUpdate('giveaways',$partnerId,[
    'name'=>'[TOM DEMO 06] Local Treasure Bonus Giveaway',
    'title'=>trText('Local Treasure Bonus Giveaway'),
    'description'=>trText('Bonus giveaway for qualifying hunter activity, funded by participating merchants or sponsors.'),
    'is_active'=>1,
]);

$result['records'][] = cloneOrUpdate('referral_programs',$partnerId,[
    'name'=>'[TOM DEMO 07] Bring Another Hunter',
    'title'=>trText('Bring Another Hunter'),
    'description'=>trText('Reward existing hunters for bringing a new participant who completes qualifying activity.'),
    'is_active'=>1,
]);

$stamp8 = cloneOrUpdate('stamp_cards',$partnerId,[
    'club_id'=>$clubId,
    'name'=>'[TOM DEMO 08] Treasure Trail VIP Pass',
    'title'=>trText('Treasure Trail VIP Pass'),
    'description'=>trText('Complete eight participating stops to unlock Local Legend recognition and a premium merchant perk.'),
    'stamps_required'=>8,'stamps_per_purchase'=>1,'max_stamps_per_day'=>8,'max_stamps_per_transaction'=>1,
    'min_purchase_amount'=>0,'reward_title'=>trText('Local Legend Reward'),'reward_description'=>trText('Premium reward for completing the extended Treasure Trail.'),
    'currency'=>'USD','requires_physical_claim'=>0,'bg_color'=>'#0E7490','text_color'=>'#FFFFFF','stamp_color'=>'#06B6D4','empty_stamp_color'=>'#CFFAFE',
    'is_active'=>1,'is_visible_by_default'=>1,
    'meta'=>json_encode(['demo_sequence'=>8,'purpose'=>'vip_progression_cross_merchant'],JSON_UNESCAPED_SLASHES),
], $legacyStampId);
$result['records'][]=$stamp8;

$scratch = cloneOrUpdate('scratch_games',$partnerId,[
    'name'=>'[TOM DEMO 09] Mystery Bonus Scratch & Win',
    'description'=>'Instant-win merchant engagement that remains separate from official treasure clues and treasure-finding odds.',
    'win_rate'=>25,'is_active'=>1,
], $legacyScratchId);
$result['records'][]=$scratch;

$voucher = cloneOrUpdate('vouchers',$partnerId,[
    'club_id'=>$clubId,
    'code'=>'TOMRETURN5',
    'name'=>'[TOM DEMO 10] Hunter Comeback $5 Off $25',
    'title'=>trText('Hunter Comeback: $5 Off $25'),
    'description'=>trText('A post-hunt return-visit trigger designed to convert event traffic into another local purchase.'),
    'value'=>5,'currency'=>'USD','min_purchase_amount'=>25,
    'max_uses_per_member'=>1,'is_active'=>1,'is_public'=>1,'is_visible_by_default'=>1,'is_single_use'=>1,'stackable'=>0,
    'source'=>'tom_demo',
    'meta'=>json_encode(['demo_sequence'=>10,'purpose'=>'post_hunt_reactivation'],JSON_UNESCAPED_SLASHES),
], $legacyVoucherId);
$result['records'][]=$voucher;

$result['records'][] = cloneOrUpdate('email_campaigns',$partnerId,[
    'subject'=>'[TOM DEMO 11] Your Treasure Hunt rewards are still waiting',
    'body'=>'The treasure hunt brought you here. Your local rewards, unfinished trail progress and participating-business offers give you a reason to come back. This demo campaign is intentionally left in draft status.',
    'segment_type'=>'custom','segment_config'=>json_encode(['demo_sequence'=>11,'audience'=>'inactive_or_unfinished_hunters'],JSON_UNESCAPED_SLASHES),
    'status'=>'draft',
]);
$result['records'][] = cloneOrUpdate('email_campaigns',$partnerId,[
    'subject'=>'[TOM DEMO 12] Be first in line for the next Treasure Hunt',
    'body'=>'Early-access reactivation for prior hunters: preserve the audience, reopen sponsor conversations and start the next hunt with measurable momentum instead of starting from zero.',
    'segment_type'=>'custom','segment_config'=>json_encode(['demo_sequence'=>12,'audience'=>'prior_hunters'],JSON_UNESCAPED_SLASHES),
    'status'=>'draft',
]);

$moduleLinks = [];
foreach ($sequence as $step) {
    $id = $presentationIds[$step['n']] ?? null;
    $moduleLinks[] = [
        'sequence'=>$step['n'],
        'title'=>$step['title'],
        'stage'=>$step['stage'],
        'presentation_card'=>$id ? "https://app.placesrewards.com/en-us/card/{$id}" : null,
        'merchant_image_upload'=>'Partner card editor → Background image',
        'why_tom_cares'=>$step['why'],
    ];
}
if (!empty($stamp5['id'])) $moduleLinks[4]['live_module']="https://app.placesrewards.com/en-us/stamp-card/{$stamp5['id']}";
if (!empty($stamp8['id'])) $moduleLinks[7]['live_module']="https://app.placesrewards.com/en-us/stamp-card/{$stamp8['id']}";
if (!empty($voucher['id'])) $moduleLinks[9]['live_module']="https://app.placesrewards.com/en-us/voucher/{$voucher['id']}";
$moduleLinks[10]['live_module']='https://app.placesrewards.com/en-us/partner/analytics';
$moduleLinks[11]['live_module']='https://app.placesrewards.com/en-us/partner/email-campaigns';

$cardsHtml = '';
foreach ($sequence as $step) {
    $n = str_pad((string)$step['n'],2,'0',STR_PAD_LEFT);
    $id = $presentationIds[$step['n']] ?? '';
    $publicCard = $id ? "/en-us/card/{$id}" : '#';
    $cardsHtml .= '<article class="module" id="step-'.$n.'" data-step="'.$step['n'].'">'
      .'<div class="number">'.$n.'</div>'
      .'<div class="art" style="background:linear-gradient(135deg,'.htmlspecialchars($step['color']).',#07111f)"><div class="artnum">'.$n.'</div><div class="artstage">'.htmlspecialchars($step['stage']).'</div><div class="arttitle">'.htmlspecialchars($step['title']).'</div><div class="artsub">Northeast Ohio Treasure Hunt × Places Rewards</div></div>'
      .'<div class="copy"><span class="stage">'.htmlspecialchars($step['stage']).'</span>'
      .'<h2>'.htmlspecialchars($step['title']).'</h2>'
      .'<p>'.htmlspecialchars($step['desc']).'</p>'
      .'<div class="why"><strong>Why Tom cares:</strong> '.htmlspecialchars($step['why']).'</div>'
      .'<div class="custom">Merchant image: customizable for this specific card.</div>'
      .'<div class="actions"><a class="primary" href="'.htmlspecialchars($publicCard).'" target="_blank" rel="noopener">Open live card</a>'
      .($step['n'] < 12 ? '<a href="#step-'.str_pad((string)($step['n']+1),2,'0',STR_PAD_LEFT).'">Next →</a>' : '<a href="#step-01">Back to 01</a>')
      .'</div></div></article>';
}
$html = '<!doctype html><html><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1">'
.'<title>Places Rewards × Northeast Ohio Treasure Hunt</title><style>'
.'*{box-sizing:border-box}html{scroll-behavior:smooth}body{margin:0;font-family:Inter,ui-sans-serif,system-ui,-apple-system,Segoe UI,sans-serif;background:#07111f;color:#eaf2ff}.hero{padding:64px 24px 42px;max-width:1180px;margin:auto}.eyebrow{color:#4ade80;font-weight:800;letter-spacing:.12em;text-transform:uppercase;font-size:13px}.hero h1{font-size:clamp(38px,7vw,76px);line-height:.98;margin:14px 0;max-width:1000px}.hero p{font-size:20px;max-width:760px;color:#b8c6da;line-height:1.55}.sequence{display:grid;gap:22px;max-width:1180px;margin:auto;padding:0 24px 80px}.module{display:grid;grid-template-columns:minmax(260px,420px) 1fr;background:#fff;color:#102033;border-radius:24px;overflow:hidden;box-shadow:0 24px 70px rgba(0,0,0,.28);position:relative}.art{min-height:420px;padding:32px;display:flex;flex-direction:column;justify-content:flex-end;color:#fff}.artnum{font-size:90px;font-weight:950;line-height:.8;opacity:.22}.artstage{font-weight:900;letter-spacing:.16em;font-size:13px;margin:18px 0 8px}.arttitle{font-size:34px;font-weight:900;line-height:1.05}.artsub{margin-top:12px;opacity:.82}.copy{padding:34px}.number{position:absolute;left:18px;top:18px;background:#07111f;color:#fff;border:2px solid #4ade80;border-radius:999px;width:58px;height:58px;display:grid;place-items:center;font-weight:900;font-size:20px;z-index:2}.stage{font-weight:900;letter-spacing:.13em;font-size:12px;color:#047857}.copy h2{font-size:34px;line-height:1.05;margin:10px 0 16px}.copy p{font-size:17px;line-height:1.55;color:#42536a}.why{margin-top:22px;padding:16px;border-radius:14px;background:#ecfdf5;border:1px solid #bbf7d0}.custom{margin-top:12px;color:#64748b;font-size:13px}.actions{display:flex;gap:12px;flex-wrap:wrap;margin-top:24px}.actions a{text-decoration:none;padding:12px 17px;border-radius:12px;border:1px solid #cbd5e1;color:#102033;font-weight:800}.actions .primary{background:#0f766e;color:white;border-color:#0f766e}.footer{max-width:1180px;margin:auto;padding:0 24px 50px;color:#94a3b8}.footer strong{color:#fff}@media(max-width:760px){.module{grid-template-columns:1fr}.art{min-height:320px}.copy{padding:26px}.copy h2{font-size:29px}}</style></head><body>'
.'<section class="hero"><div class="eyebrow">Strategic Module Showcase • Live App Demo</div>'
.'<h1>The Hunt Creates the Traffic.<br>Places Rewards Makes It Compound.</h1>'
.'<p>Tom, this 12-step demonstration follows the hunter from first discovery through merchant visits, referrals, comeback revenue, analytics and next-hunt reactivation. Every card is intentionally different and appears in the order it matters.</p></section>'
.'<main class="sequence">'.$cardsHtml.'</main>'
.'<div class="footer"><strong>Places Rewards</strong> • Northeast Ohio Treasure Hunt subscription demonstration • Official treasure clues remain independent from bonus rewards and gamification.</div></body></html>';

@mkdir($publicRoot,0755,true);
file_put_contents($publicRoot.'/index.html',$html);
$result['module_links']=$moduleLinks;
$result['presentation_url']='https://app.placesrewards.com/demo/northeast-ohio-treasure-hunt/tom/';
$result['status']='completed';
$result['completed_at']=now()->toIso8601String();

file_put_contents($out,json_encode($result,JSON_PRETTY_PRINT|JSON_UNESCAPED_SLASHES));
file_put_contents($linkOut,json_encode([
    'presentation_url'=>$result['presentation_url'],
    'generated_at'=>now()->toIso8601String(),
    'modules'=>$moduleLinks
],JSON_PRETTY_PRINT|JSON_UNESCAPED_SLASHES));
echo json_encode(['status'=>$result['status'],'presentation_url'=>$result['presentation_url'],'records'=>count($result['records']),'cards'=>count($presentationIds)],JSON_PRETTY_PRINT|JSON_UNESCAPED_SLASHES),"\n";
