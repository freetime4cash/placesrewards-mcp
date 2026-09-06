<?php

declare(strict_types=1);

$sourcePath = __DIR__.'/deploy-northeast-ohio-tom-v2.php';
$source = (string) file_get_contents($sourcePath);

$badTier = <<<'PHP'
'display_name'=>'Hunter VIP','description'=>$m['description']
PHP;
$goodTier = <<<'PHP'
'display_name'=>tomTr('Hunter VIP'),'description'=>tomTr($m['description'])
PHP;
$source = str_replace($badTier, $goodTier, $source);

$tmp = sys_get_temp_dir().'/placesrewards-tom-v2-fixed.php';
file_put_contents($tmp, $source, LOCK_EX);
$command = escapeshellarg(PHP_BINARY).' '.escapeshellarg($tmp);
passthru($command, $exitCode);
exit($exitCode);
