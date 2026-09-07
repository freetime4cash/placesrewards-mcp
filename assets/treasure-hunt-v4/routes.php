<?php

declare(strict_types=1);

use App\Http\Controllers\Demo\TreasureHuntDemoController;
use Illuminate\Support\Facades\Route;

Route::get('/demo/treasure-hunt', [TreasureHuntDemoController::class, 'index'])->name('demo.treasure-hunt.index');
Route::get('/demo/treasure-hunt/reward', [TreasureHuntDemoController::class, 'reward'])->name('demo.treasure-hunt.reward');
Route::get('/demo/treasure-hunt/check-in', [TreasureHuntDemoController::class, 'checkin'])->name('demo.treasure-hunt.checkin');
Route::get('/demo/treasure-hunt/prize', [TreasureHuntDemoController::class, 'prize'])->name('demo.treasure-hunt.prize');
Route::get('/demo/treasure-hunt/vip', [TreasureHuntDemoController::class, 'vip'])->name('demo.treasure-hunt.vip');
Route::get('/demo/treasure-hunt/retention', [TreasureHuntDemoController::class, 'retention'])->name('demo.treasure-hunt.retention');
Route::get('/demo/treasure-hunt/analytics', [TreasureHuntDemoController::class, 'analytics'])->name('demo.treasure-hunt.analytics');
