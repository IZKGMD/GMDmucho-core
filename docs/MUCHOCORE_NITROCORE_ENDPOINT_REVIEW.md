# MuchoCore PHP 8 and NitroCore endpoint review

MuchoCore baseline `9028d95`. NitroCore reference `506fdaf`. NitroCore implements its GD API in TypeScript and labels its supported range as 1.9–2.206. The changes here implement selected behavior in PHP and retain MuchoCore’s MIT license.

The table covers the 166 canonical routes in the [PHP 8 contract registry](../tests/protocol/endpoint-contracts.json). 65 resolve to a NitroCore route after normalizing aliases and 2 have a related route with a separate or newer MuchoCore name. A dash means NitroCore has no matching GD route in this snapshot. The links point to the implementations for inspection. Route matching describes coverage. Native GD 2.209 acceptance requires separate client observations.

## Behavior differences observed in the shared handlers

| Area | NitroCore | MuchoCore PHP 8 |
| --- | --- | --- |
| Account discovery and sign-in | The connector emits the same ID twice on login. Registration and login use different credential flows. | Login returns the separate account and profile IDs. It accepts legacy credentials and GJP2, and preserves an existing imported account’s identifiers. |
| Cloud Save | Backup appends game and binary versions. Sync adds the remaining trailer fields. | The encrypted repository keeps revision history and checks integrity. The service preserves version metadata while supporting legacy stored payloads. |
| Level search and transfer | Shared legacy handlers implement filters, level encoding and batched song lookup. | Discovery uses joined profiles and songs, versioned encoders and separate 2.209 difficulty and timestamp behavior. Upload and download have dedicated paths for update and native transfer metadata. |
| Lists and comment history | The route filters lists by search mode and uses optional account auth to read friends’ lists. Comment history uses the player ID. | Friends-only discovery and owned updates apply separately. Comment history keeps user and account ID namespaces separate and filters hidden levels. |
| Social and private messages | Authentication middleware resolves a viewer. The message deletion handler delegates to a single delete operation. | Private messages keep separate sender and recipient deletion state. Friend-request deletion supports the request row ID and the existing peer ID with participant checks in the delete itself. |
| Music, packs and special levels | Discovery endpoints are backed by music, packs and quests controllers. | Pack order is configured explicitly. Daily, weekly and event responses use separate indices and an expiry computed from the active rotation. |
| Level scores, ratings and votes | Acts on authenticated score, level or rating requests. | Current and historic leaderboards retain their different payloads. Moderation role checks, score improvements, and vote uniqueness are enforced using the existing persistence rules. |
| New native and MuchoClient endpoints | The reference has no matching routes for 2.209 threads, following, notifications, editor audio, control telemetry, abuse cases, or MuchoClient’s JSON API. | These contracts depend on MuchoCore’s fixtures and native client traces. Matching older NitroCore routes cannot verify their wire formats. |

## Route inventory

