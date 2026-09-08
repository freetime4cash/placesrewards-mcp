import test from 'node:test';
import assert from 'node:assert/strict';
import { RevenueEngineService, entitlementFor } from './index.js';

const base = {
  id:'biz-1', name:'Demo Dental', industry:'dental', averageTicket:250, source:'fixture',
  metrics:{missed_calls_monthly:20, uncontacted_leads_monthly:10, dormant_customers:100, repeat_purchase_gap_monthly:5, review_rating:3.9, review_count:40}
};

test('growth can diagnose but cannot discover',()=>{
  const service=new RevenueEngineService({tier:'growth'});
  const {report}=service.diagnose(base);
  assert.ok(report.leaks.length>=4);
  assert.throws(()=>service.discover([base]),/automatedDiscovery/);
});

test('pro ranks businesses and builds advanced report',()=>{
  const service=new RevenueEngineService({tier:'pro'});
  const ranked=service.discover([base,{...base,id:'biz-2',name:'Low Leak',metrics:{missed_calls_monthly:1}}]);
  assert.equal(ranked[0].business.id,'biz-1');
  const report=service.report(base);
  assert.ok(report.summary.annualOpportunity>0);
  assert.match(report.headline,/annual revenue opportunity/i);
});

test('enterprise monitoring estimates improvement',()=>{
  const service=new RevenueEngineService({tier:'enterprise'});
  const current={...base,metrics:{...base.metrics,missed_calls_monthly:2,uncontacted_leads_monthly:1,dormant_customers:20,repeat_purchase_gap_monthly:1,review_rating:4.5}};
  const comparison=service.monitor(base,current);
  assert.equal(comparison.improved,true);
  assert.ok(comparison.recoveredEstimate>0);
});

test('disabled entitlement has no premium capabilities',()=>{
  const e=entitlementFor('disabled');
  assert.equal(e.enabled,false);
  assert.equal(Object.values(e.capabilities).some(Boolean),false);
});
