<?php

declare(strict_types=1);

$sourcePath = __DIR__.'/deploy-northeast-ohio-tom-v2.php';
$source = (string) file_get_contents($sourcePath);

// Legacy v2 used TOMHUNTRETURN on the sequence-09 voucher. The corrected
// sequence-10 comeback offer gets its own code so the new demo can be created
// without colliding with the inactive legacy demo record.
$source = str_replace("'code'=>'TOMHUNTRETURN'", "'code'=>'TOMHUNTRETURN10'", $source);

// Push each module's unique card copy into the underlying Places Rewards
// record too, so native card/module views do not fall back to generic text.
$source = str_replace(
    "tomTr(\$m['description'])",
    "tomTr(\$m['description'].' Demo card content: '.(\$m['example_content']??''))",
    $source
);
$source = str_replace(
    "'description'=>\$m['description']",
    "'description'=>\$m['description'].' Demo card content: '.(\$m['example_content']??'')",
    $source
);

$tmp = sys_get_temp_dir().'/placesrewards-tom-v2-fixed.php';
file_put_contents($tmp, $source, LOCK_EX);
$command = escapeshellarg(PHP_BINARY).' '.escapeshellarg($tmp);
passthru($command, $exitCode);

if ($exitCode === 0) {
    $agentRoot = '/home/placevle/placesrewards-agent-server';
    $appRoot = '/home/placevle/app.placesrewards.com';
    $moduleViewSource = $agentRoot.'/assets/northeast-ohio-tom/module.blade.php';
    $moduleViewTarget = $appRoot.'/resources/views/demo/northeast-ohio-tom-module.blade.php';
    if (is_file($moduleViewSource)) {
        @mkdir(dirname($moduleViewTarget), 0755, true);
        copy($moduleViewSource, $moduleViewTarget);
    }

    foreach (['northeast-ohio-tom-v2-result.json','northeast-ohio-tom-v2-links.json'] as $resultFile) {
        $path = $agentRoot.'/results/campaigns/'.$resultFile;
        if (!is_file($path)) continue;
        $payload = json_decode((string) file_get_contents($path), true);
        if (!is_array($payload)) continue;
        $payload['workflow_version'] = 4;
        foreach (($payload['modules'] ?? []) as $i => $module) {
            $sequence = (int)($module['sequence'] ?? 0);
            if ($sequence < 1 || $sequence > 12) continue;
            $payload['modules'][$i]['module_url'] = 'https://app.placesrewards.com/demo/northeast-ohio-treasure-hunt/tom/module/'.str_pad((string)$sequence, 2, '0', STR_PAD_LEFT);
        }
        file_put_contents($path, json_encode($payload, JSON_PRETTY_PRINT|JSON_UNESCAPED_SLASHES), LOCK_EX);
    }

    $verify = __DIR__.'/verify-northeast-ohio-module-content.php';
    if (is_file($verify)) {
        $verifyCommand = escapeshellarg(PHP_BINARY).' '.escapeshellarg($verify);
        passthru($verifyCommand, $verifyExit);
        if ($verifyExit !== 0) $exitCode = $verifyExit;
    }
}

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
