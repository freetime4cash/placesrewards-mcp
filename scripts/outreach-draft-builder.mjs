import { promises as fs } from "node:fs";
import path from "node:path";

const ROOT = process.env.PLACESREWARDS_AGENT_ROOT ?? "/home/placevle/placesrewards-agent-server";
const DATA_DIR = path.join(ROOT, "data");
const QUEUE_FILE = path.join(DATA_DIR, "commercial-queue.json");
const OUT_FILE = path.join(DATA_DIR, "outreach-drafts.json");

async function readJson(file, fallback = null) {
  try { return JSON.parse(await fs.readFile(file, "utf8")); }
  catch { return fallback; }
}

async function writeJsonAtomic(file, value) {
  await fs.mkdir(path.dirname(file), { recursive: true });
  const temp = `${file}.tmp`;
  await fs.writeFile(temp, JSON.stringify(value, null, 2) + "\n", "utf8");
  await fs.rename(temp, file);
}

function dollars(value) {
  return `$${Math.round((Number(value) || 0) * 100) / 100}`;
}

function makeDraft(merchant, previous = null) {
  const packet = merchant.commercialPacket ?? {};
  const sales = packet.sales ?? {};
  const campaign = packet.campaign?.campaign ?? {};
  const demo = packet.demo?.demo ?? {};
  const opening = sales.openingProof ?? {};
  const offer = sales.offer ?? {};
  const topLeak = opening.highestPriorityLeak ?? ((merchant.categories ?? []).join(", ") || "a measurable customer-growth opportunity");
  const modeled = Number(opening.modeledMonthlyOpportunity ?? merchant.modeledMonthlyOpportunity ?? 0);
  const offerName = merchant.recommendedEntryOffer ?? offer.name ?? "Revenue Recovery Pilot";
  const kpis = Array.isArray(campaign.kpis) ? campaign.kpis : ["recovered revenue"];
  const demoStory = demo.story ?? "a short measurable recovery workflow";
  const businessName = merchant.businessName ?? "your business";
  const recipient = merchant.publicContact?.email ?? null;
  const modelLine = modeled > 0
    ? `The current model estimates roughly ${dollars(modeled)} per month of opportunity around this area. That is an estimate to validate with your own baseline, not a guaranteed revenue claim.`
    : "I am not assigning a dollar loss from public information. The first step is validating the baseline so any opportunity can be measured rather than guessed.";

  return {
    key: merchant.key,
    businessId: merchant.businessId ?? null,
    businessName,
    stage: recipient ? "addressed_draft_ready" : "contact_resolution_required",
    createdAt: previous?.createdAt ?? new Date().toISOString(),
    updatedAt: new Date().toISOString(),
    sendAuthorized: false,
    recipient,
    contactEvidence: merchant.publicContact?.sourceUrl ?? null,
    subject: `${businessName}: one customer-growth opportunity worth validating`,
    body: [
      `Hi ${businessName} team,`,
      "",
      `I found one specific area worth validating in your public customer journey: ${topLeak}.`,
      "",
      modelLine,
      "",
      `Rather than pitch broad marketing, I would show you a short ${offerName} demo: ${demoStory}`,
      "",
      `We would track ${kpis.slice(0, 2).join(" and ")} over a short measurement window, then keep it only if the economics make sense.`,
      "",
      "Would you be open to a short walkthrough?",
      "",
      "Nathan Newton",
      "Places Rewards",
      "https://placesrewards.com"
    ].join("\n"),
    followUps: [
      {
        sequence: 1,
        waitDays: 3,
        body: `Hi ${businessName} team, following up on the ${offerName} idea. The point is to validate one measurable opportunity first, not add another complicated marketing system. If useful, I can show the recovery flow in a short walkthrough.`
      },
      {
        sequence: 2,
        waitDays: 7,
        body: `Hi ${businessName} team, last note from me on this. I have a simple demo showing how you could measure ${kpis[0] ?? "customer recovery"} against a baseline. If that is not a priority right now, no problem.`
      }
    ],
    attachmentPlan: {
      demoTitle: demo.title ?? `${businessName}: ${offerName}`,
      requiredArtifacts: demo.requiredArtifacts ?? [],
      claimsPolicy: demo.claimsPolicy ?? "Keep observed evidence, modeled opportunity and measured results clearly separated."
    },
    guardrails: {
      notSent: true,
      modeledRevenueIsNotGuaranteed: true,
      verifiedPublicContactRequired: true,
      externalSendRequiresSeparateAuthorizationPath: true
    }
  };
}

await fs.mkdir(DATA_DIR, { recursive: true });
const queue = await readJson(QUEUE_FILE, { merchants: {} });
const previous = await readJson(OUT_FILE, { drafts: {} });
const drafts = {};

for (const merchant of Object.values(queue?.merchants ?? {})) {
  if (merchant?.stage !== "ready_for_outreach_draft") continue;
  drafts[merchant.key] = makeDraft(merchant, previous?.drafts?.[merchant.key] ?? null);
}

const output = {
  schemaVersion: 1,
  generatedAt: new Date().toISOString(),
  draftCount: Object.keys(drafts).length,
  addressedDraftCount: Object.values(drafts).filter(draft => Boolean(draft.recipient)).length,
  contactResolutionRequiredCount: Object.values(drafts).filter(draft => !draft.recipient).length,
  sendAuthorizedCount: Object.values(drafts).filter(draft => draft.sendAuthorized === true).length,
  drafts
};
await writeJsonAtomic(OUT_FILE, output);
console.log(JSON.stringify({ ok: true, draftCount: output.draftCount, addressedDraftCount: output.addressedDraftCount, contactResolutionRequiredCount: output.contactResolutionRequiredCount, sendAuthorizedCount: output.sendAuthorizedCount }, null, 2));
