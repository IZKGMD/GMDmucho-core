#!/usr/bin/env python3
"""Safe, read-only GD 2.209 shared-host News and routing diagnostic.

Does not send credentials, register accounts, change hosting configuration,
download APKs, or print any account or news content.
"""
from __future__ import annotations

import argparse
import base64
import gzip
import json
import re
import sys
import urllib.error
import urllib.parse
import urllib.request


def normalized_origin(value: str) -> str:
    url = urllib.parse.urlsplit(value)
    if (
        url.scheme != "https"
        or not url.hostname
        or url.port is not None
        or url.username is not None
        or url.password is not None
        or url.path not in ("", "/")
        or url.query
        or url.fragment
        or not re.fullmatch(r"[A-Za-z0-9.-]+", url.hostname)
    ):
        raise ValueError("Enter HTTPS origin only, e.g. https://your-gdps.5v.pl")
    return "https://" + url.hostname.lower()


def fetch(origin: str, path: str, method: str = "GET", timeout: float = 10):
    req = urllib.request.Request(
        origin + path,
        data=b"" if method == "POST" else None,
        method=method,
        headers={"User-Agent": "MuchoCore-GD2209-Route-Probe/1.0"},
    )
    try:
        with urllib.request.urlopen(req, timeout=timeout) as response:
            return int(response.status), response.read(131072)
    except urllib.error.HTTPError as error:
        return int(error.code), error.read(4096)


def check_news(origin: str, timeout: float = 10):
    report = {"origin": origin, "checks": {}}

    def record(label: str, status: int, ok: bool, summary: str) -> None:
        report["checks"][label] = {"http_status": status, "ok": ok, "message": summary}

    status, body = fetch(origin, "/mc/health", timeout=timeout)
    record("health", status, status == 200 and body.strip() == b"1",
           "Native adapter responded" if status == 200 and body.strip() == b"1"
           else "Check PHP7 adapter and database; expected HTTP 200 body 1")

    status, body = fetch(origin, "/a/getCustomContentURL.php", "POST", timeout)
    expected = (origin + "/gd-content").encode("ascii")
    lookup_ok = status == 200 and body.strip() == expected
    record("content_url", status, lookup_ok,
           "GD 2.209 content URL points at this host" if lookup_ok else
           ("Apache /a/ rewrite is missing" if status == 404 else
            "Expect " + origin + "/gd-content; verify enable_native_news and site_url"))

    status, body = fetch(origin, "/gd-content/news/news_version.txt", timeout=timeout)
    version_ok = (
        status == 200 and re.fullmatch(rb"[0-9]{7,12}", body.strip()) is not None
        and int(body.strip()) >= 1000000
    )
    record("news_version", status, version_ok,
           "News version is valid" if version_ok else
           ("Missing .txt News rewrite at domain root" if status == 404 else
            "Check native News flag, JSON source, or server response"))

    status, body = fetch(origin, "/gd-content/news/news.dat", timeout=timeout)
    payload_ok = False
    try:
        encoded = body.strip()
        decoded = base64.urlsafe_b64decode(encoded + b"=" * (-len(encoded) % 4))
        payload_ok = (
            status == 200 and gzip.decompress(decoded).startswith(b"4,")
        )
    except (ValueError, OSError, EOFError):
        pass
    record("news_data", status, payload_ok,
           "Valid compressed GD 2.209 News payload" if payload_ok else
           ("Missing .dat News rewrite at domain root" if status == 404 else
            "Expected Base64URL-encoded GZIP; check private news JSON and adapter"))

    report["status"] = "news_routes_ok_not_client_certified" if all(
        item["ok"] for item in report["checks"].values()
    ) else "news_or_routing_configuration_gap"
    report["registration"] = (
        "Experimental PHP 7 registration is disabled by default and not certified "
        "for public accounts. Use full PHP 8.2+ MuchoCore for player signups."
    )
    return report


def main(argv=None) -> int:
    parser = argparse.ArgumentParser(
        description="Check GD 2.209 native News routes without credentials or account writes"
    )
    parser.add_argument("origin", help="https://your-gdps.example (no /mc or /a)")
    args = parser.parse_args(argv)
    try:
        origin = normalized_origin(args.origin)
        report = check_news(origin)
    except (ValueError, urllib.error.URLError, TimeoutError, OSError) as error:
        print(json.dumps({"status": "probe_unavailable", "message": str(error)},
                         ensure_ascii=False, indent=2))
        return 2
    print(json.dumps(report, ensure_ascii=False, indent=2))
    return 0 if report["status"].startswith("news_routes_ok") else 1


if __name__ == "__main__":
    sys.exit(main())
