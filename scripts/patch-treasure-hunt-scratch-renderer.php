<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Artisan;

$agentRoot='/home/placevle/placesrewards-agent-server';
$appRoot='/home/placevle/app.placesrewards.com';
$out=$agentRoot.'/results/campaigns/treasure-hunt-scratch-renderer.json';
$view=$appRoot.'/resources/views/member/scratch-cards/show.blade.php';

$result=['status'=>'running','started_at'=>now()->toIso8601String(),'view'=>$view,'changes'=>[]];
try {
    if(!is_file($view)) throw new RuntimeException('Native scratch-card view not found');
    $before=(string)file_get_contents($view);
    $content=$before;

    $backupDir=$agentRoot.'/data/backups/treasure-hunt-scratch-renderer';
    @mkdir($backupDir,0755,true);
    $backup=$backupDir.'/show.blade.'.date('Ymd-His').'.php';
    @copy($view,$backup);
    $result['backup']=$backup;

    if(!str_contains($content,'coverImage:')){
        $needle="            csrfToken: '{{ csrf_token() }}',\n";
        $insert=$needle."            coverImage: '{{ \$scratchCard->scratchGame->cover_image ? asset('storage/' . \$scratchCard->scratchGame->cover_image) : '' }}',\n";
        if(!str_contains($content,$needle)) throw new RuntimeException('Could not locate scratch config csrfToken line');
        $content=str_replace($needle,$insert,$content);
        $result['changes'][]='added_cover_image_to_blade_config';
    }

    if(!str_contains($content,'coverImage: config.coverImage')){
        $needle="        csrfToken: config.csrfToken,\n";
        $insert=$needle."        coverImage: config.coverImage,\n";
        if(!str_contains($content,$needle)) throw new RuntimeException('Could not locate Alpine csrfToken property');
        $content=str_replace($needle,$insert,$content);
        $result['changes'][]='added_cover_image_to_alpine_state';
    }

    if(!str_contains($content,'TREASURE_HUNT_COVER_RENDERER')){
        $startMarker='            // Draw scratch surface';
        $endMarker='            let isScratching = false;';
        $start=strpos($content,$startMarker);
        $end=$start===false?false:strpos($content,$endMarker,$start);
        if($start===false || $end===false || $end<=$start) throw new RuntimeException('Could not locate native scratch surface boundaries');

        $replacement=<<<'JS'
            // TREASURE_HUNT_COVER_RENDERER
            const drawFallbackSurface = () => {
                ctx.globalCompositeOperation = 'source-over';
                ctx.globalAlpha = 1;
                ctx.fillStyle = '#C0C0C0';
                ctx.fillRect(0, 0, canvas.width, canvas.height);
                ctx.fillStyle = '#A0A0A0';
                ctx.font = 'bold 16px sans-serif';
                ctx.textAlign = 'center';
                for (let y = 30; y < canvas.height; y += 40) {
                    for (let x = 60; x < canvas.width; x += 120) {
                        ctx.fillText('SCRATCH', x, y);
                    }
                }
            };

            drawFallbackSurface();
            if (this.coverImage) {
                const cover = new Image();
                cover.crossOrigin = 'anonymous';
                cover.onload = () => {
                    if (this.scratchProgress > 0 || this.isPlayed) return;
                    ctx.globalCompositeOperation = 'source-over';
                    ctx.globalAlpha = 1;
                    ctx.clearRect(0, 0, canvas.width, canvas.height);
                    const imageRatio = cover.width / cover.height;
                    const canvasRatio = canvas.width / canvas.height;
                    let sx = 0, sy = 0, sw = cover.width, sh = cover.height;
                    if (imageRatio > canvasRatio) {
                        sw = cover.height * canvasRatio;
                        sx = (cover.width - sw) / 2;
                    } else {
                        sh = cover.width / canvasRatio;
                        sy = (cover.height - sh) / 2;
                    }
                    ctx.drawImage(cover, sx, sy, sw, sh, 0, 0, canvas.width, canvas.height);
                };
                cover.onerror = drawFallbackSurface;
                cover.src = this.coverImage;
            }

JS;
        $content=substr($content,0,$start).$replacement.substr($content,$end);
        $result['changes'][]='render_cover_image_on_scratch_canvas';
    }

    if($content!==$before){
        if(file_put_contents($view,$content,LOCK_EX)===false) throw new RuntimeException('Could not write patched scratch view');
    }

    try{Artisan::call('view:clear');}catch(Throwable $e){}
    try{Artisan::call('cache:clear');}catch(Throwable $e){}

    $after=(string)file_get_contents($view);
    $result['checks']=[
        'cover_config'=>str_contains($after,'coverImage:'),
        'cover_state'=>str_contains($after,'coverImage: config.coverImage'),
        'cover_renderer'=>str_contains($after,'TREASURE_HUNT_COVER_RENDERER'),
        'native_storage_helper'=>str_contains($after,"asset('storage/' . \$scratchCard->scratchGame->cover_image)"),
        'scratch_events_preserved'=>str_contains($after,'let isScratching = false;'),
    ];
    $result['verified']=!in_array(false,$result['checks'],true);
    $result['status']=$result['verified']?'completed':'failed';
} catch(Throwable $e) {
    $result['status']='failed';$result['verified']=false;$result['error']=$e->getMessage();
}
$result['completed_at']=now()->toIso8601String();
@mkdir(dirname($out),0755,true);file_put_contents($out,json_encode($result,JSON_PRETTY_PRINT|JSON_UNESCAPED_SLASHES),LOCK_EX);echo json_encode(['status'=>$result['status'],'verified'=>$result['verified']??false,'changes'=>$result['changes'],'error'=>$result['error']??null]),"\n";exit($result['status']==='completed'?0:1);
