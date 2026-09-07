<?php

declare(strict_types=1);
use Illuminate\Support\Facades\DB;
$agentRoot='/home/placevle/placesrewards-agent-server';$appRoot='/home/placevle/app.placesrewards.com';$out=$agentRoot.'/results/campaigns/treasure-hunt-scratch-instances.json';
require $appRoot.'/vendor/autoload.php';$app=require $appRoot.'/bootstrap/app.php';$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();
$gameId='1fefb288-a8cc-46d4-a4a3-04fe56f91329';
$rows=DB::table('scratch_cards')->where('scratch_game_id',$gameId)->orderByDesc('created_at')->limit(25)->get()->map(fn($r)=>(array)$r)->all();
$result=['status'=>'completed','generated_at'=>now()->toIso8601String(),'game_id'=>$gameId,'count'=>count($rows),'instances'=>$rows];
@mkdir(dirname($out),0755,true);file_put_contents($out,json_encode($result,JSON_PRETTY_PRINT|JSON_UNESCAPED_SLASHES),LOCK_EX);echo json_encode(['status'=>'completed','count'=>count($rows),'output'=>$out]),"\n";
