<?php

declare(strict_types=1);
use Illuminate\Support\Facades\DB;
$agentRoot='/home/placevle/placesrewards-agent-server';$appRoot='/home/placevle/app.placesrewards.com';$out=$agentRoot.'/results/campaigns/treasure-hunt-scratch-schema-compact.json';
require $appRoot.'/vendor/autoload.php';$app=require $appRoot.'/bootstrap/app.php';$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();
$col=function(string $table){return array_map(fn($x)=>(array)$x,DB::select('SHOW COLUMNS FROM `'.$table.'`'));};
$tables=DB::select("SELECT TABLE_NAME FROM information_schema.TABLES WHERE TABLE_SCHEMA=DATABASE() AND (TABLE_NAME LIKE '%member%' OR TABLE_NAME LIKE '%club%' OR TABLE_NAME LIKE '%scratch%') ORDER BY TABLE_NAME");
$interesting=[];foreach($tables as $t){$name=$t->TABLE_NAME;$interesting[$name]=array_column($col($name),'Field');}
$game=DB::table('scratch_games')->where('id','1fefb288-a8cc-46d4-a4a3-04fe56f91329')->first();
$result=['status'=>'completed','generated_at'=>now()->toIso8601String(),'scratch_cards_columns'=>$col('scratch_cards'),'members_columns'=>$col('members'),'related_tables'=>$interesting,'game'=>$game?(array)$game:null];
@mkdir(dirname($out),0755,true);file_put_contents($out,json_encode($result,JSON_PRETTY_PRINT|JSON_UNESCAPED_SLASHES),LOCK_EX);echo json_encode(['status'=>'completed','output'=>$out]),"\n";
