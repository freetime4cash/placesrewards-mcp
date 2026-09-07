<?php

declare(strict_types=1);

namespace App\Http\Controllers\Demo;

use App\Http\Controllers\Controller;
use Illuminate\View\View;

class TreasureHuntDemoController extends Controller
{
    private function routeFor(int $sequence): string
    {
        $map = [
            1 => '/demo/treasure-hunt/loyalty',
            2 => '/demo/treasure-hunt/stamps',
            3 => '/demo/treasure-hunt/directory',
            4 => '/demo/treasure-hunt/reward',
            5 => '/demo/treasure-hunt/check-in',
            6 => '/demo/treasure-hunt/prize',
            7 => '/demo/treasure-hunt/referrals',
            8 => '/demo/treasure-hunt/vip',
            9 => '/demo/treasure-hunt/scratch',
            10 => '/demo/treasure-hunt/voucher',
            11 => '/demo/treasure-hunt/retention',
            12 => '/demo/treasure-hunt/analytics',
        ];
        return $map[$sequence] ?? '/demo/treasure-hunt';
    }

    private function nativeLinks(): array
    {
        $base='https://app.placesrewards.com';
        $card='95cbd0bf-8bbb-436d-b7c6-a2e1e558db25';
        $explorer='a9566430-b6b0-434d-ae3c-f3ba85421c5f';
        $checkin='5738988e-265f-422f-9b41-5828790af3c0';
        $clueReward='29304849-3c10-4a06-8f8f-4bad776b79f9';
        $prizeReward='13085fc2-2a5d-43ee-92b5-441bc368c55b';
        $scratch='1fefb288-a8cc-46d4-a4a3-04fe56f91329';
        $voucher='14788f52-438e-4293-bd6b-c82b8e448983';
        $campaign='b1ea6974-d61c-41d4-a1ce-0c0a27ffa5bd';

        return [
            1 => [
                'actual_url'=>"$base/en-us/card/$card",
                'actual_label'=>'Open Actual Hunter Passport',
                'secondary_url'=>"$base/en-us/partner/loyalty-card-analytics/card/$card",
                'secondary_label'=>'Open Hunter Passport Analytics',
                'secondary_auth'=>true,
            ],
            2 => [
                'actual_url'=>"$base/en-us/stamp-card/$explorer",
                'actual_label'=>'Open Actual 5-Stop Explorer Trail',
                'secondary_url'=>"$base/en-us/partner/stamp-card-analytics/card/$explorer",
                'secondary_label'=>'Open Explorer Trail Analytics',
                'secondary_auth'=>true,
            ],
            3 => [
                'actual_url'=>"$base/en-us",
                'actual_label'=>'Open Native Member Discovery Surface',
                'actual_note'=>'Places Rewards currently exposes discovery in the native member experience; there is no separate public web route named Directory.',
            ],
            4 => [
                'actual_url'=>"$base/en-us/card/$card/$clueReward",
                'actual_label'=>'Open Actual Clue Activity Reward',
            ],
            5 => [
                'actual_url'=>"$base/en-us/stamp-card/$checkin",
                'actual_label'=>'Open Actual Merchant Check-In Card',
                'secondary_url'=>"$base/en-us/partner/stamp-card-analytics/card/$checkin",
                'secondary_label'=>'Open Merchant Check-In Analytics',
                'secondary_auth'=>true,
            ],
            6 => [
                'actual_url'=>"$base/en-us/card/$card/$prizeReward",
                'actual_label'=>'Open Actual Local Prize Reward',
            ],
            7 => [
                'actual_url'=>"$base/r/65CRHW",
                'actual_label'=>'Open Actual Bring Another Hunter Referral',
                'secondary_url'=>"$base/en-us/referrals",
                'secondary_label'=>'Open Referral Dashboard',
                'secondary_auth'=>true,
            ],
            8 => [
                'actual_url'=>"$base/en-us/card/$card",
                'actual_label'=>'Open Loyalty Program Containing Hunter VIP',
                'actual_note'=>'Hunter VIP is a real tier attached to the loyalty program; this Laravel build does not expose a separate public tier detail route.',
            ],
            9 => [
                'actual_url'=>"$base/demo/scratch-win",
                'actual_label'=>'Open Actual Interactive Scratch & Win',
                'secondary_url'=>"$base/en-us/partner/scratch-cards/$scratch/results",
                'secondary_label'=>'Open Scratch Results Module',
                'secondary_auth'=>true,
            ],
            10 => [
                'actual_url'=>"$base/en-us/voucher/$voucher",
                'actual_label'=>'Open Actual $5 Off $25 Comeback Voucher',
                'secondary_url'=>"$base/en-us/partner/voucher-analytics/voucher/$voucher",
                'secondary_label'=>'Open Comeback Voucher Analytics',
                'secondary_auth'=>true,
            ],
            11 => [
                'actual_url'=>"$base/en-us/partner/email-campaigns/$campaign",
                'actual_label'=>'Open Actual Post-Hunt Email Campaign',
                'actual_auth'=>true,
            ],
            12 => [
                'actual_url'=>"$base/en-us/partner/loyalty-card-analytics/card/$card",
                'actual_label'=>'Open Actual Organizer & Merchant Analytics',
                'actual_auth'=>true,
            ],
        ];
    }

