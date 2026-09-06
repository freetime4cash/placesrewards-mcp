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

$badSubject = <<<'PHP'
'subject'=>'[TOM DEMO 11] Your Hunt rewards and trail progress are still waiting'
PHP;
$goodSubject = <<<'PHP'
'subject'=>tomTr('[TOM DEMO 11] Your Hunt rewards and trail progress are still waiting')
PHP;
$source = str_replace($badSubject, $goodSubject, $source);

$badBody = <<<'PHP'
'body'=>'Thanks for exploring Northeast Ohio. Your saved rewards, unfinished trail progress and local offers give you a reason to come back. This is a draft demonstration and is not sent automatically.'
PHP;
$goodBody = <<<'PHP'
'body'=>tomTr('Thanks for exploring Northeast Ohio. Your saved rewards, unfinished trail progress and local offers give you a reason to come back. This is a draft demonstration and is not sent automatically.')
PHP;
$source = str_replace($badBody, $goodBody, $source);

$tmp = sys_get_temp_dir().'/placesrewards-tom-v2-fixed.php';
file_put_contents($tmp, $source, LOCK_EX);
$command = escapeshellarg(PHP_BINARY).' '.escapeshellarg($tmp);
passthru($command, $exitCode);
exit($exitCode);