| MuchoCore route | PHP handler | NitroCore handler | Change in this review |
| --- | --- | --- | --- |
| `/getGJLevels21` | [`LevelController::list`](../src/Level/LevelController.php) | [`getGJLevels.php`](https://github.com/RigbyHost/NitroCore/blob/506fdafa8b1ad30e84cc287d3b633672f0f887c9/server/routes/%5Bsrvid%5D/db/getGJLevels.php.post.ts) |  |
| `/getGJScores20` | [`UserController::scores`](../src/User/UserController.php) | [`getGJScores.php`](https://github.com/RigbyHost/NitroCore/blob/506fdafa8b1ad30e84cc287d3b633672f0f887c9/server/routes/%5Bsrvid%5D/db/getGJScores.php.post.ts) |  |
| `/loginGJAccount` | [`AccountController::login`](../src/Account/AccountController.php) | [`accounts/loginGJAccount.php`](https://github.com/RigbyHost/NitroCore/blob/506fdafa8b1ad30e84cc287d3b633672f0f887c9/server/routes/%5Bsrvid%5D/db/accounts/loginGJAccount.php.post.ts) | Requires a credential before looking up account state. |
| `/uploadGJLevel21` | [`LevelTransferController::upload`](../src/Level/LevelTransferController.php) | [`uploadGJLevel.php`](https://github.com/RigbyHost/NitroCore/blob/506fdafa8b1ad30e84cc287d3b633672f0f887c9/server/routes/%5Bsrvid%5D/db/uploadGJLevel.php.post.ts) |  |
| `/downloadGJLevel21` | [`LevelTransferController::download`](../src/Level/LevelTransferController.php) | [`downloadGJLevel.php`](https://github.com/RigbyHost/NitroCore/blob/506fdafa8b1ad30e84cc287d3b633672f0f887c9/server/routes/%5Bsrvid%5D/db/downloadGJLevel.php.post.ts) |  |
| `/getGJComments21` | [`CommentController::getLevelComments`](../src/Interaction/CommentController.php) | [`getGJComments.php`](https://github.com/RigbyHost/NitroCore/blob/506fdafa8b1ad30e84cc287d3b633672f0f887c9/server/routes/%5Bsrvid%5D/db/getGJComments.php.post.ts) |  |
| `/updateGJUserName` | [`Legacy10IdentityController::updateUsername`](../src/Compatibility/Legacy10IdentityController.php) | — |  |
| `/getAccountURL` | [`UrlController::accountUrl`](../src/Url/UrlController.php) | [`getAccountURL.php`](https://github.com/RigbyHost/NitroCore/blob/506fdafa8b1ad30e84cc287d3b633672f0f887c9/server/routes/%5Bsrvid%5D/db/getAccountURL.php.ts) |  |
| `/getCustomContentURL` | [`UrlController::customContentUrl`](../src/Url/UrlController.php) | [`getCustomContentURL.php`](https://github.com/RigbyHost/NitroCore/blob/506fdafa8b1ad30e84cc287d3b633672f0f887c9/server/routes/%5Bsrvid%5D/db/getCustomContentURL.php.get.ts) |  |
| `/getGJCommentHistory` | [`CommentHistoryController::get`](../src/Comment/CommentHistoryController.php) | [`getGJCommentHistory.php`](https://github.com/RigbyHost/NitroCore/blob/506fdafa8b1ad30e84cc287d3b633672f0f887c9/server/routes/%5Bsrvid%5D/db/getGJCommentHistory.php.post.ts) |  |
| `/getGJLevelLists` | [`LevelListController::get`](../src/LevelList/LevelListController.php) | [`getGJLevelLists.php`](https://github.com/RigbyHost/NitroCore/blob/506fdafa8b1ad30e84cc287d3b633672f0f887c9/server/routes/%5Bsrvid%5D/db/getGJLevelLists.php.post.ts) | Binds text filters consistently and skips the row query on an empty page. |
| `/uploadGJLevelList` | [`LevelListController::upload`](../src/LevelList/LevelListController.php) | [`uploadGJLevelList.php`](https://github.com/RigbyHost/NitroCore/blob/506fdafa8b1ad30e84cc287d3b633672f0f887c9/server/routes/%5Bsrvid%5D/db/uploadGJLevelList.php.post.ts) | Rejects a malformed update ID before creating a list. |
| `/deleteGJLevelList` | [`LevelListController::delete`](../src/LevelList/LevelListController.php) | [`deleteGJLevelList.php`](https://github.com/RigbyHost/NitroCore/blob/506fdafa8b1ad30e84cc287d3b633672f0f887c9/server/routes/%5Bsrvid%5D/db/deleteGJLevelList.php.post.ts) |  |
| `/getGJTopArtists` | [`TopArtistController::get`](../src/Artist/TopArtistController.php) | [`getGJTopArtists.php`](https://github.com/RigbyHost/NitroCore/blob/506fdafa8b1ad30e84cc287d3b633672f0f887c9/server/routes/%5Bsrvid%5D/db/getGJTopArtists.php.post.ts) |  |
| `/registerGJAccount` | [`AccountController::register`](../src/Account/AccountController.php) | [`accounts/registerGJAccount.php`](https://github.com/RigbyHost/NitroCore/blob/506fdafa8b1ad30e84cc287d3b633672f0f887c9/server/routes/%5Bsrvid%5D/db/accounts/registerGJAccount.php.post.ts) | Rejects a NUL byte before hashing. |
| `/backupGJAccount` | [`CloudSaveController::backup`](../src/CloudSave/CloudSaveController.php) | [`accounts/backupGJAccount.php`](https://github.com/RigbyHost/NitroCore/blob/506fdafa8b1ad30e84cc287d3b633672f0f887c9/server/routes/%5Bsrvid%5D/db/accounts/backupGJAccount.php.post.ts) | Retains the uploader’s game and binary versions. |
| `/syncGJAccount` | [`CloudSaveController::sync`](../src/CloudSave/CloudSaveController.php) | [`accounts/syncGJAccount.php`](https://github.com/RigbyHost/NitroCore/blob/506fdafa8b1ad30e84cc287d3b633672f0f887c9/server/routes/%5Bsrvid%5D/db/accounts/syncGJAccount.php.post.ts) | Keeps a complete trailer and adds the legacy default to an unframed save. |
| `/updateGJLevel` | [`LevelTransferController::checkUpdate`](../src/Level/LevelTransferController.php) | — |  |
| `/uploadGJLevel22` | [`LevelTransferController::upload`](../src/Level/LevelTransferController.php) | [`uploadGJLevel.php`](https://github.com/RigbyHost/NitroCore/blob/506fdafa8b1ad30e84cc287d3b633672f0f887c9/server/routes/%5Bsrvid%5D/db/uploadGJLevel.php.post.ts) (related route) |  |
| `/downloadGJLevel22` | [`LevelTransferController::download`](../src/Level/LevelTransferController.php) | [`downloadGJLevel.php`](https://github.com/RigbyHost/NitroCore/blob/506fdafa8b1ad30e84cc287d3b633672f0f887c9/server/routes/%5Bsrvid%5D/db/downloadGJLevel.php.post.ts) |  |
| `/deleteGJLevelUser20` | [`LevelTransferController::delete`](../src/Level/LevelTransferController.php) | [`deleteGJLevelUser20.php`](https://github.com/RigbyHost/NitroCore/blob/506fdafa8b1ad30e84cc287d3b633672f0f887c9/server/routes/%5Bsrvid%5D/db/deleteGJLevelUser20.php.post.ts) |  |
| `/updateGJLevelDesc20` | [`LevelTransferController::updateDescription`](../src/Level/LevelTransferController.php) | [`updateGJDesc20.php`](https://github.com/RigbyHost/NitroCore/blob/506fdafa8b1ad30e84cc287d3b633672f0f887c9/server/routes/%5Bsrvid%5D/db/updateGJDesc20.php.post.ts) |  |
| `/getGJSongInfo` | [`SongController::info`](../src/Music/SongController.php) | [`getGJSongInfo.php`](https://github.com/RigbyHost/NitroCore/blob/506fdafa8b1ad30e84cc287d3b633672f0f887c9/server/routes/%5Bsrvid%5D/db/getGJSongInfo.php.post.ts) |  |
| `/suggestGJStars20` | [`ModerationController::suggest`](../src/Moderation/ModerationController.php) | [`suggestGJStars20.php`](https://github.com/RigbyHost/NitroCore/blob/506fdafa8b1ad30e84cc287d3b633672f0f887c9/server/routes/%5Bsrvid%5D/db/suggestGJStars20.php.post.ts) |  |
| `/rateGJStars20` | [`ModerationController::rateStars`](../src/Moderation/ModerationController.php) | [`rateGJStars20.php`](https://github.com/RigbyHost/NitroCore/blob/506fdafa8b1ad30e84cc287d3b633672f0f887c9/server/routes/%5Bsrvid%5D/db/rateGJStars20.php.post.ts) |  |
| `/rateGJStars211` | [`ModerationController::rateStars`](../src/Moderation/ModerationController.php) | [`rateGJStars20.php`](https://github.com/RigbyHost/NitroCore/blob/506fdafa8b1ad30e84cc287d3b633672f0f887c9/server/routes/%5Bsrvid%5D/db/rateGJStars20.php.post.ts) |  |
| `/rateGJDemon21` | [`ModerationController::rateDemon`](../src/Moderation/ModerationController.php) | [`rateGJDemon21.php`](https://github.com/RigbyHost/NitroCore/blob/506fdafa8b1ad30e84cc287d3b633672f0f887c9/server/routes/%5Bsrvid%5D/db/rateGJDemon21.php.post.ts) |  |
| `/reportGJLevel` | [`ModerationController::report`](../src/Moderation/ModerationController.php) | [`reportGJLevel.php`](https://github.com/RigbyHost/NitroCore/blob/506fdafa8b1ad30e84cc287d3b633672f0f887c9/server/routes/%5Bsrvid%5D/db/reportGJLevel.php.post.ts) |  |
| `/requestUserAccess` | [`UserController::requestAccess`](../src/User/UserController.php) | [`requestUserAccess.php`](https://github.com/RigbyHost/NitroCore/blob/506fdafa8b1ad30e84cc287d3b633672f0f887c9/server/routes/%5Bsrvid%5D/db/requestUserAccess.php.post.ts) |  |
| `/getGJUserInfo20` | [`UserController::profile`](../src/User/UserController.php) | [`getGJUserInfo20.php`](https://github.com/RigbyHost/NitroCore/blob/506fdafa8b1ad30e84cc287d3b633672f0f887c9/server/routes/%5Bsrvid%5D/db/getGJUserInfo20.php.post.ts) |  |
| `/getGJUsers20` | [`UserController::search`](../src/User/UserController.php) | [`getGJUsers20.php`](https://github.com/RigbyHost/NitroCore/blob/506fdafa8b1ad30e84cc287d3b633672f0f887c9/server/routes/%5Bsrvid%5D/db/getGJUsers20.php.post.ts) |  |
| `/updateGJAccSettings20` | [`UserController::updateSettings`](../src/User/UserController.php) | [`updateGJAccSettings20.php`](https://github.com/RigbyHost/NitroCore/blob/506fdafa8b1ad30e84cc287d3b633672f0f887c9/server/routes/%5Bsrvid%5D/db/updateGJAccSettings20.php.post.ts) |  |
| `/updateGJUserScore` | [`UserController::updateScore`](../src/User/UserController.php) | [`updateGJUserScore.php`](https://github.com/RigbyHost/NitroCore/blob/506fdafa8b1ad30e84cc287d3b633672f0f887c9/server/routes/%5Bsrvid%5D/db/updateGJUserScore.php.post.ts) |  |
| `/updateGJUserScore22` | [`UserController::updateScore`](../src/User/UserController.php) | [`updateGJUserScore.php`](https://github.com/RigbyHost/NitroCore/blob/506fdafa8b1ad30e84cc287d3b633672f0f887c9/server/routes/%5Bsrvid%5D/db/updateGJUserScore.php.post.ts) |  |
| `/uploadGJComment20` | [`CommentController::uploadLevelComment`](../src/Interaction/CommentController.php) | [`uploadGJComment.php`](https://github.com/RigbyHost/NitroCore/blob/506fdafa8b1ad30e84cc287d3b633672f0f887c9/server/routes/%5Bsrvid%5D/db/uploadGJComment.php.post.ts) |  |
| `/uploadGJComment21` | [`CommentController::uploadLevelComment`](../src/Interaction/CommentController.php) | [`uploadGJComment.php`](https://github.com/RigbyHost/NitroCore/blob/506fdafa8b1ad30e84cc287d3b633672f0f887c9/server/routes/%5Bsrvid%5D/db/uploadGJComment.php.post.ts) |  |
| `/deleteGJComment20` | [`CommentController::deleteComment`](../src/Interaction/CommentController.php) | [`deleteGJComment20.php`](https://github.com/RigbyHost/NitroCore/blob/506fdafa8b1ad30e84cc287d3b633672f0f887c9/server/routes/%5Bsrvid%5D/db/deleteGJComment20.php.post.ts) |  |
| `/getGJAccountComments20` | [`CommentController::getAccountComments`](../src/Interaction/CommentController.php) | [`getGJAccountComments20.php`](https://github.com/RigbyHost/NitroCore/blob/506fdafa8b1ad30e84cc287d3b633672f0f887c9/server/routes/%5Bsrvid%5D/db/getGJAccountComments20.php.post.ts) |  |
| `/uploadGJAccComment20` | [`CommentController::uploadAccountComment`](../src/Interaction/CommentController.php) | [`uploadGJAccComment20.php`](https://github.com/RigbyHost/NitroCore/blob/506fdafa8b1ad30e84cc287d3b633672f0f887c9/server/routes/%5Bsrvid%5D/db/uploadGJAccComment20.php.post.ts) |  |
| `/deleteGJAccComment20` | [`CommentController::deleteAccountComment`](../src/Interaction/CommentController.php) | [`deleteGJAccComment20.php`](https://github.com/RigbyHost/NitroCore/blob/506fdafa8b1ad30e84cc287d3b633672f0f887c9/server/routes/%5Bsrvid%5D/db/deleteGJAccComment20.php.post.ts) |  |
| `/likeGJItem21` | [`LikeController::like`](../src/Interaction/LikeController.php) | [`likeGJItem.php`](https://github.com/RigbyHost/NitroCore/blob/506fdafa8b1ad30e84cc287d3b633672f0f887c9/server/routes/%5Bsrvid%5D/db/likeGJItem.php.post.ts) |  |
| `/likeGJItem211` | [`LikeController::like`](../src/Interaction/LikeController.php) | [`likeGJItem.php`](https://github.com/RigbyHost/NitroCore/blob/506fdafa8b1ad30e84cc287d3b633672f0f887c9/server/routes/%5Bsrvid%5D/db/likeGJItem.php.post.ts) |  |
| `/getGJMessages20` | [`MessageController::getMessages`](../src/Social/MessageController.php) | [`getGJMessages20.php`](https://github.com/RigbyHost/NitroCore/blob/506fdafa8b1ad30e84cc287d3b633672f0f887c9/server/routes/%5Bsrvid%5D/db/getGJMessages20.php.post.ts) |  |
| `/downloadGJMessage20` | [`MessageController::readMessage`](../src/Social/MessageController.php) | [`downloadGJMessage20.php`](https://github.com/RigbyHost/NitroCore/blob/506fdafa8b1ad30e84cc287d3b633672f0f887c9/server/routes/%5Bsrvid%5D/db/downloadGJMessage20.php.post.ts) |  |
| `/uploadGJMessage20` | [`MessageController::sendMessage`](../src/Social/MessageController.php) | [`uploadGJMessage20.php`](https://github.com/RigbyHost/NitroCore/blob/506fdafa8b1ad30e84cc287d3b633672f0f887c9/server/routes/%5Bsrvid%5D/db/uploadGJMessage20.php.post.ts) |  |
| `/deleteGJMessages20` | [`MessageController::deleteMessage`](../src/Social/MessageController.php) | [`deleteGJMessages20.php`](https://github.com/RigbyHost/NitroCore/blob/506fdafa8b1ad30e84cc287d3b633672f0f887c9/server/routes/%5Bsrvid%5D/db/deleteGJMessages20.php.post.ts) |  |
| `/uploadFriendRequest20` | [`RelationshipController::send`](../src/Social/RelationshipController.php) | [`uploadFriendRequest20.php`](https://github.com/RigbyHost/NitroCore/blob/506fdafa8b1ad30e84cc287d3b633672f0f887c9/server/routes/%5Bsrvid%5D/db/uploadFriendRequest20.php.post.ts) |  |
| `/getGJFriendRequests20` | [`RelationshipController::get`](../src/Social/RelationshipController.php) | [`getGJFriendRequests20.php`](https://github.com/RigbyHost/NitroCore/blob/506fdafa8b1ad30e84cc287d3b633672f0f887c9/server/routes/%5Bsrvid%5D/db/getGJFriendRequests20.php.post.ts) |  |
| `/readGJFriendRequest20` | [`RelationshipController::read`](../src/Social/RelationshipController.php) | [`readGJFriendRequest20.php`](https://github.com/RigbyHost/NitroCore/blob/506fdafa8b1ad30e84cc287d3b633672f0f887c9/server/routes/%5Bsrvid%5D/db/readGJFriendRequest20.php.post.ts) |  |
| `/acceptGJFriendRequest20` | [`RelationshipController::accept`](../src/Social/RelationshipController.php) | [`acceptGJFriendRequest20.php`](https://github.com/RigbyHost/NitroCore/blob/506fdafa8b1ad30e84cc287d3b633672f0f887c9/server/routes/%5Bsrvid%5D/db/acceptGJFriendRequest20.php.post.ts) |  |
| `/deleteGJFriendRequests20` | [`RelationshipController::delete`](../src/Social/RelationshipController.php) | [`deleteGJFriendRequests20.php`](https://github.com/RigbyHost/NitroCore/blob/506fdafa8b1ad30e84cc287d3b633672f0f887c9/server/routes/%5Bsrvid%5D/db/deleteGJFriendRequests20.php.post.ts) | Accepts a request ID or the existing peer ID and checks the selected owner role. |
| `/removeGJFriend20` | [`RelationshipController::remove`](../src/Social/RelationshipController.php) | [`removeGJFriend20.php`](https://github.com/RigbyHost/NitroCore/blob/506fdafa8b1ad30e84cc287d3b633672f0f887c9/server/routes/%5Bsrvid%5D/db/removeGJFriend20.php.post.ts) |  |
| `/blockGJUser20` | [`RelationshipController::block`](../src/Social/RelationshipController.php) | [`blockGJUser20.php`](https://github.com/RigbyHost/NitroCore/blob/506fdafa8b1ad30e84cc287d3b633672f0f887c9/server/routes/%5Bsrvid%5D/db/blockGJUser20.php.post.ts) |  |
| `/unblockGJUser20` | [`RelationshipController::unblock`](../src/Social/RelationshipController.php) | [`unblockGJUser20.php`](https://github.com/RigbyHost/NitroCore/blob/506fdafa8b1ad30e84cc287d3b633672f0f887c9/server/routes/%5Bsrvid%5D/db/unblockGJUser20.php.post.ts) |  |
| `/getGJUserList20` | [`RelationshipController::userList`](../src/Social/RelationshipController.php) | [`getGJUserList20.php`](https://github.com/RigbyHost/NitroCore/blob/506fdafa8b1ad30e84cc287d3b633672f0f887c9/server/routes/%5Bsrvid%5D/db/getGJUserList20.php.post.ts) |  |
| `/api/clans/create` | [`ClanController::create`](../src/Clan/ClanController.php) | — |  |
| `/api/clans/my` | [`ClanController::myClan`](../src/Clan/ClanController.php) | — |  |
| `/api/clans/get` | [`ClanController::get`](../src/Clan/ClanController.php) | — |  |
| `/api/clans/search` | [`ClanController::search`](../src/Clan/ClanController.php) | — |  |
| `/api/clans/join` | [`ClanController::join`](../src/Clan/ClanController.php) | — |  |
| `/api/clans/leave` | [`ClanController::leave`](../src/Clan/ClanController.php) | — |  |
| `/api/clans/invite` | [`ClanController::invite`](../src/Clan/ClanController.php) | — |  |
| `/api/clans/invite/accept` | [`ClanController::acceptInvite`](../src/Clan/ClanController.php) | — |  |
| `/api/clans/invite/decline` | [`ClanController::declineInvite`](../src/Clan/ClanController.php) | — |  |
| `/api/clans/kick` | [`ClanController::kick`](../src/Clan/ClanController.php) | — |  |
| `/api/clans/role` | [`ClanController::setRole`](../src/Clan/ClanController.php) | — |  |
| `/api/clans/invites` | [`ClanController::invites`](../src/Clan/ClanController.php) | — |  |
| `/api/clans/invites/sent` | [`ClanController::sentInvites`](../src/Clan/ClanController.php) | — |  |
| `/api/clans/settings` | [`ClanController::updateSettings`](../src/Clan/ClanController.php) | — |  |
| `/api/clans/transfer` | [`ClanController::transferOwnership`](../src/Clan/ClanController.php) | — |  |
| `/api/clans/disband` | [`ClanController::disband`](../src/Clan/ClanController.php) | — |  |
| `/api/clans/invite/revoke` | [`ClanController::revokeInvite`](../src/Clan/ClanController.php) | — |  |
| `/api/clans/ban` | [`ClanController::ban`](../src/Clan/ClanController.php) | — |  |
| `/api/clans/unban` | [`ClanController::unban`](../src/Clan/ClanController.php) | — |  |
| `/api/clans/bans` | [`ClanController::bans`](../src/Clan/ClanController.php) | — |  |
| `/getGJCreators` | [`DiscoveryController::creators`](../src/Compatibility/DiscoveryController.php) | [`getGJCreators.php`](https://github.com/RigbyHost/NitroCore/blob/506fdafa8b1ad30e84cc287d3b633672f0f887c9/server/routes/%5Bsrvid%5D/db/getGJCreators.php.post.ts) |  |
| `/getGJCreators19` | [`DiscoveryController::creators`](../src/Compatibility/DiscoveryController.php) | [`getGJCreators.php`](https://github.com/RigbyHost/NitroCore/blob/506fdafa8b1ad30e84cc287d3b633672f0f887c9/server/routes/%5Bsrvid%5D/db/getGJCreators.php.post.ts) |  |
| `/getGJDailyLevel` | [`DiscoveryController::daily`](../src/Compatibility/DiscoveryController.php) | [`getGJDailyLevel.php`](https://github.com/RigbyHost/NitroCore/blob/506fdafa8b1ad30e84cc287d3b633672f0f887c9/server/routes/%5Bsrvid%5D/db/getGJDailyLevel.php.post.ts) | Reads a validated selector from the routed request. |
| `/getGJGauntlets` | [`DiscoveryController::gauntlets`](../src/Compatibility/DiscoveryController.php) | [`getGJGauntlets.php`](https://github.com/RigbyHost/NitroCore/blob/506fdafa8b1ad30e84cc287d3b633672f0f887c9/server/routes/%5Bsrvid%5D/db/getGJGauntlets.php.post.ts) |  |
| `/getGJGauntlets21` | [`DiscoveryController::gauntlets`](../src/Compatibility/DiscoveryController.php) | [`getGJGauntlets.php`](https://github.com/RigbyHost/NitroCore/blob/506fdafa8b1ad30e84cc287d3b633672f0f887c9/server/routes/%5Bsrvid%5D/db/getGJGauntlets.php.post.ts) |  |
| `/getGJMapPacks` | [`DiscoveryController::mapPacks`](../src/Compatibility/DiscoveryController.php) | [`getGJMapPacks.php`](https://github.com/RigbyHost/NitroCore/blob/506fdafa8b1ad30e84cc287d3b633672f0f887c9/server/routes/%5Bsrvid%5D/db/getGJMapPacks.php.post.ts) | Rejects invalid pagination before querying the database. |
| `/getGJMapPacks20` | [`DiscoveryController::mapPacks`](../src/Compatibility/DiscoveryController.php) | [`getGJMapPacks.php`](https://github.com/RigbyHost/NitroCore/blob/506fdafa8b1ad30e84cc287d3b633672f0f887c9/server/routes/%5Bsrvid%5D/db/getGJMapPacks.php.post.ts) | Uses the same validated pagination as the unversioned route. |
| `/getGJLevelScores` | [`LevelScoreController::regular`](../src/Score/LevelScoreController.php) | [`getGJLevelScores.php`](https://github.com/RigbyHost/NitroCore/blob/506fdafa8b1ad30e84cc287d3b633672f0f887c9/server/routes/%5Bsrvid%5D/db/getGJLevelScores.php.post.ts) |  |
| `/getGJLevelScores211` | [`LevelScoreController::regular`](../src/Score/LevelScoreController.php) | [`getGJLevelScores.php`](https://github.com/RigbyHost/NitroCore/blob/506fdafa8b1ad30e84cc287d3b633672f0f887c9/server/routes/%5Bsrvid%5D/db/getGJLevelScores.php.post.ts) |  |
| `/getGJLevelScoresPlat` | [`PlatformerScoreController::handle`](../src/Score/PlatformerScoreController.php) | [`getGJLevelScoresPlat.php`](https://github.com/RigbyHost/NitroCore/blob/506fdafa8b1ad30e84cc287d3b633672f0f887c9/server/routes/%5Bsrvid%5D/db/getGJLevelScoresPlat.php.post.ts) |  |
| `/getGJRewards` | [`RewardsController::getRewards`](../src/Interaction/RewardsController.php) | [`getGJRewards.php`](https://github.com/RigbyHost/NitroCore/blob/506fdafa8b1ad30e84cc287d3b633672f0f887c9/server/routes/%5Bsrvid%5D/db/getGJRewards.php.post.ts) |  |
| `/getGJSecretReward` | [`RewardsController::getSecretReward`](../src/Interaction/RewardsController.php) | — |  |
| `/getGJChallenges` | [`RewardsController::getChallenges`](../src/Interaction/RewardsController.php) | [`getGJChallenges.php`](https://github.com/RigbyHost/NitroCore/blob/506fdafa8b1ad30e84cc287d3b633672f0f887c9/server/routes/%5Bsrvid%5D/db/getGJChallenges.php.post.ts) |  |
| `/muchoclient/manifest` | [`ClientBridgeController::manifest`](../src/Client/ClientBridgeController.php) | — |  |
| `/muchoclient/negotiate` | [`ClientBridgeController::negotiate`](../src/Client/ClientBridgeController.php) | — |  |
| `/api/clans/leaderboard` | [`ClanController::leaderboard`](../src/Clan/ClanController.php) | — |  |
| `/api/appearance/batch` | [`AppearanceController::batch`](../src/Appearance/AppearanceController.php) | — |  |
| `/api/appearance/my` | [`AppearanceController::mine`](../src/Appearance/AppearanceController.php) | — |  |
| `/api/appearance/save` | [`AppearanceController::save`](../src/Appearance/AppearanceController.php) | — |  |
| `/api/clans/quests` | [`ClanQuestController::quests`](../src/Clan/ClanQuestController.php) | — |  |
| `/api/clans/quests/create` | [`ClanQuestController::create`](../src/Clan/ClanQuestController.php) | — |  |
| `/api/clans/quests/cancel` | [`ClanQuestController::cancel`](../src/Clan/ClanQuestController.php) | — |  |
| `/api/clans/quests/contributions` | [`ClanQuestController::contributions`](../src/Clan/ClanQuestController.php) | — |  |
| `/api/clans/activity` | [`ClanQuestController::activity`](../src/Clan/ClanQuestController.php) | — |  |
| `/api/profile/get` | [`ProfileMediaController::profile`](../src/Appearance/ProfileMediaController.php) | — |  |
| `/api/profile/upload` | [`ProfileMediaController::upload`](../src/Appearance/ProfileMediaController.php) | — |  |
| `/api/profile/remove` | [`ProfileMediaController::remove`](../src/Appearance/ProfileMediaController.php) | — |  |
| `/api/profile/image` | [`ProfileMediaController::image`](../src/Appearance/ProfileMediaController.php) | — |  |
| `/muchocore/news` | [`NewsService::published`](../src/News/NewsService.php) | — |  |
| `/gd-content/news/news.dat` | [`NativeNewsFormat::encode`](../src/News/NativeNewsFormat.php) | — |  |
| `/gd-content/news/news_version.txt` | [`NewsService::nativeVersion`](../src/News/NewsService.php) | — |  |
| `/news/news.dat` | [`NativeNewsFormat::encode`](../src/News/NativeNewsFormat.php) | — |  |
| `/news/news_version.txt` | [`NewsService::nativeVersion`](../src/News/NewsService.php) | — |  |
| `/getGJNotifications` | [`NotificationController::get`](../src/Social/NotificationController.php) | — |  |
| `/claimGJNotificationReward` | [`NotificationController::claim`](../src/Social/NotificationController.php) | — |  |
| `/getGJFollowing` | [`FollowingController::get`](../src/Social/FollowingController.php) | — |  |
| `/syncGJFollowing` | [`FollowingController::sync`](../src/Social/FollowingController.php) | — |  |
| `/followGJUser` | [`FollowingController::follow`](../src/Social/FollowingController.php) | — |  |
| `/unfollowGJUser` | [`FollowingController::unfollow`](../src/Social/FollowingController.php) | — |  |
| `/muteGJUser` | [`FollowingController::mute`](../src/Social/FollowingController.php) | — |  |
| `/getGJThreads` | [`ThreadController::getThreads`](../src/Social/ThreadController.php) | — |  |
| `/getGJThreadMessages` | [`ThreadController::getMessages`](../src/Social/ThreadController.php) | — |  |
| `/getGJThreadMembers` | [`ThreadController::getMembers`](../src/Social/ThreadController.php) | — |  |
| `/getGJThreadInvites` | [`ThreadController::getInvites`](../src/Social/ThreadController.php) | — |  |
| `/sendGJThreadMessage` | [`ThreadController::send`](../src/Social/ThreadController.php) | — |  |
| `/searchGJThreadMessages` | [`ThreadController::search`](../src/Social/ThreadController.php) | — |  |
| `/updateGJThread` | [`ThreadController::update`](../src/Social/ThreadController.php) | — |  |
| `/getGJDeathData` | [`LevelTelemetryController::getDeaths`](../src/Level/LevelTelemetryController.php) | — |  |
| `/uploadGJDeathData` | [`LevelTelemetryController::uploadDeath`](../src/Level/LevelTelemetryController.php) | — |  |
| `/getGJLevelControls` | [`LevelTelemetryController::getControls`](../src/Level/LevelTelemetryController.php) | — |  |
| `/uploadGJLevelControls` | [`LevelTelemetryController::uploadControls`](../src/Level/LevelTelemetryController.php) | — |  |
| `/deleteGJLevelControls` | [`LevelTelemetryController::deleteControls`](../src/Level/LevelTelemetryController.php) | — |  |
| `/getGJEditorSounds` | [`EditorSoundController::get`](../src/Music/EditorSoundController.php) | — |  |
| `/uploadGJEditorSound` | [`EditorSoundController::upload`](../src/Music/EditorSoundController.php) | — |  |
| `/downloadGJEditorSound` | [`EditorSoundController::download`](../src/Music/EditorSoundController.php) | — |  |
| `/deleteGJEditorSound` | [`EditorSoundController::delete`](../src/Music/EditorSoundController.php) | — |  |
| `/updateGJSoundDesc` | [`EditorSoundController::updateDescription`](../src/Music/EditorSoundController.php) | — |  |
| `/getGJUserSearch` | [`UserController::search209`](../src/User/UserController.php) | [`getGJUsers20.php`](https://github.com/RigbyHost/NitroCore/blob/506fdafa8b1ad30e84cc287d3b633672f0f887c9/server/routes/%5Bsrvid%5D/db/getGJUsers20.php.post.ts) (related route) |  |
| `/getGJSongUsage` | [`SongUsageController::get`](../src/Music/SongUsageController.php) | — |  |
| `/adminGetReportQueue` | [`ReportQueueController::queue`](../src/Moderation/ReportQueueController.php) | — |  |
| `/reportGJContent` | [`Gd2209CaseController::reportContent`](../src/Moderation/Gd2209CaseController.php) | — |  |
| `/getGJAudioFilesize` | [`Gd2209UtilityController::audioSize`](../src/Compatibility/Gd2209UtilityController.php) | — |  |
| `/getGJContentStatus` | [`Gd2209UtilityController::contentStatus`](../src/Compatibility/Gd2209UtilityController.php) | — |  |
| `/openedRegister` | [`Gd2209UtilityController::openedRegister`](../src/Compatibility/Gd2209UtilityController.php) | — |  |
| `/adminGetUserHistory` | [`Gd2209CaseController::userHistory`](../src/Moderation/Gd2209CaseController.php) | — |  |
| `/adminGetReportedContent` | [`Gd2209CaseController::reportedContent`](../src/Moderation/Gd2209CaseController.php) | — |  |
| `/adminClearReportedContent` | [`Gd2209CaseController::clearReportedContent`](../src/Moderation/Gd2209CaseController.php) | — |  |
| `/adminGetAbuseInfo` | [`Gd2209CaseController::abuseInfo`](../src/Moderation/Gd2209CaseController.php) | — |  |
| `/adminGetAccountReview` | [`Gd2209CaseController::accountReview`](../src/Moderation/Gd2209CaseController.php) | — |  |
| `/adminThreadAction` | [`Gd2209ContentActionController::threadAction`](../src/Moderation/Gd2209ContentActionController.php) | — |  |
| `/adminDeleteUserComments` | [`Gd2209ContentActionController::deleteUser`](../src/Moderation/Gd2209ContentActionController.php) | — |  |
| `/adminCommentBan` | [`Gd2209SanctionController::apply`](../src/Moderation/Gd2209SanctionController.php) | — |  |
| `/adminUploadBan` | [`Gd2209SanctionController::apply`](../src/Moderation/Gd2209SanctionController.php) | — |  |
| `/adminMessageBan` | [`Gd2209SanctionController::apply`](../src/Moderation/Gd2209SanctionController.php) | — |  |
| `/adminFreezeAccount` | [`Gd2209SanctionController::apply`](../src/Moderation/Gd2209SanctionController.php) | — |  |
| `/adminTrendingBan` | [`Gd2209SanctionController::apply`](../src/Moderation/Gd2209SanctionController.php) | — |  |
| `/adminNameLock` | [`Gd2209SanctionController::apply`](../src/Moderation/Gd2209SanctionController.php) | — |  |
| `/adminCommentUnban` | [`Gd2209SanctionController::apply`](../src/Moderation/Gd2209SanctionController.php) | — |  |
| `/adminUploadUnban` | [`Gd2209SanctionController::apply`](../src/Moderation/Gd2209SanctionController.php) | — |  |
| `/adminCloseCase` | [`Gd2209CaseController::closeCase`](../src/Moderation/Gd2209CaseController.php) | — |  |
| `/adminMessageUnban` | [`Gd2209SanctionController::apply`](../src/Moderation/Gd2209SanctionController.php) | — |  |
| `/adminUnfreezeAccount` | [`Gd2209SanctionController::apply`](../src/Moderation/Gd2209SanctionController.php) | — |  |
| `/adminTrendingUnban` | [`Gd2209SanctionController::apply`](../src/Moderation/Gd2209SanctionController.php) | — |  |
| `/adminNameUnlock` | [`Gd2209SanctionController::apply`](../src/Moderation/Gd2209SanctionController.php) | — |  |
| `/deleteGJComments` | [`Gd2209ContentActionController::deleteOwn`](../src/Moderation/Gd2209ContentActionController.php) | — |  |
| `/adminQueueMute` | [`Gd2209SanctionController::apply`](../src/Moderation/Gd2209SanctionController.php) | — |  |
| `/adminQueueUnmute` | [`Gd2209SanctionController::apply`](../src/Moderation/Gd2209SanctionController.php) | — |  |
| `/getLevelRateInfo` | [`Gd2209UtilityController::levelRateInfo`](../src/Compatibility/Gd2209UtilityController.php) | — |  |
| `/getSaveData` | [`Gd2209UtilityController::savedLevelRatings`](../src/Compatibility/Gd2209UtilityController.php) | — |  |
| `/accountManagement` | [`AccountManagementController::handle`](../src/Account/AccountManagementController.php) | [`accounts/accountManagement.php`](https://github.com/RigbyHost/NitroCore/blob/506fdafa8b1ad30e84cc287d3b633672f0f887c9/server/routes/%5Bsrvid%5D/db/accounts/accountManagement.php.get.ts) |  |
| `/submitGJUserInfo` | [`UserInfoController::submit`](../src/Account/UserInfoController.php) | — |  |

NitroCore also exposes server-specific music-library and SFX files under `content/` and a `switcher/` metadata route. These are outside the MuchoCore application contract registry. Its account management and Cloud Save aliases are included in the route comparison.