    private function modules(): array
    {
        return [
            1 => ['sequence'=>1,'phase'=>'CAPTURE','kind'=>'loyalty','label'=>'Hunter Passport','title'=>'Northeast Ohio Treasure Hunt — Hunter Passport','headline'=>'Join once. Keep earning through the entire Hunt.','card'=>'HUNTER PASSPORT • 100 welcome points • One account for visits, rewards, referrals and return offers.','body'=>'The Hunter Passport turns an anonymous Treasure Hunt visitor into a permissioned Places Rewards member. The same identity follows the hunter through every participating-business interaction so the relationship can continue after the treasure is found.','customer_action'=>'Join the Hunt once and keep the Passport on the phone.','system_action'=>'Creates the hunter relationship and stores points, qualifying activity, rewards and referral attribution.','merchant_value'=>'Participating businesses receive identifiable, measurable campaign traffic instead of anonymous footfall.','proof'=>'Enrollment count, active hunters, points activity and merchant interactions.'],
            2 => ['sequence'=>2,'phase'=>'CIRCULATE','kind'=>'stamps','label'=>'Explorer Trail','title'=>'5-Stop Explorer Trail — Move Hunters Across Participating Businesses','headline'=>'Five verified stops. Five reasons to support local.','card'=>'EXPLORER TRAIL • Visit 5 participating businesses • 1 verified stamp per stop • Complete 5 to unlock the Explorer Reward.','body'=>'This is the cross-business traffic engine. Instead of one clue creating one isolated visit, the Explorer Trail gives participants a visible reason to continue through multiple participating merchants.','customer_action'=>'Visit participating locations and collect one verified stamp at each stop.','system_action'=>'Tracks progress and unlocks the completion reward after five verified stops.','merchant_value'=>'Traffic is intentionally distributed through the merchant network rather than concentrated at one location.','proof'=>'Stops completed, merchants visited, trail completion rate and cross-business circulation.'],
            3 => ['sequence'=>3,'phase'=>'DISCOVER','kind'=>'directory','label'=>'Business Discovery','title'=>'Participating Business Discovery — Give Every Merchant a Reason to Be Found','headline'=>'The Hunt introduces the customer. The merchant page gives them a reason to walk in.','card'=>'FEATURED PARTICIPATING BUSINESS • Business name • Logo • Location • Category • Hunt-safe visit reason • Merchant-specific offer.','body'=>'Every participating business can have its own discovery card. This is not a repeated generic Hunt card: the merchant name, logo, location, category, offer and visit message belong to that business.','customer_action'=>'Choose the next participating business based on location, category, reward or Hunt activity.','system_action'=>'Surfaces merchant-specific discovery information without exposing or changing Tom’s official clues.','merchant_value'=>'Sponsor visibility becomes an actionable customer-discovery placement rather than passive logo exposure.','proof'=>'Business profile views, clicks, visits and downstream reward activity.'],
            4 => ['sequence'=>4,'phase'=>'ENGAGE','kind'=>'reward','label'=>'Clue Activity Reward','title'=>'Clue Activity Reward — Reward Engagement Without Touching the Official Clue','headline'=>'Reward the verified activity, never the answer.','card'=>'CLUE ACTIVITY BONUS • +250 demo points after an approved Hunt activity or verified participating-business visit.','body'=>'Tom keeps complete control of the clue sequence and treasure. Places Rewards adds a separate reward layer around approved activity so a business can recognize a visit without changing clue difficulty, answer access or treasure odds.','customer_action'=>'Complete the approved Hunt activity or verified visit.','system_action'=>'Issues the configured reward only after the qualifying action.','merchant_value'=>'A clue-related visit can now produce a measurable customer interaction tied to the participating business.','proof'=>'Reward issues, qualifying activities and merchant attribution.'],
            5 => ['sequence'=>5,'phase'=>'VERIFY','kind'=>'checkin','label'=>'Merchant Check-In','title'=>'Merchant Check-In — Prove the Hunt Sent Someone Through the Door','headline'=>'A verified visit becomes measurable campaign evidence.','card'=>'MERCHANT CHECK-IN • Staff/QR verification • Visit confirmed • Explorer stamp issued • Merchant attribution recorded.','body'=>'The business verifies the hunter at the point of visit. That verification can issue the correct stamp or points while recording which merchant received the Treasure Hunt traffic.','customer_action'=>'Check in at the participating business.','system_action'=>'Records the visit and triggers the configured stamp, points or next-step eligibility.','merchant_value'=>'The merchant can show that campaign traffic reached the business instead of relying on estimates.','proof'=>'Verified visits by merchant, time and campaign step.'],
            6 => ['sequence'=>6,'phase'=>'REWARD','kind'=>'prize','label'=>'Local Prize & Giveaway','title'=>'Local Prize & Giveaway — Give Participating Businesses Their Own Win Moment','headline'=>'The grand treasure stays special while local businesses add more reasons to participate.','card'=>'LOCAL BUSINESS BONUS PRIZE • Example only: merchant-defined gift card, product, service or experience • Eligibility tied to approved activity.','body'=>'A participating business can contribute its own prize or experience. The prize is separate from the official treasure and can be tied to a verified visit, trail milestone or another approved action.','customer_action'=>'Complete the qualifying action and become eligible for that merchant’s local prize.','system_action'=>'Tracks eligibility and associates the reward with the contributing business.','merchant_value'=>'Sponsors gain a concrete promotional activation instead of only logo placement.','proof'=>'Eligible hunters, prize claims and merchant-level engagement.'],
            7 => ['sequence'=>7,'phase'=>'GROW','kind'=>'referral','label'=>'Bring Another Hunter','title'=>'Bring Another Hunter — Turn Word of Mouth Into Trackable Growth','headline'=>'Hunters can help grow the next wave of hunters.','card'=>'BRING ANOTHER HUNTER • Referrer earns 100 points after the referred hunter’s first qualifying purchase • New hunter receives 50 points.','body'=>'Each participant can share a trackable referral path. Places Rewards records who invited whom and can reward both sides only after the configured qualifying action.','customer_action'=>'Share the Hunt referral invitation with a friend or family member.','system_action'=>'Attributes the referral and applies the configured rewards after qualification.','merchant_value'=>'Participant growth becomes measurable and less dependent on paid advertising.','proof'=>'Invitations, referred sign-ups, successful referrals and resulting merchant activity.'],
            8 => ['sequence'=>8,'phase'=>'DEEPEN','kind'=>'vip','label'=>'Hunter VIP','title'=>'Hunter VIP — Reward the People Who Support Local Most','headline'=>'The most active hunters become the most valuable long-term audience.','card'=>'HUNTER VIP • Demo threshold: 2,500 points • Early Hunt previews • Premium merchant perks • VIP local experiences.','body'=>'Hunter VIP creates progression beyond a single clue or visit. The most engaged participants can unlock a recognizable level with benefits that keep them connected to the participating-business network.','customer_action'=>'Earn qualifying activity and progress toward Hunter VIP.','system_action'=>'Tracks tier qualification and applies the configured VIP benefits.','merchant_value'=>'Businesses gain a high-engagement customer segment for premium offers and future campaigns.','proof'=>'VIP qualifiers, VIP activity, repeat visits and premium-offer engagement.'],
            9 => ['sequence'=>9,'phase'=>'GAMIFY','kind'=>'scratch','label'=>'Mystery Bonus Scratch & Win','title'=>'Mystery Bonus Scratch & Win — Add Instant Excitement Between Clues','headline'=>'Instant-win fun that is completely separate from the real treasure.','card'=>'MYSTERY BONUS • Qualifying visit unlocks one scratch opportunity • Merchant-defined prize pool and win rate • No effect on treasure odds.','body'=>'A qualifying merchant visit can unlock a digital scratch card containing a merchant perk, bonus points, discount or no-win outcome according to configured inventory.','customer_action'=>'Complete the qualifying visit and scratch the digital card.','system_action'=>'Applies the configured win logic and records the outcome.','merchant_value'=>'Businesses gain a repeatable engagement mechanic and additional sponsor inventory.','proof'=>'Scratch attempts, wins, prize claims and resulting visits.'],
            10 => ['sequence'=>10,'phase'=>'CONVERT','kind'=>'voucher','label'=>'Hunter Comeback Offer','title'=>'Hunter Comeback Offer — Turn the Hunt Visit Into a Second Purchase','headline'=>'The first visit came from the Hunt. The second visit becomes merchant revenue.','card'=>'HUNTER COMEBACK • $5 OFF $25 demo offer • One-time use • Designed for a return visit after the Hunt interaction.','body'=>'A time-bounded comeback voucher gives the hunter a reason to return after the initial Hunt visit. The demo uses $5 off $25; each participating business can set its own economics.','customer_action'=>'Return to the business and redeem the comeback offer.','system_action'=>'Tracks saving, redemption and attributable return activity.','merchant_value'=>'The campaign creates a measurable path from foot traffic to repeat spending.','proof'=>'Vouchers saved, redemptions, repeat visits and attributable purchase activity.'],
            11 => ['sequence'=>11,'phase'=>'RETAIN','kind'=>'retention','label'=>'Post-Hunt Retention','title'=>'Post-Hunt Retention — Keep Working After the Treasure Is Found','headline'=>'The event can end without ending the customer relationship.','card'=>'POST-HUNT FOLLOW-UP • “You’re one stop from your Explorer Reward.” • “Your comeback offer is still available.” • “Hunter VIP progress: 72%.”','body'=>'Places Rewards can prepare different follow-up for active hunters, inactive hunters, near-complete trail participants, referred hunters and VIP prospects instead of sending everyone the same generic message.','customer_action'=>'Return for unfinished rewards, merchant offers or continued local benefits.','system_action'=>'Segments Hunt activity and prepares the relevant retention message or offer.','merchant_value'=>'Businesses keep access to the customer opportunity after the Hunt media attention disappears.','proof'=>'Return engagement, recovered inactive hunters, completed trails and offer conversions.'],
            12 => ['sequence'=>12,'phase'=>'PROVE','kind'=>'analytics','label'=>'Organizer & Merchant ROI','title'=>'Organizer & Merchant ROI — Show Exactly What the Hunt Produced','headline'=>'Finish the Hunt with proof Tom can use to renew sponsors and improve the next campaign.','card'=>'TREASURE HUNT ROI • Hunters enrolled • Verified merchant visits • Cross-business stops • Referrals • Rewards • Comeback redemptions • Return activity.','body'=>'The final module turns campaign activity into an organizer and merchant story. It is intentionally presented as the metrics Places Rewards measures—never as fabricated revenue or results before real campaign data exists.','customer_action'=>'None. This module summarizes the completed customer journey.','system_action'=>'Aggregates measurable activity across the Hunt workflow.','merchant_value'=>'Tom and participating businesses can evaluate performance, improve the next Hunt and strengthen sponsor-renewal conversations.','proof'=>'Enrollment, merchant visits, referrals, redemptions, repeat activity and merchant-level campaign outcomes.'],
        ];
    }

