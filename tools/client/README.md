# Client Tools

This directory contains client-side utilities used to patch Geometry Dash Windows clients and summarize real-client protocol traces.

| Tool | Purpose |
| --- | --- |
| client-patch.py | Cross-platform binary URL patcher and self-test |
| client-patch.bat | Windows launcher for the patcher |
| client-patcher.ps1 | Windows PowerShell patcher and self-test |
| client-trace-summary.py | Converts real client traces into versioned endpoint contracts |
| client-probe.py | Read-only PE/endpoint report from the actual client executable |

Use the mucho-client-audit.php CLI with the generated JSON to compare a probe
report to the core's actual route inventory. Static name coverage does not
certify a working game, native hooks or wire formats. See
[the 2.209 port notes](../../docs/features/gd2209-compatibility.md).

These tools operate on local client files or diagnostic traces. They do not store credentials in generated client contracts.
