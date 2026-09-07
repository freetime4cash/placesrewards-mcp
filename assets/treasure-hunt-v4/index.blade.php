<!doctype html>
<html lang="en">
<head>
<meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1">
<title>Northeast Ohio Treasure Hunt — Places Rewards Demo</title>
<style>
body{margin:0;font-family:Inter,system-ui,sans-serif;background:#f5f8fb;color:#13233a}.hero{background:linear-gradient(135deg,#071a31,#0e6248);color:#fff;padding:42px 22px}.wrap{max-width:1180px;margin:auto}.hero h1{font-size:44px;line-height:1.05;margin:8px 0 12px}.hero p{max-width:850px;line-height:1.6;color:#dfeee8}.grid{display:grid;grid-template-columns:repeat(3,1fr);gap:18px;padding:30px 20px 60px}.card{background:#fff;border:1px solid #dde6ef;border-radius:18px;padding:22px;box-shadow:0 10px 30px #0b1f3a0c}.num{display:inline-grid;place-items:center;width:42px;height:42px;border-radius:50%;background:#0b1f3a;color:#fff;font-weight:900}.phase{font-size:11px;font-weight:900;letter-spacing:.12em;color:#0f9d67;margin-left:8px}.card h2{font-size:21px;margin:14px 0 8px}.card p{color:#64748b;line-height:1.55;min-height:72px}.btn{display:inline-block;text-decoration:none;background:#0f9d67;color:#fff;font-weight:900;padding:11px 14px;border-radius:10px}@media(max-width:900px){.grid{grid-template-columns:1fr 1fr}}@media(max-width:620px){.grid{grid-template-columns:1fr}.hero h1{font-size:34px}}
</style></head><body>
<header class="hero"><div class="wrap"><div style="font-weight:900;letter-spacing:.12em;color:#9ae6c0">PLACES REWARDS • TREASURE HUNT DEMO</div><h1>One workflow. Twelve different modules.</h1><p>Every step below opens a separate Treasure Hunt module with its own purpose, card content, customer action and merchant value. These are not twelve links to the same presentation page.</p></div></header>
<main class="wrap grid">
@php $paths=[1=>'loyalty',2=>'stamps',3=>'directory',4=>'reward',5=>'check-in',6=>'prize',7=>'referrals',8=>'vip',9=>'scratch',10=>'voucher',11=>'retention',12=>'analytics']; @endphp
@foreach($modules as $m)
<div class="card"><span class="num">{{ str_pad($m['sequence'],2,'0',STR_PAD_LEFT) }}</span><span class="phase">{{ $m['phase'] }}</span><h2>{{ $m['label'] }}</h2><p>{{ $m['headline'] }}</p><a class="btn" href="/demo/treasure-hunt/{{ $paths[$m['sequence']] }}">Open Module {{ str_pad($m['sequence'],2,'0',STR_PAD_LEFT) }} →</a></div>
@endforeach
</main></body></html>
