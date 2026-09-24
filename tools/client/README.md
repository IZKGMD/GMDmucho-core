# Client Tools

This directory contains client-side utilities used to patch Geometry Dash Windows clients and summarize real-client protocol traces.

| Tool | Purpose |
| --- | --- |
| client-patch.py | Cross-platform binary URL patcher and self-test |
| client-patch.bat | Windows launcher for the patcher |
| client-patcher.ps1 | Windows PowerShell patcher and self-test |
| client-trace-summary.py | Converts real client traces into versioned endpoint contracts |

These tools operate on local client files or diagnostic traces. They do not store credentials in generated client contracts.
