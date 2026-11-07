# MuchoCore VPS bridge (restricted GitHub Actions → SSH)

## Fast setup: one VPS command

On an Ubuntu VPS, connect over SSH and run the **single line** below as root.
It creates a dedicated SSH identity and restricted user, installs GitHub CLI if
needed, detects host/port and adds all encrypted GitHub Actions secrets.
**You must still approve one official GitHub device login** in your browser;
there is no way to grant repository access without this authorization.

```bash
curl -fsSL https://raw.githubusercontent.com/IZKGMD/GMDmucho-core/main/tools/bridge/bootstrap-vps-bridge.sh -o /root/mucho-bridge-bootstrap.sh && bash /root/mucho-bridge-bootstrap.sh
```

The download is open-source code from the project repository, executed as
root. Inspect the bootstrap before running it if you do not trust the current
`main` branch. To override external SSH address/port detection, set
`MUCHO_BRIDGE_HOST` and `MUCHO_BRIDGE_PORT` in the environment before running.
No VPS application containers, production SQL or firewall rules are modified.

**After setup:** ask ChatGPT to post `/vps status` on issue #205 and check
that it returns the isolated test server status. Then `/vps gd2209` can
exercise two read-only synthetic Geometry Dash 2.209 POST requests.

The rest of this guide covers manual setup and security details.

The bridge lets the repository owner, including via the connected GitHub
integration, request narrowly scoped diagnostic operations on a VPS **without**
giving ChatGPT a private key or direct SSH session.

- Control issue: https://github.com/IZKGMD/GMDmucho-core/issues/205
- Workflow: `.github/workflows/vps-bridge.yml`
- Server installer: `tools/bridge/install-vps-bridge.sh`
- Forced command: `tools/bridge/vps-bridge-remote.sh`

**This is a deliberately limited first stage, not unrestricted VPS management.**
It does not deploy source changes, obtain Docker/root privileges, inspect
application secrets, modify the production database, or execute arbitrary shell
from an issue comment. To expand it, change and review the allowlist in GitHub
and install the reviewed server-side command update.

## Optional: privileged **stress-only** automation

The initial bridge is read-only. To let GitHub Actions manage the **isolated**
MuchoCore stress app, run **one command** from an SSH root shell on the VPS:

```bash
curl -fsSL https://raw.githubusercontent.com/IZKGMD/GMDmucho-core/main/tools/bridge/upgrade-privileged-bridge.sh -o /root/mucho-bridge-upgrade.sh && bash /root/mucho-bridge-upgrade.sh
```

This does not repeat GitHub authorization, regenerate keys, modify GitHub
Secrets, or grant arbitrary root access. The code is executed as root, so
inspect the downloaded script beforehand if the GitHub branch is untrusted.

Once installed, the repository owner can comment exactly:

- `/vps stress status`: list running services for the isolated stress stack
- `/vps stress restart`: restart **only** the `app` container in that stack
- `/vps stress update`: pull its current image tag and recreate **only** its
  `app` container. This operation changes the test deployment and may
  briefly interrupt synthetic tests.

The server-side root helper is owned by root at
`/usr/local/libexec/mucho-vps-admin` and the restricted sudoers policy lists
**only those three exact argument strings**. It verifies that
`/opt/mucho-stress` and `docker-compose.stress.yml` are root-owned,
non-symlinked, and not writable by other users. Root operations are
not available for any other path, service, image reference, environment
variable or arbitrary command. The production `/opt/mucho-core`
installation is explicitly outside the supported scope.

**Security model:** a compromised repository owner GitHub session can issue
these stress-only actions. A compromised source repository or published stress
image remains a serious supply-chain risk. Never turn the bridge into an
unrestricted sudo/shell gateway via publicly visible issue comments.
Root-capable actions are deliberately opt-in and require one local installation.

If the command says root configuration is not owned by root, inspect the
installation instead of bypassing the checks. To disable privileged operations
without disabling ordinary diagnostics, remove
`/etc/sudoers.d/90-muchocore-bridge` and validate with `sudo visudo -c`.

## Prerequisites

- Ubuntu VPS with an SSH server reachable **from GitHub-hosted runners** on a
  public IP and SSH port; an inbound firewall or provider block will prevent
  the bridge from working. We do not open ports automatically.
- An existing isolated stress installation listening only on
  `127.0.0.1:18080` (not a production listener). This is the
  `muchocore-stress-caddy-1` configuration used by the project.
- Root/sudo access for one-time setup; OpenSSH, curl and Python 3 installed.
- Repository Settings permission to create GitHub Actions secrets.

Do not send private keys, root passwords or database credentials in chat.

## 1. Create a dedicated key

On the VPS (not inside the production application):

```sh
sudo mkdir -p /root/mucho-bridge-setup
sudo ssh-keygen -t ed25519 -N '' \
  -f /root/mucho-bridge-setup/bridge_ed25519 \
  -C 'muchocore-github-actions'
```

The private file is `bridge_ed25519`; the public file ends in `.pub`.
Only the public key is passed to the installer. **Never paste the private key in
an issue, a PR, a command argument, or this chat.**

## 2. Install the restricted server command

