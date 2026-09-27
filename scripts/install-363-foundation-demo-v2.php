<?php

declare(strict_types=1);

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

$agentRoot='/home/placevle/placesrewards-agent-server';
$appRoot='/home/placevle/app.placesrewards.com';
$out=$agentRoot.'/results/campaigns/363-foundation-demo-install-v2-result.json';
$partnerId='019dbfc5-e395-7082-9214-20859f344cce';
$clubId='019dc14a-adae-73eb-b837-79b042032b4f';

require $appRoot.'/vendor/autoload.php';
$app=require $appRoot.'/bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();

function cols(string $t): array { return Schema::hasTable($t)?Schema::getColumnListing($t):[]; }
function ownerQ(string $t,string $pid){$c=cols($t);$q=DB::table($t);if(in_array('created_by',$c,true))$q->where('created_by',$pid);elseif(in_array('partner_id',$c,true))$q->where('partner_id',$pid);elseif(in_array('owner_id',$c,true))$q->where('owner_id',$pid);return $q;}
function tr(string $s): string { return json_encode(['en'=>$s],JSON_UNESCAPED_SLASHES); }

function cloneSafe(string $table,string $pid,array $o,?callable $filter=null): array {
    try {
        if(!Schema::hasTable($table)) return ['status'=>'skipped','table'=>$table,'reason'=>'table_missing'];
        $c=cols($table);
        $nameCol=in_array('name',$c,true)?'name':(in_array('title',$c,true)?'title':null);
        if(!$nameCol) return ['status'=>'skipped','table'=>$table,'reason'=>'no_name_or_title_column'];
        $wanted=$o[$nameCol]??null;
        if($wanted!==null){
            $e=ownerQ($table,$pid)->where($nameCol,$wanted)->first();
            if($e){
                $update=[];
                foreach($o as $k=>$v) if(in_array($k,$c,true)) $update[$k]=$v;
                if(in_array('updated_at',$c,true)) $update['updated_at']=now();
                if($update!==[]) DB::table($table)->where('id',$e->id)->update($update);
                return ['status'=>'updated','table'=>$table,'id'=>$e->id??null,'name'=>$wanted];
            }
        }
        $q=ownerQ($table,$pid); if($filter)$filter($q,$c); $tpl=$q->first();
        if(!$tpl)return ['status'=>'skipped','table'=>$table,'reason'=>'no_template'];
        $d=(array)$tpl;
        if(in_array('id',$c,true))$d['id']=(string)Str::uuid();
        foreach(['deleted_at','deleted_by','updated_by','played_at','winner_id'] as $x)if(in_array($x,$c,true))$d[$x]=null;
        if(in_array('created_at',$c,true))$d['created_at']=now(); if(in_array('updated_at',$c,true))$d['updated_at']=now();
        if(in_array('created_by',$c,true))$d['created_by']=$pid; if(in_array('partner_id',$c,true))$d['partner_id']=$pid;
        foreach(['unique_identifier','identifier','code','slug'] as $x)if(in_array($x,$c,true)&&array_key_exists($x,$d))$d[$x]='363-'.strtoupper(Str::random(12));
        foreach($o as $k=>$v)if(in_array($k,$c,true))$d[$k]=$v;
        DB::table($table)->insert($d);
        return ['status'=>'created','table'=>$table,'id'=>$d['id']??null,'name'=>$wanted];
    } catch(Throwable $e){ return ['status'=>'failed','table'=>$table,'error'=>$e->getMessage()]; }
}

$r=['campaign'=>'363 Foundation Complete Demo Campaign','partner_id'=>$partnerId,'club_id'=>$clubId,'records'=>[],'started_at'=>now()->toIso8601String()];

