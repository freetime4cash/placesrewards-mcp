<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Artisan;
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

    // Native scratch views/controllers call asset('storage/' . image_value), so the
    // DB must contain a path relative to storage/app/public — never /files/... .
    $srcDir=$agentRoot.'/assets/treasure-hunt-v4/scratch';
    $destDir=$appRoot.'/storage/app/public/treasure-hunt/scratch';
    @mkdir($destDir,0755,true);

    $map=['cover'=>'cover.webp','winner'=>'winner.webp','loser'=>'loser.webp'];
    foreach($map as $key=>$file){
        $src=$srcDir.'/'.$file;
        $dst=$destDir.'/'.$file;
        if(!is_file($src)) throw new RuntimeException("Missing source asset: $src");
        if(!copy($src,$dst)) throw new RuntimeException("Could not copy $file to Laravel public storage");
        @chmod($dst,0644);
        $info=@getimagesize($dst);
        if(!$info || ($info['mime']??'')!=='image/webp') throw new RuntimeException("Invalid WebP asset: $file");
        $result['files'][$key]=[
            'source'=>$src,'destination'=>$dst,'bytes'=>filesize($dst),'sha1'=>sha1_file($dst),
            'width'=>$info[0]??null,'height'=>$info[1]??null,'mime'=>$info['mime']??null,
        ];
    }

    // Ensure Laravel's /public/storage symlink exists.
    $publicStorage=$appRoot.'/public/storage';
    $storageTarget=$appRoot.'/storage/app/public';
    if(!is_link($publicStorage) && !is_dir($publicStorage)){
        try { Artisan::call('storage:link'); } catch(Throwable $e) {}
    }
    if(!is_link($publicStorage) && !is_dir($publicStorage)){
        @symlink($storageTarget,$publicStorage);
    }
    if(!is_link($publicStorage) && !is_dir($publicStorage)) throw new RuntimeException('Laravel public/storage link is unavailable');

    $values=[
        'cover_image'=>'treasure-hunt/scratch/cover.webp',
        'win_image'=>'treasure-hunt/scratch/winner.webp',
        'loss_image'=>'treasure-hunt/scratch/loser.webp',
        'updated_at'=>now(),
    ];
    DB::table('scratch_games')->where('id',$gameId)->update($values);
    $after=DB::table('scratch_games')->where('id',$gameId)->first();

    $result['database']=[
        'before'=>['cover_image'=>$game->cover_image,'win_image'=>$game->win_image,'loss_image'=>$game->loss_image],
        'after'=>['cover_image'=>$after->cover_image,'win_image'=>$after->win_image,'loss_image'=>$after->loss_image],
    ];
    $result['public_urls']=[
        'cover'=>'https://app.placesrewards.com/storage/treasure-hunt/scratch/cover.webp',
        'winner'=>'https://app.placesrewards.com/storage/treasure-hunt/scratch/winner.webp',
        'loser'=>'https://app.placesrewards.com/storage/treasure-hunt/scratch/loser.webp',
    ];
    $result['storage_link']=['path'=>$publicStorage,'is_link'=>is_link($publicStorage),'is_dir'=>is_dir($publicStorage)];
    $result['verified']=
        is_file($destDir.'/cover.webp') && is_file($destDir.'/winner.webp') && is_file($destDir.'/loser.webp') &&
        $after->cover_image==='treasure-hunt/scratch/cover.webp' &&
        $after->win_image==='treasure-hunt/scratch/winner.webp' &&
        $after->loss_image==='treasure-hunt/scratch/loser.webp';
    $result['status']=$result['verified']?'completed':'failed';
} catch(Throwable $e) {
    $result['status']='failed';
    $result['verified']=false;
    $result['error']=$e->getMessage();
}
$result['completed_at']=now()->toIso8601String();
@mkdir(dirname($out),0755,true);
file_put_contents($out,json_encode($result,JSON_PRETTY_PRINT|JSON_UNESCAPED_SLASHES),LOCK_EX);
echo json_encode(['status'=>$result['status'],'verified'=>$result['verified']??false,'database'=>$result['database']['after']??null]),"\n";
exit($result['status']==='completed'?0:1);
