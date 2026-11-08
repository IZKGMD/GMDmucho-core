#!/usr/bin/env python3
"""Real-browser responsive layout check for MuchoCore EN and RU.

Requires playwright Python package and system Chrome/Chromium. Runs offline:
the files are served through a loopback-only local HTTP server.
"""
from __future__ import annotations
import base64
from functools import partial
from http.server import SimpleHTTPRequestHandler, ThreadingHTTPServer
import os
from pathlib import Path
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
    if executable is None:
        raise RuntimeError("Chrome/Chromium is required for the visual test")
    handler = partial(QuietHandler, directory=str(PUBLIC))
    server = ThreadingHTTPServer(("127.0.0.1", 0), handler)
    server_thread = threading.Thread(target=server.serve_forever, daemon=True)
    server_thread.start()
    root = f"http://127.0.0.1:{server.server_port}"
    try:
        with sync_playwright() as playwright:
            browser = playwright.chromium.launch(executable_path=executable, headless=True, args=["--no-sandbox"])
            try:
                for language, path in (("en", "/muchocore/"), ("ru", "/muchocore/ru/")):
                    for width, height in ((360, 780), (390, 844), (430, 932), (1440, 900)):
                        page = browser.new_page(viewport={"width": width, "height": height}, device_scale_factor=1)
                        errors = []
                        page.on("pageerror", lambda exc: errors.append(str(exc)))
                        response = page.goto(root + path, wait_until="networkidle")
                        assert response and response.status == 200, f"HTTP error: {language} {width}"
                        assert page.locator("html").get_attribute("lang") == language
                        assert page.locator("h1").count() == 1
                        assert page.locator("blockquote").count() == 1
                        assert page.locator("blockquote").inner_text().strip()
                        assert page.locator("#install-commands").count() == 1
                        assert page.locator("body").evaluate("(b) => getComputedStyle(b).backgroundColor") == "rgb(12, 13, 18)"
                        dims = page.evaluate("""() => ({
                            width: window.innerWidth,
                            document: document.documentElement.scrollWidth,
                            body: document.body.scrollWidth,
                            title: document.querySelector('h1').getBoundingClientRect().toJSON(),
                            buttons: [...document.querySelectorAll('.cta a')].map(a => a.getBoundingClientRect().toJSON())
                        })""")
                        assert dims["document"] <= dims["width"] + 1, f"Horizontal overflow {language}/{width}: {dims}"
                        assert dims["title"]["width"] > 200 and dims["title"]["x"] >= 0
                        assert all(b["right"] <= dims["width"] + 1 for b in dims["buttons"]), f"CTA overflow: {dims}"
                        assert not errors, f"Runtime error in {language}/{width}: {errors}"
                        if width == 390:
                            file = SHOTS / f"muchocore-{language}-390.jpg"
                            page.screenshot(path=str(file), type="jpeg", quality=65)
                            print(f"PREVIEW {language} {width}: screenshot={file.name} bytes={file.stat().st_size}")
                            if os.environ.get("MUCHO_PREVIEW_B64") == "1":
                                print(f"MUCHO_IMAGE_{language.upper()}=" + base64.b64encode(file.read_bytes()).decode("ascii"))
                        if width == 390:
                            # The real menu must open and close on mobile.
                            page.locator("#menu-toggle").click()
                            assert page.locator("#main-navigation").is_visible(), f"Mobile menu not visible: {language}"
                            page.locator("#menu-toggle").click()
                            assert not page.locator("#main-navigation").is_visible(), f"Mobile menu won't close: {language}"
                            if language == "ru":
                                # Verify the attributed quote isn't clipped below the hero.
                                page.locator(".quote-section").scroll_into_view_if_needed()
                                quote_file = SHOTS / "muchocore-ru-quote.jpg"
                                page.screenshot(path=str(quote_file), type="jpeg", quality=65)
                                if os.environ.get("MUCHO_PREVIEW_B64") == "1":
                                    print("MUCHO_IMAGE_QUOTE=" + base64.b64encode(quote_file.read_bytes()).decode("ascii"))
                        print(f"VISUAL_OK {language} {width} scroll={dims['document']} viewport={dims['width']}")
                        page.close()
                # Verify the actual anchor switches the server-side rendered language.
                page = browser.new_page()
                page.goto(root + "/muchocore/", wait_until="domcontentloaded")
                page.locator('.languages a[hreflang="ru"]').click()
                assert page.url.endswith("/muchocore/ru/")
                assert page.locator("html").get_attribute("lang") == "ru"
                assert "Всё для людей" in page.locator("blockquote").inner_text()
                print("VISUAL_OK navigation EN->RU")
                page.close()
            finally:
                browser.close()
    finally:
        server.shutdown()
        server.server_close()


if __name__ == "__main__":
    main()
