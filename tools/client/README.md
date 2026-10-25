# Client Tools

This directory contains client-side utilities used to patch Geometry Dash Windows clients and summarize real-client protocol traces.

| Tool | Purpose |
| --- | --- |
| client-patch.py | Cross-platform binary URL patcher and self-test |
| client-patch.bat | Windows launcher for the patcher |
| client-patcher.ps1 | Windows PowerShell patcher and self-test |
| client-trace-summary.py | Converts real client traces into versioned endpoint contracts |
| START-MuchoCore.cmd / Start-MuchoCore.ps1 | Start a prepared client pack with file verification, a fixed working folder and local exit/crash diagnostics |

These tools operate on local client files or diagnostic traces. They do not store credentials in generated client contracts.

Supported Windows originals and verified URL-only patches can be retargeted.
The complete reconstructed original SHA-256 must match; arbitrary modern
EXEs remain rejected. See [retargeting and startup diagnostics](../../docs/WINDOWS_CLIENT_REPATCH.md).
