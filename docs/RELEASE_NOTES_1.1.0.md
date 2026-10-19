# MuchoCore 1.1.0 — Release notes

> **Status: release preparation, not a published stable version.** The `main` branch identifies itself as 1.1.0, but the last published stable GitHub release is 1.0.9. Do not announce 1.1.0 as stable until its endpoint-certification gate passes and both release archives are published successfully.

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
- Safer database backup, restore and migration flows, including a migration-adapter foundation.
- Improved update recovery and retry handling for interrupted deployments.
- Better worker-queue, cache and background-job concurrency protections.
- Profile avatars/covers and clan quests are available in the relevant server and MuchoClient source components; separately built clients or launchers are **not** implied to be bundled with this backend release.

## Before installing

1. **Back up your database and deployment configuration.** Test migrations on staging before applying them to a server with active players.
2. The stable installation/update channel still points to **v1.0.9** until a new official release is published. The current `main` branch is development source, not a stable release.
3. Check the [1.1.0 readiness guide](V110_RELEASE.md), [2.209 validation status](GD_2209_VERIFICATION_GATES.md) and [full changelog](../CHANGELOG.md).
4. Do not assume pending pull requests (including launcher, client and migration proposals) are already included in `main`.

### Release gate

The project intentionally prevents a stable 1.1.0 publication until the endpoint quality registry has complete protocol, security, database, performance and real-client evidence. At the last documented checkpoint, the registry contained **132 routes**, **126 inventory-only** entries, and **zero fully release-gated** endpoints. Those are certification gaps, **not a claim that 126 routes are broken**.

The publishing workflow must also build and upload both the standard and shared-hosting archives before marking the GitHub release public.

---

## Коротко по-русски

**MuchoCore 1.1.0** — крупное обновление интерфейса, установки и серверной части:

- **Админка:** удобнее навигация, обновлённый дизайн и облегчённый режим без CSS.
- **News:** изображения, YouTube, черновики и полноценное редактирование публикаций.
- **GD 2.209:** новые серверные маршруты для сообщений, групп, социальных функций и модерации. Полная совместимость с игровым клиентом **пока не подтверждена**.
- **Развёртывание:** понятнее установка на VPS, обновлён Auto Patch 2.0, появились единые клиентские архивы и контрольные суммы.
- **Надёжность:** улучшены обновления, резервные копии, миграция, фоновые задачи и кеширование.

**Важно:** исходники 1.1.0 уже в `main`, но официальный стабильный релиз ещё не опубликован. Сначала необходимо пройти проверки совместимости и собрать оба релизных ZIP-архива. Не обновляйте работающий GDPS из `main` без резервной копии и тестового стенда.
