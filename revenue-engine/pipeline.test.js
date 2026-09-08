import assert from 'node:assert/strict';
import { RevenueDiscoveryPipeline } from './pipeline.js';

const pipeline = new RevenueDiscoveryPipeline({tier:'pro'});

const records = [
  {
    id:'a', name:'Alpha Dental', averageTicket:450,
    calls:{missedCallsMonthly:18,callConversionRate:0.4,evidence:'call-log'},
    crm:{uncontactedLeadsMonthly:9,leadCloseRate:0.25,dormantCustomers:120,evidence:'crm-export'},
    publicProfile:{reviewRating:3.7,reviewCount:84,reviewUrl:'https://example.test/reviews',websiteUrl:'https://example.test'},
    website:{hasBooking:false,hasContactForm:true,hasLoyalty:false,hasReferral:false,url:'https://example.test'}
  },
  {
    id:'b', name:'Beta Cafe', averageTicket:22,
    publicProfile:{reviewRating:4.8,reviewCount:400,reviewUrl:'https://example.test/beta'}
  }
];

const ranked = pipeline.rank(records);
assert.equal(ranked.length,1);
assert.equal(ranked[0].business.id,'a');
assert.ok(ranked[0].report.totalEstimatedMonthlyLoss > 0);
assert.ok(ranked[0].evidenceQuality.score > 0);
assert.ok(['priority','qualified','nurture','low-priority'].includes(ranked[0].qualification));
console.log('Revenue discovery pipeline tests passed');
