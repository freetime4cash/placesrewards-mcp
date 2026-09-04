export class RevenueEvidenceAdapter {
  constructor(name) { this.name = name; }
  supports(_record) { return false; }
  extract(_record) { return []; }
}

export class PublicProfileAdapter extends RevenueEvidenceAdapter {
  constructor() { super('public-profile'); }
  supports(record) { return Boolean(record?.publicProfile); }
  extract(record) {
    const p = record.publicProfile || {};
    const observedAt = p.observedAt || new Date().toISOString();
    const source = p.source || this.name;
    const signals = [];
    const push = (key, value, evidence) => {
      if (value === undefined || value === null) return;
      signals.push({ source, key, value, observedAt, evidence });
    };
    push('review_rating', p.reviewRating, p.reviewUrl);
    push('review_count', p.reviewCount, p.reviewUrl);
    push('website_has_booking', Boolean(p.websiteHasBooking), p.websiteUrl);
    push('website_has_contact_form', Boolean(p.websiteHasContactForm), p.websiteUrl);
    push('website_has_loyalty', Boolean(p.websiteHasLoyalty), p.websiteUrl);
    push('website_has_referral', Boolean(p.websiteHasReferral), p.websiteUrl);
    push('response_time_hours', p.responseTimeHours, p.responseEvidence);
    push('social_inactivity_days', p.socialInactivityDays, p.socialUrl);
    return signals;
  }
}

export class CRMAdapter extends RevenueEvidenceAdapter {
  constructor() { super('crm'); }
  supports(record) { return Boolean(record?.crm); }
  extract(record) {
    const c = record.crm || {};
    const observedAt = c.observedAt || new Date().toISOString();
    const source = c.source || this.name;
    const map = {
      uncontactedLeadsMonthly: 'uncontacted_leads_monthly',
      leadCloseRate: 'lead_close_rate',
      dormantCustomers: 'dormant_customers',
      reactivationRate: 'reactivation_rate',
      repeatPurchaseGapMonthly: 'repeat_purchase_gap_monthly',
      averageTicket: 'average_ticket',
    };
    return Object.entries(map).flatMap(([input,key]) => {
      const value = c[input];
      return value === undefined ? [] : [{ source, key, value, observedAt, evidence:c.evidence }];
    });
  }
}

export class CallActivityAdapter extends RevenueEvidenceAdapter {
  constructor() { super('call-activity'); }
  supports(record) { return Boolean(record?.calls); }
  extract(record) {
    const c = record.calls || {};
    const observedAt = c.observedAt || new Date().toISOString();
    const source = c.source || this.name;
    return [
      ['missed_calls_monthly', c.missedCallsMonthly, c.evidence],
      ['call_conversion_rate', c.callConversionRate, c.evidence],
    ].flatMap(([key,value,evidence]) => value === undefined ? [] : [{ source, key, value, observedAt, evidence }]);
  }
}

export class WebsiteConversionAdapter extends RevenueEvidenceAdapter {
  constructor() { super('website-conversion'); }
  supports(record) { return Boolean(record?.website); }
  extract(record) {
    const w = record.website || {};
    const observedAt = w.observedAt || new Date().toISOString();
    const source = w.source || this.name;
    const out = [];
    const push = (key,value,evidence=w.url) => { if (value !== undefined) out.push({source,key,value,observedAt,evidence}); };
    push('website_has_booking', Boolean(w.hasBooking));
    push('website_has_contact_form', Boolean(w.hasContactForm));
    push('website_has_loyalty', Boolean(w.hasLoyalty));
    push('website_has_referral', Boolean(w.hasReferral));
    push('website_load_seconds', w.loadSeconds);
    push('website_mobile_score', w.mobileScore);
    return out;
  }
}

export class RevenueSignalAggregator {
  constructor(adapters=[new PublicProfileAdapter(),new CRMAdapter(),new CallActivityAdapter(),new WebsiteConversionAdapter()]) {
    this.adapters = adapters;
  }
  enrich(record) {
    const providerSignals = this.adapters.filter(a=>a.supports(record)).flatMap(a=>a.extract(record));
    const metricSignals = Object.entries(record.metrics || {}).flatMap(([key,value]) =>
      ['number','string','boolean'].includes(typeof value)
        ? [{source:record.source || 'generic',key,value,observedAt:record.observedAt || new Date().toISOString()}]
        : []
    );
    const latest = new Map();
    for (const signal of [...metricSignals,...providerSignals]) {
      const k = `${signal.key}`;
      const prior = latest.get(k);
      if (!prior || signal.observedAt >= prior.observedAt) latest.set(k, signal);
    }
    return {...record, signals:[...latest.values()]};
  }
}
