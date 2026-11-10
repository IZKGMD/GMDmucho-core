from pathlib import Path

import yaml

ROOT = Path(__file__).resolve().parents[2]


def test_remote_bridge_is_private_unprivileged_compose_service():
    compose = yaml.safe_load((ROOT / "docker-compose.yml").read_text(encoding="utf-8"))
    service = compose["services"]["remote-bridge"]
    assert service["read_only"] is True
    assert "ports" not in service
    assert "remote_bridge_data:/data" in service["volumes"]
    assert "no-new-privileges:true" in service["security_opt"]
    assert "ALL" in service["cap_drop"]
    assert service["mem_limit"] == "256m"
    assert "remote-bridge" in compose["services"]["caddy"]["depends_on"]
    assert "MUCHOCORE_REMOTE_HOST" in compose["services"]["caddy"]["environment"]


def test_caddy_exposes_bridge_only_on_canonical_host():
    caddy = (ROOT / "docker" / "Caddyfile").read_text(encoding="utf-8")
    assert "host {$MUCHOCORE_REMOTE_HOST}" in caddy
    assert "path /remote/*" in caddy
    assert "handle_path @muchocore_remote" in caddy
    assert "reverse_proxy remote-bridge:8777" in caddy


def test_agent_is_not_installed_as_a_hidden_service():
    agent = (ROOT / "remote_bridge" / "agent.py").read_text(encoding="utf-8")
    assert "Visible foreground agent" in agent
    assert "Ctrl+C stops remote access immediately" in agent
    assert "shell=False" in agent
    assert "powershell.exe" in agent
