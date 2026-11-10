from __future__ import annotations

import hashlib
import json
import os
import secrets
import sqlite3
import time
import uuid
from contextlib import contextmanager
from pathlib import Path
from typing import Any

from argon2 import PasswordHasher
from argon2.exceptions import VerifyMismatchError
from fastapi import Depends, FastAPI, Header, HTTPException, Request, Response
from fastapi.responses import FileResponse, JSONResponse
from pydantic import BaseModel, Field

DB_PATH = Path(os.environ.get("BRIDGE_DB_PATH", "/data/bridge.sqlite3"))
COOKIE_NAME = "mc_remote_session"
COOKIE_PATH = os.environ.get("BRIDGE_COOKIE_PATH", "/remote/")
COOKIE_SECURE = os.environ.get("BRIDGE_COOKIE_SECURE", "1") != "0"
SESSION_TTL = 12 * 60 * 60
PAIR_TTL = 15 * 60
JOB_TTL = 5 * 60
RUNNING_JOB_TTL = 60 * 60
MAX_RESULT_BYTES = 6 * 1024 * 1024
MAX_PENDING_PER_AGENT = 12
PASSWORDS = PasswordHasher(time_cost=3, memory_cost=65536, parallelism=2)

ALLOWED_ACTIONS = {
    "pc.status", "pc.screenshot", "pc.files.list", "pc.files.read",
    "pc.files.write", "pc.files.delete", "pc.app.launch", "pc.terminal.run",
    "pc.desktop.click", "pc.desktop.type", "pc.desktop.hotkey",
    "pc.desktop.scroll",
    "ftp.status", "ftp.list", "ftp.read_text", "ftp.upload_file",
    "ftp.download_file", "ftp.mkdir", "ftp.rename", "ftp.delete_file",
    "ftp.backup_tree", "ftp.preview_clear_root", "ftp.clear_root",
}

app = FastAPI(title="MuchoCore Remote Bridge", version="0.1.0",
              docs_url=None, redoc_url=None, openapi_url=None)


def _now() -> int:
    return int(time.time())


def _hash(value: str) -> str:
    return hashlib.sha256(value.encode("utf-8")).hexdigest()


@contextmanager
def _db():
    DB_PATH.parent.mkdir(parents=True, exist_ok=True)
    conn = sqlite3.connect(DB_PATH, timeout=10, isolation_level=None)
    conn.row_factory = sqlite3.Row
    conn.execute("PRAGMA journal_mode=WAL")
    conn.execute("PRAGMA foreign_keys=ON")
    conn.execute("PRAGMA busy_timeout=10000")
    try:
        yield conn
    finally:
        conn.close()


def init_db() -> None:
    with _db() as db:
        db.executescript("""
        CREATE TABLE IF NOT EXISTS admin (
            id INTEGER PRIMARY KEY CHECK(id=1),
            password_hash TEXT NOT NULL,
            created_at INTEGER NOT NULL
        );
        CREATE TABLE IF NOT EXISTS sessions (
            token_hash TEXT PRIMARY KEY,
            csrf_token TEXT NOT NULL,
            created_at INTEGER NOT NULL,
            expires_at INTEGER NOT NULL
        );
        CREATE TABLE IF NOT EXISTS pairings (
            token_hash TEXT PRIMARY KEY,
            name TEXT NOT NULL,
            created_at INTEGER NOT NULL,
            expires_at INTEGER NOT NULL,
            used_at INTEGER
        );
        CREATE TABLE IF NOT EXISTS agents (
            id TEXT PRIMARY KEY,
            name TEXT NOT NULL,
            token_hash TEXT NOT NULL,
            created_at INTEGER NOT NULL,
            last_seen INTEGER,
            status TEXT NOT NULL DEFAULT 'offline',
            version TEXT,
            capabilities_json TEXT NOT NULL DEFAULT '[]',
            revoked_at INTEGER
        );
        CREATE TABLE IF NOT EXISTS jobs (
            id TEXT PRIMARY KEY,
            agent_id TEXT NOT NULL REFERENCES agents(id),
            action TEXT NOT NULL,
            payload_json TEXT NOT NULL,
            status TEXT NOT NULL,
            created_at INTEGER NOT NULL,
            claimed_at INTEGER,
            finished_at INTEGER,
            result_json TEXT,
            error TEXT
        );
        CREATE INDEX IF NOT EXISTS jobs_agent_status_created
            ON jobs(agent_id,status,created_at);
        CREATE TABLE IF NOT EXISTS audit (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            created_at INTEGER NOT NULL,
            actor TEXT NOT NULL,
            action TEXT NOT NULL,
            target TEXT,
            details_json TEXT NOT NULL DEFAULT '{}'
        );
        CREATE INDEX IF NOT EXISTS audit_created ON audit(created_at DESC);
        """)


