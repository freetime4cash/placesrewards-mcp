<?php

declare(strict_types=1);

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

$agentRoot='/home/placevle/placesrewards-agent-server';
$appRoot='/home/placevle/app.placesrewards.com';
$out=$agentRoot.'/results/campaigns/treasure-hunt-scratch-images.json';
$gameId='1fefb288-a8cc-46d4-a4a3-04fe56f91329';

require $appRoot.'/vendor/autoload.php';
$app=require $appRoot.'/bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();

$result=['status'=>'running','game_id'=>$gameId,'started_at'=>now()->toIso8601String(),'files'=>[]];
try {
    if(!Schema::hasTable('scratch_games')) throw new RuntimeException('scratch_games table not found');
    foreach(['cover_image','win_image','loss_image'] as $column) {
        if(!Schema::hasColumn('scratch_games',$column)) throw new RuntimeException("scratch_games.$column not found");
    }
    $game=DB::table('scratch_games')->where('id',$gameId)->first();
    if(!$game) throw new RuntimeException('Treasure Hunt scratch game not found');

    $srcDir=$agentRoot.'/assets/treasure-hunt-v4/scratch';
    $destDir=$appRoot.'/public/files/demo/treasure-hunt/scratch';
    @mkdir($destDir,0755,true);
    $map=['cover'=>'cover.webp','winner'=>'winner.webp','loser'=>'loser.webp'];
    foreach($map as $key=>$file){
        $src=$srcDir.'/'.$file;
        $dst=$destDir.'/'.$file;
        if(!is_file($src)) throw new RuntimeException("Missing source asset: $src");
        if(!copy($src,$dst)) throw new RuntimeException("Could not copy $file to public files");
        @chmod($dst,0644);
        $result['files'][$key]=['source'=>$src,'destination'=>$dst,'bytes'=>filesize($dst),'sha1'=>sha1_file($dst)];
    }

    $sample=DB::table('scratch_games')
        ->where('id','!=',$gameId)
        ->where(function($q){$q->whereNotNull('cover_image')->orWhereNotNull('win_image')->orWhereNotNull('loss_image');})
        ->first();
    $sampleValue=(string)($game->cover_image ?: $game->win_image ?: $game->loss_image ?: ($sample->cover_image ?? $sample->win_image ?? $sample->loss_image ?? ''));
    $format=function(string $file) use ($sampleValue): string {
        $relative='files/demo/treasure-hunt/scratch/'.$file;
        if(str_starts_with($sampleValue,'http://') || str_starts_with($sampleValue,'https://')) return 'https://app.placesrewards.com/'.$relative;
        if(str_starts_with($sampleValue,'/')) return '/'.$relative;
        return $relative;
    };
    $values=[
        'cover_image'=>$format('cover.webp'),
        'win_image'=>$format('winner.webp'),
        'loss_image'=>$format('loser.webp'),
        'updated_at'=>now(),
    ];
    DB::table('scratch_games')->where('id',$gameId)->update($values);
    $after=DB::table('scratch_games')->where('id',$gameId)->first();

    $result['database']=[
        'before'=>['cover_image'=>$game->cover_image,'win_image'=>$game->win_image,'loss_image'=>$game->loss_image],
        'after'=>['cover_image'=>$after->cover_image,'win_image'=>$after->win_image,'loss_image'=>$after->loss_image],
    ];
    $result['public_urls']=[
        'cover'=>'https://app.placesrewards.com/files/demo/treasure-hunt/scratch/cover.webp',
        'winner'=>'https://app.placesrewards.com/files/demo/treasure-hunt/scratch/winner.webp',
        'loser'=>'https://app.placesrewards.com/files/demo/treasure-hunt/scratch/loser.webp',
    ];
    $result['verified']=is_file($destDir.'/cover.webp') && is_file($destDir.'/winner.webp') && is_file($destDir.'/loser.webp')
        && !empty($after->cover_image) && !empty($after->win_image) && !empty($after->loss_image);
    $result['status']=$result['verified']?'completed':'failed';
} catch(Throwable $e) {
    $result['status']='failed';
    $result['error']=$e->getMessage();
}
$result['completed_at']=now()->toIso8601String();
@mkdir(dirname($out),0755,true);
file_put_contents($out,json_encode($result,JSON_PRETTY_PRINT|JSON_UNESCAPED_SLASHES),LOCK_EX);
echo json_encode(['status'=>$result['status'],'verified'=>$result['verified']??false]),"\n";
exit($result['status']==='completed'?0:1);
