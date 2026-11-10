import time

import pytest
from argon2 import PasswordHasher
from fastapi.testclient import TestClient

from remote_bridge import server


@pytest.fixture
def client(tmp_path, monkeypatch):
    monkeypatch.setattr(server, "DB_PATH", tmp_path / "bridge.sqlite3")
    monkeypatch.setattr(server, "COOKIE_SECURE", False)
    monkeypatch.setattr(server, "COOKIE_PATH", "/")
    server._login_failures.clear()
    server.init_db()
    with server._db() as db:
        db.execute(
            "INSERT INTO admin(id,password_hash,created_at) VALUES(1,?,?)",
            (PasswordHasher().hash("correct-horse-battery-staple"), int(time.time())),
        )
    with TestClient(server.app) as test_client:
        yield test_client


def login(client):
    response = client.post(
        "/api/v1/auth/login",
        json={"password": "correct-horse-battery-staple"},
    )
    assert response.status_code == 200
    return response.json()["csrf_token"]


def pair(client):
    token = "p" * 48
    with server._db() as db:
        db.execute(
            "INSERT INTO pairings(token_hash,name,created_at,expires_at) VALUES(?,?,?,?)",
            (server._hash(token), "Test PC", int(time.time()), int(time.time()) + 900),
        )
    response = client.post(
        "/api/v1/agent/pair",
        json={"pairing_token": token, "name": "Test PC"},
    )
    assert response.status_code == 200
    return response.json()


def test_health_does_not_require_auth(client):
    response = client.get("/healthz")
    assert response.status_code == 200
    assert response.json()["service"] == "muchocore-remote-bridge"


def test_admin_login_sets_secure_session_cookie_and_csrf(client):
    response = client.post(
        "/api/v1/auth/login",
        json={"password": "correct-horse-battery-staple"},
    )
    assert response.status_code == 200
    cookie = response.headers["set-cookie"].lower()
    assert "httponly" in cookie
    assert "samesite=strict" in cookie
    assert response.json()["csrf_token"]


def test_admin_endpoints_require_session(client):
    assert client.get("/api/v1/admin/agents").status_code == 401
    assert client.post("/api/v1/admin/jobs", json={
        "agent_id": "x" * 32, "action": "pc.status", "payload": {}
    }).status_code == 401


def test_pairing_token_is_one_time(client):
    token = "q" * 48
    with server._db() as db:
        db.execute(
            "INSERT INTO pairings(token_hash,name,created_at,expires_at) VALUES(?,?,?,?)",
            (server._hash(token), "One time", int(time.time()), int(time.time()) + 900),
        )
    body = {"pairing_token": token, "name": "Windows"}
    assert client.post("/api/v1/agent/pair", json=body).status_code == 200
    assert client.post("/api/v1/agent/pair", json=body).status_code == 401


def test_agent_poll_result_and_admin_job_flow(client):
    csrf = login(client)
    paired = pair(client)
    headers = {"Authorization": "Bearer " + paired["agent_token"]}
    hb = client.post("/api/v1/agent/heartbeat", headers=headers, json={
        "version": "test", "capabilities": ["status", "terminal"]
    })
    assert hb.status_code == 200

    denied = client.post("/api/v1/admin/jobs", json={
        "agent_id": paired["agent_id"], "action": "pc.status", "payload": {}
    })
    assert denied.status_code == 403

    queued = client.post("/api/v1/admin/jobs", headers={"X-CSRF-Token": csrf}, json={
        "agent_id": paired["agent_id"],
        "action": "pc.terminal.run",
        "payload": {"root_index": 0, "cwd": "", "command": "git status"},
    })
    assert queued.status_code == 200
    job_id = queued.json()["job_id"]

    claimed = client.post("/api/v1/agent/poll", headers=headers)
    assert claimed.status_code == 200
    assert claimed.json()["job_id"] == job_id
    assert claimed.json()["action"] == "pc.terminal.run"

    completed = client.post("/api/v1/agent/result", headers=headers, json={
        "job_id": job_id, "status": "completed",
        "result": {"returncode": 0, "output": "working tree clean"},
    })
    assert completed.status_code == 200

    result = client.get("/api/v1/admin/jobs/" + job_id)
    assert result.status_code == 200
    assert result.json()["status"] == "completed"
    assert result.json()["result"]["returncode"] == 0


def test_unknown_agent_action_is_rejected(client):
    csrf = login(client)
    paired = pair(client)
    headers = {"Authorization": "Bearer " + paired["agent_token"]}
    client.post("/api/v1/agent/heartbeat", headers=headers, json={})
    response = client.post(
        "/api/v1/admin/jobs",
        headers={"X-CSRF-Token": csrf},
        json={"agent_id": paired["agent_id"], "action": "server.exec", "payload": {"cmd": "id"}},
    )
    assert response.status_code == 400


def test_revoked_agent_cannot_poll(client):
    csrf = login(client)
    paired = pair(client)
    revoke = client.post(
        "/api/v1/admin/agents/" + paired["agent_id"] + "/revoke",
        headers={"X-CSRF-Token": csrf},
    )
    assert revoke.status_code == 200
    response = client.post(
        "/api/v1/agent/poll",
        headers={"Authorization": "Bearer " + paired["agent_token"]},
    )
    assert response.status_code == 401


def test_agent_result_cannot_complete_another_agents_job(client):
    login(client)
    paired = pair(client)
    headers = {"Authorization": "Bearer " + paired["agent_token"]}
    client.post("/api/v1/agent/heartbeat", headers=headers, json={})
    # An arbitrary ID is not a running job owned by this agent.
    response = client.post("/api/v1/agent/result", headers=headers, json={
        "job_id": "f" * 32, "status": "completed", "result": {}
    })
    assert response.status_code == 404
