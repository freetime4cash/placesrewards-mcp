<?php

declare(strict_types=1);

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

$agentRoot='/home/placevle/placesrewards-agent-server';
$appRoot='/home/placevle/app.placesrewards.com';
$out=$agentRoot.'/results/campaigns/treasure-hunt-native-card-content.json';

require $appRoot.'/vendor/autoload.php';
$app=require $appRoot.'/bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();

function tr(string $value): string { return json_encode(['en'=>$value], JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE); }
function patchRecord(string $table, string $id, array $values): array {
    if (!Schema::hasTable($table)) return ['table'=>$table,'id'=>$id,'status'=>'missing_table'];
    $row=DB::table($table)->where('id',$id)->first();
    if (!$row) return ['table'=>$table,'id'=>$id,'status'=>'missing_record'];
    $update=[];
    foreach($values as $column=>$value) if(Schema::hasColumn($table,$column)) $update[$column]=$value;
    if(Schema::hasColumn($table,'updated_at')) $update['updated_at']=now();
    DB::table($table)->where('id',$id)->update($update);
    return ['table'=>$table,'id'=>$id,'status'=>'updated','columns'=>array_keys($update)];
}

$result=['status'=>'running','updated_at'=>now()->toIso8601String(),'records'=>[]];

$result['records'][]=patchRecord('cards','95cbd0bf-8bbb-436d-b7c6-a2e1e558db25',[
    'head'=>tr('01 • HUNTER PASSPORT — Join once. Keep earning through the entire Hunt.'),
    'title'=>tr('01 — Northeast Ohio Treasure Hunt Hunter Passport'),
    'description'=>tr('WELCOME TO THE HUNT. Join once and use this Hunter Passport across participating-business visits, rewards and referrals. Demo benefit: 100 welcome points. Your Passport is the customer relationship that continues after the treasure is found.'),
]);

$result['records'][]=patchRecord('stamp_cards','a9566430-b6b0-434d-ae3c-f3ba85421c5f',[
    'title'=>tr('02 — 5-Stop Northeast Ohio Explorer Trail'),
    'description'=>tr('VISIT 5 PARTICIPATING BUSINESSES. Earn one verified stamp at each stop. Complete all five stops to unlock the Explorer Trail reward. The purpose of this card is to move Treasure Hunt traffic across the participating merchant network.'),
    'reward_title'=>tr('Explorer Trail Completion Reward'),
    'reward_description'=>tr('Complete all five participating-business stops and unlock a local reward supplied by the campaign or participating merchant.'),
]);

$result['records'][]=patchRecord('rewards','29304849-3c10-4a06-8f8f-4bad776b79f9',[
    'title'=>tr('04 — Clue Activity Bonus'),
    'description'=>tr('CLUE ACTIVITY BONUS: +250 demo points after an approved Hunt activity or verified participating-business visit. This reward never reveals an answer, changes an official clue or affects the odds of finding the treasure.'),
]);

$result['records'][]=patchRecord('stamp_cards','5738988e-265f-422f-9b41-5828790af3c0',[
    'title'=>tr('05 — Merchant Check-In Verification'),
    'description'=>tr('PROVE THE FOOT TRAFFIC. Staff or QR verification confirms the hunter physically reached the participating business. The verified visit can issue the appropriate stamp or points and creates merchant-level campaign attribution.'),
    'reward_title'=>tr('Verified Treasure Hunt Business Visit'),
    'reward_description'=>tr('Visit confirmed. This check-in demonstrates how Places Rewards turns Hunt traffic into measurable merchant visits.'),
]);

$result['records'][]=patchRecord('rewards','13085fc2-2a5d-43ee-92b5-441bc368c55b',[
    'title'=>tr('06 — Local Business Bonus Prize'),
    'description'=>tr('LOCAL BUSINESS BONUS PRIZE. A participating merchant can supply a gift card, product, service or experience and tie eligibility to a verified visit, Explorer Trail milestone or other approved action. This local prize is separate from the grand treasure.'),
]);

$result['records'][]=patchRecord('tiers','8744df20-c34b-484c-9f8f-15140f8fc542',[
    'display_name'=>tr('08 — Hunter VIP'),
    'description'=>tr('HUNTER VIP rewards the people who support participating businesses most. Demo qualification: 2,500 points. Benefits can include early Hunt previews, premium merchant perks, special local experiences and priority invitations for the next Hunt.'),
    'benefits'=>json_encode(['Early Hunt previews','Premium participating-business perks','VIP local experiences','Priority invitations for the next Hunt'],JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE),
]);

$result['records'][]=patchRecord('scratch_games','1fefb288-a8cc-46d4-a4a3-04fe56f91329',[
    'name'=>'09 — Mystery Bonus Scratch & Win',
    'description'=>'MYSTERY BONUS: a qualifying participating-business visit can unlock one digital scratch opportunity. The prize pool can contain merchant perks, bonus points or discounts according to the configured win rate and inventory. This never affects the real treasure or Hunt odds.',
]);

$result['records'][]=patchRecord('vouchers','14788f52-438e-4293-bd6b-c82b8e448983',[
    'title'=>tr('10 — Hunter Comeback: $5 Off $25'),
    'description'=>tr('TURN THE FIRST VISIT INTO THE SECOND. This demo comeback voucher gives a hunter $5 off a $25 return purchase after the initial Treasure Hunt visit. Each participating business can set its own offer and minimum spend.'),
]);

$result['records'][]=patchRecord('email_campaigns','b1ea6974-d61c-41d4-a1ce-0c0a27ffa5bd',[
    'subject'=>tr('11 — Keep your Northeast Ohio Treasure Hunt rewards moving'),
    'body'=>tr('Thanks for exploring Northeast Ohio. You still have value waiting in Places Rewards: unfinished Explorer Trail progress, participating-business rewards, comeback offers and Hunter VIP progress. This demo campaign shows how different hunters can receive relevant post-Hunt follow-up instead of one generic message. This record remains a draft and is not automatically sent.'),
]);

$result['status']='completed';
$result['completed_at']=now()->toIso8601String();
@mkdir(dirname($out),0755,true);
file_put_contents($out,json_encode($result,JSON_PRETTY_PRINT|JSON_UNESCAPED_SLASHES),LOCK_EX);
echo json_encode(['status'=>'completed','record_count'=>count($result['records'])]),"\n";
