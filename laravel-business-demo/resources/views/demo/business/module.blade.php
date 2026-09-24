<!doctype html>
<html>
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width,initial-scale=1">
<title>{{ $module['title'] }} — {{ $demo['business'] }} Demo</title>
<style>
*{box-sizing:border-box}
body{margin:0;background:#f5f7fb;color:#172033;font-family:Arial,sans-serif;font-size:20px;line-height:1.55}
.hero{background:linear-gradient(135deg,#111827,#312e81);color:#fff;padding:38px 24px}
.wrap{max-width:1080px;margin:auto;padding:28px}
.hero h1{font-size:44px;line-height:1.1;margin:7px 0}
.hero p{font-size:22px;margin:4px 0}
.badge{display:inline-block;background:#ecfdf5;color:#047857;padding:8px 12px;border-radius:999px;font-weight:900;font-size:15px}
.native-badge{display:inline-block;background:#e0f2fe;color:#075985;padding:7px 10px;border-radius:999px;font-size:14px;font-weight:900;margin-left:6px}
.card{background:#fff;border:1px solid #e5e7eb;border-radius:20px;padding:30px;margin:22px 0}
.card h2{font-size:34px;line-height:1.15;margin:4px 0 14px}
.why{background:#f7f2ff;border-radius:14px;padding:18px;font-size:21px}
.notice{background:#fff7ed;border-radius:14px;padding:16px}
.btn{display:inline-block;border:0;background:#6c1cff;color:#fff;padding:14px 20px;border-radius:12px;font-size:20px;font-weight:900;text-decoration:none;cursor:pointer}
.btn.secondary{background:#111827}
.stamps{display:grid;grid-template-columns:repeat(4,1fr);gap:14px;max-width:620px;margin:22px 0}
.stamp{aspect-ratio:1;border-radius:50%;border:4px dashed #6c1cff;display:flex;align-items:center;justify-content:center;font-weight:900;font-size:25px;background:#fff}
.stamp.filled{background:#6c1cff;color:#fff;border-style:solid}
.scratch{position:relative;max-width:640px;height:270px;border-radius:18px;overflow:hidden;background:linear-gradient(135deg,#f59e0b,#f97316);display:flex;align-items:center;justify-content:center;text-align:center;margin:18px 0}
.prize{font-size:30px;font-weight:900;color:#fff;padding:20px}
.cover{position:absolute;inset:0;background:linear-gradient(135deg,#6c1cff,#312e81);display:flex;align-items:center;justify-content:center;color:#fff;font-size:31px;font-weight:900;cursor:pointer;padding:20px}
.referral-code{font-size:28px;font-weight:900;background:#f3f4f6;padding:18px;border-radius:12px;display:inline-block}
.voucher{border:3px dashed #6c1cff;border-radius:18px;padding:26px;max-width:720px}
.voucher h3{font-size:34px;margin:0 0 8px}
.metric-grid{display:grid;grid-template-columns:repeat(3,1fr);gap:14px}
.metric{background:#f8fafc;border-radius:14px;padding:18px}
.metric b{font-size:30px;display:block}
.plan{border:3px solid #6c1cff;border-radius:18px;padding:24px;max-width:700px}
.plan .price{font-size:42px;font-weight:900}
.qr{width:240px;height:240px;background:repeating-conic-gradient(#111 0 25%,#fff 0 50%) 0/28px 28px;border:14px solid #fff;box-shadow:0 0 0 2px #111;margin:22px 0}
.module-visual{margin:22px 0;border-radius:18px;overflow:hidden;border:1px solid #e5e7eb;background:#fff}.module-visual img{display:block;width:100%;height:auto;max-height:675px;object-fit:contain}
ul{font-size:21px}
@media(max-width:700px){
  body{font-size:19px}.wrap{padding:18px}.hero{padding:30px 18px}.hero h1{font-size:34px}
  .metric-grid{grid-template-columns:1fr}.stamps{grid-template-columns:repeat(3,1fr)}
}
</style>
</head>
<body>
<section class="hero">
  <div class="wrap" style="padding:0">
    <div class="badge">DEMO STEP {{ $module['sequence'] }}</div>
    @if(!empty($demo['native']))<span class="native-badge">NATIVE APP BACKED</span>@endif
    <h1>{{ $module['title'] }}</h1>
    <p>{{ $demo['business'] }}</p>
  </div>
</section>

<main class="wrap">
  <section class="card">
    <div class="why"><strong>Why this step exists:</strong> {{ $module['why'] }}</div>
  </section>
  @php($visualKinds=['snapshot','enrollment','loyalty','scratch','referral','voucher','analytics','plan'])
  @if(in_array($module['kind'],$visualKinds,true))
    @php($moduleImage='demo-'.$demo['slug'].'-'.$module['kind'].'.png')
    <div class="module-visual"><img src="/{{ $moduleImage }}?v={{ @filemtime(public_path($moduleImage)) ?: 1 }}" alt="{{ $demo['business'] }} {{ $module['title'] }} visual" onerror="this.parentElement.style.display='none'"></div>
  @endif

  @if($module['kind']==='snapshot')
    <section class="card">
      <h2>Business Snapshot</h2>
      <p><strong>Priority score:</strong> {{ $demo['score'] ?? '?' }}/100 @if(!empty($demo['rank'])) · Rank #{{ $demo['rank'] }} @endif</p>
      <p>{{ $demo['simple_why'] ?? '' }}</p>
      <div class="notice"><strong>Important:</strong> this is a sales analysis. Revenue, traffic, and operational opportunities are hypotheses until verified with the business owner.</div>
    </section>

  @elseif($module['kind']==='enrollment')
    <section class="card">
      <h2>Join & QR Enrollment Demo</h2>
      <p>A customer scans the business QR code and joins the Places Rewards experience.</p>
      <div class="qr" aria-label="Demo QR visual"></div>
      <button class="btn" onclick="document.getElementById('join-status').textContent='Demo enrollment simulated. No production customer record was created.'">Simulate QR Enrollment</button>
      @if(!empty($native['stamp_enroll_url'] ?? null))
        <a class="btn secondary" target="_blank" rel="noopener" href="{{ $native['stamp_enroll_url'] }}">Open Native Enrollment Flow</a>
      @endif
      <p id="join-status" style="font-weight:900"></p>
    </section>

  @elseif($module['kind']==='loyalty')
    <section class="card">
      <h2>{{ $demo['business'] }} Loyalty Card</h2>
      <p><strong>Fallback demo:</strong> complete visits to unlock a reward. New Autopilot demos open the real native stamp-card record for this step.</p>
      <div id="stamps" class="stamps">
        @for($i=1;$i<=10;$i++)<div class="stamp" data-i="{{ $i }}">{{ $i }}</div>@endfor
      </div>
      <button class="btn" onclick="addStamp()">Add Demo Stamp</button>
      <p id="stamp-status"><strong>0 of 10 visits recorded.</strong></p>
    </section>
    <script>
      let stampCount=0;
      function addStamp(){
        if(stampCount<10){stampCount++;document.querySelector('.stamp[data-i="'+stampCount+'"]').classList.add('filled');}
        document.getElementById('stamp-status').innerHTML='<strong>'+stampCount+' of 10 visits recorded.</strong>'+(stampCount===10?' Reward unlocked!':'');
      }
    </script>

  @elseif($module['kind']==='scratch')
    @php($game=$nativeData['scratch_game'] ?? null)
    <section class="card">
      <h2>{{ $game?->name ?? 'Mystery / Scratch Reward Demo' }}</h2>
      @if($game)
        <p><span class="native-badge">REAL SCRATCH GAME RECORD</span></p>
        <p>{{ $game->description }}</p>
        <p><strong>Configured demo win rate:</strong> {{ (int)$game->win_rate }}%</p>
      @else
        <p>The customer reveals a surprise after an eligible visit or action.</p>
      @endif
      <div class="scratch">
        <div class="prize">Demo reveal complete!</div>
        <div class="cover" onclick="this.style.display='none'">TAP TO REVEAL</div>
      </div>
      <p class="notice"><strong>Demo only.</strong> This reveal does not issue or play a production member scratch card.</p>
    </section>

  @elseif($module['kind']==='referral')
    @php($ref=$nativeData['referral'] ?? null)
    <section class="card">
      <h2>{{ $ref?->name ?? 'Referral Reward Demo' }}</h2>
      @if($ref)
        <p><span class="native-badge">REAL REFERRAL CAMPAIGN RECORD</span></p>
        <p>{{ $ref->description }}</p>
        <div class="referral-code">Referrer: {{ (int)$ref->referrer_points }} pts · Friend: {{ (int)$ref->referee_points }} pts</div>
      @else
        <p>A happy customer gets a simple code or link to bring in a friend.</p>
      @endif
      <p><button class="btn" onclick="document.getElementById('ref-status').textContent='Demo referral simulated. No production referral, points, or member record was created.'">Simulate Friend Joining</button></p>
      <p id="ref-status" style="font-weight:900"></p>
    </section>

  @elseif($module['kind']==='voucher')
    <section class="card">
      <h2>Targeted Comeback Offer Demo</h2>
      <div class="voucher">
        <h3>Come Back Offer</h3>
        <p>New Autopilot demos open the real isolated native voucher record for this step.</p>
      </div>
    </section>

  @elseif($module['kind']==='analytics')
    @php($a=$nativeData['analytics'] ?? null)
    <section class="card">
      <h2>Analytics & Customer Insights Demo</h2>
      @if($a)
        <p class="notice"><strong>These are the current real activity counters from this isolated demo workspace.</strong> Zero means the demo asset exists but has not generated demo activity yet.</p>
        <div class="metric-grid">
          <div class="metric"><span>Stamps issued</span><b>{{ $a['stamps_issued'] }}</b></div>
          <div class="metric"><span>Stamp completions</span><b>{{ $a['stamp_completions'] }}</b></div>
          <div class="metric"><span>Scratch cards issued</span><b>{{ $a['scratch_issued'] }}</b></div>
          <div class="metric"><span>Scratch cards played</span><b>{{ $a['scratch_played'] }}</b></div>
          <div class="metric"><span>Voucher views</span><b>{{ $a['voucher_views'] }}</b></div>
          <div class="metric"><span>Voucher uses</span><b>{{ $a['voucher_uses'] }}</b></div>
        </div>
      @else
        <p class="notice"><strong>Demo analytics are unavailable for this older manifest.</strong></p>
      @endif
      <p><strong>Simple meaning:</strong> this lets the owner see what customers actually did instead of guessing.</p>
    </section>

  @elseif($module['kind']==='plan')
    <section class="card">
      <h2>Recommended Starting Plan</h2>
      <div class="plan">
        <h3>{{ $demo['recommended_plan']['name'] ?? 'Growth' }}</h3>
        @if(isset($demo['recommended_plan']['monthly']))<div class="price">${{ $demo['recommended_plan']['monthly'] }}/month</div>@endif
        @if(isset($demo['recommended_plan']['setup']))<p>Setup starts at ${{ $demo['recommended_plan']['setup'] }}.</p>@endif
        <p>{{ $demo['recommended_plan']['why'] ?? '' }}</p>
      </div>
      <p class="notice">This is the recommended demo starting point. Final configuration can change if the business needs more complex implementation.</p>
    </section>

  @else
    <section class="card"><h2>{{ $module['title'] }}</h2><p>{{ $module['simple_explanation'] ?? 'This module is part of the Places Rewards demonstration.' }}</p></section>
  @endif

  <p><a class="btn secondary" href="/demo/business/{{ $demo['slug'] }}">← Back to full 10-step demo</a></p>
</main>
</body>
</html>