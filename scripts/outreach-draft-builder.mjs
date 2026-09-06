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
  const topLeak = opening.highestPriorityLeak ?? ((merchant.categories ?? []).join(", ") || "a measurable revenue leak");
  const modeled = Number(opening.modeledMonthlyOpportunity ?? merchant.modeledMonthlyOpportunity ?? 0);
  const offerName = offer.name ?? "Revenue Recovery Pilot";
  const kpis = Array.isArray(campaign.kpis) ? campaign.kpis : ["recovered revenue"];
  const demoStory = demo.story ?? "a short measurable recovery workflow";
  const businessName = merchant.businessName ?? "your business";
  const modelLine = modeled > 0
    ? `The current model estimates roughly ${dollars(modeled)} per month of opportunity around this area. That is an estimate to validate with your own baseline, not a guaranteed revenue claim.`
    : "The first step is validating the baseline so any recovery can be measured rather than guessed.";

  return {
    key: merchant.key,
    businessId: merchant.businessId ?? null,
    businessName,
    stage: "draft_ready",
    createdAt: previous?.createdAt ?? new Date().toISOString(),
    updatedAt: new Date().toISOString(),
    sendAuthorized: false,
    recipient: null,
    subject: `${businessName}: one revenue leak worth validating`,
    body: [
      "Hi [Name],",
      "",
      `I found one specific area worth validating in ${businessName}'s customer activity: ${topLeak}.`,
      "",
      modelLine,
      "",
      `Rather than pitch broad marketing, I would show you a short ${offerName} demo: ${demoStory}`,
      "",
      `We would track ${kpis.slice(0, 2).join(" and ")} over a short measurement window, then keep it only if the economics make sense.`,
      "",
      "Would you be open to a short walkthrough?",
      "",
      "Nathan"
    ].join("\n"),
    followUps: [
      {
        sequence: 1,
        waitDays: 3,
        body: `Hi [Name], following up on the ${offerName} idea for ${businessName}. The point is to validate one measurable leak first, not add another complicated marketing system. If useful, I can show the recovery flow in a short walkthrough.`
      },
      {
        sequence: 2,
        waitDays: 7,
        body: `Hi [Name], last note from me on this. I have a simple demo showing how ${businessName} could measure ${kpis[0] ?? "recovered revenue"} against a baseline. If that is not a priority right now, no problem.`
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
      contactMustBeResolvedBeforeAnySend: true,
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
  sendAuthorizedCount: Object.values(drafts).filter(draft => draft.sendAuthorized === true).length,
  drafts
};
await writeJsonAtomic(OUT_FILE, output);
console.log(JSON.stringify({ ok: true, draftCount: output.draftCount, sendAuthorizedCount: output.sendAuthorizedCount }, null, 2));
