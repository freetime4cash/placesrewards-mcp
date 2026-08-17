PlacesRewards Apply Test Bootstrap Repair v0.6.3

This package automatically locates the newest isolated proposal whose manifest
type is:

test-bootstrap-repair

It does not require you to copy or type the proposal ID.

Upload to:

/home/placevle/placesrewards-agent-server/

Extract there.

Run ONE command:

bash /home/placevle/placesrewards-agent-server/Apply-Test-Bootstrap-Repair.sh

The script will:
- locate the latest bootstrap repair proposal
- show the test files to be changed
- require explicit APPLY approval
- create timestamped backups
- apply the test-only patch
- run ConversionsTest and StampServiceTest
- auto-rollback if either targeted test file still fails
- if targeted tests pass, run the full suite to expose the next remaining issue
- leave UpdateServicePhpBinaryTest for a separate minimal repair if needed
- never run migrations or touch .env