def _audit(db: sqlite3.Connection, actor: str, action: str,
           target: str | None = None, details: dict[str, Any] | None = None) -> None:
    db.execute(
        "INSERT INTO audit(created_at,actor,action,target,details_json) VALUES(?,?,?,?,?)",
        (_now(), actor, action, target,
         json.dumps(details or {}, ensure_ascii=False)[:12000]),
    )


def _get_session(request: Request) -> sqlite3.Row:
    raw = request.cookies.get(COOKIE_NAME, "")
    if len(raw) < 40:
        raise HTTPException(401, "Authentication required")
    with _db() as db:
        row = db.execute(
            "SELECT * FROM sessions WHERE token_hash=? AND expires_at>?",
            (_hash(raw), _now()),
        ).fetchone()
    if not row:
        raise HTTPException(401, "Session expired")
    return row


def require_admin(request: Request) -> sqlite3.Row:
    return _get_session(request)


def require_admin_write(
    request: Request,
    x_csrf_token: str | None = Header(default=None, alias="X-CSRF-Token"),
) -> sqlite3.Row:
    session = _get_session(request)
    if not x_csrf_token or not secrets.compare_digest(
        x_csrf_token, session["csrf_token"]
    ):
        raise HTTPException(403, "CSRF validation failed")
    return session


def _agent_from_request(authorization: str | None) -> sqlite3.Row:
    if not authorization or not authorization.startswith("Bearer "):
        raise HTTPException(401, "Agent token required")
    token = authorization[7:].strip()
    if len(token) < 40:
        raise HTTPException(401, "Invalid agent token")
    with _db() as db:
        row = db.execute(
            "SELECT * FROM agents WHERE token_hash=? AND revoked_at IS NULL",
            (_hash(token),),
        ).fetchone()
    if not row:
        raise HTTPException(401, "Unknown or revoked agent")
    return row


class LoginRequest(BaseModel):
    password: str = Field(min_length=12, max_length=256)


class PairRequest(BaseModel):
    pairing_token: str = Field(min_length=32, max_length=128)
    name: str = Field(min_length=1, max_length=64)


class HeartbeatRequest(BaseModel):
    version: str = Field(default="0.1.0", max_length=32)
    capabilities: list[str] = Field(default_factory=list, max_length=32)


class JobRequest(BaseModel):
    agent_id: str = Field(min_length=8, max_length=64)
    action: str = Field(min_length=3, max_length=48)
    payload: dict[str, Any] = Field(default_factory=dict)


class JobResultRequest(BaseModel):
    job_id: str = Field(min_length=8, max_length=64)
    status: str = Field(pattern="^(completed|denied|failed)$")
    result: dict[str, Any] = Field(default_factory=dict)
    error: str | None = Field(default=None, max_length=2000)


def _json_size(value: Any) -> int:
    return len(json.dumps(value, ensure_ascii=False,
                          separators=(",", ":")).encode("utf-8"))


def _validate_job(action: str, payload: dict[str, Any]) -> None:
    if action not in ALLOWED_ACTIONS:
        raise HTTPException(400, "Unsupported agent action")
    if _json_size(payload) > 64 * 1024:
        raise HTTPException(413, "Job payload is too large")
    if action == "pc.terminal.run":
        command = payload.get("command")
        if not isinstance(command, str) or not command.strip() or len(command) > 6000:
            raise HTTPException(400, "Terminal command must be 1–6000 characters")
        if __import__("re").search(r"(?i)(password|passwd|secret|token|api[_-]?key)\s*[:=]\s*\S+", command):
            raise HTTPException(400, "Do not place passwords, tokens or API keys in terminal commands")
    if action == "pc.desktop.type":
        text = payload.get("text")
        if not isinstance(text, str) or len(text) > 2000:
            raise HTTPException(400, "Desktop text must be at most 2000 characters")
    if action == "pc.desktop.click":
        for key in ("x", "y"):
            if not isinstance(payload.get(key), int) or not 0 <= payload[key] <= 20000:
                raise HTTPException(400, "Click coordinates are invalid")
    if action == "pc.files.write":
        content = payload.get("content")
        if not isinstance(content, str) or len(content.encode("utf-8")) > 256 * 1024:
            raise HTTPException(400, "File content must be UTF-8 text under 256 KiB")
    if action == "pc.files.delete":
        path = payload.get("path")
        if not isinstance(path, str) or not path.strip() or len(path) > 1000:
            raise HTTPException(400, "A relative file path is required")
    if action.startswith("ftp."):
        for key in ("path", "remote_path", "source", "destination", "local_path", "backup_id", "confirmation"):
            value = payload.get(key)
            if value is not None and (not isinstance(value, str) or len(value) > 2000):
                raise HTTPException(400, "Invalid FTPS path or confirmation field")
        if action == "ftp.upload_file" and not payload.get("remote_path"):
            raise HTTPException(400, "Remote destination path is required")
        if action == "ftp.download_file" and not payload.get("local_path"):
            raise HTTPException(400, "Local destination path is required")
        if action == "ftp.clear_root" and not payload.get("backup_id"):
            raise HTTPException(400, "Verified backup ID is required")