Obtain a reviewed copy of the repository after the bridge PR is merged. Use a
separate directory so that existing production application changes are not
touched:

```sh
sudo git clone --depth 1 \
  https://github.com/IZKGMD/GMDmucho-core.git \
  /root/mucho-bridge-setup/source

sudo bash /root/mucho-bridge-setup/source/tools/bridge/install-vps-bridge.sh \
  /root/mucho-bridge-setup/bridge_ed25519.pub
```

The installer creates a separate `muchobridge` account with a root-owned home
and root-owned `authorized_keys`; OpenSSH enforces:

```text
restrict,command="/usr/local/libexec/mucho-vps-bridge" ssh-ed25519 AAAA...
```

The SSH account has no Docker group or sudo and cannot open a normal shell,
allocate a TTY, forward ports, change arbitrary files, or run commands outside
`status`, `smoke`, `bench`, `gd2209`. The installed executable is owned by root.
The script does not alter `/opt/mucho-core`, SSH configuration or the firewall.

## 3. Add five GitHub Actions repository secrets

Open **Repository → Settings → Secrets and variables → Actions → New
repository secret**. Add:

| Secret | Value |
| --- | --- |
| `VPS_BRIDGE_HOST` | Public VPS IPv4 or DNS name, without scheme |
| `VPS_BRIDGE_PORT` | SSH TCP port (optional; defaults to 22) |
| `VPS_BRIDGE_USER` | `muchobridge` |
| `VPS_BRIDGE_PRIVATE_KEY` | Entire private key from `bridge_ed25519`, including BEGIN/END lines |
| `VPS_BRIDGE_KNOWN_HOSTS` | Trusted OpenSSH host-key line for this exact host/port |

To generate the **trusted** host-key line locally on the VPS for port 22, use:

```sh
HOST='YOUR_PUBLIC_VPS_IP'
printf '%s ' "$HOST"
sudo awk '{print $1, $2}' /etc/ssh/ssh_host_ed25519_key.pub
```

For a nonstandard SSH port replace the hostname field with
`[YOUR_PUBLIC_VPS_IP]:YOUR_SSH_PORT`. The host key must correspond to the
actual server; do not bypass host-key verification with
`StrictHostKeyChecking=no`. You can verify its fingerprint on the VPS with
`ssh-keygen -lf /etc/ssh/ssh_host_ed25519_key.pub`.

Enter the private key **only** in GitHub Actions Secrets via your own trusted
device. Once configured and working, delete the extra private key copy from
the VPS if you do not intend to reuse it. Keep the authorized public key.

## 4. Run from GitHub (or ask ChatGPT to comment)

Once `.github/workflows/vps-bridge.yml` has been merged into `main`, create
a new comment on control issue **#205** with exactly one of:

- `/vps status`: uptime, RAM, disk and isolated `/health` HTTP status
- `/vps smoke`: `/health`, online check and a read-only GD level-search route
- `/vps bench`: 40 **sequential** localhost `/health` requests, max 3 s each,
  150 ms spacing, reporting p50/p95 successful-request latency
- `/vps gd2209`: **read-only synthetic** Geometry Dash 2.209 POST search
  requests (`gameVersion=22`, `binaryVersion=51`) to the isolated localhost
  stress server; it does not log in, mutate data or certify a real game client

Only comments from GitHub user ID `112145870` (IZKGMD) are executed.
There are no parameters or arbitrary command execution. GitHub Actions posts
a short diagnostic result back to the same issue; bot comments are ignored.
The workflow has `contents: read` and `issues: write` permissions only.

Reports and issue comments are visible to repository readers, so no secrets,
tokens, account IDs, private database data or sensitive logs belong in output.
`smoke` validates HTTP routing, not full in-game behavior; `bench` is
neither a realistic workload nor a production performance certification.

## Diagnostics

- `Permission denied (publickey)`: check SSH user/key, root-owned
  `authorized_keys`, and public/private key pairing.
- `Host key verification failed`: the known-hosts line doesn't match the
  configured SSH host and port; verify the server's real host key.
- `Connection timed out`: SSH isn't reachable from GitHub-hosted runners;
  check cloud firewall, IP/port, sshd and provider rules.
- `/health` fails: check isolated stress stack `127.0.0.1:18080` from
  the VPS itself. The workflow must not fall back to production.
- `Workflow did not run`: it must be on the **default branch**; issue
  number, owner ID and exact comment text must match.

## Security and removal

The bridge deliberately does **not** put the SSH user in the `docker` group
(such membership is effectively root-equivalent), allow shell arguments, use a
GitHub personal access token on the VPS, or turn off SSH host-key checking.
Keep the repository owner account protected by 2FA.

To disable remotely, delete GitHub secret `VPS_BRIDGE_PRIVATE_KEY`.
To remove fully on VPS:

```sh
sudo userdel muchobridge
sudo rm -rf /var/lib/mucho-bridge
sudo rm -f /usr/local/libexec/mucho-vps-bridge \
  /usr/local/libexec/mucho-vps-bridge-bench \
  /usr/local/libexec/mucho-vps-bridge-gd2209
```

Verify the paths before removal; none are the MuchoCore production path.
