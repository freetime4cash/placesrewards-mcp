<?php

declare(strict_types=1);

$agentRoot='/home/placevle/placesrewards-agent-server';
$appRoot='/home/placevle/app.placesrewards.com';
$target=$appRoot.'/app/Http/Middleware/SetLocale.php';
$out=$agentRoot.'/results/campaigns/demo-locale-bypass.json';
$result=['status'=>'running','target'=>$target,'changed'=>false,'verified'=>false,'updated_at'=>gmdate('c')];

if(!is_file($target)){
    $result['status']='failed';$result['error']='SetLocale.php not found';
}else{
    $source=(string)file_get_contents($target);
    $backupDir=$agentRoot.'/data/backups';
    @mkdir($backupDir,0755,true);
    if(preg_match('/protected\s+array\s+\$bypassPatterns\s*=\s*\[(.*?)\];/s',$source,$m)){
        $block=$m[0];
        if(!preg_match("/'demo'\\s*,?/",$block)){
            @copy($target,$backupDir.'/SetLocale.php.before-demo-bypass-'.date('Ymd-His'));
            if(str_contains($block,"'shopify'")){
                $newBlock=str_replace("'shopify'", "'demo',     // /demo/* - Locale-agnostic public demo modules\n        'shopify'", $block);
            }elseif(str_contains($block,"'r'")){
                $newBlock=str_replace("'r',", "'r',\n        'demo',     // /demo/* - Locale-agnostic public demo modules", $block);
            }else{
                $newBlock=preg_replace('/\];$/',"    'demo',     // /demo/* - Locale-agnostic public demo modules\n    ];",$block);
            }
            $source=str_replace($block,$newBlock,$source);
            file_put_contents($target,$source,LOCK_EX);
            $result['changed']=true;
        }
        $after=(string)file_get_contents($target);
        $result['verified']=preg_match('/protected\s+array\s+\$bypassPatterns\s*=\s*\[(?:(?!\];).)*[\'\"]demo[\'\"]/s',$after)===1;
        $result['status']=$result['verified']?'completed':'failed';
    }else{
        $result['status']='failed';$result['error']='bypassPatterns block not found';
    }
}
@mkdir(dirname($out),0755,true);
file_put_contents($out,json_encode($result,JSON_PRETTY_PRINT|JSON_UNESCAPED_SLASHES),LOCK_EX);
echo json_encode(['status'=>$result['status'],'changed'=>$result['changed'],'verified'=>$result['verified']]),"\n";
exit($result['status']==='completed'?0:1);
