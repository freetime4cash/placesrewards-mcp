<?php

declare(strict_types=1);

/**
 * Reward Loyalty - Proprietary Software
 * Copyright (c) 2025 NowSquare. All rights reserved.
 * See LICENSE file for terms.
 *
 * API Routes
 *
 * Notes:
 * - This file is mounted under the global `/api` prefix (see bootstrap/app.php).
 * - Locale + versioned APIs live under `/{locale}/v1/...` for mobile/admin clients.
 * - Shopify widget + webhooks are intentionally NOT locale-prefixed:
 *   - Widget: store-facing and CORS-protected
 *   - Webhooks: server-to-server callbacks from Shopify
 */

use App\Http\Controllers\Integration\Shopify\ShopifyIntegrationController;
use App\Http\Controllers\Integration\Shopify\WebhookController;
use App\Http\Controllers\Integration\Shopify\WidgetController;
use App\Http\Controllers\Api\MemberMembershipWebhookController; // ADDED
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| API Routes
|--------------------------------------------------------------------------
|
| Here is where you can register API routes for your application. These
| routes are loaded by the RouteServiceProvider within a group which
| is assigned the "api" middleware group. Enjoy building your API!
|
*/


// Places Rewards private demo automation bridge.
// Authentication is enforced inside BusinessDemoController with X-PR-DEMO-BRIDGE.
Route::post('demo/internal/native-build', [\App\Http\Controllers\Demo\BusinessDemoController::class, 'nativeBuild']);
Route::post('demo/internal/asset-deploy', [\App\Http\Controllers\Demo\BusinessDemoController::class, 'assetDeploy']);
Route::post('demo/internal/manifest-deploy', [\App\Http\Controllers\Demo\BusinessDemoController::class, 'manifestDeploy']);

/*
|--------------------------------------------------------------------------
| Shopify Widget (Storefront)
|--------------------------------------------------------------------------
|
| These endpoints are called by the embedded Shopify storefront widget.
| Authentication is via X-API-Key (ClubIntegration.public_api_key).
|
| CORS is configured to allow https://*.myshopify.com origins for these paths.
|
*/
Route::prefix('widget')->group(function () {
    Route::get('{integrationId}/config', [WidgetController::class, 'config']);
    Route::post('{integrationId}/redeem', [WidgetController::class, 'redeem']);

    // Explicit OPTIONS routes to guarantee preflight support in all environments.
    Route::options('{integrationId}/config', fn () => response()->noContent());
    Route::options('{integrationId}/redeem', fn () => response()->noContent());
});

/*
|--------------------------------------------------------------------------
| Shopify Webhooks (Server-to-Server)
|--------------------------------------------------------------------------
|
| Shopify registers webhooks per topic. We expose a dedicated endpoint per topic
| (topic slugs use '-' instead of '/').
|
*/
Route::prefix('webhooks/shopify/{integrationId}')->group(function () {
    Route::post('orders-paid', [WebhookController::class, 'ordersPaid']);
    Route::post('refunds-create', [WebhookController::class, 'refundsCreate']);
    Route::post('customers-create', [WebhookController::class, 'customersCreate']);
    Route::post('customers-update', [WebhookController::class, 'customersUpdate']);
    Route::post('app-uninstalled', [WebhookController::class, 'appUninstalled']);
});

/*
|--------------------------------------------------------------------------
| Places Rewards: WooCommerce Membership Webhook (Server-to-Server) — ADDED
|--------------------------------------------------------------------------
|
| Relayed via a Make.com scenario watching WooCommerce order completions
| on placesrewards.com. Sets the consumer-facing membership tier
| (Free/Basic/Premium/VIP) on the matching `members` record.
|
| Authentication is via X-Webhook-Secret header (see
| config/services.php -> membership_webhook.secret), not agent-key auth.
|
*/
Route::post('webhooks/member-membership', [MemberMembershipWebhookController::class, 'updateTier']); // ADDED

