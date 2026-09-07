<?php

declare(strict_types=1);

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Route;

if (!function_exists('placesRewardsLoadTomDemo')) {
    function placesRewardsLoadTomDemo(): array
    {
        $path = storage_path('app/northeast-ohio-tom/modules-runtime.json');
        if (!is_file($path)) {
            abort(503, 'The Northeast Ohio Treasure Hunt demo is not installed yet.');
        }
        $decoded = json_decode((string) file_get_contents($path), true);
        if (!is_array($decoded)) {
            abort(500, 'The Northeast Ohio Treasure Hunt demo configuration is invalid.');
        }
        return $decoded;
    }
}

if (!function_exists('placesRewardsSaveTomDemo')) {
    function placesRewardsSaveTomDemo(array $demo): void
    {
        $path = storage_path('app/northeast-ohio-tom/modules-runtime.json');
        file_put_contents($path, json_encode($demo, JSON_PRETTY_PRINT|JSON_UNESCAPED_SLASHES), LOCK_EX);
    }
}

if (!function_exists('placesRewardsAuthorizeTomDemoEditor')) {
    function placesRewardsAuthorizeTomDemoEditor(): void
    {
        $user = Auth::user();
        if (!$user) abort(403);
        $email = strtolower((string) ($user->email ?? ''));
        $role = strtolower((string) ($user->role ?? $user->type ?? ''));
        $allowed = $email === 'placesrewards@gmail.com' || in_array($role, ['admin','partner'], true);
        if (!$allowed) abort(403);
    }
}

Route::get('/demo/northeast-ohio-treasure-hunt/tom', function () {
    return view('demo.northeast-ohio-tom', ['demo' => placesRewardsLoadTomDemo()]);
})->name('demo.northeast-ohio-tom');

Route::get('/demo/northeast-ohio-treasure-hunt/tom/module/{sequence}', function (int $sequence) {
    abort_if($sequence < 1 || $sequence > 12, 404);
    $demo = placesRewardsLoadTomDemo();
    $module = null;
    $next = null;
    foreach (($demo['modules'] ?? []) as $candidate) {
        if ((int) ($candidate['sequence'] ?? 0) === $sequence) $module = $candidate;
        if ((int) ($candidate['sequence'] ?? 0) === $sequence + 1) $next = $candidate;
    }
    abort_if(!$module, 404);
    return view('demo.northeast-ohio-tom-module', compact('demo','module','next'));
})->whereNumber('sequence')->name('demo.northeast-ohio-tom.module');

Route::middleware(['auth'])->group(function () {
    Route::get('/partner/demo/northeast-ohio-treasure-hunt/tom', function () {
        placesRewardsAuthorizeTomDemoEditor();
        return view('partner.northeast-ohio-tom-editor', ['demo' => placesRewardsLoadTomDemo()]);
    })->name('partner.demo.northeast-ohio-tom.editor');

    Route::post('/partner/demo/northeast-ohio-treasure-hunt/tom/{sequence}/image', function (Request $request, int $sequence) {
        placesRewardsAuthorizeTomDemoEditor();
        $request->validate([
            'image' => ['required','image','mimes:jpg,jpeg,png,webp','max:5120'],
        ]);

        $demo = placesRewardsLoadTomDemo();
        $index = null;
        foreach (($demo['modules'] ?? []) as $i => $module) {
            if ((int) ($module['sequence'] ?? 0) === $sequence) { $index = $i; break; }
        }
        abort_if($index === null, 404);

        $dir = public_path('files/demo/northeast-ohio-tom/uploads');
        if (!is_dir($dir)) mkdir($dir, 0755, true);
        $ext = strtolower($request->file('image')->getClientOriginalExtension() ?: 'jpg');
        $safeExt = in_array($ext, ['jpg','jpeg','png','webp'], true) ? $ext : 'jpg';
        $fileName = sprintf('%02d-%s-%s.%s', $sequence, $demo['modules'][$index]['slug'], substr(sha1((string) microtime(true).random_int(1000,9999)), 0, 12), $safeExt);
        $request->file('image')->move($dir, $fileName);

        $old = $demo['modules'][$index]['merchant_image'] ?? null;
        if (is_string($old) && str_starts_with($old, '/files/demo/northeast-ohio-tom/uploads/')) {
            $oldPath = public_path(ltrim($old, '/'));
            if (is_file($oldPath)) @unlink($oldPath);
        }

        $demo['modules'][$index]['merchant_image'] = '/files/demo/northeast-ohio-tom/uploads/'.$fileName;
        $demo['modules'][$index]['merchant_image_updated_at'] = now()->toIso8601String();
        placesRewardsSaveTomDemo($demo);
        return back()->with('status', sprintf('Card %02d image updated.', $sequence));
    })->name('partner.demo.northeast-ohio-tom.image');

    Route::post('/partner/demo/northeast-ohio-treasure-hunt/tom/{sequence}/image/reset', function (int $sequence) {
        placesRewardsAuthorizeTomDemoEditor();
        $demo = placesRewardsLoadTomDemo();
        foreach (($demo['modules'] ?? []) as $i => $module) {
            if ((int) ($module['sequence'] ?? 0) !== $sequence) continue;
            $old = $demo['modules'][$i]['merchant_image'] ?? null;
            if (is_string($old) && str_starts_with($old, '/files/demo/northeast-ohio-tom/uploads/')) {
                $oldPath = public_path(ltrim($old, '/'));
                if (is_file($oldPath)) @unlink($oldPath);
            }
            $demo['modules'][$i]['merchant_image'] = null;
            $demo['modules'][$i]['merchant_image_updated_at'] = null;
            placesRewardsSaveTomDemo($demo);
            return back()->with('status', sprintf('Card %02d restored to its generated image.', $sequence));
        }
        abort(404);
    })->name('partner.demo.northeast-ohio-tom.image.reset');
});
