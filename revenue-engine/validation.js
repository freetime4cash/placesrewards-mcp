import { ensure } from './errors.js';

export function object(value, label = 'body') {
  ensure(value !== null && typeof value === 'object' && !Array.isArray(value), 'VALIDATION', `${label} must be an object`);
  return value;
}
export function fields(value, allowed, required = []) {
  object(value);
  ensure(Object.keys(value).every(key => allowed.includes(key)), 'VALIDATION', 'Unknown field');
  for (const key of required) ensure(Object.hasOwn(value, key), 'VALIDATION', `Missing ${key}`);
  return value;
}
export function string(value, label, max = 200) {
  ensure(typeof value === 'string' && value.trim().length > 0 && value.length <= max, 'VALIDATION', `${label} must be a nonempty string (max ${max})`);
  return value;
}
export function number(value, label, min = 0, max = 1e9) {
  ensure(typeof value === 'number' && Number.isFinite(value) && value >= min && value <= max, 'VALIDATION', `${label} must be between ${min} and ${max}`);
  return value;
}
export function choice(value, values, label) {
  ensure(values.includes(value), 'VALIDATION', `Invalid ${label}`);
  return value;
}
export function timestamp(value, label) {
  string(value, label, 40);
  ensure(/^\d{4}-\d\d-\d\dT/.test(value) && Number.isFinite(Date.parse(value)), 'VALIDATION', `Invalid ${label}`);
  return new Date(value).toISOString();
}
export function identifier(value, label = 'id') {
  string(value, label, 160);
  ensure(/^[a-zA-Z0-9][a-zA-Z0-9_.:@-]*$/.test(value), 'VALIDATION', `Invalid ${label}`);
  return value;
}

const metricBounds = {
  missed_calls_monthly: [0, 1e7], call_conversion_rate: [0, 1],
  uncontacted_leads_monthly: [0, 1e7], lead_close_rate: [0, 1],
  dormant_customers: [0, 1e7], reactivation_rate: [0, 1],
  repeat_purchase_gap_monthly: [0, 1e7], average_ticket: [0, 1e6],
  review_rating: [0, 5], review_count: [0, 1e7], response_time_hours: [0, 1e6],
  social_inactivity_days: [0, 1e6], website_load_seconds: [0, 1e5], website_mobile_score: [0, 100],
};
const booleanMetrics = ['website_has_booking', 'website_has_contact_form', 'website_has_loyalty', 'website_has_referral'];
export function metric(key, value) {
  if (booleanMetrics.includes(key)) ensure(typeof value === 'boolean', 'VALIDATION', `${key} must be boolean`);
  else {
    ensure(Object.hasOwn(metricBounds, key), 'VALIDATION', `Unsupported metric ${key}`);
    number(value, key, ...metricBounds[key]);
  }
}
const mappings = {
  calls: { missedCallsMonthly: 'missed_calls_monthly', callConversionRate: 'call_conversion_rate' },
  crm: { uncontactedLeadsMonthly: 'uncontacted_leads_monthly', leadCloseRate: 'lead_close_rate', dormantCustomers: 'dormant_customers', reactivationRate: 'reactivation_rate', repeatPurchaseGapMonthly: 'repeat_purchase_gap_monthly', averageTicket: 'average_ticket' },
  publicProfile: { reviewRating: 'review_rating', reviewCount: 'review_count', websiteHasBooking: 'website_has_booking', websiteHasContactForm: 'website_has_contact_form', websiteHasLoyalty: 'website_has_loyalty', websiteHasReferral: 'website_has_referral', responseTimeHours: 'response_time_hours', socialInactivityDays: 'social_inactivity_days' },
  website: { hasBooking: 'website_has_booking', hasContactForm: 'website_has_contact_form', hasLoyalty: 'website_has_loyalty', hasReferral: 'website_has_referral', loadSeconds: 'website_load_seconds', mobileScore: 'website_mobile_score' },
};
const metadata = ['observedAt', 'source', 'evidence', 'reviewUrl', 'websiteUrl', 'responseEvidence', 'socialUrl', 'url'];
function validateMetadata(record, now) {
  for (const key of metadata) if (record[key] !== undefined) {
    if (key === 'observedAt') {
      record[key] = timestamp(record[key], key);
      ensure(Date.parse(record[key]) <= now + 60_000, 'VALIDATION', 'Evidence cannot be in the future');
    } else string(record[key], key, 2000);
  }
}
export function businessRecord(input, now = Date.now()) {
  const record = structuredClone(input);
  fields(record, ['id', 'name', 'industry', 'averageTicket', 'monthlyRevenue', 'metrics', 'source', 'observedAt', ...Object.keys(mappings)], ['id', 'name']);
  identifier(record.id, 'business id');
  string(record.name, 'name');
  if (record.industry !== undefined) string(record.industry, 'industry');
  if (record.averageTicket !== undefined) number(record.averageTicket, 'averageTicket', 0, 1e6);
  if (record.monthlyRevenue !== undefined) number(record.monthlyRevenue, 'monthlyRevenue');
  validateMetadata(record, now);
  if (record.metrics !== undefined) {
    object(record.metrics, 'metrics');
    for (const [key, value] of Object.entries(record.metrics)) metric(key, value);
  }
  for (const [section, mapping] of Object.entries(mappings)) if (record[section] !== undefined) {
    fields(record[section], [...Object.keys(mapping), ...metadata]);
    validateMetadata(record[section], now);
    for (const [key, value] of Object.entries(record[section])) if (Object.hasOwn(mapping, key)) metric(mapping[key], value);
  }
  return record;
}
