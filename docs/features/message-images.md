# Private images, compression and local AI (MuchoClient 1.2.1)

Images default **off**. Deploy this core, run the usual migrations and install the matching MuchoClient/launcher. In **Admin → Settings**, enable game images and choose Manual, Local AI or Disabled moderation. Existing external-provider mode settings migrate to Local AI. There is no external moderation API or API key.

## Compression and readability

Uploads accept JPEG/PNG up to 4 MiB and 12 million pixels. The core decodes each file, removes metadata and tests multiple encodings:

- lossless PNG, plus guarded indexed PNG for screenshots, text and flat graphics;
- progressive JPEG qualities 84 to 54 for photos;
- the smallest candidate that passes sampled structural similarity (SSIM ≥ 0.975), color-error and sharp-edge checks is stored;
- up to 2048 pixels on the long edge, preserving proportions and never enlarging an image; larger inputs may use 1600/1280 when needed;
- output **at most 256 KiB**. If no candidate passes the quality and size guards, ask the sender to crop/split instead of storing an unreadable result.

These are quality guards, not an OCR guarantee for arbitrary tiny text. The game viewer includes Fit, +/− zoom and drag-to-pan. Small text remains inspectable beyond the initial thumbnail. These limits apply to private attachments; profile artwork keeps its existing format/limits.

## Local AI setup

The optional Python worker runs the quantized ONNX conversion of `Falconsai/nsfw_image_detection` on CPU. Its 87 MB weights are pinned by repository revision and SHA-256 in `tools/moderation/model.json`. The worker loads once, uses two CPU threads and exchanges data with PHP **only over a Unix socket**. It has no TCP listener, uploads nothing, writes no images/captions, and downloads nothing during inference. Model download happens once during setup/build.

Docker deployment:

```sh
docker compose -f docker-compose.yml -f docker-compose.moderation.yml up -d --build
```

The optional moderation container has `network_mode: none`, no published ports, a read-only filesystem, a non-root user and a 768 MiB memory cap. The core sees `/run/mucho-moderation/moderation.sock`; an initializer sets its directory ownership. Then select **Local AI** in the admin settings. Normal deployments without this overlay can use Manual/Disabled mode. Selecting Local AI without a healthy worker leaves image uploads pending.

Standalone Linux (example checkout `/opt/muchocore`):

```sh
cd /opt/muchocore
python3 -m venv .venv-moderation
.venv-moderation/bin/pip install -r tools/moderation/requirements.txt
.venv-moderation/bin/python tools/moderation/fetch_model.py --out storage/moderation-model
sudo cp tools/moderation/muchocore-moderation.service /etc/systemd/system/
sudo systemctl daemon-reload
sudo systemctl enable --now muchocore-moderation
```

The model must be readable by `www-data`. Set `MUCHO_LOCAL_MODERATION_SOCKET=/run/mucho-moderation/moderation.sock` in the server environment / `.env`. Adapt the sample systemd unit's checkout path and PHP user if different. It restricts address families to AF_UNIX and denies IP networking. Shared hosting without Python/long-running processes can use manual review.

The model screens **NSFW images**, not every prohibited category, age, legal status, violence or CSAM. Probability ≤0.15 approves, ≥0.80 rejects, intermediate scores require human review. Several image regions are inspected so a small unsafe area is less likely to be hidden by full-frame resizing. The worker receives the validated original image; storage compression does not affect its input.

Room text and captions can use **local phrase rules**, separate from the image neural model: supply `--text-rules /path/to/rules.json` to the worker, where the file is a JSON array of forbidden phrases. The default list is empty. Rules normalize Unicode/case and match phrase boundaries. They are not a language-model moderation system. Worker failures block room sends in Local AI mode and leave image uploads hidden.

Other prohibited materials need trusted hash blocks and human review. A report quarantines the file and blocks its raw/sanitized hashes before further automatic screening. Existing matching copies are hidden too. **Admin → Image moderation** can approve a false report or reject/purge with a permanent hash ban. Reported files do not go back to automatic classification.

## In the game and access rules

Photo in the native message composer sends an attachment; Images in the native message reader opens it. Social → Room → Chat supports text/photos. Mucho Features → My images shows upload status and allows owner deletion. Pending images never reach recipient inboxes or room feeds. Image viewers include reports for NSFW, violence, child safety and other concerns.

Only authenticated conversation participants can fetch images. Account bans, either-direction blocks, message deletion, recipient preferences, room membership and expiry are checked. Private images use authenticated POST, `Cache-Control: no-store` and no disk/public texture cache. Limits: 20 uploads/hour, 50 stored images/account, 15 room texts/minute, 20 reports/hour.

`POST /api/media` actions: config, upload, attachments, image, mine, remove, report, chat, chat-send. Private actions require accountID, gameVersion=22, gjp2. Upload uses scope=message|room, targetID, base64 imageData, subject, caption. Image responses are sanitized image/png or image/jpeg. No image URLs or remote moderation endpoints are accepted.

The registration fix excludes login/registration trees, password and number fields from emoji injection; only the emoji menu changes touch priority. Native credential validation stays active.