/*
|--------------------------------------------------------------------------
| Agent API Routes (Agentic Layer)
|--------------------------------------------------------------------------
|
| IMPORTANT: This block MUST be registered BEFORE the {locale}/v1 routes
| below. The {locale} wildcard would otherwise match "agent" as a locale
| and route /api/agent/v1/partner/* to the wrong middleware stack.
|
| Machine-to-machine API for AI agents, POS systems, webshops, and
| automation platforms (Zapier, Make, n8n). Authentication is via
| X-Agent-Key header — no sessions, no CSRF, no locale in URL.
|
| Route structure:
|   /api/agent/v1/health            → Smoke test (any role)
|   /api/agent/v1/partner/*         → Partner-scoped operations
|   /api/agent/v1/admin/*           → Admin-scoped operations (Phase 4)
|   /api/agent/v1/member/*          → Member-scoped operations (Phase 4)
|
| Middleware stack:
|   agent.auth   → Authenticate via X-Agent-Key (prefix lookup + bcrypt)
|   agent.rate   → Per-key rate limiting (configurable RPM, 429 on exceed)
|   agent.locale → Set locale from Accept-Language header (optional)
|   agent.log    → Audit log every request (runs in terminate phase)
|   agent.role:X → Enforce key belongs to the correct owner type
|
| @see RewardLoyalty-100-agent.md
| @see RewardLoyalty-100a-phase1-foundation.md §10
|
*/
if (config('default.feature_agent_api')) {
Route::prefix('agent/v1')
    ->withoutMiddleware('throttle:api')
    ->middleware(['agent.auth', 'agent.rate', 'agent.locale', 'agent.log'])
    ->group(function () {

    // Health check — available to all agent roles
    Route::get('health', App\Http\Controllers\Api\Agent\AgentHealthController::class);

    // Tool discovery — returns tool definitions scoped to the key's role + scopes
    Route::get('tools', App\Http\Controllers\Api\Agent\AgentToolsController::class);

    // ─────────────────────────────────────────────────────────────────────
    // PARTNER ENDPOINTS
    // ─────────────────────────────────────────────────────────────────────
    // Phase 2: Clubs, Cards, Rewards, Members, Transactions, Stamps,
    //          Vouchers, Tiers, Staff controllers
    Route::prefix('partner')->middleware(['agent.role:partner', 'agent.partner_enabled'])->group(function () {
        // Clubs
        Route::get('clubs', [App\Http\Controllers\Api\Agent\Partner\AgentClubController::class, 'index']);
        Route::get('clubs/{id}', [App\Http\Controllers\Api\Agent\Partner\AgentClubController::class, 'show']);
        Route::post('clubs', [App\Http\Controllers\Api\Agent\Partner\AgentClubController::class, 'store']);
        Route::put('clubs/{id}', [App\Http\Controllers\Api\Agent\Partner\AgentClubController::class, 'update']);
        Route::delete('clubs/{id}', [App\Http\Controllers\Api\Agent\Partner\AgentClubController::class, 'destroy']);

        // Cards
        Route::get('cards', [App\Http\Controllers\Api\Agent\Partner\AgentCardController::class, 'index']);
        Route::get('cards/{id}', [App\Http\Controllers\Api\Agent\Partner\AgentCardController::class, 'show']);
        Route::post('cards', [App\Http\Controllers\Api\Agent\Partner\AgentCardController::class, 'store']);
        Route::put('cards/{id}', [App\Http\Controllers\Api\Agent\Partner\AgentCardController::class, 'update']);
        Route::delete('cards/{id}', [App\Http\Controllers\Api\Agent\Partner\AgentCardController::class, 'destroy']);

        // Rewards
        Route::get('rewards', [App\Http\Controllers\Api\Agent\Partner\AgentRewardController::class, 'index']);
        Route::get('rewards/{id}', [App\Http\Controllers\Api\Agent\Partner\AgentRewardController::class, 'show']);
        Route::post('rewards', [App\Http\Controllers\Api\Agent\Partner\AgentRewardController::class, 'store']);
        Route::put('rewards/{id}', [App\Http\Controllers\Api\Agent\Partner\AgentRewardController::class, 'update']);
        Route::delete('rewards/{id}', [App\Http\Controllers\Api\Agent\Partner\AgentRewardController::class, 'destroy']);

        // Transactions (list + POS operations)
        Route::get('transactions', [App\Http\Controllers\Api\Agent\Partner\AgentTransactionController::class, 'index']);
        Route::post('transactions/purchase', [App\Http\Controllers\Api\Agent\Partner\AgentTransactionController::class, 
'purchase']);
        Route::post('transactions/redeem', [App\Http\Controllers\Api\Agent\Partner\AgentTransactionController::class, 'redeem']);

        // Members (read + balance only)
        Route::get('members', [App\Http\Controllers\Api\Agent\Partner\AgentMemberController::class, 'index']);
        Route::get('members/{id}', [App\Http\Controllers\Api\Agent\Partner\AgentMemberController::class, 'show']);
        Route::get('members/{id}/balance/{cardId}', [App\Http\Controllers\Api\Agent\Partner\AgentMemberController::class, 
'balance']);

        // Stamp Cards
        Route::get('stamp-cards', [App\Http\Controllers\Api\Agent\Partner\AgentStampCardController::class, 'index']);
        Route::get('stamp-cards/{id}', [App\Http\Controllers\Api\Agent\Partner\AgentStampCardController::class, 'show']);
        Route::post('stamp-cards', [App\Http\Controllers\Api\Agent\Partner\AgentStampCardController::class, 'store']);
        Route::put('stamp-cards/{id}', [App\Http\Controllers\Api\Agent\Partner\AgentStampCardController::class, 'update']);
        Route::delete('stamp-cards/{id}', [App\Http\Controllers\Api\Agent\Partner\AgentStampCardController::class, 'destroy']);
        Route::post('stamp-cards/{id}/stamps', [App\Http\Controllers\Api\Agent\Partner\AgentStampCardController::class, 
'addStamps']);
        Route::post('stamp-cards/{id}/redeem', [App\Http\Controllers\Api\Agent\Partner\AgentStampCardController::class, 
'redeemStampReward']);

        // Vouchers
        Route::get('vouchers', [App\Http\Controllers\Api\Agent\Partner\AgentVoucherController::class, 'index']);
        Route::get('vouchers/{id}', [App\Http\Controllers\Api\Agent\Partner\AgentVoucherController::class, 'show']);
        Route::post('vouchers', [App\Http\Controllers\Api\Agent\Partner\AgentVoucherController::class, 'store']);
        Route::put('vouchers/{id}', [App\Http\Controllers\Api\Agent\Partner\AgentVoucherController::class, 'update']);
        Route::delete('vouchers/{id}', [App\Http\Controllers\Api\Agent\Partner\AgentVoucherController::class, 'destroy']);
        Route::post('vouchers/validate', [App\Http\Controllers\Api\Agent\Partner\AgentVoucherController::class, 
'validateVoucher']);
        Route::post('vouchers/{id}/redeem', [App\Http\Controllers\Api\Agent\Partner\AgentVoucherController::class, 
'redeemVoucher']);

        // Tiers
        Route::get('tiers', [App\Http\Controllers\Api\Agent\Partner\AgentTierController::class, 'index']);
        Route::get('tiers/{id}', [App\Http\Controllers\Api\Agent\Partner\AgentTierController::class, 'show']);
        Route::post('tiers', [App\Http\Controllers\Api\Agent\Partner\AgentTierController::class, 'store']);
        Route::put('tiers/{id}', [App\Http\Controllers\Api\Agent\Partner\AgentTierController::class, 'update']);
        Route::delete('tiers/{id}', [App\Http\Controllers\Api\Agent\Partner\AgentTierController::class, 'destroy']);

        // Staff
        Route::get('staff', [App\Http\Controllers\Api\Agent\Partner\AgentStaffController::class, 'index']);
        Route::get('staff/{id}', [App\Http\Controllers\Api\Agent\Partner\AgentStaffController::class, 'show']);
        Route::post('staff', [App\Http\Controllers\Api\Agent\Partner\AgentStaffController::class, 'store']);
        Route::put('staff/{id}', [App\Http\Controllers\Api\Agent\Partner\AgentStaffController::class, 'update']);
        Route::delete('staff/{id}', [App\Http\Controllers\Api\Agent\Partner\AgentStaffController::class, 'destroy']);
    });

    // ─────────────────────────────────────────────────────────────────────
    // ADMIN ENDPOINTS (Phase 4)
    // ─────────────────────────────────────────────────────────────────────
    Route::prefix('admin')->middleware('agent.role:admin')->group(function () {
        // Partners
        Route::get('partners', [App\Http\Controllers\Api\Agent\Admin\AgentPartnerController::class, 'index']);
        Route::get('partners/{id}', [App\Http\Controllers\Api\Agent\Admin\AgentPartnerController::class, 'show']);
        Route::patch('partners/{id}/permissions', [App\Http\Controllers\Api\Agent\Admin\AgentPartnerController::class, 
'updatePermissions']);
        Route::post('partners/{id}/activate', [App\Http\Controllers\Api\Agent\Admin\AgentPartnerController::class, 'activate']);
        Route::post('partners/{id}/deactivate', [App\Http\Controllers\Api\Agent\Admin\AgentPartnerController::class, 
'deactivate']);

        // Campaign management for a specific partner
        Route::post('partners/{partnerId}/cards', [App\Http\Controllers\Api\Agent\Admin\AgentAdminCardController::class, 'store']);

        Route::get('partners/{partnerId}/clubs', [App\Http\Controllers\Api\Agent\Admin\AgentAdminClubController::class, 'index']);
        Route::post('partners/{partnerId}/clubs', [App\Http\Controllers\Api\Agent\Admin\AgentAdminClubController::class, 'store']);

        // Members (platform-wide, read-only)
        Route::get('members', [App\Http\Controllers\Api\Agent\Admin\AgentAdminMemberController::class, 'index']);
        Route::get('members/{id}', [App\Http\Controllers\Api\Agent\Admin\AgentAdminMemberController::class, 'show']);

        // Analytics
        Route::get('analytics/overview', [App\Http\Controllers\Api\Agent\Admin\AgentAnalyticsController::class, 'overview']);
        Route::get('analytics/partners/{id}', [App\Http\Controllers\Api\Agent\Admin\AgentAnalyticsController::class, 
'partnerMetrics']);

        // Acquisition: enrich a new business from its public website.
        Route::post('businesses/enrich', App\Http\Controllers\Api\Agent\Admin\AgentBusinessEnrichmentController::class);
    });

    // ─────────────────────────────────────────────────────────────────────
    // MEMBER ENDPOINTS (Phase 4)
    // ─────────────────────────────────────────────────────────────────────
    Route::prefix('member')->middleware('agent.role:member')->group(function () {
        // Profile
        Route::get('profile', [App\Http\Controllers\Api\Agent\Member\AgentProfileController::class, 'show']);
        Route::put('profile', [App\Http\Controllers\Api\Agent\Member\AgentProfileController::class, 'update']);

        // Balance & Cards
        Route::get('balance', [App\Http\Controllers\Api\Agent\Member\AgentBalanceController::class, 'balance']);
        Route::get('cards', [App\Http\Controllers\Api\Agent\Member\AgentBalanceController::class, 'cards']);
        Route::get('cards/{id}', [App\Http\Controllers\Api\Agent\Member\AgentBalanceController::class, 'show']);

        // Transaction History
        Route::get('transactions', [App\Http\Controllers\Api\Agent\Member\AgentBalanceController::class, 'transactions']);
        Route::get('transactions/{cardId}', [App\Http\Controllers\Api\Agent\Member\AgentBalanceController::class, 
'cardTransactions']);

        // Rewards
        Route::get('rewards', [App\Http\Controllers\Api\Agent\Member\AgentMemberRewardController::class, 'index']);
        Route::post('rewards/{id}/claim', [App\Http\Controllers\Api\Agent\Member\AgentMemberRewardController::class, 'claim']);

        // Discover (browse homepage cards, resolve QR/URLs, follow/unfollow)
        Route::get('discover', [App\Http\Controllers\Api\Agent\Member\AgentDiscoverController::class, 'index']);
        Route::post('discover/resolve', [App\Http\Controllers\Api\Agent\Member\AgentDiscoverController::class, 'resolve']);
        Route::post('discover/follow', [App\Http\Controllers\Api\Agent\Member\AgentDiscoverController::class, 'follow']);
        Route::post('discover/unfollow', [App\Http\Controllers\Api\Agent\Member\AgentDiscoverController::class, 'unfollow']);
    });
});
} // FEATURE_AGENT_API