try {
  if(Schema::hasTable('clubs')&&DB::table('clubs')->where('id',$clubId)->exists()){
    $u=['is_active'=>1]; if(Schema::hasColumn('clubs','description'))$u['description']='363 Foundation / 363 Empire community engagement demo powered by Places Rewards.'; if(Schema::hasColumn('clubs','updated_at'))$u['updated_at']=now();
    DB::table('clubs')->where('id',$clubId)->update($u); $r['records'][]=['status'=>'updated','table'=>'clubs','id'=>$clubId,'name'=>'BLACKEMPIRE363'];
  }
} catch(Throwable $e){$r['records'][]=['status'=>'failed','table'=>'clubs','error'=>$e->getMessage()];}

$cardDefs=[
 ['[DEMO] 363 Foundation Community Rewards','363 Foundation Community Rewards','Join. Participate. Earn. Grow.','Your 363 Foundation community rewards card. Join the community, check in at eligible events and activities, earn points for participation, unlock supporter rewards, and keep track of your progress in one place. Radio Rich and 363 Foundation use this card to turn one-time engagement into an ongoing community relationship.',10],
 ['[DEMO] 363 Foundation Founder Momentum Card','363 Founder Momentum','Turn Participation Into Momentum','A progress card for founders, supporters and community builders. Earn recognition for meaningful participation, event attendance, referrals and milestone actions. Use your progress to unlock higher-value community benefits and VIP opportunities.',20]
];
$cardIds=[];
foreach($cardDefs as [$name,$title,$head,$desc,$ppc]){
 $x=cloneSafe('cards',$partnerId,['club_id'=>$clubId,'name'=>$name,'title'=>tr($title),'head'=>tr($head),'description'=>tr($desc),'currency'=>'USD','initial_bonus_points'=>363,'points_per_currency'=>$ppc,'currency_unit_amount'=>1,'points_expiration_months'=>24,'is_active'=>1,'is_visible_by_default'=>1],fn($q,$c)=>in_array('name',$c,true)?$q->where('name','like','%DEMO%'):null);
 $r['records'][]=$x;if(($x['status']==='created'||$x['status']==='existing')&&($x['id']??null))$cardIds[]=$x['id'];
}
$primaryCard=$cardIds[0]??null;

$rewardDefs=[
 ['[DEMO] 363 Foundation Supporter Welcome Reward','363 Supporter Welcome Reward','A first-step reward for joining and participating in the 363 Foundation community. Use it to recognize a new supporter and give them an immediate reason to stay connected.'],
 ['[DEMO] 363 Foundation Community Builder Reward','363 Community Builder Reward','A reward for supporters who help grow the community through referrals, introductions, volunteering or other qualifying community-building actions.'],
 ['[DEMO] 363 Foundation Event VIP Reward','363 Event VIP Reward','A higher-level reward for engaged supporters. Unlock eligible VIP access, special recognition or event-based benefits after completing the qualifying participation goal.'],
 ['[DEMO] 363 Foundation Milestone Reward','363 Milestone Reward','A milestone benefit for sustained engagement. Earn it by reaching the qualifying points, participation or progress threshold shown in your Places Rewards account.']
];
foreach($rewardDefs as [$name,$title,$desc]){
 $x=cloneSafe('rewards',$partnerId,['club_id'=>$clubId,'name'=>$name,'title'=>tr($title),'description'=>tr($desc),'is_active'=>1],fn($q,$c)=>in_array('name',$c,true)?$q->where('name','like','%DEMO%'):null);$r['records'][]=$x;
 if($primaryCard&&($x['id']??null)&&Schema::hasTable('card_reward')){try{DB::table('card_reward')->updateOrInsert(['card_id'=>$primaryCard,'reward_id'=>$x['id']],['created_at'=>now(),'updated_at'=>now()]);}catch(Throwable $e){}}
}

