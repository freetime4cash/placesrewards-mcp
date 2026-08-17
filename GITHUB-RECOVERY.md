# PlacesRewards Server Runtime Recovery

This repository is the portable server-hosted agent runtime.

Production Laravel application:
`/home/placevle/app.placesrewards.com`

Agent runtime:
`/home/placevle/placesrewards-agent-server`

Important security rule:
Never commit `.env`, API keys, server-control tokens, snapshots, backups, reports,
logs, or production database data.

After cloning to a replacement server:
1. configure the cPanel Node application environment variables;
2. configure `PLACESREWARDS_AGENT_KEY`;
3. configure `PLACESREWARDS_API_URL=https://app.placesrewards.com/api/agent/v1`;
4. run the Node application;
5. install the 5-minute worker cron using `run-worker-cron.sh`.

The live Laravel source is protected separately by sanitized compressed snapshots.
