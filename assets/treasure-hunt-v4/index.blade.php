<!doctype html>
<html lang="en">
<head>
<meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1">
<title>Northeast Ohio Treasure Hunt — Places Rewards Demo</title>
<style>
*{box-sizing:border-box}body{margin:0;font-family:Inter,system-ui,sans-serif;background:#f5f8fb;color:#13233a}.hero{background:linear-gradient(135deg,#071a31,#0e6248);color:#fff;padding:42px 22px}.wrap{max-width:1220px;margin:auto}.hero h1{font-size:44px;line-height:1.05;margin:8px 0 12px}.hero p{max-width:930px;line-height:1.65;color:#dfeee8}.grid{display:grid;grid-template-columns:repeat(2,1fr);gap:22px;padding:32px 20px 70px}.card{background:#fff;border:1px solid #dbe5ee;border-radius:20px;padding:24px;box-shadow:0 12px 32px #0b1f3a0c;display:flex;flex-direction:column}.topline{display:flex;align-items:center;gap:9px}.num{display:inline-grid;place-items:center;width:46px;height:46px;border-radius:50%;background:#0b1f3a;color:#fff;font-weight:950;font-size:18px}.phase{font-size:11px;font-weight:950;letter-spacing:.13em;color:#0f9d67}.card h2{font-size:24px;line-height:1.15;margin:16px 0 7px}.headline{color:#475569;line-height:1.5;font-weight:720;margin-bottom:15px}.cardcopy{background:#eef8f3;border-left:5px solid #0f9d67;border-radius:10px;padding:14px 15px;font-weight:850;line-height:1.45;margin-bottom:16px}.detail{display:grid;grid-template-columns:1fr 1fr;gap:10px;margin-bottom:16px}.mini{border:1px solid #e1e8ef;border-radius:12px;padding:12px;background:#fbfdfe}.mini b{display:block;font-size:10px;letter-spacing:.09em;text-transform:uppercase;color:#0f9d67;margin-bottom:5px}.mini span{font-size:13px;line-height:1.45;color:#526173}.proof{border-top:1px solid #e5ebf1;padding-top:12px;margin-top:auto;color:#64748b;font-size:13px;line-height:1.5}.proof b{color:#17324a}.btn{display:inline-block;text-decoration:none;background:#0f9d67;color:#fff;font-weight:900;padding:11px 14px;border-radius:10px;margin-top:15px;align-self:flex-start}@media(max-width:900px){.grid{grid-template-columns:1fr}}@media(max-width:620px){.hero h1{font-size:34px}.detail{grid-template-columns:1fr}}
</style></head><body>
<header class="hero"><div class="wrap"><div style="font-weight:900;letter-spacing:.12em;color:#9ae6c0">PLACES REWARDS • NORTHEAST OHIO TREASURE HUNT</div><h1>12 modules. 12 different jobs in the customer journey.</h1><p>Every card below now contains the actual Treasure Hunt-specific content Tom and the participating businesses need to see: the offer or action on the card, what the hunter does, what Places Rewards does, why the merchant benefits, and what the system measures.</p></div></header>
<main class="wrap grid">
@php $paths=[1=>'loyalty',2=>'stamps',3=>'directory',4=>'reward',5=>'check-in',6=>'prize',7=>'referrals',8=>'vip',9=>'scratch',10=>'voucher',11=>'retention',12=>'analytics']; @endphp
@foreach($modules as $m)
<article class="card">
  <div class="topline"><span class="num">{{ str_pad($m['sequence'],2,'0',STR_PAD_LEFT) }}</span><span class="phase">{{ $m['phase'] }}</span></div>
  <h2>{{ $m['label'] }}</h2>
  <div class="headline">{{ $m['headline'] }}</div>
  <div class="cardcopy">{{ $m['card'] }}</div>
  <div class="detail">
    <div class="mini"><b>Hunter does</b><span>{{ $m['customer_action'] }}</span></div>
    <div class="mini"><b>Places Rewards does</b><span>{{ $m['system_action'] }}</span></div>
    <div class="mini"><b>Business benefit</b><span>{{ $m['merchant_value'] }}</span></div>
    <div class="mini"><b>Measured result</b><span>{{ $m['proof'] }}</span></div>
  </div>
  <div class="proof"><b>Why this card exists:</b> {{ $m['body'] }}</div>
  <a class="btn" href="/demo/treasure-hunt/{{ $paths[$m['sequence']] }}">Open Module {{ str_pad($m['sequence'],2,'0',STR_PAD_LEFT) }} →</a>
</article>
@endforeach
</main></body></html>
