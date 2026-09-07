<?php

declare(strict_types=1);

$sourcePath = __DIR__.'/deploy-northeast-ohio-tom-v2.php';
$source = (string) file_get_contents($sourcePath);

// Legacy v2 used TOMHUNTRETURN on the sequence-09 voucher. The corrected
// sequence-10 comeback offer gets its own code so the new demo can be created
// without colliding with the inactive legacy demo record.
$source = str_replace("'code'=>'TOMHUNTRETURN'", "'code'=>'TOMHUNTRETURN10'", $source);

$tmp = sys_get_temp_dir().'/placesrewards-tom-v2-fixed.php';
file_put_contents($tmp, $source, LOCK_EX);
$command = escapeshellarg(PHP_BINARY).' '.escapeshellarg($tmp);
passthru($command, $exitCode);
exit($exitCode);
