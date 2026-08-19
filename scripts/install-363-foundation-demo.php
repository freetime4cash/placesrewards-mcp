<?php

declare(strict_types=1);

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

$agentRoot = '/home/placevle/placesrewards-agent-server';
$appRoot = '/home/placevle/app.placesrewards.com';
$out = $agentRoot . '/results/campaigns/363-foundation-demo-install-result.json';
$partnerId = '019dbfc5-e395-7082-9214-20859f344cce';
$clubId = '019dc14a-adae-73eb-b837-79b042032b4f'; // existing BLACKEMPIRE363

require $appRoot . '/vendor/autoload.php';
$app = require $appRoot . '/bootstrap/app.php';
$kernel = $app->make(Illuminate\Contracts\Console\Kernel::class);
$kernel->bootstrap();

function columns(string $table): array {
    return Schema::hasTable($table) ? Schema::getColumnListing($table) : [];
}

function rowOwnerQuery(string $table, string $partnerId) {
    $cols = columns($table);
    $q = DB::table($table);
    if (in_array('created_by', $cols, true)) $q->where('created_by', $partnerId);
    elseif (in_array('partner_id', $cols, true)) $q->where('partner_id', $partnerId);
    elseif (in_array('owner_id', $cols, true)) $q->where('owner_id', $partnerId);
    return $q;
}

function cloneTemplate(string $table, string $partnerId, array $overrides, ?callable $templateFilter = null): array {
    if (!Schema::hasTable($table)) return ['status'=>'skipped','reason'=>'table_missing','table'=>$table];
    $cols = columns($table);
    $nameCol = in_array('name', $cols, true) ? 'name' : (in_array('title', $cols, true) ? 'title' : null);
    $wanted = $overrides[$nameCol] ?? null;
    if ($nameCol && $wanted !== null) {
        $existingQ = rowOwnerQuery($table, $partnerId)->where($nameCol, $wanted);
        $existing = $existingQ->first();
        if ($existing) return ['status'=>'existing','table'=>$table,'id'=>$existing->id ?? null,'name'=>$wanted];
    }

    $q = rowOwnerQuery($table, $partnerId);
    if ($templateFilter) $templateFilter($q, $cols);
    $template = $q->orderByDesc(in_array('created_at',$cols,true) ? 'created_at' : ($cols[0] ?? 'id'))->first();
    if (!$template) return ['status'=>'skipped','reason'=>'no_template','table'=>$table];

    $data = (array)$template;
    if (in_array('id', $cols, true)) $data['id'] = (string) Str::uuid();
    foreach (['deleted_at','deleted_by','updated_by'] as $c) if (in_array($c,$cols,true)) $data[$c] = null;
    if (in_array('created_at',$cols,true)) $data['created_at'] = now();
    if (in_array('updated_at',$cols,true)) $data['updated_at'] = now();
    if (in_array('created_by',$cols,true)) $data['created_by'] = $partnerId;
    if (in_array('partner_id',$cols,true)) $data['partner_id'] = $partnerId;
    if (in_array('unique_identifier',$cols,true)) $data['unique_identifier'] = random_int(100,999).'-'.random_int(100,999).'-'.random_int(100,999).'-'.random_int(100,999);
    if (in_array('code',$cols,true) && isset($data['code'])) $data['code'] = '363-'.strtoupper(Str::random(10));

    foreach ($overrides as $k=>$v) if (in_array($k,$cols,true)) $data[$k] = $v;
    DB::table($table)->insert($data);
    return ['status'=>'created','table'=>$table,'id'=>$data['id'] ?? null,'name'=>$wanted];
}

function trans(string $text): string { return json_encode(['en'=>$text], JSON_UNESCAPED_SLASHES); }

$result = [
  'campaign' => '363 Foundation Complete Demo Campaign',
  'partner_id' => $partnerId,
  'club_id' => $clubId,
  'club_name' => 'BLACKEMPIRE363',
  'started_at' => now()->toIso8601String(),
  'records' => [],
  'errors' => [],
];

