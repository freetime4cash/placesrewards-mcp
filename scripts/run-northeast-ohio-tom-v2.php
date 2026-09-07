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

// Only retire the old public Northeast Ohio demo cards after the new 01–12
// result has completed successfully. The retirement script is reversible: it
// deactivates/hides legacy demo records and never deletes them.
if ($exitCode === 0) {
    $cleanup = __DIR__.'/retire-northeast-ohio-legacy-demo.php';
    if (is_file($cleanup)) {
        $cleanupCommand = escapeshellarg(PHP_BINARY).' '.escapeshellarg($cleanup);
        passthru($cleanupCommand, $cleanupExit);
        if ($cleanupExit !== 0) $exitCode = $cleanupExit;
    }
}

exit($exitCode);
