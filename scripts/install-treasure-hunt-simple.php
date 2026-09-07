<?php
declare(strict_types=1);
// Only this new static page is installed. Existing routes and templates are untouched.
$source = __DIR__.'/../assets/treasure-hunt-simple/index.html';
$target = '/home/placevle/app.placesrewards.com/public/demo/treasure-hunt-simple/index.html';
if (!is_file($source)) { throw new RuntimeException('Simple presentation source missing'); }
$content = file_get_contents($source);
if (is_file($target) && hash_file('sha256', $target) === hash('sha256', $content)) { exit(0); }
if (!is_dir(dirname($target)) && !mkdir(dirname($target), 0755, true)) { throw new RuntimeException('Could not create new page directory'); }
if (file_put_contents($target.'.tmp', $content, LOCK_EX) === false || !rename($target.'.tmp', $target)) { throw new RuntimeException('Could not publish new page'); }
echo "Separate Treasure Hunt simple presentation installed\n";
