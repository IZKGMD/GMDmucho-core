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
