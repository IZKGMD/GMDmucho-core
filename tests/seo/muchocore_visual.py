#!/usr/bin/env python3
"""Real Chromium browser checks of the bilingual MuchoCore editorial redesign."""
from __future__ import annotations
from functools import partial
from http.server import SimpleHTTPRequestHandler, ThreadingHTTPServer
from pathlib import Path
import base64
import os
import shutil
import threading
from playwright.sync_api import sync_playwright

PUBLIC = Path(__file__).resolve().parents[2] / "public"
SHOTS = Path("/tmp/muchocore-ui-preview")
SHOTS.mkdir(parents=True, exist_ok=True)

class QuietHandler(SimpleHTTPRequestHandler):
    def log_message(self, *args):
        pass

def main():
    executable = next((p for name in ("google-chrome", "google-chrome-stable", "chromium", "chromium-browser")
                       if (p := shutil.which(name))), None)
    if not executable:
        raise RuntimeError("Chrome or Chromium required")
    server = ThreadingHTTPServer(("127.0.0.1", 0), partial(QuietHandler, directory=str(PUBLIC)))
    thread = threading.Thread(target=server.serve_forever, daemon=True)
    thread.start()
    root = f"http://127.0.0.1:{server.server_port}"
    try:
        with sync_playwright() as p:
            browser = p.chromium.launch(executable_path=executable, headless=True, args=["--no-sandbox"])
            try:
                for lang, route in (("en", "/muchocore/"), ("ru", "/muchocore/ru/")):
                    for width, height in ((360, 780), (390, 844), (430, 932), (768, 900), (1440, 900)):
                        page = browser.new_page(viewport={"width": width, "height": height}, device_scale_factor=1)
                        errors = []
                        page.on("pageerror", lambda e: errors.append(str(e)))
                        response = page.goto(root + route, wait_until="networkidle")
                        assert response and response.status == 200, (lang, width, "bad response")
                        assert page.locator("html").get_attribute("lang") == lang
                        assert page.locator("h1").count() == 1
                        assert page.locator("blockquote").count() == 1
                        assert page.locator("#install-commands").count() == 1
                        assert page.locator("body").evaluate("(b) => getComputedStyle(b).backgroundColor") == "rgb(16, 16, 23)"
                        assert page.locator(".mc-demo-window").evaluate("(e) => getComputedStyle(e).backgroundColor") == "rgb(33, 25, 46)"
                        dims = page.evaluate("""() => ({
                          viewport: innerWidth,
                          root: document.documentElement.scrollWidth,
                          body: document.body.scrollWidth,
                          heading: document.querySelector('h1').getBoundingClientRect().toJSON(),
                          actions: [...document.querySelectorAll('.mc-hero-cta a')].map(x=>x.getBoundingClientRect().toJSON())
                        })""")
                        assert dims["root"] <= dims["viewport"] + 1, (lang, width, dims)
                        assert dims["heading"]["x"] >= 0 and dims["heading"]["width"] > 200, dims
                        assert all(a["right"] <= dims["viewport"] + 1 for a in dims["actions"]), dims
                        assert not errors, (lang, width, errors)
                        tabs = page.locator('[data-demo-target]')
                        assert tabs.count() == 3
                        tabs.nth(1).click()
                        assert tabs.nth(1).get_attribute("aria-selected") == "true"
                        assert page.locator("#mc-demo-panel-1").is_visible()
                        assert not page.locator("#mc-demo-panel-0").is_visible()
                        tabs.nth(1).press("ArrowRight")
                        assert tabs.nth(2).get_attribute("aria-selected") == "true"
                        assert page.locator("#mc-demo-panel-2").is_visible()
                        assert '/' not in page.locator('.mc-demo-edition').inner_text()
                        assert '/' not in page.locator('.mc-hero-topline span').first.inner_text()
                        tabs.nth(0).click()
                        if width in (390, 1440):
                            file = SHOTS / f"muchocore-v3-{lang}-{width}.jpg"
                            page.screenshot(path=str(file), type="jpeg", quality=74)
                            full = SHOTS / f"muchocore-hybrid-{lang}-{width}-full.jpg"
                            page.screenshot(path=str(full), type="jpeg", quality=76, full_page=True)
                            print(f"PREVIEW_FULL {lang}/{width}: {full.name} {full.stat().st_size} bytes")
                            print(f"PREVIEW {lang}/{width}: {file.name} {file.stat().st_size} bytes")
                            if os.environ.get("MUCHO_PREVIEW_B64") == "1":
                                print(f"MUCHO_IMAGE_{lang.upper()}_{width}=" + base64.b64encode(file.read_bytes()).decode("ascii"))
                        if width in (360,390):
                            toggle = page.locator("#menu-toggle")
                            toggle.click()
                            assert page.locator("#main-navigation").is_visible()
                            assert toggle.get_attribute("aria-expanded") == "true"
                            toggle.press("Escape")
                            assert not page.locator("#main-navigation").is_visible()
                            assert toggle.get_attribute("aria-expanded") == "false"
                        page.close()
                        print(f"VISUAL_OK {lang}/{width}")
                # Static child pages must follow the same visual system and never overflow.
                for route, label in (("/install/", "install"), ("/muchocore/about/", "about"),
                                     ("/muchocore/compatibility/", "compatibility")):
                    for width in (390, 1440):
                        child = browser.new_page(viewport={"width":width,"height":900}, device_scale_factor=1)
                        response = child.goto(root + route, wait_until="networkidle")
                        assert response and response.status == 200, (label, response.status if response else None)
                        dims = child.evaluate("""() => ({
                            viewport: innerWidth, root: document.documentElement.scrollWidth,
                            content: document.body.scrollWidth
                        })""")
                        assert dims["root"] <= width + 1, (label, width, dims)
                        assert child.locator("h1").count() == 1, (label, "missing H1")
                        if label == "install":
                            assert child.locator(".mc-install-method").count() == 3
                        if label == "install" and width == 390:
                            child.locator("#menu-toggle").click()
                            assert child.locator("#main-navigation").is_visible()
                        if label == "install":
                            file = SHOTS / f"muchocore-v3-{label}-{width}.jpg"
                            child.screenshot(path=str(file), type="jpeg", quality=74)
                            print(f"PREVIEW {label}/{width}: {file.name} {file.stat().st_size} bytes")
                        child.close()
                        print(f"VISUAL_OK {label}/{width}")
                page = browser.new_page()
                page.goto(root + "/muchocore/", wait_until="domcontentloaded")
                page.locator('.mc-site-lang a[hreflang="ru"]').click()
                assert page.url.endswith("/muchocore/ru/")
                assert page.locator("html").get_attribute("lang") == "ru"
                assert "Всё для людей" in page.locator("blockquote").inner_text()
                page.close()
                print("VISUAL_OK language EN→RU")
            finally:
                browser.close()
    finally:
        server.shutdown()
        server.server_close()

if __name__ == "__main__":
    main()
