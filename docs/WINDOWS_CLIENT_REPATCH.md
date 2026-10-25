# Retargeting an already patched Windows client

The shared admin and Python patcher accept a supported original GD 2.209 EXE
and a verified URL-only patch of either supported build. The version label
alone does not identify an executable.

For a patched input, the patcher restores the known official API URL slots in
memory, including the original `/databas/` health typo and the Geometry Dash
host used by the multiplayer comment URL. The entire restored image must then
match a supported original SHA-256. Other edits, unknown builds, incorrect
padding and changes to code/imports/resources remain rejected. Retargeting
starts from that verified image, so it retains the original slot capacities
even when the previous server had a shorter hostname. The input is preserved.

The report records the actual input SHA-256, `input_state`, the verified
`source_original_sha256` and output SHA-256. `verified: static-binary-only`
does not mean the game was started or its endpoints certified.

## A client that closes before showing a window

1. Fully extract the game into a writable folder. Preserve its matching
   support DLLs and `Resources`. An EXE by itself is not a complete game.
2. Use the filename `GeometryDash.exe` in the game folder. Do not start an old
   shortcut pointing at another installation.
3. Keep the original Steam integration. URL patching does not replace Steam,
   account ownership checks, Microsoft Visual C++ runtimes or graphics drivers.
4. The optional `START-MuchoCore.cmd` launcher sets the correct working folder,
   verifies the files listed in `client-verification.json`, checks for the
   Microsoft x64 runtime files, and records process exit/crash information.
   It does not upload logs, alter credentials/saves, or install software.
   `START-MuchoCore.cmd -CheckOnly` checks without starting the game.

An included Geode log from an older launch is not evidence that the current
EXE loads Geode. Check the active loader and a fresh crash record before
attributing a startup failure to mods.
