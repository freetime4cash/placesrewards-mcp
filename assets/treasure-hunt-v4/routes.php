<?php

declare(strict_types=1);

use App\Http\Controllers\Demo\TreasureHuntDemoController;
use App\Models\Member;
use App\Models\ScratchCard;
use App\Models\ScratchGame;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Str;

Route::get('/demo/treasure-hunt', [TreasureHuntDemoController::class, 'index'])->name('demo.treasure-hunt.index');
Route::get('/demo/treasure-hunt/reward', [TreasureHuntDemoController::class, 'reward'])->name('demo.treasure-hunt.reward');
Route::get('/demo/treasure-hunt/check-in', [TreasureHuntDemoController::class, 'checkin'])->name('demo.treasure-hunt.checkin');
Route::get('/demo/treasure-hunt/prize', [TreasureHuntDemoController::class, 'prize'])->name('demo.treasure-hunt.prize');
Route::get('/demo/treasure-hunt/vip', [TreasureHuntDemoController::class, 'vip'])->name('demo.treasure-hunt.vip');
Route::get('/demo/treasure-hunt/retention', [TreasureHuntDemoController::class, 'retention'])->name('demo.treasure-hunt.retention');
Route::get('/demo/treasure-hunt/analytics', [TreasureHuntDemoController::class, 'analytics'])->name('demo.treasure-hunt.analytics');

// Bridge into the real member scratch-card module. Existing members keep their own
// account. Anonymous presentation visitors use an isolated, non-personal demo member.
Route::get('/demo/scratch-win', function () {
    $member = auth('member')->user();

    if (!$member) {
        $demoIdentifier = 'TH-DEMO-HUNTER-2026';
        $row = DB::table('members')->where('unique_identifier', $demoIdentifier)->first();
        if (!$row) {
            $id = (string) Str::uuid();
            DB::table('members')->insert([
                'id' => $id,
                'unique_identifier' => $demoIdentifier,
                'display_name' => 'Treasure Hunt Demo Hunter',
                'name' => 'Treasure Hunt Demo Hunter',
                'role' => 1,
                'gender' => 0,
                'is_active' => 1,
                'locale' => 'en_US',
                'country_code' => 'US',
                'currency' => 'USD',
                'time_zone' => 'America/New_York',
                'meta' => json_encode(['treasure_hunt_demo' => true, 'purpose' => 'public scratch-card demonstration']),
                'created_at' => now(),
                'updated_at' => now(),
            ]);
            $row = DB::table('members')->where('id', $id)->first();
        }
        $member = Member::query()->findOrFail($row->id);
        auth('member')->login($member);
        request()->session()->regenerate();
    }

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
})->name('demo.treasure-hunt.scratch-play');