DB::beginTransaction();
try {
    // Ensure club branding is active without replacing the existing 363 club.
    if (Schema::hasTable('clubs')) {
        $club = DB::table('clubs')->where('id',$clubId)->first();
        if ($club) {
            $updates = ['is_active'=>1,'updated_at'=>now()];
            if (Schema::hasColumn('clubs','description')) $updates['description'] = '363 Foundation / 363 Empire community engagement demo powered by Places Rewards.';
            DB::table('clubs')->where('id',$clubId)->update($updates);
            $result['records'][] = ['status'=>'updated','table'=>'clubs','id'=>$clubId,'name'=>'BLACKEMPIRE363'];
        }
    }

    $primaryCard = cloneTemplate('cards',$partnerId,[
        'club_id'=>$clubId,
        'name'=>'[DEMO] 363 Foundation Community Rewards',
        'title'=>trans('363 Foundation Community Rewards'),
        'head'=>trans('Build. Support. Earn. Elevate.'),
        'description'=>trans('A community rewards experience for 363 Foundation supporters, events, referrals, participation and mission-driven engagement.'),
        'currency'=>'USD','initial_bonus_points'=>363,'points_per_currency'=>10,'currency_unit_amount'=>1,
        'points_expiration_months'=>24,'is_active'=>1,'is_visible_by_default'=>1,
    ], fn($q,$cols) => in_array('name',$cols,true) ? $q->where('name','like','%DEMO%') : null);
    $result['records'][] = $primaryCard;
    $cardId = $primaryCard['id'] ?? null;

    $result['records'][] = cloneTemplate('cards',$partnerId,[
        'club_id'=>$clubId,
        'name'=>'[DEMO] 363 Foundation Founder Momentum Card',
        'title'=>trans('363 Founder Momentum'),
        'head'=>trans('Participation Creates Momentum'),
        'description'=>trans('Demo points card showing how founders and supporters can be rewarded for attendance, referrals, purchases, community actions and milestones.'),
        'currency'=>'USD','initial_bonus_points'=>363,'points_per_currency'=>20,'currency_unit_amount'=>1,
        'points_expiration_months'=>24,'is_active'=>1,'is_visible_by_default'=>1,
    ], fn($q,$cols) => in_array('name',$cols,true) ? $q->where('name','like','%DEMO%') : null);

    $rewardNames = [
        ['[DEMO] 363 Foundation Supporter Welcome Reward','363 Supporter Welcome Reward','Welcome supporters with an instant benefit that demonstrates day-one engagement.'],
        ['[DEMO] 363 Foundation Community Builder Reward','363 Community Builder Reward','Reward referrals, introductions and actions that grow the 363 Foundation community.'],
        ['[DEMO] 363 Foundation Event VIP Reward','363 Event VIP Reward','Demonstrates VIP access, recognition or event-based perks for highly engaged supporters.'],
        ['[DEMO] 363 Foundation Milestone Reward','363 Milestone Reward','Unlock a milestone benefit when supporters reach sustained engagement goals.'],
    ];
    foreach ($rewardNames as [$name,$title,$desc]) {
        $r = cloneTemplate('rewards',$partnerId,[
            'name'=>$name,'title'=>trans($title),'description'=>trans($desc),'is_active'=>1,
            'club_id'=>$clubId,
        ], fn($q,$cols) => in_array('name',$cols,true) ? $q->where('name','like','%DEMO%') : null);
        $result['records'][] = $r;
        if ($cardId && ($r['id'] ?? null) && Schema::hasTable('card_reward')) {
            DB::table('card_reward')->updateOrInsert(['card_id'=>$cardId,'reward_id'=>$r['id']],['created_at'=>now(),'updated_at'=>now()]);
        }
    }

    $stampTargets = [
        ['[DEMO] 363 Foundation Action Streak','363 Action Streak','Complete 6 meaningful community actions to unlock a supporter reward.'],
        ['[DEMO] 363 Foundation Live & Event Streak','363 Live + Event Streak','Attend or participate repeatedly to demonstrate retention and recurring engagement.'],
    ];
    foreach ($stampTargets as [$name,$title,$desc]) {
        $result['records'][] = cloneTemplate('stamp_cards',$partnerId,[
            'club_id'=>$clubId,'name'=>$name,'title'=>trans($title),'description'=>trans($desc),'is_active'=>1,
        ], fn($q,$cols) => in_array('name',$cols,true) ? $q->where('name','like','%DEMO%') : null);
    }

    $voucherTargets = [
        ['[DEMO] 363 Foundation Event Access Pass','363 Event Access Pass','Demo voucher for event access, supporter perks or campaign activations.'],
        ['[DEMO] 363 Foundation Community Thank-You','363 Community Thank-You','A limited supporter thank-you voucher for reactivation and appreciation.'],
    ];
    foreach ($voucherTargets as [$name,$title,$desc]) {
        $result['records'][] = cloneTemplate('vouchers',$partnerId,[
            'club_id'=>$clubId,'name'=>$name,'title'=>trans($title),'description'=>trans($desc),'is_active'=>1,
        ], fn($q,$cols) => in_array('name',$cols,true) ? $q->where('name','like','%DEMO%') : null);
    }

    $tierTargets = [
        ['[DEMO] 363 Foundation Supporter','363 Supporter'],
        ['[DEMO] 363 Foundation Builder','363 Builder'],
        ['[DEMO] 363 Foundation Inner Circle','363 Inner Circle'],
    ];
    foreach ($tierTargets as [$name,$title]) {
        $result['records'][] = cloneTemplate('tiers',$partnerId,[
            'club_id'=>$clubId,'name'=>$name,'title'=>trans($title),'is_active'=>1,
        ], fn($q,$cols) => in_array('name',$cols,true) ? $q->where('name','like','%DEMO%') : null);
    }

    // Optional modules: clone only when both table and a safe template already exist for this same partner.
    $optional = [
      'scratch_cards' => ['[DEMO] 363 Foundation Scratch & Win','363 Foundation Scratch & Win','Instant-win demo for livestreams, events and supporter activation.'],
      'giveaways' => ['[DEMO] 363 Foundation Spotlight Giveaway','363 Foundation Spotlight Giveaway','A demo giveaway for community participation, podcasts, events and livestreams.'],
      'referral_programs' => ['[DEMO] 363 Foundation Community Referral','363 Foundation Community Referral','Reward supporters for bringing aligned people into the 363 community.'],
      'segments' => ['[DEMO] 363 Foundation Engaged Supporters','363 Foundation Engaged Supporters','Demo audience segment for targeted follow-up and reactivation.'],
      'email_campaigns' => ['[DEMO] 363 Foundation Welcome & Reactivation','363 Foundation Welcome + Reactivation','Demo lifecycle campaign for supporter onboarding and re-engagement.'],
      'review_campaigns' => ['[DEMO] 363 Foundation Community Voice','363 Foundation Community Voice','Demo feedback/review campaign to turn participant sentiment into social proof.'],
    ];
    foreach ($optional as $table=>$vals) {
        [$name,$title,$desc] = $vals;
        $result['records'][] = cloneTemplate($table,$partnerId,[
            'club_id'=>$clubId,'name'=>$name,'title'=>trans($title),'description'=>trans($desc),'is_active'=>1,
        ], fn($q,$cols) => in_array('name',$cols,true) ? $q->where('name','like','%DEMO%') : null);
    }

    DB::commit();
    $result['status'] = 'completed';
} catch (Throwable $e) {
    DB::rollBack();
    $result['status'] = 'rolled_back';
    $result['errors'][] = ['message'=>$e->getMessage(),'file'=>$e->getFile(),'line'=>$e->getLine()];
}

$result['completed_at'] = now()->toIso8601String();
file_put_contents($out, json_encode($result, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
echo json_encode($result, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES), "\n";
