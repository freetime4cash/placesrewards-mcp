<?php

declare(strict_types=1);

use App\Http\Controllers\Demo\TreasureHuntDemoController;
use App\Models\ScratchCard;
use App\Models\ScratchGame;
use Illuminate\Support\Facades\Route;

Route::get('/demo/treasure-hunt', [TreasureHuntDemoController::class, 'index'])->name('demo.treasure-hunt.index');
Route::get('/demo/treasure-hunt/reward', [TreasureHuntDemoController::class, 'reward'])->name('demo.treasure-hunt.reward');
Route::get('/demo/treasure-hunt/check-in', [TreasureHuntDemoController::class, 'checkin'])->name('demo.treasure-hunt.checkin');
Route::get('/demo/treasure-hunt/prize', [TreasureHuntDemoController::class, 'prize'])->name('demo.treasure-hunt.prize');
Route::get('/demo/treasure-hunt/vip', [TreasureHuntDemoController::class, 'vip'])->name('demo.treasure-hunt.vip');
Route::get('/demo/treasure-hunt/retention', [TreasureHuntDemoController::class, 'retention'])->name('demo.treasure-hunt.retention');
Route::get('/demo/treasure-hunt/analytics', [TreasureHuntDemoController::class, 'analytics'])->name('demo.treasure-hunt.analytics');

// Presentation-safe bridge into the real native scratch-card module.
// The member auto-auth middleware establishes the visitor's member session; we then
// reuse an unplayed Treasure Hunt scratch card for that member or issue a new one.
Route::get('/demo/scratch-win', function () {
    $member = auth('member')->user();
    abort_unless($member, 403);

    $game = ScratchGame::query()
        ->whereKey('1fefb288-a8cc-46d4-a4a3-04fe56f91329')
        ->where('is_active', true)
        ->firstOrFail();

    $scratchCard = ScratchCard::query()
        ->where('scratch_game_id', $game->id)
        ->where('member_id', $member->id)
        ->where('is_played', false)
        ->latest()
        ->first();

    if (!$scratchCard) {
        $scratchCard = ScratchCard::create([
            'scratch_game_id' => $game->id,
            'member_id' => $member->id,
            'partner_id' => $game->partner_id,
            'is_played' => false,
            'is_winner' => null,
            'played_at' => null,
        ]);
    }

    return redirect('/en-us/scratch-cards/'.$scratchCard->id);
})->middleware(['member.auth.auto','member.role:1,2,3'])->name('demo.treasure-hunt.scratch-play');
