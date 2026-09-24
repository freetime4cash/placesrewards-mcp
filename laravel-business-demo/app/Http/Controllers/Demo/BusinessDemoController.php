<?php
declare(strict_types=1);

namespace App\Http\Controllers\Demo;

use App\Http\Controllers\Controller;
use App\Models\Card;
use App\Models\Club;
use App\Models\Partner;
use App\Models\ReferralSetting;
use App\Models\ScratchCard;
use App\Models\ScratchGame;
use App\Models\StampCard;
use App\Models\Voucher;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\View\View;

class BusinessDemoController extends Controller
{
    private function loadDemo(string $slug): array
    {
        $path = storage_path('app/demo-' . $slug . '.json');
        abort_unless(is_file($path), 404);

        $demo = json_decode((string) file_get_contents($path), true);
        abort_unless(is_array($demo), 404);

        return $demo;
    }

    public function show(string $slug, ?int $sequence = null): View
    {
        $demo = $this->loadDemo($slug);
        $modules = $demo['sequence'] ?? [];
        $active = $sequence ?: 1;

        return view('demo.treasure-hunt.business', compact('demo', 'modules', 'active'));
    }

    public function module(string $slug, string $kind)
    {
        $demo = $this->loadDemo($slug);
        $modules = $demo['sequence'] ?? [];
        $native = $demo['native'] ?? [];

        $module = null;
        foreach ($modules as $candidate) {
            if (($candidate['kind'] ?? '') === $kind) {
                $module = $candidate;
                break;
            }
        }

        abort_unless(is_array($module), 404);

        // Keep every demo step inside the demo shell. Native records are loaded
        // and rendered below instead of redirecting to routes that may change.
        $nativeData = [];
        if ($kind === 'loyalty' && !empty($native['stamp_card_id'])) {
            $nativeData['stamp_card'] = StampCard::find($native['stamp_card_id']);
        }
        if ($kind === 'voucher' && !empty($native['voucher_id'])) {
            $nativeData['voucher'] = Voucher::find($native['voucher_id']);
        }
        if ($kind === 'scratch' && !empty($native['scratch_game_id'])) {
            $nativeData['scratch_game'] = ScratchGame::find($native['scratch_game_id']);
        }
        if ($kind === 'referral' && !empty($native['referral_setting_id'])) {
            $nativeData['referral'] = ReferralSetting::with(['referrerCard', 'refereeCard'])->find($native['referral_setting_id']);
        }
        if ($kind === 'analytics') {
            $stamp = !empty($native['stamp_card_id']) ? StampCard::find($native['stamp_card_id']) : null;
            $scratch = !empty($native['scratch_game_id']) ? ScratchGame::find($native['scratch_game_id']) : null;
            $voucher = !empty($native['voucher_id']) ? Voucher::find($native['voucher_id']) : null;
            $referral = !empty($native['referral_setting_id']) ? ReferralSetting::find($native['referral_setting_id']) : null;

            $nativeData['analytics'] = [
                'stamps_issued' => (int) ($stamp?->total_stamps_issued ?? 0),
                'stamp_completions' => (int) ($stamp?->total_completions ?? 0),
                'scratch_issued' => $scratch ? ScratchCard::where('scratch_game_id', $scratch->id)->count() : 0,
                'scratch_played' => $scratch ? ScratchCard::where('scratch_game_id', $scratch->id)->where('is_played', true)->count() : 0,
                'voucher_views' => (int) ($voucher?->views ?? 0),
                'voucher_uses' => (int) ($voucher?->times_used ?? 0),
                'referral_campaign' => $referral ? 1 : 0,
            ];
        }

        $moduleUrl = url('/demo/business/' . $slug . '/module/' . $kind);

        return view('demo.business.module', compact('demo', 'module', 'native', 'nativeData', 'moduleUrl'));
    }

