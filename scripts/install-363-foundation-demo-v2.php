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
        if($wanted!==null){$e=ownerQ($table,$pid)->where($nameCol,$wanted)->first();if($e)return ['status'=>'existing','table'=>$table,'id'=>$e->id??null,'name'=>$wanted];}
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
 ['[DEMO] 363 Foundation Community Rewards','363 Foundation Community Rewards','Build. Support. Earn. Elevate.','A community rewards experience for 363 Foundation supporters, events, referrals, participation and mission-driven engagement.',10],
 ['[DEMO] 363 Foundation Founder Momentum Card','363 Founder Momentum','Participation Creates Momentum','Reward founders and supporters for attendance, referrals, purchases, community actions and milestones.',20]
];
$cardIds=[];
foreach($cardDefs as [$name,$title,$head,$desc,$ppc]){
 $x=cloneSafe('cards',$partnerId,['club_id'=>$clubId,'name'=>$name,'title'=>tr($title),'head'=>tr($head),'description'=>tr($desc),'currency'=>'USD','initial_bonus_points'=>363,'points_per_currency'=>$ppc,'currency_unit_amount'=>1,'points_expiration_months'=>24,'is_active'=>1,'is_visible_by_default'=>1],fn($q,$c)=>in_array('name',$c,true)?$q->where('name','like','%DEMO%'):null);
 $r['records'][]=$x;if(($x['status']==='created'||$x['status']==='existing')&&($x['id']??null))$cardIds[]=$x['id'];
}
$primaryCard=$cardIds[0]??null;

$rewardDefs=[
 ['[DEMO] 363 Foundation Supporter Welcome Reward','363 Supporter Welcome Reward','Welcome supporters with an instant benefit that demonstrates day-one engagement.'],
 ['[DEMO] 363 Foundation Community Builder Reward','363 Community Builder Reward','Reward referrals, introductions and actions that grow the 363 Foundation community.'],
 ['[DEMO] 363 Foundation Event VIP Reward','363 Event VIP Reward','VIP access, recognition or event-based perks for highly engaged supporters.'],
 ['[DEMO] 363 Foundation Milestone Reward','363 Milestone Reward','Unlock a milestone benefit when supporters reach sustained engagement goals.']
];
foreach($rewardDefs as [$name,$title,$desc]){
 $x=cloneSafe('rewards',$partnerId,['club_id'=>$clubId,'name'=>$name,'title'=>tr($title),'description'=>tr($desc),'is_active'=>1],fn($q,$c)=>in_array('name',$c,true)?$q->where('name','like','%DEMO%'):null);$r['records'][]=$x;
 if($primaryCard&&($x['id']??null)&&Schema::hasTable('card_reward')){try{DB::table('card_reward')->updateOrInsert(['card_id'=>$primaryCard,'reward_id'=>$x['id']],['created_at'=>now(),'updated_at'=>now()]);}catch(Throwable $e){}}
}

foreach([
 ['[DEMO] 363 Foundation Action Streak','363 Action Streak','Complete 6 meaningful community actions to unlock a supporter reward.'],
 ['[DEMO] 363 Foundation Live & Event Streak','363 Live + Event Streak','Attend or participate repeatedly to demonstrate retention and recurring engagement.']
] as [$name,$title,$desc])$r['records'][]=cloneSafe('stamp_cards',$partnerId,['club_id'=>$clubId,'name'=>$name,'title'=>tr($title),'description'=>tr($desc),'is_active'=>1],fn($q,$c)=>in_array('name',$c,true)?$q->where('name','like','%DEMO%'):null);

foreach([
 ['[DEMO] 363 Foundation Event Access Pass','363 Event Access Pass','Demo voucher for event access, supporter perks or campaign activations.'],
 ['[DEMO] 363 Foundation Community Thank-You','363 Community Thank-You','A limited supporter thank-you voucher for reactivation and appreciation.']
] as [$name,$title,$desc])$r['records'][]=cloneSafe('vouchers',$partnerId,['club_id'=>$clubId,'name'=>$name,'title'=>tr($title),'description'=>tr($desc),'is_active'=>1],fn($q,$c)=>in_array('name',$c,true)?$q->where('name','like','%DEMO%'):null);

foreach([
 ['tiers','[DEMO] 363 Foundation Supporter','363 Supporter','Entry supporter tier demonstrating recognition and progression.'],
 ['tiers','[DEMO] 363 Foundation Builder','363 Builder','Mid-tier recognition for consistent community builders.'],
 ['tiers','[DEMO] 363 Foundation Inner Circle','363 Inner Circle','Top demo tier for highly engaged founders and supporters.'],
 ['scratch_games','[DEMO] 363 Foundation Scratch & Win','363 Foundation Scratch & Win','Instant-win demo for livestreams, events and supporter activation.'],
 ['giveaways','[DEMO] 363 Foundation Spotlight Giveaway','363 Foundation Spotlight Giveaway','Community giveaway for participation, podcasts, events and livestreams.'],
 ['referral_programs','[DEMO] 363 Foundation Community Referral','363 Foundation Community Referral','Reward supporters for bringing aligned people into the 363 community.'],
 ['segments','[DEMO] 363 Foundation Engaged Supporters','363 Foundation Engaged Supporters','Audience segment for targeted follow-up and reactivation.'],
 ['email_campaigns','[DEMO] 363 Foundation Welcome & Reactivation','363 Foundation Welcome + Reactivation','Lifecycle campaign for supporter onboarding and re-engagement.'],
 ['review_campaigns','[DEMO] 363 Foundation Community Voice','363 Foundation Community Voice','Feedback and review campaign that turns participant sentiment into social proof.']
] as [$table,$name,$title,$desc])$r['records'][]=cloneSafe($table,$partnerId,['club_id'=>$clubId,'name'=>$name,'title'=>tr($title),'description'=>tr($desc),'is_active'=>1],fn($q,$c)=>in_array('name',$c,true)?$q->where('name','like','%DEMO%'):null);

$r['status']=count(array_filter($r['records'],fn($x)=>($x['status']??'')==='failed'))===0?'completed':'completed_with_skips_or_failures';
$r['completed_at']=now()->toIso8601String();
file_put_contents($out,json_encode($r,JSON_PRETTY_PRINT|JSON_UNESCAPED_SLASHES));
echo json_encode($r,JSON_PRETTY_PRINT|JSON_UNESCAPED_SLASHES),"\n";
