# Native route inventory

Native handlers registered: **166 / 166** canonical routes from `src/Core/Application.php`.

This counts registered handlers, not completed features or production readiness. Aliases do not count as additional implementations. PHP differential tests cover selected scenarios; real-client acceptance remains pending.

API v2 standalone scripts, browser admin, recovery/passkeys, plugins, deployment/update/backup tooling and client tooling require separate acceptance and are not included in this denominator.

| Canonical path | Native handler | PHP entry points |
| --- | --- | --- |
| `/acceptgjfriendrequest20` | Present | `/acceptGJFriendRequest20` |
| `/accountmanagement` | Present | `/accountManagement` |
| `/adminclearreportedcontent` | Present | `/adminClearReportedContent` |
| `/adminclosecase` | Present | `/adminCloseCase` |
| `/admincommentban` | Present | `/adminCommentBan` |
| `/admincommentunban` | Present | `/adminCommentUnban` |
| `/admindeleteusercomments` | Present | `/adminDeleteUserComments` |
| `/adminfreezeaccount` | Present | `/adminFreezeAccount` |
| `/admingetabuseinfo` | Present | `/adminGetAbuseInfo` |
| `/admingetaccountreview` | Present | `/adminGetAccountReview` |
| `/admingetreportedcontent` | Present | `/adminGetReportedContent` |
| `/admingetreportqueue` | Present | `/adminGetReportQueue` |
| `/admingetuserhistory` | Present | `/adminGetUserHistory` |
| `/adminmessageban` | Present | `/adminMessageBan` |
| `/adminmessageunban` | Present | `/adminMessageUnban` |
| `/adminnamelock` | Present | `/adminNameLock` |
| `/adminnameunlock` | Present | `/adminNameUnlock` |
| `/adminqueuemute` | Present | `/adminQueueMute` |
| `/adminqueueunmute` | Present | `/adminQueueUnmute` |
| `/adminthreadaction` | Present | `/adminThreadAction` |
| `/admintrendingban` | Present | `/adminTrendingBan` |
| `/admintrendingunban` | Present | `/adminTrendingUnban` |
| `/adminunfreezeaccount` | Present | `/adminUnfreezeAccount` |
| `/adminuploadban` | Present | `/adminUploadBan` |
| `/adminuploadunban` | Present | `/adminUploadUnban` |
| `/appearance/batch` | Present | `/api/appearance/batch` |
| `/appearance/my` | Present | `/api/appearance/my` |
| `/appearance/save` | Present | `/api/appearance/save` |
| `/backupgjaccount` | Present | `/backupGJAccount`, `/backupGJAccount20` |
| `/blockgjuser20` | Present | `/blockGJUser20` |
| `/claimgjnotificationreward` | Present | `/claimGJNotificationReward` |
| `/clans/activity` | Present | `/api/clans/activity` |
| `/clans/ban` | Present | `/api/clans/ban` |
| `/clans/bans` | Present | `/api/clans/bans` |
| `/clans/create` | Present | `/api/clans/create` |
| `/clans/disband` | Present | `/api/clans/disband` |
| `/clans/get` | Present | `/api/clans/get` |
| `/clans/invite` | Present | `/api/clans/invite` |
| `/clans/invite/accept` | Present | `/api/clans/invite/accept` |
| `/clans/invite/decline` | Present | `/api/clans/invite/decline` |
| `/clans/invite/revoke` | Present | `/api/clans/invite/revoke` |
| `/clans/invites` | Present | `/api/clans/invites` |
| `/clans/invites/sent` | Present | `/api/clans/invites/sent` |
| `/clans/join` | Present | `/api/clans/join` |
| `/clans/kick` | Present | `/api/clans/kick` |
| `/clans/leaderboard` | Present | `/api/clans/leaderboard` |
| `/clans/leave` | Present | `/api/clans/leave` |
| `/clans/my` | Present | `/api/clans/my` |
| `/clans/quests` | Present | `/api/clans/quests` |
| `/clans/quests/cancel` | Present | `/api/clans/quests/cancel` |
| `/clans/quests/contributions` | Present | `/api/clans/quests/contributions` |
| `/clans/quests/create` | Present | `/api/clans/quests/create` |
| `/clans/role` | Present | `/api/clans/role` |
| `/clans/search` | Present | `/api/clans/search` |
| `/clans/settings` | Present | `/api/clans/settings` |
| `/clans/transfer` | Present | `/api/clans/transfer` |
| `/clans/unban` | Present | `/api/clans/unban` |
| `/deletegjacccomment20` | Present | `/deleteGJAccComment20` |
| `/deletegjcomment20` | Present | `/deleteGJComment20` |
| `/deletegjcomments` | Present | `/deleteGJComments` |
| `/deletegjeditorsound` | Present | `/deleteGJEditorSound` |
| `/deletegjfriendrequests20` | Present | `/deleteGJFriendRequests20` |
| `/deletegjlevelcontrols` | Present | `/deleteGJLevelControls` |
| `/deletegjlevellist` | Present | `/deleteGJLevelList` |
| `/deletegjleveluser20` | Present | `/deleteGJLevelUser20` |
| `/deletegjmessages20` | Present | `/deleteGJMessages20` |
| `/downloadgjeditorsound` | Present | `/downloadGJEditorSound` |
| `/downloadgjlevel21` | Present | `/downloadGJLevel21` |
| `/downloadgjlevel22` | Present | `/downloadGJLevel22` |
| `/downloadgjmessage20` | Present | `/downloadGJMessage20` |
| `/followgjuser` | Present | `/followGJUser` |
| `/gd-content/news/news.dat` | Present | `/gd-content/news/news.dat` |
| `/gd-content/news/news_version.txt` | Present | `/gd-content/news/news_version.txt` |
| `/getaccounturl` | Present | `/getAccountURL` |
| `/getcustomcontenturl` | Present | `/getCustomContentURL` |
| `/getgjaccountcomments20` | Present | `/getGJAccountComments20` |
| `/getgjaudiofilesize` | Present | `/getGJAudioFilesize` |
| `/getgjchallenges` | Present | `/getGJChallenges` |
| `/getgjcommenthistory` | Present | `/getGJCommentHistory` |
| `/getgjcomments21` | Present | `/getGJComments21` |
| `/getgjcontentstatus` | Present | `/getGJContentStatus` |
| `/getgjcreators` | Present | `/getGJCreators` |
| `/getgjcreators19` | Present | `/getGJCreators19` |
| `/getgjdailylevel` | Present | `/getGJDailyLevel` |
| `/getgjdeathdata` | Present | `/getGJDeathData` |
| `/getgjeditorsounds` | Present | `/getGJEditorSounds` |
| `/getgjfollowing` | Present | `/getGJFollowing` |
| `/getgjfriendrequests20` | Present | `/getGJFriendRequests20` |
| `/getgjgauntlets` | Present | `/getGJGauntlets` |
| `/getgjgauntlets21` | Present | `/getGJGauntlets21` |
| `/getgjlevelcontrols` | Present | `/getGJLevelControls` |
| `/getgjlevellists` | Present | `/getGJLevelLists` |
| `/getgjlevels21` | Present | `/getGJLevels21` |
| `/getgjlevelscores` | Present | `/getGJLevelScores` |
| `/getgjlevelscores211` | Present | `/getGJLevelScores211` |
| `/getgjlevelscoresplat` | Present | `/getGJLevelScoresPlat` |
| `/getgjmappacks` | Present | `/getGJMapPacks` |
| `/getgjmappacks21` | Present | `/getGJMapPacks20`, `/getGJMapPacks21` |
| `/getgjmessages20` | Present | `/getGJMessages20` |
| `/getgjnotifications` | Present | `/getGJNotifications` |
| `/getgjrewards` | Present | `/getGJRewards` |
| `/getgjscores20` | Present | `/getGJScores20` |
| `/getgjsecretreward` | Present | `/getGJSecretReward` |
| `/getgjsonginfo` | Present | `/getGJSongInfo` |
| `/getgjsongusage` | Present | `/getGJSongUsage` |
| `/getgjthreadinvites` | Present | `/getGJThreadInvites` |
| `/getgjthreadmembers` | Present | `/getGJThreadMembers` |
| `/getgjthreadmessages` | Present | `/getGJThreadMessages` |
| `/getgjthreads` | Present | `/getGJThreads` |
| `/getgjtopartists` | Present | `/getGJTopArtists` |
| `/getgjuserinfo20` | Present | `/getGJUserInfo20` |
| `/getgjuserlist20` | Present | `/getGJUserList20` |
| `/getgjusers20` | Present | `/getGJUsers20` |
| `/getgjusersearch` | Present | `/getGJUserSearch` |
| `/getlevelrateinfo` | Present | `/getLevelRateInfo` |
| `/getsavedata` | Present | `/getSaveData` |
| `/health` | Present | `/health` |
| `/likegjitem21` | Present | `/likeGJItem21` |
| `/likegjitem211` | Present | `/likeGJItem211` |
| `/logingjaccount` | Present | `/loginGJAccount` |
| `/muchoclient/manifest` | Present | `/muchoclient/manifest` |
| `/muchoclient/negotiate` | Present | `/muchoclient/negotiate` |
| `/muchocore/news` | Present | `/muchocore/news` |
| `/mutegjuser` | Present | `/muteGJUser` |
| `/news/news.dat` | Present | `/news/news.dat` |
| `/news/news_version.txt` | Present | `/news/news_version.txt` |
| `/openedregister` | Present | `/openedRegister`, `/accounts/openedRegister` |
| `/profile/get` | Present | `/api/profile/get` |
| `/profile/image` | Present | `/api/profile/image` |
| `/profile/remove` | Present | `/api/profile/remove` |
| `/profile/upload` | Present | `/api/profile/upload` |
| `/rategjdemon21` | Present | `/rateGJDemon21` |
| `/rategjstars20` | Present | `/rateGJStars20` |
| `/rategjstars211` | Present | `/rateGJStars211` |
| `/readgjfriendrequest20` | Present | `/readGJFriendRequest20` |
| `/registergjaccount` | Present | `/registerGJAccount` |
| `/removegjfriend20` | Present | `/removeGJFriend20` |
| `/reportgjcontent` | Present | `/reportGJContent` |
| `/reportgjlevel` | Present | `/reportGJLevel` |
| `/requestuseraccess` | Present | `/requestUserAccess` |
| `/searchgjthreadmessages` | Present | `/searchGJThreadMessages` |
| `/sendgjthreadmessage` | Present | `/sendGJThreadMessage` |
| `/suggestgjstars20` | Present | `/suggestGJStars20` |
| `/syncgjaccount` | Present | `/syncGJAccount`, `/syncGJAccount20` |
| `/syncgjfollowing` | Present | `/syncGJFollowing` |
| `/unblockgjuser20` | Present | `/unblockGJUser20` |
| `/unfollowgjuser` | Present | `/unfollowGJUser` |
| `/updategjaccsettings20` | Present | `/updateGJAccSettings20` |
| `/updategjlevel` | Present | `/updateGJLevel` |
| `/updategjleveldesc20` | Present | `/updateGJLevelDesc20` |
| `/updategjsounddesc` | Present | `/updateGJSoundDesc` |
| `/updategjthread` | Present | `/updateGJThread` |
| `/updategjusername` | Present | `/updateGJUserName` |
| `/updategjuserscore` | Present | `/updateGJUserScore` |
| `/updategjuserscore22` | Present | `/updateGJUserScore22` |
| `/uploadfriendrequest20` | Present | `/uploadFriendRequest20` |
| `/uploadgjacccomment20` | Present | `/uploadGJAccComment20` |
| `/uploadgjcomment20` | Present | `/uploadGJComment20` |
| `/uploadgjcomment21` | Present | `/uploadGJComment21` |
| `/uploadgjdeathdata` | Present | `/uploadGJDeathData` |
| `/uploadgjeditorsound` | Present | `/uploadGJEditorSound` |
| `/uploadgjlevel21` | Present | `/uploadGJLevel21` |
| `/uploadgjlevel22` | Present | `/uploadGJLevel22` |
| `/uploadgjlevelcontrols` | Present | `/uploadGJLevelControls` |
| `/uploadgjlevellist` | Present | `/uploadGJLevelList` |
| `/uploadgjmessage20` | Present | `/uploadGJMessage20` |

## Standalone services

API v2: **9 / 10** endpoint scripts have native handlers. This excludes its bootstrap/security include files, whose protection and metrics run as middleware.

| PHP endpoint | Native handler |
| --- | --- |
| `/api/v2/admin-client.php` | **Pending** |
| `/api/v2/branding.php` | Present |
| `/api/v2/client-config.php` | Present |
| `/api/v2/dashboard.php` | Present |
| `/api/v2/health.php` | Present |
| `/api/v2/heartbeat.php` | Present |
| `/api/v2/index.php` | Present |
| `/api/v2/music-upload.php` | Present |
| `/api/v2/music.php` | Present |
| `/api/v2/profile.php` | Present |

Account recovery (`/api/api/accounts/lostusername.php`) has a native browser/service implementation. Browser administration, administrator passkeys, plugins and deployment/update/backup tools require separate migration and acceptance.
