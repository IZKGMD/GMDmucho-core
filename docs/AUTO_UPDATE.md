# Automatic Updates

Manual and automatic VPS updates use `update.sh` and share a nonblocking `flock` lock. If an update is running, the next invocation exits without starting a second deployment.

## Update channels

`MUCHO_UPDATE_CHANNEL` selects the source:

| Channel | Source |
| --- | --- |
| `auto` (default) | An installation at an exact semantic-version Git tag follows stable releases; an untagged development checkout follows `origin/main`. |
| `stable` | Latest stable GitHub Release, with semantic-version Git tags as a fallback when the Releases API is unavailable. |
| `main` | Latest development commit on `origin/main`; does not require a published release. |

Stable updates fetch the exact tag and resolve annotated tags to their commit. Source changes require the target to descend from the installed commit. Intentional transitions between divergent source lines require `MUCHO_ALLOW_RELEASE_REBASE=1`.

Update a development installation:

```bash
sudo env MUCHO_UPDATE_CHANNEL=main /opt/mucho-core/update.sh
```

## Configuration

```text
MUCHO_AUTO_UPDATE=1
MUCHO_AUTO_UPDATE_INTERVAL=15min
```

Set `MUCHO_AUTO_UPDATE=0` to disable automatic updates, then synchronize the timer:

```bash
sudo /opt/mucho-core/bin/mucho-install-auto-update.sh
```

## Recovery

Before switching source, the updater writes `.muchocore/update.pending`. The checkpoint remains until containers, PHP dependencies, database migrations, admin synchronization and service-status checks succeed. An optional client-repatching failure is logged as a warning.

Build/runtime/dependency failures or interruptions before migrations restore the previous source checkout. This is a source rollback; containers that were already recreated are not reverted automatically. Run the updater again to rebuild a consistent deployment.

After migrations start, the updater preserves the new source and checkpoint. It does not roll back database changes. Running the normal update command again resumes the deployment even if Git already points at the latest commit. Do not delete the checkpoint to bypass a failure.

To reapply the installed source manually, including repairs of deployments that failed before checkpoint support existed:

```bash
sudo env MUCHO_UPDATE_CHANNEL=main MUCHO_UPDATE_REPAIR=1 /opt/mucho-core/update.sh
```

Tracked local changes still stop the updater before any reset. The repair option does not bypass that protection or the overlap lock.

## Inspect

```bash
systemctl status muchocore-auto-update.timer
systemctl list-timers muchocore-auto-update.timer
sudo journalctl -u muchocore-auto-update.service -n 100 --no-pager
```
