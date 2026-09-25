# Release-based Automatic Updates

MuchoCore can update the VPS automatically, but only from a **published stable GitHub Release**.

The updater does **not** deploy directly from `main`.

```text
GitHub CI
  ↓
validated main commit
  ↓
published stable GitHub Release
  ↓
exact release tag (for example v1.0.2)
  ↓
MuchoCore automatic updater
  ↓
update.sh
  ↓
fetch exact release tag
  ↓
Docker rebuild + dependencies + migrations
```

Draft releases, prereleases and ordinary commits on `main` are ignored.

## Configuration

```text
MUCHO_AUTO_UPDATE=1
MUCHO_AUTO_UPDATE_INTERVAL=15min
```

Disable automatic updates:

```text
MUCHO_AUTO_UPDATE=0
```

Then synchronize the timer:

```bash
sudo /opt/mucho-core/bin/mucho-install-auto-update.sh
```

## Inspect

```bash
systemctl status muchocore-auto-update.timer
systemctl list-timers muchocore-auto-update.timer
sudo journalctl -u muchocore-auto-update.service -n 100 --no-pager
```

The automatic updater uses the same `update.sh` path as manual updates. That path checks the published stable GitHub Release before changing source files, and it refuses a downgrade.

The updater also stops when tracked local changes are present, so it never overwrites local modifications silently.
