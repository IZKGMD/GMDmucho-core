# Geometry Dash Version Profiles

MuchoCore uses **one server core** for all supported Geometry Dash generations. The core selects the correct protocol behavior from the client version instead of maintaining separate server copies.

The installer lets you choose which known client generations the installation should accept.

| Profile | Runtime setting | Purpose |
| --- | --- | --- |
| All supported | `all` | GD 1.9, 2.0, 2.1 and 2.2 |
| GD 1.9 only | `19` | Legacy 1.9-only server |
| GD 2.0 only | `20` | 2.0-only server |
| GD 2.1 only | `21` | 2.1-only server |
| GD 2.2 only | `22` | 2.2-only server |
| Custom | `19,22` | Any supported combination |

## What changes between versions?

The version profile does **not** install a different PHP application.

Instead, MuchoCore keeps shared domain logic and selects version-specific protocol behavior at the boundaries:

~~~text
                    MuchoCore
                        │
                ClientVersion
                        │
               CompatibilityProfile
                        │
        ┌───────────────┼───────────────┐
        │               │               │
      GD 1.9          GD 2.0          GD 2.1 / 2.2
      legacy          legacy            modern
      wire            wire              wire
~~~

### GD 1.9

The legacy profile covers the protocol behavior required by the 1.9 generation, including legacy text handling, old comment/account surfaces, legacy level transfer behavior and version-specific response encoding.

### GD 2.0

GD 2.0 uses the 2.x protocol family while retaining compatibility behavior distinct from GD 2.1.

### GD 2.1

GD 2.1 has its own client contract and version-aware protocol fields. The runtime can accept it independently from 2.0 when a restricted profile is selected.

### GD 2.2

GD 2.2 uses the modern protocol path, including GJP2-aware authentication and the currently verified real-client compatibility surface.

## Installer usage

Interactive installation:

~~~bash
sudo ./install
~~~

The installer presents a version menu.

For automated deployment, set the environment variable before running the installer:

~~~bash
export MUCHO_GD_VERSIONS=19,22
sudo -E bash install.sh
~~~

Use:

~~~text
MUCHO_GD_VERSIONS=all
MUCHO_GD_VERSIONS=19
MUCHO_GD_VERSIONS=20
MUCHO_GD_VERSIONS=21
MUCHO_GD_VERSIONS=22
MUCHO_GD_VERSIONS=19,22
~~~

The selected value is stored in:

~~~text
.env
.muchocore/profile.env
~~~

## Runtime enforcement

When a request explicitly identifies a known client version, MuchoCore checks it against the selected profile.

A blocked version receives the normal Geometry Dash failure body:

~~~text
-1
~~~

Versionless requests remain allowed when the server cannot safely identify their generation. This is intentional because some genuine legacy endpoints do not include a version field.

## Changing the profile

Edit the existing installation:

~~~bash
sudo nano /opt/mucho-core/.env
~~~

Change:

~~~text
MUCHO_GD_VERSIONS=all
~~~

to the desired profile, for example:

~~~text
MUCHO_GD_VERSIONS=19
~~~

Then run:

~~~bash
sudo /opt/mucho-core/update.sh
~~~

The update process keeps the selected profile and displays it after the update.
