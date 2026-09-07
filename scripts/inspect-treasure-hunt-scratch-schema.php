<?php

declare(strict_types=1);
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
$agentRoot='/home/placevle/placesrewards-agent-server';$appRoot='/home/placevle/app.placesrewards.com';$out=$agentRoot.'/results/campaigns/treasure-hunt-scratch-schema.json';
require $appRoot.'/vendor/autoload.php';$app=require $appRoot.'/bootstrap/app.php';$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();
$columns=[];foreach(DB::select('SHOW COLUMNS FROM scratch_cards') as $c)$columns[]=(array)$c;
$controller=$appRoot.'/app/Http/Controllers/Member/ScratchCardController.php';$model=$appRoot.'/app/Models/ScratchCard.php';
$game=DB::table('scratch_games')->where('id','1fefb288-a8cc-46d4-a4a3-04fe56f91329')->first();
$members=DB::table('members')->where('partner_id',$game->partner_id??'')->orderByDesc('created_at')->limit(10)->get()->map(fn($x)=>(array)$x)->all();
$result=['status'=>'completed','generated_at'=>now()->toIso8601String(),'columns'=>$columns,'controller'=>is_file($controller)?file_get_contents($controller):null,'model'=>is_file($model)?file_get_contents($model):null,'game'=>$game?(array)$game:null,'candidate_members'=>$members];
@mkdir(dirname($out),0755,true);file_put_contents($out,json_encode($result,JSON_PRETTY_PRINT|JSON_UNESCAPED_SLASHES),LOCK_EX);echo json_encode(['status'=>'completed','columns'=>count($columns),'members'=>count($members),'output'=>$out]),"\n";
