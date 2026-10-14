# Game images and room chat (MuchoClient 1.2.0)

Images are **disabled by default**. Deploy the matching core, run the normal database migrations, and install MuchoClient 1.2.0. The Android launcher must contain that client too, because its integrity check restores its embedded package. No game binary patch is necessary.

In **Admin → Settings**, enable images and choose a moderation mode:

| Mode | Behavior |
| --- | --- |
| Manual (default) | Every uploaded image remains private to its owner and the review queue until an administrator approves it. |
| OpenAI | The server screens the sanitized image and caption using `omni-moderation-latest`. Failed or incomplete responses remain pending for manual review. |
| Disabled | Valid images publish immediately. Reports and the server hash blocklist still work. |

For automatic screening put `MUCHO_MODERATION_API_KEY` in the server environment / `.env` and restart PHP-FPM if required by your deployment. Never place the key in the client or Git. PHP needs GD with JPEG support, mbstring and cURL. The Docker image installs them. Images, captions and room text messages are sent to OpenAI only in OpenAI mode; the client shows this before sending. A provider failure keeps image uploads pending and blocks room text sends until screening is available.

OpenAI's [moderation documentation](https://developers.openai.com/api/docs/guides/moderation) describes image screening for sexual content, violence and self-harm. Several other categories are text-only. This is **not a detector for all prohibited materials**, nor a CSAM detection service. Do not submit known or suspected CSAM to it. Maintain a trusted hash blocklist and review reports manually. Matching raw or sanitized hashes are blocked before external screening; a player report hides the file and blocks those hashes immediately. Reported files go only to human review, never back to the provider. A moderator can clear a false report, or reject and purge the image while retaining its hash block.

The review queue is **Admin → Image moderation**, protected by `messages.manage`, session authentication and CSRF. Protected previews are uncached. Enabling sending requires `settings.manage`.

In the game:

- **Photo** in the normal message composer sends a photo with subject/caption. **Images** in a received or sent message opens its attachments.
- **Social → Room → Chat** opens the room conversation. Text works even with image sending disabled. **Photo** attaches an image to the room.
- **Mucho Features → My images** shows approved/pending uploads and lets the owner remove stored images. Pending files never appear in recipient inboxes or room feeds.
- The private image viewer offers reports for NSFW, violence, child safety and other concerns.

Only authenticated conversation participants can fetch an image. Message deletion, account bans, either-direction blocks, recipient message preferences, current room membership and room expiry are enforced. Image URLs are not public and credentials are sent in POST bodies. Private images bypass the public profile cache and are never written to disk by the client.

Uploads accept JPEG/PNG up to 4 MiB and 12 million pixels. The server removes metadata by decoding and re-encoding JPEG, caps the longest edge at 1280 pixels and the output at 512 KiB. SVG/GIF/URLs are rejected. Limits: 20 image submissions per account per hour, 50 stored images per account, 15 text room messages per minute, 20 reports per hour. Remove older uploads to free storage. Moderation is synchronous, with a 15-second provider timeout; failures need manual approval. There is no automatic resend worker.

`POST /api/media` supports `config`, `upload`, `attachments`, `image`, `mine`, `remove`, `report`, `chat`, `chat-send`. Private actions require `accountID`, `gameVersion=22`, `gjp2`. Upload fields are `scope=message|room`, `targetID`, canonical base64 `imageData`, `subject`, `caption`. Other IDs are `messageID`, `imageID`, `roomID`. JSON responses use `ok`, `schema_version=1`, `data`; image responses are private JPEGs with `Cache-Control: no-store`.

The registration fix excludes login/registration input trees, password fields and number fields from emoji injection. Only the emoji menu gets touch priority; the surrounding account form keeps native dispatch and Submit validation.
