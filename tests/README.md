# Test Suite

MuchoCore keeps automated checks grouped by purpose so protocol work can grow without filling the top-level tests/ directory.

| Directory | Purpose |
| --- | --- |
| client/ | Client version detection, tracing, real-client contracts and patcher fixtures |
| protocol/ | Protocol matrices, version surfaces, wire-format and compatibility guards |
| security/ | MuchoProtect and anti-bot/security checks |
| application/ | Application/domain contract checks |
| integration/ | End-to-end, smoke, load and hosting checks |
| release/ | Release gates for verified client compatibility |
| client-fixtures/ | Real-client endpoint contracts grouped by Geometry Dash family |

Run focused checks directly from the repository root, for example:

```bash
php tests/protocol/protocol-19-surface.php
php tests/protocol/protocol-19-wire.php
php tests/protocol/protocol-20-surface.php
php tests/protocol/protocol-20-wire.php
php tests/protocol/social-wire.php
php tests/protocol/protocol-21-surface.php
php tests/security/muchoprotect.php
bash tests/client/client-contract.sh
bash tests/release/release-1.9-gate.sh
bash tests/release/release-2.2-gate.sh
```
