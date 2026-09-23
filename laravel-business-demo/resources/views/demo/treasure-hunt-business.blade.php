<!doctype html>
<html><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1">
<title>{{ $demo['business'] ?? 'Business' }} — Places Rewards Demo</title>
<style>
*{box-sizing:border-box}
body{margin:0;background:#f5f7fb;color:#172033;font-family:Arial,sans-serif;font-size:20px;line-height:1.55}
.hero{padding:42px 24px;background:linear-gradient(135deg,#111827,#312e81);color:#fff}
.hero h1{margin:6px 0;font-size:46px;line-height:1.1}
.hero p{font-size:22px}
.wrap{max-width:1280px;margin:auto;padding:28px}
.story{background:#fff;border-radius:18px;padding:28px;border:1px solid #e5e7eb;margin-bottom:22px}
.story b{color:#6c1cff}.story h2{font-size:32px;margin:8px 0}.story p{font-size:21px}
.flow{display:grid;grid-template-columns:repeat(2,minmax(0,1fr));gap:14px;margin:24px 0}
.step{background:#fff;border:2px solid #e5e7eb;border-radius:14px;padding:18px;text-decoration:none;color:#172033;font-size:20px;line-height:1.3}
.step.active{outline:4px solid #6c1cff}.num{font-size:15px;font-weight:900;color:#6c1cff;margin-bottom:5px}
.module{background:#fff;border:1px solid #e5e7eb;border-radius:20px;padding:30px;margin:22px 0}
.module h2{margin:7px 0 12px;font-size:34px;line-height:1.15}
.module p{font-size:21px}
.why{background:#f7f2ff;border-radius:14px;padding:18px;margin:16px 0;font-size:21px}
.shot-frame{overflow-x:auto;overflow-y:hidden;border:2px solid #d8dbe5;border-radius:16px;background:#eef1f7;padding:12px}
.shot-link{display:block;width:max-content;max-width:none}
.shot{display:block;width:1200px;max-width:none;height:auto;border-radius:12px;box-shadow:0 10px 24px #0001}
.zoom-hint{font-size:18px;font-weight:800;margin:10px 0 0;color:#4b5563}
.badge{display:inline-block;background:#ecfdf5;color:#047857;padding:8px 12px;border-radius:999px;font-weight:900;font-size:15px}
.note{background:#fff7ed;padding:17px;border-radius:14px;font-size:20px}
.native-note{background:#e0f2fe;padding:17px;border-radius:14px;font-size:19px;margin-top:12px}
.live-frame{width:100%;height:760px;border:2px solid #d8dbe5;border-radius:16px;background:#fff}
.cta{display:inline-block;background:#6c1cff;color:#fff;text-decoration:none;font-weight:900;border-radius:12px;padding:14px 18px;margin-top:10px;font-size:20px}
@media(max-width:800px){
 body{font-size:19px}.wrap{padding:18px}.hero{padding:30px 18px}.hero h1{font-size:36px}.hero p{font-size:20px}
 .flow{grid-template-columns:1fr}.step{font-size:20px}.module{padding:22px}.module h2{font-size:29px}.story h2{font-size:28px}
 .shot{width:1000px}.zoom-hint{font-size:18px}
}
</style></head>
<body>
<section class="hero"><div class="wrap" style="padding:0"><div style="font-size:12px;font-weight:900;letter-spacing:.1em">PLACES REWARDS • BUSINESS-SPECIFIC DEMO</div><h1>{{ $demo['business'] }}</h1><p>Score {{ $demo['score'] ?? '?' }}/100 @if(!empty($demo['rank'])) · Priority rank #{{ $demo['rank'] }} @endif</p></div></section>
<main class="wrap">
<section class="story">
<b>The simple story</b>
<h2>Visit → Learn → Reward → Return → Refer → Measure</h2>
<p>{{ $demo['simple_why'] ?? '' }}</p>
<div class="note"><strong>Demo safety:</strong> TotalContest remains the real contest engine. Demo campaigns stay isolated from production, and no production customer data is created by the demo interactions.</div>
@if(!empty($demo['native']))<div class="native-note"><strong>Native Places Rewards modules are connected.</strong> Loyalty, scratch, referral and voucher steps are backed by isolated native app records created for this business.</div>@endif
</section>

<nav class="flow">@foreach($modules as $m)<a class="step {{ (int)$active===(int)$m['sequence']?'active':'' }}" href="/demo/business/{{ $demo['slug'] }}/{{ $m['sequence'] }}"><div class="num">STEP {{ $m['sequence'] }}</div><b>{{ $m['title'] }}</b></a>@endforeach</nav>

@foreach($modules as $m)
<section class="module" id="module-{{ $m['sequence'] }}">
<div class="badge">STEP {{ $m['sequence'] }}</div><h2>{{ $m['title'] }}</h2>
<div class="why"><strong>Why this is here:</strong> {{ $m['why'] }}</div>

@if(!empty($m['screenshot']))
<div class="shot-frame"><a class="shot-link" target="_blank" rel="noopener" href="{{ $m['screenshot'] }}"><img class="shot" src="{{ $m['screenshot'] }}" alt="{{ $m['title'] }} TotalContest demo screenshot"></a></div>
<p class="zoom-hint">The contest preview stays large so the text is readable. Scroll sideways on a small screen, or click the image to open it full size.</p>
@endif

@if(empty($m['screenshot']) && !empty($m['preview_url']))
<p class="zoom-hint">Live preview from the real TotalContest demo created for this business.</p>
<iframe class="live-frame" src="{{ $m['preview_url'] }}" title="{{ $m['title'] }} live TotalContest demo" loading="lazy"></iframe>
@endif

@if(!empty($m['preview_url']))
<p><a class="cta" target="_blank" rel="noopener" href="{{ $m['preview_url'] }}">Open This Demo →</a></p>
@else
<p>{{ $m['simple_explanation'] ?? 'Autopilot selects and explains this Places Rewards module based on the business analysis.' }}</p>
<p><a class="cta" target="_blank" rel="noopener" href="/demo/business/{{ $demo['slug'] }}/module/{{ $m['kind'] }}">Open This Demo →</a></p>
@endif
</section>
@endforeach
</main></body></html>