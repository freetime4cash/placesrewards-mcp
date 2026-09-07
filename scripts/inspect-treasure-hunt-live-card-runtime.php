<?php

declare(strict_types=1);

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

$agentRoot='/home/placevle/placesrewards-agent-server';
$appRoot='/home/placevle/app.placesrewards.com';
$out=$agentRoot.'/results/campaigns/treasure-hunt-live-card-runtime.json';

require $appRoot.'/vendor/autoload.php';
$app=require $appRoot.'/bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();

$result=['status'=>'running','generated_at'=>now()->toIso8601String()];
try {
    $cardId='95cbd0bf-8bbb-436d-b7c6-a2e1e558db25';
    $card=\App\Models\Card::query()->find($cardId);
    $result['card']=[
        'id'=>$card?->id,
        'name'=>$card?->name,
        'head'=>$card?->head,
        'title'=>$card?->title,
        'description'=>$card?->description,
        'logo'=>$card?->getImageUrl('logo','md'),
        'background'=>$card?->getImageUrl('background','md'),
    ];
    if($card){
        $component=new \App\View\Components\Member\Card(card:$card);
        $result['component']=[
            'class'=>get_class($component),
            'contentHead'=>$component->contentHead ?? null,
            'contentTitle'=>$component->contentTitle ?? null,
            'contentDescription'=>$component->contentDescription ?? null,
            'logo'=>$component->logo ?? null,
            'bgImage'=>$component->bgImage ?? null,
        ];
    }

    $premium=$appRoot.'/resources/views/components/member/premium-card.blade.php';
    $cardClass=$appRoot.'/app/View/Components/Member/Card.php';
    $result['premium_card_template']=is_file($premium)?file_get_contents($premium):null;
    $result['card_component_class']=is_file($cardClass)?file_get_contents($cardClass):null;

    foreach(['vouchers','stamp_cards','scratch_games','rewards','tiers','email_campaigns','cards'] as $table){
        if(!Schema::hasTable($table)) continue;
        $result['schemas'][$table]=array_map(fn($c)=>$c->Field ?? $c->field ?? null, DB::select('SHOW COLUMNS FROM `'.$table.'`'));
    }

    if(Schema::hasTable('vouchers')){
        $result['treasure_vouchers']=DB::table('vouchers')
            ->where(function($q){$q->where('name','like','%TOM DEMO%')->orWhere('title','like','%Hunter Comeback%');})
            ->orderBy('created_at')
            ->get()->map(fn($r)=>(array)$r)->all();
    }
    foreach([
        'cards'=>['95cbd0bf-8bbb-436d-b7c6-a2e1e558db25'],
        'stamp_cards'=>['a9566430-b6b0-434d-ae3c-f3ba85421c5f','5738988e-265f-422f-9b41-5828790af3c0'],
        'scratch_games'=>['1fefb288-a8cc-46d4-a4a3-04fe56f91329'],
        'vouchers'=>['14788f52-438e-4293-bd6b-c82b8e448983'],
        'rewards'=>['29304849-3c10-4a06-8f8f-4bad776b79f9','13085fc2-2a5d-43ee-92b5-441bc368c55b'],
        'tiers'=>['8744df20-c34b-484c-9f8f-15140f8fc542'],
        'email_campaigns'=>['b1ea6974-d61c-41d4-a1ce-0c0a27ffa5bd'],
    ] as $table=>$ids){
        if(Schema::hasTable($table)) $result['canonical'][$table]=DB::table($table)->whereIn('id',$ids)->get()->map(fn($r)=>(array)$r)->all();
    }
    $result['status']='completed';
} catch(Throwable $e){
    $result['status']='failed';
    $result['error']=$e->getMessage();
    $result['trace']=substr($e->getTraceAsString(),0,8000);
}
$result['completed_at']=now()->toIso8601String();
@mkdir(dirname($out),0755,true);
file_put_contents($out,json_encode($result,JSON_PRETTY_PRINT|JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE),LOCK_EX);
echo json_encode(['status'=>$result['status']]),"\n";
exit($result['status']==='failed'?1:0);
