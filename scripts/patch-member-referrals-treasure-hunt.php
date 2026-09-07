<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Artisan;

$agentRoot='/home/placevle/placesrewards-agent-server';
$appRoot='/home/placevle/app.placesrewards.com';
$out=$agentRoot.'/results/campaigns/member-referrals-page-patch.json';
$view=$appRoot.'/resources/views/member/referrals/index.blade.php';

$result=['status'=>'running','started_at'=>now()->toIso8601String(),'view'=>$view,'changes'=>[]];
try {
    if(!is_file($view)) throw new RuntimeException('Member referrals view not found');
    $before=(string)file_get_contents($view);
    $content=$before;
    $backupDir=$agentRoot.'/data/backups/member-referrals';@mkdir($backupDir,0755,true);
    $backup=$backupDir.'/index.blade.'.date('Ymd-His').'.php';@copy($view,$backup);$result['backup']=$backup;

    // The referrals page must explain referrals. Do not render the loyalty-card
    // component here: that caused the Hunter Passport card to appear on this page.
    $startMarker='                            {{-- Cards Grid - Show the actual loyalty cards! --}}';
    $endMarker='                            {{-- Reward Breakdown - Crystal Clear --}}';
    $start=strpos($content,$startMarker);
    $end=$start===false?false:strpos($content,$endMarker,$start);
    if($start===false || $end===false || $end<=$start) {
        // Already patched is valid.
        if(!str_contains($content,'TREASURE_HUNT_REFERRAL_CARD')) throw new RuntimeException('Could not locate referral card block');
    } else {
        $replacement=<<<'BLADE'
                            {{-- TREASURE_HUNT_REFERRAL_CARD: referral-specific program card --}}
                            @php
                                $referrerCard = $program['settings']->referrerCard ?? null;
                                $refereeCard = $program['settings']->refereeCard ?? null;
                                $isTreasureHuntReferral =
                                    optional($referrerCard)->id === '95cbd0bf-8bbb-436d-b7c6-a2e1e558db25' ||
                                    optional($refereeCard)->id === '95cbd0bf-8bbb-436d-b7c6-a2e1e558db25';
                                $referrerPoints = (int) ($program['settings']->referrer_points ?? 0);
                                $refereePoints = (int) ($program['settings']->referee_points ?? 0);
                            @endphp

                            <div class="relative overflow-hidden rounded-3xl border border-violet-200 dark:border-violet-800/60 bg-gradient-to-br from-violet-50 via-white to-emerald-50 dark:from-violet-950/30 dark:via-secondary-900 dark:to-emerald-950/20 p-7 md:p-8 shadow-lg shadow-violet-900/5">
                                <div class="absolute -top-16 -right-16 w-44 h-44 rounded-full bg-violet-400/10 blur-3xl"></div>
                                <div class="relative space-y-6">
                                    <div class="flex items-start gap-4">
                                        <div class="w-12 h-12 rounded-2xl bg-violet-600 text-white flex items-center justify-center shadow-md flex-shrink-0">
                                            <x-ui.icon icon="users" class="w-6 h-6" />
                                        </div>
                                        <div>
                                            <div class="text-xs font-black tracking-[0.18em] uppercase text-violet-600 dark:text-violet-400 mb-1">
                                                {{ $isTreasureHuntReferral ? '07 • BRING ANOTHER HUNTER' : 'REFERRAL PROGRAM' }}
                                            </div>
                                            <h3 class="text-2xl md:text-3xl font-black text-secondary-900 dark:text-white leading-tight">
                                                {{ $isTreasureHuntReferral ? 'Grow the Hunt by Referring a Friend' : 'Invite a Friend. Earn Together.' }}
                                            </h3>
                                        </div>
                                    </div>

                                    <p class="text-base md:text-lg text-secondary-700 dark:text-secondary-300 leading-relaxed">
                                        @if($isTreasureHuntReferral)
                                            Share your personal Treasure Hunt referral link with a friend or family member. Places Rewards tracks who invited whom, verifies the qualifying action, and applies the configured rewards so referral growth is measurable instead of anonymous word of mouth.
                                        @else
                                            Share your personal referral link. Places Rewards tracks the invitation, qualifying action, and resulting rewards so you and the person you invite can earn together.
                                        @endif
                                    </p>

                                    <div class="grid grid-cols-1 sm:grid-cols-3 gap-3">
                                        <div class="rounded-2xl bg-white/80 dark:bg-secondary-900/80 border border-secondary-200 dark:border-secondary-700 p-4">
                                            <div class="text-xs font-bold uppercase tracking-wider text-secondary-500 mb-1">1. Share</div>
                                            <div class="font-bold text-secondary-900 dark:text-white">Send your unique referral link</div>
                                        </div>
                                        <div class="rounded-2xl bg-white/80 dark:bg-secondary-900/80 border border-secondary-200 dark:border-secondary-700 p-4">
                                            <div class="text-xs font-bold uppercase tracking-wider text-secondary-500 mb-1">2. Qualify</div>
                                            <div class="font-bold text-secondary-900 dark:text-white">Your friend completes the required action</div>
                                        </div>
                                        <div class="rounded-2xl bg-white/80 dark:bg-secondary-900/80 border border-secondary-200 dark:border-secondary-700 p-4">
                                            <div class="text-xs font-bold uppercase tracking-wider text-secondary-500 mb-1">3. Reward</div>
                                            <div class="font-bold text-secondary-900 dark:text-white">Referral is attributed and rewards are issued</div>
                                        </div>
                                    </div>

                                    <div class="grid grid-cols-2 gap-3">
                                        <div class="rounded-2xl bg-emerald-50 dark:bg-emerald-950/30 border border-emerald-200 dark:border-emerald-800/50 p-4">
                                            <div class="text-xs font-bold uppercase tracking-wider text-emerald-700 dark:text-emerald-400">You can earn</div>
                                            <div class="text-3xl font-black text-emerald-900 dark:text-emerald-100">{{ $referrerPoints }} <span class="text-sm font-bold">points</span></div>
                                        </div>
                                        <div class="rounded-2xl bg-violet-50 dark:bg-violet-950/30 border border-violet-200 dark:border-violet-800/50 p-4">
                                            <div class="text-xs font-bold uppercase tracking-wider text-violet-700 dark:text-violet-400">New hunter can earn</div>
                                            <div class="text-3xl font-black text-violet-900 dark:text-violet-100">{{ $refereePoints }} <span class="text-sm font-bold">points</span></div>
                                        </div>
                                    </div>

                                    @if($isTreasureHuntReferral)
                                        <div class="rounded-2xl bg-secondary-900 text-white p-4 md:p-5">
                                            <div class="font-black mb-1">Why this matters to participating businesses</div>
                                            <div class="text-sm text-secondary-200 leading-relaxed">Every successful referral can create a new attributable hunter, another participating-business visit, and another customer relationship that can continue after the treasure is found.</div>
                                        </div>
                                    @endif
                                </div>
                            </div>

BLADE;
        $content=substr($content,0,$start).$replacement.substr($content,$end);
        $result['changes'][]='replaced_loyalty_card_grid_with_referral_program_card';
    }

    // Make the section heading itself referral-specific.
    $content=str_replace('<x-ui.icon icon="credit-card" class="w-5 h-5 text-primary-600 dark:text-primary-400" />','<x-ui.icon icon="users" class="w-5 h-5 text-primary-600 dark:text-primary-400" />',$content);
    $content=str_replace('<h2 class="text-xl font-bold text-secondary-900 dark:text-white">{{ trans(\'common.your_rewards\') }}</h2>','<h2 class="text-xl font-bold text-secondary-900 dark:text-white">Your Referral Program</h2>',$content);

    if($content!==$before && file_put_contents($view,$content,LOCK_EX)===false) throw new RuntimeException('Could not write referrals view');
    try{Artisan::call('view:clear');}catch(Throwable $e){}
    try{Artisan::call('cache:clear');}catch(Throwable $e){}

    $after=(string)file_get_contents($view);
    $result['checks']=[
        'referral_card_marker'=>str_contains($after,'TREASURE_HUNT_REFERRAL_CARD'),
        'bring_another_hunter'=>str_contains($after,'BRING ANOTHER HUNTER'),
        'referral_heading'=>str_contains($after,'Your Referral Program'),
        'loyalty_grid_removed'=>!str_contains($after,'Cards Grid - Show the actual loyalty cards!'),
    ];
    $result['verified']=!in_array(false,$result['checks'],true);
    $result['status']=$result['verified']?'completed':'failed';
} catch(Throwable $e) {
    $result['status']='failed';$result['verified']=false;$result['error']=$e->getMessage();
}
$result['completed_at']=now()->toIso8601String();
@mkdir(dirname($out),0755,true);file_put_contents($out,json_encode($result,JSON_PRETTY_PRINT|JSON_UNESCAPED_SLASHES),LOCK_EX);
echo json_encode(['status'=>$result['status'],'verified'=>$result['verified']??false,'changes'=>$result['changes'],'error'=>$result['error']??null]),"\n";
exit($result['status']==='completed'?0:1);