/*
|--------------------------------------------------------------------------
| Locale-Prefixed API Routes (Mobile/Admin/Partner Clients)
|--------------------------------------------------------------------------
|
| IMPORTANT: The {locale} wildcard MUST come AFTER literal prefixes
| (like agent/v1) to avoid accidentally matching them as locales.
|
*/
Route::prefix('{locale}/v1')->group(function () {
    Route::prefix('admin')->group(function () {
        Route::post('login', [App\Http\Controllers\Api\AdminAuthController::class, 'login']);

        Route::middleware('auth:admin_api', 'admin.auth.api')->group(function () {
            Route::get('/', [App\Http\Controllers\Api\AdminAuthController::class, 'getAdmin']);
            Route::post('logout', [App\Http\Controllers\Api\AdminAuthController::class, 'logout']);

            // Partners CRUD
            Route::get('partners', [App\Http\Controllers\Api\AdminPartnerController::class, 'getPartners']);
            Route::post('partners', [App\Http\Controllers\Api\AdminPartnerController::class, 'createPartner']);
            Route::get('partner/{partnerId}', [App\Http\Controllers\Api\AdminPartnerController::class, 'getPartner']);
            Route::put('partner/{partnerId}', [App\Http\Controllers\Api\AdminPartnerController::class, 'updatePartner']);
            Route::delete('partner/{partnerId}', [App\Http\Controllers\Api\AdminPartnerController::class, 'deletePartner']);

            // Partner Permissions & Usage (SaaS Billing)
            Route::get('partner/{partnerId}/permissions', [App\Http\Controllers\Api\AdminPartnerController::class, 
'getPartnerPermissions']);
            Route::patch('partner/{partnerId}/permissions', [App\Http\Controllers\Api\AdminPartnerController::class, 
'updatePartnerPermissions']);
            Route::get('partner/{partnerId}/usage', [App\Http\Controllers\Api\AdminPartnerController::class, 'getPartnerUsage']);
        });
    });
    Route::prefix('partner')->group(function () {
        Route::post('login', [App\Http\Controllers\Api\PartnerAuthController::class, 'login']);

        Route::middleware('auth:partner_api', 'partner.auth.api')->group(function () {
            Route::get('/', [App\Http\Controllers\Api\PartnerAuthController::class, 'getPartner']);
            Route::put('/', [App\Http\Controllers\Api\PartnerController::class, 'update']);
            Route::post('logout', [App\Http\Controllers\Api\PartnerAuthController::class, 'logout']);

            // Clubs CRUD
            Route::get('clubs', [App\Http\Controllers\Api\PartnerClubController::class, 'getClubs']);
            Route::post('clubs', [App\Http\Controllers\Api\PartnerClubController::class, 'createClub']);
            Route::get('clubs/{clubId}', [App\Http\Controllers\Api\PartnerClubController::class, 'getClub']);
            Route::put('clubs/{clubId}', [App\Http\Controllers\Api\PartnerClubController::class, 'updateClub']);
            Route::delete('clubs/{clubId}', [App\Http\Controllers\Api\PartnerClubController::class, 'deleteClub']);

            // Cards CRUD
            Route::get('cards', [App\Http\Controllers\Api\PartnerCardController::class, 'getCards']);
            Route::post('cards', [App\Http\Controllers\Api\PartnerCardController::class, 'createCard']);
            Route::get('cards/{cardId}', [App\Http\Controllers\Api\PartnerCardController::class, 'getCard']);
            Route::put('cards/{cardId}', [App\Http\Controllers\Api\PartnerCardController::class, 'updateCard']);
            Route::delete('cards/{cardId}', [App\Http\Controllers\Api\PartnerCardController::class, 'deleteCard']);

            // Card Transactions
            Route::post('cards/{cardUID}/{memberUID}/transactions/purchases', 
[App\Http\Controllers\Api\PartnerTransactionController::class, 'addPurchase']);
            Route::post('cards/{cardUID}/{memberUID}/transactions/points', 
[App\Http\Controllers\Api\PartnerTransactionController::class, 'addPoints']);

            // Staff CRUD
            Route::get('staff', [App\Http\Controllers\Api\PartnerStaffController::class, 'getStaff']);
            Route::post('staff', [App\Http\Controllers\Api\PartnerStaffController::class, 'createStaff']);
            Route::get('staff/{staffId}', [App\Http\Controllers\Api\PartnerStaffController::class, 'getStaffMember']);
            Route::put('staff/{staffId}', [App\Http\Controllers\Api\PartnerStaffController::class, 'updateStaff']);
            Route::delete('staff/{staffId}', [App\Http\Controllers\Api\PartnerStaffController::class, 'deleteStaff']);

            // Members CRUD
            Route::get('members', [App\Http\Controllers\Api\PartnerMemberController::class, 'getMembers']);
            Route::post('members', [App\Http\Controllers\Api\PartnerMemberController::class, 'createMember']);
            Route::get('members/{memberId}', [App\Http\Controllers\Api\PartnerMemberController::class, 'getMember']);
            Route::put('members/{memberId}', [App\Http\Controllers\Api\PartnerMemberController::class, 'updateMember']);
            Route::delete('members/{memberId}', [App\Http\Controllers\Api\PartnerMemberController::class, 'deleteMember']);

            // Stamp Cards CRUD
            Route::get('stamp-cards', [App\Http\Controllers\Api\PartnerStampCardController::class, 'getStampCards']);
            Route::post('stamp-cards', [App\Http\Controllers\Api\PartnerStampCardController::class, 'createStampCard']);
            Route::get('stamp-cards/{stampCardId}', [App\Http\Controllers\Api\PartnerStampCardController::class, 'getStampCard']);
            Route::put('stamp-cards/{stampCardId}', [App\Http\Controllers\Api\PartnerStampCardController::class, 
'updateStampCard']);
            Route::delete('stamp-cards/{stampCardId}', [App\Http\Controllers\Api\PartnerStampCardController::class, 
'deleteStampCard']);

            // Vouchers CRUD
            Route::get('vouchers', [App\Http\Controllers\Api\PartnerVoucherController::class, 'getVouchers']);
            Route::post('vouchers', [App\Http\Controllers\Api\PartnerVoucherController::class, 'createVoucher']);
            Route::get('vouchers/{voucherId}', [App\Http\Controllers\Api\PartnerVoucherController::class, 'getVoucher']);
            Route::put('vouchers/{voucherId}', [App\Http\Controllers\Api\PartnerVoucherController::class, 'updateVoucher']);
            Route::delete('vouchers/{voucherId}', [App\Http\Controllers\Api\PartnerVoucherController::class, 'deleteVoucher']);

            // Rewards CRUD
            Route::get('rewards', [App\Http\Controllers\Api\PartnerRewardController::class, 'getRewards']);
            Route::post('rewards', [App\Http\Controllers\Api\PartnerRewardController::class, 'createReward']);
            Route::get('rewards/{rewardId}', [App\Http\Controllers\Api\PartnerRewardController::class, 'getReward']);
            Route::put('rewards/{rewardId}', [App\Http\Controllers\Api\PartnerRewardController::class, 'updateReward']);
            Route::delete('rewards/{rewardId}', [App\Http\Controllers\Api\PartnerRewardController::class, 'deleteReward']);

            /*
            |--------------------------------------------------------------------------
            | Shopify Integration Management (Partner API)
            |--------------------------------------------------------------------------
            |
            | Partner-authenticated endpoints for managing Shopify integrations:
            | - global settings (SettingsService)
            | - reward mappings (IntegrationReward)
            | - pause/resume/disconnect
            |
            */
            Route::prefix('integrations/shopify')->group(function () {
                Route::get('{integrationId}', [ShopifyIntegrationController::class, 'show']);
                Route::post('{integrationId}/settings', [ShopifyIntegrationController::class, 'updateSettings']);
                Route::post('{integrationId}/reward-mappings', [ShopifyIntegrationController::class, 'upsertRewardMapping']);
                Route::post('{integrationId}/pause', [ShopifyIntegrationController::class, 'pause']);
                Route::post('{integrationId}/resume', [ShopifyIntegrationController::class, 'resume']);
                Route::post('{integrationId}/disconnect', [ShopifyIntegrationController::class, 'disconnect']);
            });
        });
    });
    Route::prefix('member')->group(function () {
        Route::post('login', [App\Http\Controllers\Api\MemberAuthController::class, 'login']);
        Route::post('register', [App\Http\Controllers\Api\MemberAuthController::class, 'register']);

        /*
        |--------------------------------------------------------------------------
        | Anonymous Member Session (Public)
        |--------------------------------------------------------------------------
        |
        | Device-bound session management for anonymous members.
        | These endpoints don't require authentication — they establish identity.
        |
        | Flow:
        | 1. Client generates UUID and calls POST /init to get/create member
        | 2. Member receives a short device_code (e.g., "4K7X")
        | 3. Code can be used to switch devices via POST /session/switch
        | 4. Optional: Link email via POST /session/link-email for notifications
        |
        */
        Route::post('init', [App\Http\Controllers\Api\AnonymousMemberController::class, 'init']);
        Route::get('session', [App\Http\Controllers\Api\AnonymousMemberController::class, 'session']);
        Route::post('session/switch', [App\Http\Controllers\Api\AnonymousMemberController::class, 'switch']);
        Route::post('session/link-email', [App\Http\Controllers\Api\AnonymousMemberController::class, 'linkEmail']);

        Route::middleware('auth:member_api', 'member.auth.api')->group(function () {
            Route::get('/', [App\Http\Controllers\Api\MemberAuthController::class, 'getMember']);
            Route::post('logout', [App\Http\Controllers\Api\MemberAuthController::class, 'logout']);

            // Loyalty Cards
            Route::get('all-cards', [App\Http\Controllers\Api\MemberCardController::class, 'getAllCards']);
            Route::get('followed-cards', [App\Http\Controllers\Api\MemberCardController::class, 'getFollowedCards']);
            Route::get('transacted-cards', [App\Http\Controllers\Api\MemberCardController::class, 'getTransactedCards']);
            Route::get('balance/{cardId}', [App\Http\Controllers\Api\MemberCardController::class, 'getMemberBalance']);

            // Stamp Cards (existing)
            Route::get('stamp-cards', [App\Http\Controllers\Member\StampCardController::class, 'apiIndex']);
            Route::get('stamp-cards/{id}/history', [App\Http\Controllers\Member\StampCardController::class, 'apiHistory']);

            // Stamp Cards - My Cards (enroll/unenroll)
            Route::get('my-stamp-cards', [App\Http\Controllers\Api\MemberStampCardController::class, 'getMyStampCards']);
            Route::post('stamp-cards/{stampCardId}/enroll', [App\Http\Controllers\Api\MemberStampCardController::class, 'enroll']);
            Route::delete('stamp-cards/{stampCardId}/enroll', [App\Http\Controllers\Api\MemberStampCardController::class, 
'unenroll']);

            // Vouchers - My Cards (save/unsave)
            Route::get('my-vouchers', [App\Http\Controllers\Api\MemberVoucherController::class, 'getMyVouchers']);
            Route::post('vouchers/{voucherId}/save', [App\Http\Controllers\Api\MemberVoucherController::class, 'save']);
            Route::delete('vouchers/{voucherId}/save', [App\Http\Controllers\Api\MemberVoucherController::class, 'unsave']);
        });
    });

    /*
    |--------------------------------------------------------------------------
    | Staff API Routes
    |--------------------------------------------------------------------------
    |
    | Point-of-sale operations for staff members. Staff can:
    | - Look up members
    | - Add purchases and award points
    | - Add stamps to stamp cards
    | - Redeem rewards (loyalty and stamp cards)
    | - Validate and redeem vouchers
    |
    */
    Route::prefix('staff')->group(function () {
        // Public: Login
        Route::post('login', [App\Http\Controllers\Api\StaffAuthController::class, 'login']);

        // Protected: All staff operations
        Route::middleware('auth:staff_api')->group(function () {
            // Auth
            Route::get('/', [App\Http\Controllers\Api\StaffAuthController::class, 'getStaff']);
            Route::post('logout', [App\Http\Controllers\Api\StaffAuthController::class, 'logout']);

            // Member lookup
            Route::get('member/{identifier}', [App\Http\Controllers\Api\StaffController::class, 'findMember']);

            // Loyalty card operations
            Route::post('cards/{cardId}/purchase', [App\Http\Controllers\Api\StaffController::class, 'addPurchase']);
            Route::post('cards/{cardId}/rewards/{rewardId}/redeem', [App\Http\Controllers\Api\StaffController::class, 
'redeemReward']);

            // Stamp card operations
            Route::post('stamp-cards/{stampCardId}/stamps', [App\Http\Controllers\Api\StaffController::class, 'addStamps']);
            Route::post('stamp-cards/{stampCardId}/redeem', [App\Http\Controllers\Api\StaffController::class, 
'redeemStampReward']);

            // Voucher operations
            Route::post('vouchers/validate', [App\Http\Controllers\Api\StaffController::class, 'validateVoucher']);
            Route::post('vouchers/{voucherId}/redeem', [App\Http\Controllers\Api\StaffController::class, 'redeemVoucher']);
        });
    });
});
