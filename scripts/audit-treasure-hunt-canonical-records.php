<?php

declare(strict_types=1);

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

$agentRoot='/home/placevle/placesrewards-agent-server';
$appRoot='/home/placevle/app.placesrewards.com';
$out=$agentRoot.'/results/campaigns/treasure-hunt-canonical-record-audit.json';

require $appRoot.'/vendor/autoload.php';
$app=require $appRoot.'/bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();

$result=['status'=>'completed','generated_at'=>now()->toIso8601String(),'tables'=>[]];
$tables=['cards','stamp_cards','rewards','tiers','scratch_games','vouchers','email_campaigns'];
foreach($tables as $table){
    if(!Schema::hasTable($table)){ $result['tables'][$table]=['status'=>'missing']; continue; }
    $columns=Schema::getColumnListing($table);
    $query=DB::table($table);
    $searchCols=array_values(array_intersect(['name','title','subject','description','display_name'],$columns));
    if(!$searchCols){ $result['tables'][$table]=['status'=>'no_search_columns']; continue; }
    $query->where(function($q) use($searchCols){
        foreach($searchCols as $i=>$col){
            if($i===0) $q->where($col,'like','%TOM DEMO%');
            else $q->orWhere($col,'like','%TOM DEMO%');
        }
    });
    $rows=$query->get();
    $keep=array_values(array_intersect([
        'id','club_id','partner_id','name','title','subject','description','display_name','code','type','amount','value','discount_amount','discount_value','fixed_amount','percentage','is_active','is_visible_by_default','deleted_at','created_at','updated_at'
    ],$columns));
    $clean=[];
    foreach($rows as $row){
        $arr=(array)$row; $item=[]; foreach($keep as $col) $item[$col]=$arr[$col]??null; $clean[]=$item;
    }
    $result['tables'][$table]=['status'=>'ok','columns'=>$columns,'tom_demo_count'=>count($clean),'rows'=>$clean];
}
@mkdir(dirname($out),0755,true);
file_put_contents($out,json_encode($result,JSON_PRETTY_PRINT|JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE),LOCK_EX);
echo json_encode(['status'=>'completed','output'=>$out]),"\n";
