# MuchoCore, MuchoProtect, and the private builder boundary

Status: project policy / technical planning note (13 November 2026).

## Ownership and credit

The original MuchoCore project and its MuchoProtect protection subsystem are credited to **IZK, © 2026**, subject to the rights of individual contributors and third-party components. This is a copyright/attribution notice, **not** an assertion of registered trademark rights. Geometry Dash and RobTop Games intellectual property remains with its owners.

Public website terms: https://muchogdps.space/legal/terms/

## Current license: MIT

The repository is **public** and its `LICENSE` and `composer.json` identify the project as MIT-licensed. MIT expressly permits use, modification, and redistribution, subject to retaining required license and copyright notices. The terms of use for the *website/service* do not revoke those permissions. Previously published copies/forks continue to exist even if future licensing or repository visibility changes.

Do not add conflicting phrases such as "forking prohibited", "modifications forbidden", or "all rights reserved" to already-MIT-licensed source files or binaries without clear version and rights analysis.

## Builder confidentiality: actual state

MuchoCore currently has publicly tracked build and packaging tools, including:
- `.github/workflows/build-muchoclient.yml`;
- `.github/workflows/build-vendor-archive.yml`;
- `tools/release/build-php7-core-pack.php`;
- `patchers/` and client patchers.

**These are not closed** merely because an admin page is access-controlled or a website displays a legal notice. Git history, published release archives, and existing forks may also contain these tools.

For any **future owner-only builder implementation**:
1. Develop and store new proprietary builder code in an **access-controlled private repository** or private build host. Do not commit it to this public repository, its release bundles, or public CI job logs/artifacts.
2. Keep builder credentials, signing keys, and deployment secrets in the private system, not in clients, JavaScript bundles, GitHub workflow text, public container images, or frontend endpoints.
3. Publish only signed/verified output artifacts or a narrowly scoped authenticated build API, if the owner chooses. Use origin validation, authorization, quotas, and audit logging; never rely on obscurity.
4. Have a build/release checklist audit tracked and archived files and public workflows for unintended source/secret disclosure.
5. Keep the documented self-hosted MuchoCore runtime installable and operational. Moving existing public tools into a private builder requires a compatibility/migration plan and cannot erase copies already distributed.

This policy is **not confirmation that a private builder has already been created**.

## Legal release gate

Before making a "terms accepted" claim:
- Verify that the user actually sees the current terms and takes a meaningful acceptance action where required.
- Provide a separate, accurate privacy notice for the real operator (data collected, purpose, legal basis, retention, recipients, user rights, contact channel) before handling public accounts at scale.
- Have a qualified local lawyer review service terms and operator duties. Disclaimers cannot override mandatory law or guarantee immunity from claims.
