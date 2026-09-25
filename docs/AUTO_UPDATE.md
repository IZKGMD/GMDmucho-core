# Automatic Updates

MuchoCore can automatically check the `main` branch and update an installed VPS deployment when a new commit is available.

## Default behavior

The installer enables automatic updates by default:

~~~text
MUCHO_AUTO_UPDATE=1
MUCHO_AUTO_UPDATE_INTERVAL=15min
~~~

The timer performs a lightweight Git check every configured interval. If the installed commit is already current, no containers are rebuilt.

When a new commit is found, MuchoCore runs the normal:

~~~bash
sudo /opt/mucho-core/update.sh
~~~

deployment path, including Docker rebuilds, dependency installation, database migrations, administrator synchronization and service checks.

## Disable automatic updates

Edit:

~~~text
/opt/mucho-core/.env
~~~

Set:

~~~text
MUCHO_AUTO_UPDATE=0
~~~

Then run:

~~~bash
sudo /opt/mucho-core/bin/mucho-install-auto-update.sh
~~~

## Change the interval

Supported examples:

~~~text
MUCHO_AUTO_UPDATE_INTERVAL=15min
MUCHO_AUTO_UPDATE_INTERVAL=30min
MUCHO_AUTO_UPDATE_INTERVAL=1h
MUCHO_AUTO_UPDATE_INTERVAL=1d
~~~

After changing it:

~~~bash
sudo /opt/mucho-core/bin/mucho-install-auto-update.sh
~~~

## Safety behavior

The automatic updater will not overwrite tracked local changes. It stops instead and records the reason in the log.

The updater uses a filesystem lock so overlapping checks are skipped.

Automatic updates intentionally use the same `update.sh` path as a manual update so there is one deployment mechanism to test and maintain.

## Inspect the timer

~~~bash
systemctl status muchocore-auto-update.timer
systemctl list-timers muchocore-auto-update.timer
~~~

View the update log:

~~~bash
sudo tail -n 100 /var/log/muchocore/auto-update.log
~~~

To run a check immediately:

~~~bash
sudo /opt/mucho-core/auto-update.sh
~~~

## Requirements

Automatic updates are intended for the Linux VPS/Docker deployment.

The server must have:

- Git with the MuchoCore repository configured as `origin`;
- Docker Compose v2;
- systemd;
- root/sudo access.

Self-hosted installations can disable the timer and continue using the normal one-command update flow.