foreach([
 ['[DEMO] 363 Foundation Action Streak','363 Action Streak','Complete 6 qualifying community actions—such as event participation, referrals or approved supporter activities—to complete your streak and unlock the listed supporter reward.'],
 ['[DEMO] 363 Foundation Live & Event Streak','363 Live + Event Streak','Attend or participate in 6 qualifying Radio Rich or 363 Foundation live sessions, events or community activations. Collect one stamp for each verified check-in and complete the card to unlock the listed reward.']
] as [$name,$title,$desc])$r['records'][]=cloneSafe('stamp_cards',$partnerId,['club_id'=>$clubId,'name'=>$name,'title'=>tr($title),'description'=>tr($desc),'is_active'=>1],fn($q,$c)=>in_array('name',$c,true)?$q->where('name','like','%DEMO%'):null);

foreach([
 ['[DEMO] 363 Foundation Event Access Pass','363 Event Access Pass','Your digital access pass for a qualifying 363 Foundation or Radio Rich event, workshop, mixer or community activation. Save the pass, present it when requested, and use it to connect your attendance to rewards and follow-up.'],
 ['[DEMO] 363 Foundation Community Thank-You','363 Community Thank-You','A thank-you benefit for verified community participation. Claim this after a qualifying event or activity as recognition for showing up, contributing and staying connected to the 363 Foundation community.']
] as [$name,$title,$desc])$r['records'][]=cloneSafe('vouchers',$partnerId,['club_id'=>$clubId,'name'=>$name,'title'=>tr($title),'description'=>tr($desc),'is_active'=>1],fn($q,$c)=>in_array('name',$c,true)?$q->where('name','like','%DEMO%'):null);

foreach([
 ['tiers','[DEMO] 363 Foundation Supporter','363 Supporter','Entry supporter tier demonstrating recognition and progression.'],
 ['tiers','[DEMO] 363 Foundation Builder','363 Builder','Mid-tier recognition for consistent community builders.'],
 ['tiers','[DEMO] 363 Foundation Inner Circle','363 Inner Circle','Top demo tier for highly engaged founders and supporters.'],
 ['scratch_games','[DEMO] 363 Foundation Scratch & Win','Radio Rich + 363 Foundation Scratch & Win','A digital instant-reveal game used during qualifying Radio Rich and 363 Foundation events or community activations. Open the card, reveal the result, and follow the on-screen instructions for any eligible reward or participation bonus.'],
 ['giveaways','[DEMO] 363 Foundation Spotlight Giveaway','Radio Rich + 363 Foundation Spotlight Giveaway','Enter a qualifying community giveaway connected to Radio Rich, 363 Foundation events, podcasts or live activations. The card explains how to enter, what qualifies, and how winners or rewards are handled.'],
 ['referral_programs','[DEMO] 363 Foundation Community Referral','Build the Community — Refer a Friend','Invite a friend or supporter to join the 363 Foundation community. After the referred person completes the qualifying action, the referral can count toward the reward or recognition shown in the campaign.'],
 ['segments','[DEMO] 363 Foundation Engaged Supporters','363 Foundation Engaged Supporters','Audience segment for targeted follow-up and reactivation.'],
 ['email_campaigns','[DEMO] 363 Foundation Welcome & Reactivation','363 Foundation Welcome + Reactivation','Lifecycle campaign for supporter onboarding and re-engagement.'],
 ['review_campaigns','[DEMO] 363 Foundation Community Voice','363 Foundation Community Voice','Feedback and review campaign that turns participant sentiment into social proof.']
] as [$table,$name,$title,$desc])$r['records'][]=cloneSafe($table,$partnerId,['club_id'=>$clubId,'name'=>$name,'title'=>tr($title),'description'=>tr($desc),'is_active'=>1],fn($q,$c)=>in_array('name',$c,true)?$q->where('name','like','%DEMO%'):null);

$r['status']=count(array_filter($r['records'],fn($x)=>($x['status']??'')==='failed'))===0?'completed':'completed_with_skips_or_failures';
$r['completed_at']=now()->toIso8601String();
file_put_contents($out,json_encode($r,JSON_PRETTY_PRINT|JSON_UNESCAPED_SLASHES));
echo json_encode($r,JSON_PRETTY_PRINT|JSON_UNESCAPED_SLASHES),"\n";
