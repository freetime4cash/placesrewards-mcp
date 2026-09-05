export function evidenceQuality(signals=[]) {
  if (!signals.length) return { score:0, freshness:0, coverage:0, traceability:0 };
  const now = Date.now();
  const freshness = signals.reduce((sum,s)=>{
    const ageDays = Math.max(0,(now-Date.parse(s.observedAt || 0))/86400000);
    return sum + Math.max(0,1-Math.min(ageDays,90)/90);
  },0)/signals.length;
  const coverage = Math.min(1,new Set(signals.map(s=>s.key)).size/8);
  const traceability = signals.filter(s=>Boolean(s.evidence)).length/signals.length;
  const score = Math.round((freshness*0.35 + coverage*0.35 + traceability*0.30)*10000)/100;
  return {
    score,
    freshness:Math.round(freshness*10000)/100,
    coverage:Math.round(coverage*10000)/100,
    traceability:Math.round(traceability*10000)/100,
  };
}

export function confidenceMultiplier(signals=[]) {
  const q = evidenceQuality(signals).score/100;
  return 0.55 + q*0.45;
}
