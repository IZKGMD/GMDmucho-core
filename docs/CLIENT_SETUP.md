# MuchoCore Client Setup

## The simple idea

Your GDPS has two parts:

```text
Geometry Dash client
       |
       v
MuchoCore server
       |
       v
https://YOUR-DOMAIN/
```

Installing MuchoCore does not automatically change the game client. The client must be patched to use your server.

## Windows: one-click method

### 1. Get the patcher

Download this repository and open:

```text
tools/client-patch.bat
```

You do **not** need to install Python.

### 2. Choose your game

The patcher opens a small window.

Click:

```text
Browse...
```

and select your original:

```text
GeometryDash.exe
```

### 3. Check the server address

The patcher does not contain a fixed server address.

Enter the address of **your own GDPS**, for example:

```text
https://gdps.example.com
```

Do not add `/database`. The patcher builds the client-compatible path automatically.

### 4. Click Patch client

The patcher:

- checks the executable;
- finds supported Geometry Dash server URLs;
- handles the fixed-size URLs used by different client generations;
- handles the Base64 form used by some older clients;
- creates a new executable;
- never overwrites the original file.

The output will look like:

```text
GeometryDash-MuchoCore.exe
```

Start that new file.

### Why the generated URL can look unusual

Some Geometry Dash Windows builds store the server URL in a fixed-size field. The standard 2.2 database URL is:

```text
https://www.boomlings.com/database
```

The patcher keeps the same byte length, so it may generate a compatibility path such as:

```text
https://gdps.example.com/a/database
```

MuchoCore removes the compatibility prefixes before routing the Geometry Dash endpoint. You do not need to understand or change this path manually.

## Test the connection

Test in this order:

1. Log in or create an account.
2. Open the level browser.
3. Download a level.
4. Upload a small test level.
5. Post a comment.
6. Submit a test score.
7. Test cloud save.

Start with login. If login does not work, stop there and check the server trace/logs.

## If the client does not connect

First open:

```text
https://YOUR-DOMAIN/health
```

It should return:

```text
1
```

Then check the server logs:

```bash
cd /opt/mucho-core
sudo docker compose logs --tail=100
```

Make sure you patched the correct Geometry Dash client version.

## Supported automatic Windows formats

The one-click patcher currently knows these common server URL forms:

- HTTPS database URL used by GD 2.2;
- legacy HTTP database URL;
- common HTTPS/HTTP root URL variants;
- Base64-encoded variants of those URLs;
- the legacy bare `www.boomlings.com/database` form when a same-length replacement is possible.

Real client compatibility is still verified by testing the actual client build. The patcher reporting `PATCH COMPLETE` only means that a known URL string was replaced safely.

## Web patcher in the admin panel

GDPS owners can also patch a Windows client directly from the MuchoCore admin panel.

Open:

~~~text
/admin/?page=clientpatcher
~~~

Then:

1. Enter the public GDPS server root, for example `https://gdps.example.com`.
2. Upload the original `GeometryDash.exe`.
3. Click **Upload & Patch Client**.
4. Wait for the upload and patch to finish.
5. Download `GeometryDash-MuchoCore.exe`.

The web patcher uploads the executable in small chunks and performs a streaming PHP patch. It does not require Python, Docker, SSH, `exec()`, or a server-side native patching binary, which makes it suitable for many shared-hosting environments.

The web patcher accepts the server root only. Do not append `/database`; the patcher generates the client-compatible URL layout automatically.

The hosting account needs enough free disk space for the temporary upload and patched output. Temporary patch files expire automatically.

> ⚠️ Modifying an executable invalidates its original code-signing state. Keep the original client untouched and use the generated file as a separate copy.

## Android, macOS and iOS

These are advanced because the server URL is usually inside native binaries or packaged application files.

The existing Python helper can still be used:

```bash
python3 tools/client-patch.py
```

For Android 2.2, common native libraries are:

```text
arm64-v8a/libcocos2dcpp.so
armeabi-v7a/libcocos2dcpp.so
```

You must rebuild/sign the application after patching.

## What the patcher changes

The patcher changes only known Geometry Dash server URL strings.

It does not change player data, levels, passwords or the MuchoCore database.

It always writes a new output file and leaves the original file untouched.