@app.middleware("http")
async def no_store_and_size_guard(request: Request, call_next):
    if request.url.path.startswith("/api/") and request.method in {"POST", "PUT", "PATCH"}:
        length = request.headers.get("content-length", "")
        if length.isdigit() and int(length) > MAX_RESULT_BYTES + 65536:
            return JSONResponse({"detail": "Request body too large"}, status_code=413)
    response = await call_next(request)
    response.headers["Cache-Control"] = "no-store, no-cache, must-revalidate"
    response.headers["Pragma"] = "no-cache"
    response.headers["X-Content-Type-Options"] = "nosniff"
    response.headers["Referrer-Policy"] = "no-referrer"
    response.headers["X-Frame-Options"] = "DENY"
    return response


@app.on_event("startup")
def startup():
    init_db()


@app.get("/healthz")
def healthz():
    return {"ok": True, "service": "muchocore-remote-bridge", "version": "0.1.0"}


@app.get("/")
def index():
    return FileResponse(Path(__file__).parent / "web" / "index.html",
                        media_type="text/html; charset=utf-8")


_login_failures: dict[str, list[int]] = {}


@app.post("/api/v1/auth/login")
def login(body: LoginRequest, request: Request, response: Response):
    ip = request.headers.get("x-real-ip") or (
        request.client.host if request.client else "unknown"
    )
    now = _now()
    bucket = _login_failures.setdefault(ip, [])
    bucket[:] = [stamp for stamp in bucket if now - stamp < 900]
    if len(bucket) >= 8:
        raise HTTPException(429, "Too many login attempts. Wait 15 minutes.")
    with _db() as db:
        row = db.execute("SELECT password_hash FROM admin WHERE id=1").fetchone()
        if not row:
            raise HTTPException(503, "Admin is not initialized. Run the server-side init-admin command.")
        try:
            PASSWORDS.verify(row["password_hash"], body.password)
        except VerifyMismatchError:
            bucket.append(now)
            _audit(db, "unknown", "login_failed", ip)
            raise HTTPException(401, "Invalid password")
        if PASSWORDS.check_needs_rehash(row["password_hash"]):
            db.execute("UPDATE admin SET password_hash=? WHERE id=1",
                       (PASSWORDS.hash(body.password),))
        raw_session = secrets.token_urlsafe(48)
        csrf_token = secrets.token_urlsafe(32)
        db.execute(
            "INSERT INTO sessions(token_hash,csrf_token,created_at,expires_at) VALUES(?,?,?,?)",
            (_hash(raw_session), csrf_token, now, now + SESSION_TTL),
        )
        _audit(db, "admin", "login", ip)
    bucket.clear()
    response.set_cookie(
        COOKIE_NAME, raw_session, httponly=True, secure=COOKIE_SECURE,
        samesite="strict", path=COOKIE_PATH, max_age=SESSION_TTL,
    )
    return {"ok": True, "csrf_token": csrf_token, "expires_in": SESSION_TTL}


@app.get("/api/v1/auth/me")
def auth_me(session: sqlite3.Row = Depends(require_admin)):
    return {"authenticated": True, "csrf_token": session["csrf_token"]}


@app.post("/api/v1/auth/logout")
def logout(request: Request, response: Response,
           _: sqlite3.Row = Depends(require_admin_write)):
    raw = request.cookies.get(COOKIE_NAME, "")
    with _db() as db:
        db.execute("DELETE FROM sessions WHERE token_hash=?", (_hash(raw),))
        _audit(db, "admin", "logout")
    response.delete_cookie(COOKIE_NAME, path=COOKIE_PATH)
    return {"ok": True}