    public function nativeBuild(Request $request): JsonResponse
    {
        $secretPath = '/home/placevle/.pr_demo_bridge_secret';
        $expected = is_file($secretPath) ? trim((string) file_get_contents($secretPath)) : '';
        $provided = (string) $request->header('X-PR-DEMO-BRIDGE', '');
        abort_unless($expected !== '' && $provided !== '' && hash_equals($expected, $provided), 403);

        $business = trim((string) $request->input('business', ''));
        abort_unless($business !== '', 422, 'Business is required.');

        $slug = Str::slug((string) $request->input('slug', $business));
        $category = strtolower((string) $request->input('category', ''));
        $regulated = str_contains($category, 'smoke') || str_contains($category, 'tobacco') || str_contains($category, 'vape');

        $assets = DB::transaction(function () use ($business, $slug, $regulated) {
            $partner = Partner::where('email', 'placesrewards@gmail.com')->firstOrFail();

            $clubName = Str::limit('[DEMO] ' . $business, 90, '');
            $club = Club::firstOrCreate(
                ['name' => $clubName, 'created_by' => $partner->id],
                [
                    'description' => 'Isolated Places Rewards sales demo workspace for ' . $business . '. Demo-only data; never production customer data.',
                    'is_active' => true,
                    'is_primary' => false,
                ]
            );

            $cardName = Str::limit('[DEMO] ' . $business . ' Loyalty Card', 124, '');
            $card = Card::where('club_id', $club->id)->where('name', $cardName)->first();
            if (!$card) {
                $card = Card::create([
                    'club_id' => $club->id,
                    'name' => $cardName,
                    'currency' => 'USD',
                    'points_per_currency' => 1,
                    'currency_unit_amount' => 1,
                    'min_points_per_purchase' => 1,
                    'max_points_per_purchase' => 1000,
                    'points_expiration_months' => 12,
                    'is_active' => true,
                    'created_by' => $partner->id,
                ]);
            }

            $stampName = Str::limit('[DEMO] ' . $business . ' 10-Visit Loyalty', 124, '');
            $stamp = StampCard::firstOrCreate(
                ['club_id' => $club->id, 'name' => $stampName],
                [
                    'created_by' => $partner->id,
                    'title' => ['en_US' => $business . ' Loyalty Demo'],
                    'description' => ['en_US' => 'Demo-only visit loyalty card.'],
                    'stamps_required' => 10,
                    'stamps_per_purchase' => 1,
                    'reward_title' => ['en_US' => 'Demo Loyalty Reward'],
                    'reward_description' => ['en_US' => 'Demo-only reward. No production benefit is issued.'],
                    'is_active' => true,
                    'is_visible_by_default' => false,
                ]
            );

            $gameName = Str::limit('[DEMO] ' . $business . ' Mystery Reward', 250, '');
            $scratch = ScratchGame::firstOrCreate(
                ['partner_id' => $partner->id, 'name' => $gameName],
                [
                    'description' => 'Demo-only mystery engagement for ' . $business . '. No real prize is issued and no purchase is required.',
                    'win_rate' => 25,
                    'is_active' => true,
                ]
            );

            $codeBase = strtoupper(preg_replace('/[^A-Z0-9]/', '', $slug));
            $voucherCode = substr($codeBase, 0, 22) . 'DEMO';
            $voucherName = Str::limit('[DEMO] ' . $business . ' Comeback Offer', 124, '');
            $voucher = Voucher::where('club_id', $club->id)->where('name', $voucherName)->latest('created_at')->first();
            if (!$voucher) {
                $voucher = Voucher::create([
                    'club_id' => $club->id,
                    'code' => $voucherCode,
                    'name' => $voucherName,
                    'title' => ['en_US' => 'Demo Comeback Offer'],
                    'description' => ['en_US' => 'Private sales demo only. Not redeemable and not tied to any required purchase. Shows how a targeted comeback offer can be presented before production activation.'],
                    'type' => 'bonus_points',
                    'value' => 0,
                    'points_value' => 100,
                    'is_active' => true,
                    'is_public' => false,
                    'is_visible_by_default' => false,
                    'source' => 'manual',
                    'created_by' => $partner->id,
                ]);
            }

            $referralName = Str::limit('[DEMO] ' . $business . ' Referral Rewards', 185, '');
            $referral = ReferralSetting::firstOrCreate(
                ['name' => $referralName, 'created_by' => $partner->id],
                [
                    'description' => 'Isolated demo referral campaign for ' . $business . '.',
                    'is_enabled' => true,
                    'referrer_points' => 25,
                    'referrer_card_id' => $card->id,
                    'referee_points' => 25,
                    'referee_card_id' => $card->id,
                ]
            );

            return [
                'club_id' => $club->id,
                'loyalty_card_id' => $card->id,
                'stamp_card_id' => $stamp->id,
                'scratch_game_id' => $scratch->id,
                'voucher_id' => $voucher->id,
                'referral_setting_id' => $referral->id,
                'stamp_url' => url('/en-us/stamp-card/' . $stamp->id),
                'stamp_enroll_url' => url('/en-us/stamp-card/' . $stamp->id . '/enroll'),
                'voucher_url' => url('/en-us/voucher/' . $voucher->id),
                'card_url' => url('/en-us/card/' . $card->id),
            ];
        });

        return response()->json(['ok' => true, 'native' => $assets]);
    }

