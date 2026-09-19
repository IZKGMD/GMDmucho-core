# MuchoCore Client Setup

## The simple idea

Your GDPS has two parts:

```text
Geometry Dash client
       |
       v
https://YOUR-DOMAIN/database
       |
       v
MuchoCore server
```

Installing MuchoCore does not automatically change the game client. The client must be patched to use your server.

## Windows: easiest method

### 1. Install Python 3

Install Python 3 from https://www.python.org/.

On Windows, enable the option that adds Python to PATH.

### 2. Get the patcher

Download this repository. Open:

```text
tools/client-patch.bat
```

### 3. Back up the original game

Make a copy of your original Geometry Dash executable.

Never patch your only copy.

### 4. Double-click the patcher

Double-click:

```text
tools/client-patch.bat
```

The program asks for the path to your Geometry Dash executable.

Then it asks for your MuchoCore server URL.

Enter the server root only:

```text
https://gdps.example.com
```

Do not add `/database`. The patcher adds it automatically.

### 5. If the patcher says the URL is the wrong length

Some 2.2 clients store the main server URL as a fixed-size string.

The standard client URL is:

```text
https://www.boomlings.com/database
```

For this patch method, the final URL must also be 34 bytes long.

The patcher checks this for you.

If it says the length is wrong, nothing was changed. Use another domain or subdomain and run the patcher again.

### 6. Start the patched client

The patcher creates a new executable next to the original one:

```text
GeometryDash-MuchoCore.exe
```

Start that file.

## Test the connection

Test in this order:

1. Log in or create an account.
2. Open the level browser.
3. Download a level.
4. Upload a small test level.
5. Post a comment.
6. Submit a test score.
7. Test cloud save.

Start with login. If login does not work, do not continue to the next test.

## If the client does not connect

First check the server:

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
sudo docker compose logs -f
```

Make sure you patched the correct Geometry Dash client version.

## Android

Android is an advanced step because the server URL is usually inside native game libraries.

For a 2.2 APK, the common libraries are:

```text
arm64-v8a/libcocos2dcpp.so
armeabi-v7a/libcocos2dcpp.so
```

Patch each library with:

```bash
python3 tools/client-patch.py
```

Then rebuild and sign the APK.

## macOS

Patch the executable inside:

```text
Geometry Dash.app/Contents/MacOS/GeometryDash
```

Run:

```bash
python3 tools/client-patch.py
```

The helper will ask for the executable path and server URL.

macOS may require re-signing the application.

## iOS

iOS requires repackaging and signing an IPA.

The client still needs to use:

```text
https://YOUR-DOMAIN/database
```

The exact signing process depends on your build and distribution method.

## Manual command

If you do not want the interactive helper:

```bash
python3 tools/client-patch.py \
  --input "/path/to/GeometryDash.exe" \
  --output "/path/to/GeometryDash-MuchoCore.exe" \
  --server-url "https://YOUR-DOMAIN"
```

## What the patcher changes

The patcher changes only the known Geometry Dash server URL strings.

It does not change player data, levels, passwords or the database.

It always writes a new output file and leaves the original file untouched.
