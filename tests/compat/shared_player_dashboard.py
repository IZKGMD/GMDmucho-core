"""Exercise the real compiled browser portal via the shared HTTP controller."""
import http.cookies
import re
import time
import urllib.error
import urllib.parse
import urllib.request


class NoRedirect(urllib.request.HTTPRedirectHandler):
    def redirect_request(self, *args, **kwargs):
        return None


def check_music_delivery(origin, fixture):
    fixture("music-seed")
    path = origin + "/mc/music/" + "a" * 40 + ".mp3"
    expected = b"ID3" + b"0123456789" * 100

    def download(status, body, headers=None, method="GET"):
        request = urllib.request.Request(path, headers=headers or {}, method=method)
        try:
            result = urllib.request.urlopen(request, timeout=15)
        except urllib.error.HTTPError as error:
            result = error
        with result:
            assert result.status == status, (method, headers, result.status)
            assert result.read() == body, (method, headers, status)
            return result.headers

    headers = download(200, b"", method="HEAD")
    assert headers["Accept-Ranges"] == "bytes" and int(headers["Content-Length"]) == len(expected)
    assert headers.get_content_type() == "audio/mpeg"
    etag = headers["ETag"]
    headers = download(206, expected[3:10], {"Range": "bytes=3-9", "If-Range": etag})
    assert headers["Content-Range"] == "bytes 3-9/1003" and headers["Content-Length"] == "7"
    download(206, expected[-8:], {"Range": "bytes=-8"})
    download(206, expected[-3:], {"Range": "bytes=1000-"})
    download(200, expected, {"Range": "bytes=3-9", "If-Range": '"old-version"'})
    for invalid in ("bytes=1003-", "bytes=9-3", "bytes=-0", "bytes=1-3,5-6"):
        headers = download(416, b"", {"Range": invalid})
        assert headers["Content-Range"] == "bytes */1003"
    fixture("music-unverify")
    download(404, b"")


def check_player_dashboard(origin, fixture):
    opener = urllib.request.build_opener(NoRedirect())
    cookies = {}

    def request(path, data=None, expected=200):
        headers = {"Cookie": "; ".join(k + "=" + v for k, v in cookies.items())}
        encoded = None if data is None else urllib.parse.urlencode(data).encode()
        try:
            response = opener.open(urllib.request.Request(origin + path, data=encoded, headers=headers), timeout=15)
        except urllib.error.HTTPError as error:
            response = error
        with response:
            for value in response.headers.get_all("Set-Cookie", []):
                parsed = http.cookies.SimpleCookie(value)
                for key, item in parsed.items():
                    cookies[key] = item.value
                    assert item["path"] == "/mc/", "Player session escaped installation mount"
                    assert item["secure"] and item["httponly"], "Unprotected player cookie"
            body = response.read().decode()
            assert response.status == expected, (path, response.status, body[:300])
            assert "Warning:" not in body and "Fatal error" not in body, body[:500]
            return body, response.headers

    def token(body, name):
        match = re.search(r'name="' + name + r'"\s+value="([a-f0-9]+)"', body)
        assert match, "Missing form field " + name
        return match[1]

    for path in ("/mc/dashboard", "/mc/dashboard/", "/mc/dashboard/index.php"):
        page, headers = request(path)
        assert headers.get_content_type() == "text/html" and "Search a player username" in page
        assert 'href="/mc/dashboard' in page and 'href="/mc/public/assets/product-ui.css' in page
        assert 'src="/mc/public/assets/product-ui.js' in page
    csrf = token(page, "csrf")
    anti = token(page, "antibot_token")
    request("/mc/dashboard", {"action": "login", "csrf": "wrong"}, 403)
    time.sleep(1.05)  # Exercise the real form-age guard, without bypassing it.
    _, headers = request("/mc/dashboard", {"action": "login", "csrf": csrf,
        "antibot_token": anti, "website": "", "username": "P0Other", "password": "P0Fixture-2!"}, 302)
    assert headers["Location"] == "/mc/dashboard", "Login redirect lost mount"
    page, _ = request("/mc/dashboard")
    assert "Signed in as P0Other" in page and 'name="music_file"' in page
    profile, _ = request("/mc/dashboard?u=P0Other")
    assert "Player profile" in profile and "P0Other" in profile
    search, _ = request("/mc/dashboard?search=P0Other")
    assert "P0Other" in search and 'href="/mc/dashboard?u=P0Other"' in search
    clans, _ = request("/mc/dashboard/clans.php")
    assert "Create clan" in clans and 'href="/mc/dashboard"' in clans
    fixture("demonlist-seed")
    try:
        demon_path = "/mc/demonlist.php?level=951"
        demon, _ = request(demon_path)
        assert "Shared Demon" in demon and 'href="/mc/public/assets/demonlist.css"' in demon
        proof = {"level_id": 951, "proof": "https://youtu.be/abcdefghijk", "csrf": token(demon, "csrf")}
        request(demon_path, dict(proof, csrf="wrong"), 403)
        _, headers = request(demon_path, proof, 303)
        assert headers["Location"] == demon_path
        pending, _ = request(demon_path)
        assert "awaiting review" in pending and "Watch run" not in pending
        fixture("demonlist-approve")
        approved, _ = request(demon_path)
        assert "Watch run" in approved and "Your record was approved" in approved
        fixture("demonlist-revoke")
        removed, _ = request(demon_path)
        assert "Watch run" not in removed and "Review &lt;the recording&gt;." in removed
        _, headers = request(demon_path, dict(proof, csrf=token(removed, "csrf")), 303)
        assert headers["Location"] == demon_path
        resent, _ = request(demon_path)
        assert "awaiting review" in resent and "the recording" not in resent
    finally:
        fixture("demonlist-clear")
    fixture("read-only")
    try:
        request("/mc/dashboard/clans.php", {"action": "create", "csrf": token(clans, "csrf"),
            "clanName": "Disabled Write", "clanTag": "NO"}, 403)
        request("/mc/dashboard")
    finally:
        fixture("writable")
    csrf = token(page, "csrf")
    _, headers = request("/mc/dashboard", {"action": "logout", "csrf": csrf}, 302)
    assert headers["Location"] == "/mc/dashboard"
    page, _ = request("/mc/dashboard")
    assert 'name="password"' in page
    downloads, _ = request("/mc/clients", expected=503)
    assert "Explore the community" in downloads and 'href="/mc/dashboard"' in downloads
    request("/mc/profile-image.php?account=2&kind=avatar&version=invalid", expected=404)
