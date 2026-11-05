# GD 2.209 user-list relationship dates

`getGJUserList20.php` now supplies the documented user-object key **64** for
friends and followed accounts when `gameVersion >= 22` and `binaryVersion >= 51`.
The value is an integer Unix timestamp from the stored relationship, not the
account creation date or the time the list was fetched.

| List type | Meaning | Key 64 source |
| --- | --- | --- |
| 0 | Friends | `friends.created_at` for the requesting account's friendship |
| 1 | Blocked accounts | Omitted: a block is not a friendship or subscription |
| 3 | Followed accounts | `mucho_follows.created_at` for the authenticated viewer |
| 4 | Muted accounts | Omitted: no documented friendship-date meaning |

The list remains a pipe-separated sequence of `key:value` user objects. Existing
identity, statistics and unread flags keep their meanings. Reading a friendship
clears its `is_new` flag without changing its creation time; repeating a follow
also preserves the original timestamp. Old clients retain their existing wire
format. Authentication and private-list ownership checks still apply.

SQL computes `UNIX_TIMESTAMP(created_at)` directly from the timestamp column,
avoiding PHP process-timezone interpretation. The encoder never adds this field
when the repository has no documented relationship date. No migration is needed.

The PHP runtime and regenerated PHP7 classes implement the same contract. The Go
reference runtime also carries the field so the PHP/Go compatibility scenario
continues to compare identical responses.

## Evidence and limits

The field contract is documented in GD Docs
[user object](https://github.com/gd-docs/gd-docs/blob/69f67b9661694389cb34ca8afd904cf663b01472/docs/resources/server/user.md)
(key 64 and the note limiting it to `getGJUserList20`), and the list selectors
are documented in
[getGJUserList20](https://github.com/gd-docs/gd-docs/blob/69f67b9661694389cb34ca8afd904cf663b01472/docs/endpoints/socials/getGJUserList20.md).
Key 64 must not be inserted into `getGJUserInfo20` profile responses.

`tests/protocol/social-wire.php` checks serialization and legacy exclusion.
`tests/application/gd2209-social-integration.php` checks persisted, distinct
friend/follow dates, duplicate-follow behavior, unread flags, authentication,
types without a relationship date, and controller gating at binary versions
47, 50 and 51 on disposable MariaDB. Existing PHP/Go differential tests cover
the corresponding native-handler responses.

This is documented protocol and automated database evidence. An actual Windows
or Android GD 2.209 UI run has not been performed for this change. Native
acceptance of date display/sorting and the broader 2.209 release gates remains
pending.
