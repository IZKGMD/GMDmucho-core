# Creator Hub

Built-in module `creator-hub`, enabled with MuchoCore plugins. Requires MuchoClient 1.1.0. Apply migrations with the normal core deployment workflow.

Creators select public published levels in My Levels, submit a message and track review in My Requests. Requests preserve the submitted content SHA-256, level version, reviewer assignment and append-only decision history. Updated levels can be resubmitted as another cycle. Active duplicates return the original request. Three active requests and five submissions per 24 hours are allowed; terminal requests have a 24-hour resubmission cooldown. Changes-requested levels must change before resubmission.

Game moderators, elder moderators and owners can claim review work, request changes, recommend or reject with feedback. They cannot review their own level. Decisions are fenced by request revision and submitted level hash. Rating remains the existing Rating Studio operation; a recommendation does not assign stars. The request also displays native stars assigned afterwards.

Admin → Creator Hub uses `moderation.manage`, existing session authentication and CSRF. Admin/game identities are distinct in the history. An administrator can return abandoned reviews to the queue. Authors can withdraw open requests. Moderator queues and other creators' request details are access-controlled.

Authenticated POST `/extensions/creator-hub` accepts `accountID`, `gjp2`, `gameVersion=22` and action: list (view mine/levels/queue/archive, page), detail (requestID), submit (levelID, note), claim/release/withdraw (requestID, revision), decide (requestID, revision, state, note). Server responses include schema_version=1, ok and data.

Tests use isolated MariaDB databases and real authenticated requests. They cover ownership, private levels, idempotent submission, assignment, self-review rejection, stale versions, content changes, history, withdrawal, archive, cooldown and parallel quota enforcement.