    public function assetDeploy(Request $request): JsonResponse
    {
        $secretPath = '/home/placevle/.pr_demo_bridge_secret';
        $expected = is_file($secretPath) ? trim((string) file_get_contents($secretPath)) : '';
        $provided = (string) $request->header('X-PR-DEMO-BRIDGE', '');
        abort_unless($expected !== '' && $provided !== '' && hash_equals($expected, $provided), 403);

        $slug = Str::slug((string) $request->input('slug', ''));
        $kind = (string) $request->input('kind', '');
        abort_unless($slug !== '' && in_array($kind, ['selfie', 'insight', 'snapshot', 'enrollment', 'loyalty', 'scratch', 'referral', 'voucher', 'analytics', 'plan'], true), 422, 'Invalid demo asset.');

        $encoded = (string) $request->input('data_base64', '');
        abort_unless($encoded !== '' && strlen($encoded) <= 12 * 1024 * 1024, 422, 'Asset payload is invalid.');

        $raw = base64_decode($encoded, true);
        abort_unless($raw !== false && strlen($raw) > 0 && strlen($raw) <= 8 * 1024 * 1024, 422, 'Asset could not be decoded.');
        abort_unless(substr($raw, 0, 8) === "\x89PNG\r\n\x1a\n", 422, 'Only PNG demo assets are accepted.');

        $filename = 'demo-' . $slug . '-' . $kind . '.png';
        $target = public_path($filename);
        file_put_contents($target, $raw, LOCK_EX);

        return response()->json([
            'ok' => true,
            'asset_url' => url('/' . $filename),
            'bytes' => strlen($raw),
        ]);
    }

    public function manifestDeploy(Request $request): JsonResponse
    {
        $secretPath = '/home/placevle/.pr_demo_bridge_secret';
        $expected = is_file($secretPath) ? trim((string) file_get_contents($secretPath)) : '';
        $provided = (string) $request->header('X-PR-DEMO-BRIDGE', '');
        abort_unless($expected !== '' && $provided !== '' && hash_equals($expected, $provided), 403);

        $manifest = $request->input('manifest');
        abort_unless(is_array($manifest), 422, 'Manifest is required.');

        $slug = Str::slug((string) ($manifest['slug'] ?? ''));
        abort_unless($slug !== '', 422, 'Manifest slug is required.');

        $manifest['slug'] = $slug;
        $target = storage_path('app/demo-' . $slug . '.json');
        $json = json_encode($manifest, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        abort_unless($json !== false, 422, 'Manifest could not be encoded.');

        file_put_contents($target, $json . PHP_EOL, LOCK_EX);

        return response()->json([
            'ok' => true,
            'slug' => $slug,
            'path' => $target,
            'demo_url' => url('/demo/business/' . $slug),
        ]);
    }

}