    private function decorate(array $module): array
    {
        $links=$this->nativeLinks()[(int)$module['sequence']] ?? [];
        return array_merge($module,$links);
    }

    private function show(int $sequence): View
    {
        $modules=$this->modules();
        abort_unless(isset($modules[$sequence]),404);
        $module=$this->decorate($modules[$sequence]);
        $module['prev_url']=$sequence>1?$this->routeFor($sequence-1):null;
        $module['next_url']=$sequence<12?$this->routeFor($sequence+1):null;
        return view('demo.treasure-hunt.module',compact('module'));
    }

    public function index(): View
    {
        $modules=array_map(fn(array $m)=>$this->decorate($m),$this->modules());
        return view('demo.treasure-hunt.index',compact('modules'));
    }

    public function loyalty(): View { return $this->show(1); }
    public function stamps(): View { return $this->show(2); }
    public function directory(): View { return $this->show(3); }
    public function reward(): View { return $this->show(4); }
    public function checkin(): View { return $this->show(5); }
    public function prize(): View { return $this->show(6); }
    public function referrals(): View { return $this->show(7); }
    public function vip(): View { return $this->show(8); }
    public function scratch(): View { return $this->show(9); }
    public function voucher(): View { return $this->show(10); }
    public function retention(): View { return $this->show(11); }
    public function analytics(): View { return $this->show(12); }
}
