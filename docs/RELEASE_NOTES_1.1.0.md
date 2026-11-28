# MuchoCore 1.1.0 — Release notes

The [published v1.1.0 release](https://github.com/IZKGMD/GMDmucho-core/releases/tag/v1.1.0) contains the release announcement and downloads. These notes summarize the changes originally prepared for that version.

## What has changed?

MuchoCore 1.1.0 is a major update focused on making a Geometry Dash private server **easier to launch, manage and keep running**, while bringing its backend closer to the new Geometry Dash 2.209 protocol.

### A friendlier control panel
- The administration UI has been reorganized, with an updated styled interface and an optional simplified, CSS-free view.
- Navigation, mobile layouts, access controls and branding have been improved.
- Operators get more direct tools for managing players, levels, clans, ratings, news, client downloads and other server content.

### News that looks like actual news
- News posts can include JPG/PNG images and YouTube links, rather than being limited to plain text.
- Editors can preview drafts and update published posts from the administration panel.
- Media permissions and image processing were tightened to prevent private drafts from being exposed as public resources.

### Geometry Dash 2.209 groundwork
- New backend endpoints cover social features, direct and group message threads, invitations, notifications, moderation cases, editor audio and other 2.209 requests.
- Thread handling now has persisted messages, permissions, pagination and additional native-format serialization tests.
- **Important limitation:** registering all 52 documented new endpoint names does **not** mean 100% native-client compatibility. Some request/response formats and workflows still require genuine 2.209 Windows/Android acceptance tests. Treat these features as staged until certified.

### Easier deployment and client setup
- The product website now provides clearer installation choices and a guided VPS deployment interface.
- Automatic VPS deployment, manual installation and shared-hosting guidance are easier to find.
- **Auto Patch 2.0** expands Windows/Android server-address patching and can regenerate client packages when deployment URLs change.
- A unified client pack includes checksums so downloaded files can be checked for integrity.

### More dependable day-to-day operations
- Revoking a game role also revokes comment-command privileges: a canonical player/moderator role cannot inherit stronger permissions from an outdated legacy role field.
- The administrator client API protects existing game owners from unauthorized demotion and commits role/ban changes together with their audit records. Concurrent owner promotion is checked against the locked, current account state.
- Safer database backup, restore and migration flows, including a migration-adapter foundation.
- Improved update recovery and retry handling for interrupted deployments.
- Better worker-queue, cache and background-job concurrency protections.
- Profile avatars/covers and clan quests are available in the relevant server and MuchoClient source components; separately built clients or launchers are **not** implied to be bundled with this backend release.

## Before installing

1. **Back up your database and deployment configuration.** Test migrations on staging before applying them to a server with active players.
2. The stable installer and updater select the latest published stable release on GitHub.
3. Check the [1.1.0 readiness guide](V110_RELEASE.md), [2.209 validation status](GD_2209_VERIFICATION_GATES.md) and [full changelog](../CHANGELOG.md).
4. Do not assume pending pull requests (including launcher, client and migration proposals) are already included in `main`.

### Release gate

Future stable releases require protocol, security, database, performance and real-client evidence. The [release readiness guide](V110_RELEASE.md) describes the current gate and required distributions.

---

## Коротко по-русски

**MuchoCore 1.1.0** — крупное обновление интерфейса, установки и серверной части:

- **Админка:** удобнее навигация, обновлённый дизайн и облегчённый режим без CSS.
- **News:** изображения, YouTube, черновики и полноценное редактирование публикаций.
- **GD 2.209:** новые серверные маршруты для сообщений, групп, социальных функций и модерации. Полная совместимость с игровым клиентом **пока не подтверждена**.
- **Развёртывание:** понятнее установка на VPS, обновлён Auto Patch 2.0, появились единые клиентские архивы и контрольные суммы.
- **Надёжность:** улучшены обновления, резервные копии, миграция, фоновые задачи и кеширование.

**Скачать 1.1.0** можно со страницы [релиза на GitHub](https://github.com/IZKGMD/GMDmucho-core/releases/tag/v1.1.0). Перед обновлением сделайте резервную копию и проверьте миграции на тестовом стенде.