@app.post("/api/v1/agent/pair")
def pair_agent(body: PairRequest):
    now = _now()
    with _db() as db:
        pairing = db.execute(
            "SELECT * FROM pairings WHERE token_hash=? AND used_at IS NULL AND expires_at>?",
            (_hash(body.pairing_token), now),
        ).fetchone()
        if not pairing:
            raise HTTPException(401, "Pairing token is invalid, expired or already used")
        agent_id = uuid.uuid4().hex
        agent_token = secrets.token_urlsafe(48)
        db.execute("UPDATE pairings SET used_at=? WHERE token_hash=?",
                   (now, pairing["token_hash"]))
        db.execute(
            "INSERT INTO agents(id,name,token_hash,created_at,status,capabilities_json) VALUES(?,?,?,?,?,?)",
            (agent_id, body.name.strip(), _hash(agent_token), now, "offline", "[]"),
        )
        _audit(db, "pairing", "agent_paired", agent_id, {"name": body.name.strip()})
    return {"agent_id": agent_id, "agent_token": agent_token, "version": "0.1.0"}


@app.post("/api/v1/agent/heartbeat")
def agent_heartbeat(body: HeartbeatRequest,
                    authorization: str | None = Header(default=None)):
    agent = _agent_from_request(authorization)
    capabilities = [x[:48] for x in body.capabilities[:32] if isinstance(x, str)]
    with _db() as db:
        db.execute(
            "UPDATE agents SET last_seen=?,status='online',version=?,capabilities_json=? WHERE id=?",
            (_now(), body.version, json.dumps(capabilities), agent["id"]),
        )
    return {"ok": True, "server_time": _now()}


@app.post("/api/v1/agent/poll")
def agent_poll(authorization: str | None = Header(default=None)):
    agent = _agent_from_request(authorization)
    now = _now()
    with _db() as db:
        db.execute(
            "UPDATE jobs SET status='expired',finished_at=? WHERE agent_id=? AND status='pending' AND created_at<?",
            (now, agent["id"], now - JOB_TTL),
        )
        db.execute(
            "UPDATE jobs SET status='failed',finished_at=?,error='Agent result timed out' "
            "WHERE agent_id=? AND status='running' AND claimed_at<?",
            (now, agent["id"], now - RUNNING_JOB_TTL),
        )
        db.execute("BEGIN IMMEDIATE")
        row = db.execute(
            "SELECT * FROM jobs WHERE agent_id=? AND status='pending' ORDER BY created_at LIMIT 1",
            (agent["id"],),
        ).fetchone()
        if not row:
            db.execute("COMMIT")
            return Response(status_code=204)
        db.execute("UPDATE jobs SET status='running',claimed_at=? WHERE id=?",
                   (now, row["id"]))
        db.execute("COMMIT")
    return {
        "job_id": row["id"], "action": row["action"],
        "payload": json.loads(row["payload_json"]), "created_at": row["created_at"],
    }


@app.post("/api/v1/agent/result")
def agent_result(body: JobResultRequest,
                 authorization: str | None = Header(default=None)):
    agent = _agent_from_request(authorization)
    if _json_size(body.result) > MAX_RESULT_BYTES:
        raise HTTPException(413, "Result is too large")
    with _db() as db:
        row = db.execute(
            "SELECT action FROM jobs WHERE id=? AND agent_id=? AND status='running'",
            (body.job_id, agent["id"]),
        ).fetchone()
        if not row:
            raise HTTPException(404, "Running job not found")
        db.execute(
            "UPDATE jobs SET status=?,finished_at=?,result_json=?,error=? WHERE id=?",
            (body.status, _now(), json.dumps(body.result, ensure_ascii=False),
             body.error, body.job_id),
        )
        _audit(db, "agent:" + agent["id"], "job_" + body.status,
               body.job_id, {"action": row["action"]})
    return {"ok": True}


@app.get("/api/v1/admin/agents")
def list_agents(_: sqlite3.Row = Depends(require_admin)):
    with _db() as db:
        rows = db.execute(
            "SELECT id,name,created_at,last_seen,status,version,capabilities_json,revoked_at "
            "FROM agents ORDER BY created_at DESC"
        ).fetchall()
    now = _now()
    return [{
        "id": r["id"], "name": r["name"], "created_at": r["created_at"],
        "last_seen": r["last_seen"],
        "status": "online" if r["last_seen"] and now-r["last_seen"] < 45 and not r["revoked_at"] else "offline",
        "version": r["version"], "capabilities": json.loads(r["capabilities_json"]),
        "revoked": bool(r["revoked_at"]),
    } for r in rows]


