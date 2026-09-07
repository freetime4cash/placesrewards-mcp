<?php

declare(strict_types=1);

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

$agentRoot='/home/placevle/placesrewards-agent-server';
$appRoot='/home/placevle/app.placesrewards.com';
$resultFile=$agentRoot.'/results/campaigns/northeast-ohio-tom-v2-result.json';
$outFile=$agentRoot.'/results/campaigns/northeast-ohio-legacy-retirement.json';

if(!is_file($resultFile)){
    file_put_contents($outFile,json_encode(['status'=>'skipped','reason'=>'new_demo_result_missing'],JSON_PRETTY_PRINT));
    exit(0);
}
$new=json_decode((string)file_get_contents($resultFile),true);
$seq=array_map(fn($m)=>(int)($m['sequence']??0),$new['modules']??[]);
sort($seq);
if(($new['status']??null)!=='completed' || $seq!==range(1,12)){
    file_put_contents($outFile,json_encode(['status'=>'skipped','reason'=>'new_demo_not_verified_complete','sequences'=>$seq],JSON_PRETTY_PRINT));
    exit(0);
}

require $appRoot.'/vendor/autoload.php';
$app=require $appRoot.'/bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();

$partnerId=(string)($new['partner_id']??'019dbfc9-ddf9-7136-951a-124574cf7b3e');
$changes=[];

function retireRows(string $table,string $partnerId,callable $scope,array &$changes): void{
    if(!Schema::hasTable($table)) return;
    $cols=Schema::getColumnListing($table);
    if(!in_array('is_active',$cols,true)) return;
    $q=DB::table($table);
    if(in_array('created_by',$cols,true))$q->where('created_by',$partnerId);
    elseif(in_array('partner_id',$cols,true))$q->where('partner_id',$partnerId);
    $scope($q,$cols);
    $rows=$q->get();
    foreach($rows as $row){
        $update=['is_active'=>0];
        if(in_array('is_visible_by_default',$cols,true))$update['is_visible_by_default']=0;
        if(in_array('updated_at',$cols,true))$update['updated_at']=now();
        DB::table($table)->where('id',$row->id)->update($update);
        $changes[]=['table'=>$table,'id'=>$row->id,'name'=>$row->name??null,'title'=>$row->title??null];
    }
}

retireRows('cards',$partnerId,function($q,$cols){
    $q->where(function($w)use($cols){
        $w->where('id','01a000d3-701c-728e-b0c6-7d3a5754428c');
        if(in_array('title',$cols,true)){
            foreach(['What Happens Next','Main Street Market','Falls Family Diner','Treasure Trail Coffee'] as $needle)$w->orWhere('title','like','%'.$needle.'%');
        }
        if(in_array('name',$cols,true)){
            foreach(['What Happens Next','Main Street Market','Falls Family Diner','Treasure Trail Coffee'] as $needle)$w->orWhere('name','like','%'.$needle.'%');
        }
    })->where(function($w){$w->whereNull('name')->orWhere('name','not like','[TOM DEMO %]');});
},$changes);

retireRows('stamp_cards',$partnerId,function($q,$cols){
    $q->where(function($w)use($cols){
        $w->where('id','01a000f1-4a6e-719a-a541-d9c4f2b359f3');
        if(in_array('name',$cols,true))$w->orWhere('name','like','%10-Visit Local Regular%');
        if(in_array('title',$cols,true))$w->orWhere('title','like','%10-Visit Local Regular%');
    })->where(function($w){$w->whereNull('name')->orWhere('name','not like','[TOM DEMO %]');});
},$changes);

retireRows('vouchers',$partnerId,function($q,$cols){
    $q->where('id','01a000f1-4dc8-71b9-8e9c-0efb561d6e80')
      ->where(function($w){$w->whereNull('name')->orWhere('name','not like','[TOM DEMO %]');});
},$changes);

file_put_contents($outFile,json_encode([
    'status'=>'completed',
    'new_demo_verified'=>true,
    'retired_count'=>count($changes),
    'retired'=>$changes,
    'completed_at'=>now()->toIso8601String(),
],JSON_PRETTY_PRINT|JSON_UNESCAPED_SLASHES),LOCK_EX);

echo json_encode(['status'=>'completed','retired_count'=>count($changes)],JSON_UNESCAPED_SLASHES),"\n";
