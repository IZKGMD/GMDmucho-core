from __future__ import annotations

import argparse
import getpass
import secrets
import time

from argon2 import PasswordHasher

from remote_bridge.server import _audit, _db, _hash, init_db


def init_admin():
    init_db()
    password = getpass.getpass("Remote Bridge admin password (min 16 chars): ")
    confirm = getpass.getpass("Repeat password: ")
    if password != confirm or len(password) < 16:
        raise SystemExit("Passwords do not match or are shorter than 16 characters.")
    ph = PasswordHasher(time_cost=3, memory_cost=65536, parallelism=2)
    with _db() as db:
        db.execute(
            "INSERT INTO admin(id,password_hash,created_at) VALUES(1,?,?) "
            "ON CONFLICT(id) DO UPDATE SET password_hash=excluded.password_hash",
            (ph.hash(password), int(time.time())),
        )
        db.execute("DELETE FROM sessions")
        _audit(db, "cli", "admin_password_set")
    print("Admin password initialized. No plaintext password was written to disk.")


def pair_agent(name: str, ttl_minutes: int):
    init_db()
    token = secrets.token_urlsafe(36)
    expires = int(time.time()) + ttl_minutes * 60
    with _db() as db:
        db.execute(
            "INSERT INTO pairings(token_hash,name,created_at,expires_at) VALUES(?,?,?,?)",
            (_hash(token), name[:64], int(time.time()), expires),
        )
        _audit(db, "cli", "pairing_created", name, {"expires_at": expires})
    print("\nONE-TIME PAIRING TOKEN — shown only once:\n")
    print(token)
    print(f"\nExpires in {ttl_minutes} minutes. Enter it only in the local Windows setup program.")


def main():
    parser = argparse.ArgumentParser(description="MuchoCore Remote Bridge administration")
    sub = parser.add_subparsers(dest="command", required=True)
    sub.add_parser("init-admin")
    pair = sub.add_parser("pair-agent")
    pair.add_argument("--name", default="Windows-PC")
    pair.add_argument("--ttl-minutes", type=int, default=15)
    args = parser.parse_args()
    if args.command == "init-admin":
        init_admin()
    elif args.command == "pair-agent":
        if not 1 <= args.ttl_minutes <= 30:
            raise SystemExit("TTL must be 1–30 minutes.")
        pair_agent(args.name, args.ttl_minutes)


if __name__ == "__main__":
    main()