@app.post("/api/v1/admin/jobs")
def create_job(body: JobRequest, _: sqlite3.Row = Depends(require_admin_write)):
    _validate_job(body.action, body.payload)
    now = _now()
    job_id = uuid.uuid4().hex
    with _db() as db:
        agent = db.execute(
            "SELECT id,last_seen,revoked_at FROM agents WHERE id=?", (body.agent_id,)
        ).fetchone()
        if not agent or agent["revoked_at"]:
            raise HTTPException(404, "Agent not found or revoked")
        if not agent["last_seen"] or now-agent["last_seen"] > 90:
            raise HTTPException(409, "Agent is offline")
        pending = db.execute(
            "SELECT COUNT(*) AS n FROM jobs WHERE agent_id=? AND status IN ('pending','running')",
            (body.agent_id,),
        ).fetchone()["n"]
        if pending >= MAX_PENDING_PER_AGENT:
            raise HTTPException(429, "Agent queue is full")
        db.execute(
            "INSERT INTO jobs(id,agent_id,action,payload_json,status,created_at) VALUES(?,?,?,?,?,?)",
            (job_id, body.agent_id, body.action,
             json.dumps(body.payload, ensure_ascii=False), "pending", now),
        )
        _audit(db, "admin", "job_queued", job_id,
               {"agent_id": body.agent_id, "action": body.action})
    return {"job_id": job_id, "status": "pending"}


@app.get("/api/v1/admin/jobs")
def list_jobs(limit: int = 30, _: sqlite3.Row = Depends(require_admin)):
    limit = max(1, min(limit, 100))
    with _db() as db:
        rows = db.execute(
            "SELECT id,agent_id,action,payload_json,status,created_at,claimed_at,finished_at,result_json,error "
            "FROM jobs ORDER BY created_at DESC LIMIT ?", (limit,)
        ).fetchall()
    items = []
    for row in rows:
        item = dict(row)
        item["payload"] = json.loads(item.pop("payload_json"))
        item["result"] = json.loads(item.pop("result_json")) if item["result_json"] else None
        items.append(item)
    return items


@app.get("/api/v1/admin/jobs/{job_id}")
def get_job(job_id: str, _: sqlite3.Row = Depends(require_admin)):
    with _db() as db:
        row = db.execute("SELECT * FROM jobs WHERE id=?", (job_id,)).fetchone()
    if not row:
        raise HTTPException(404, "Job not found")
    return {
        "id": row["id"], "agent_id": row["agent_id"], "action": row["action"],
        "payload": json.loads(row["payload_json"]), "status": row["status"],
        "created_at": row["created_at"], "claimed_at": row["claimed_at"],
        "finished_at": row["finished_at"],
        "result": json.loads(row["result_json"]) if row["result_json"] else None,
        "error": row["error"],
    }


@app.post("/api/v1/admin/jobs/{job_id}/cancel")
def cancel_job(job_id: str, _: sqlite3.Row = Depends(require_admin_write)):
    with _db() as db:
        cur = db.execute(
            "UPDATE jobs SET status='cancelled',finished_at=? WHERE id=? AND status='pending'",
            (_now(), job_id),
        )
        if cur.rowcount != 1:
            raise HTTPException(409, "Job is not pending and cannot be cancelled")
        _audit(db, "admin", "job_cancelled", job_id)
    return {"ok": True}


@app.post("/api/v1/admin/agents/{agent_id}/revoke")
def revoke_agent(agent_id: str, _: sqlite3.Row = Depends(require_admin_write)):
    with _db() as db:
        cur = db.execute(
            "UPDATE agents SET revoked_at=?,status='revoked' WHERE id=? AND revoked_at IS NULL",
            (_now(), agent_id),
        )
        if cur.rowcount != 1:
            raise HTTPException(404, "Agent not found or already revoked")
        db.execute(
            "UPDATE jobs SET status='cancelled',finished_at=? WHERE agent_id=? AND status='pending'",
            (_now(), agent_id),
        )
        _audit(db, "admin", "agent_revoked", agent_id)
    return {"ok": True}


@app.get("/api/v1/admin/audit")
def audit_log(limit: int = 50, _: sqlite3.Row = Depends(require_admin)):
    limit = max(1, min(limit, 200))
    with _db() as db:
        rows = db.execute(
            "SELECT created_at,actor,action,target,details_json FROM audit ORDER BY id DESC LIMIT ?",
            (limit,),
        ).fetchall()
    return [{
        "created_at": r["created_at"], "actor": r["actor"], "action": r["action"],
        "target": r["target"], "details": json.loads(r["details_json"]),
    } for r in rows]
