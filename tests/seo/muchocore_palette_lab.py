#!/usr/bin/env python3
"""Real desktop + mobile Chromium screenshots for 24 MuchoCore background experiments."""
from __future__ import annotations
from functools import partial
from http.server import SimpleHTTPRequestHandler, ThreadingHTTPServer
from pathlib import Path
from playwright.sync_api import sync_playwright
import json
import re
import shutil
import threading

PUBLIC = Path(__file__).resolve().parents[2] / "public"
OUTPUT = Path("/tmp/muchocore-ui-preview")
OUTPUT.mkdir(parents=True, exist_ok=True)
BACKGROUNDS = {
  "snow": {
    "hex": "#FFFFFF",
    "mode": "light"
  },
  "porcelain": {
    "hex": "#F9FAFC",
    "mode": "light"
  },
  "paper": {
    "hex": "#F5F5F7",
    "mode": "light"
  },
  "frost": {
    "hex": "#EFF5FB",
    "mode": "light"
  },
  "ice": {
    "hex": "#E7F0FA",
    "mode": "light"
  },
  "cloud": {
    "hex": "#E8EDF4",
    "mode": "light"
  },
  "silver": {
    "hex": "#E1E5ED",
    "mode": "light"
  },
  "titanium": {
    "hex": "#ECEFF3",
    "mode": "light"
  },
  "steel": {
    "hex": "#D9E0E9",
    "mode": "light"
  },
  "bluegray": {
    "hex": "#D2DBE7",
    "mode": "light"
  },
  "lavender": {
    "hex": "#F3EFFA",
    "mode": "light"
  },
  "lilac": {
    "hex": "#EAE4F5",
    "mode": "light"
  },
  "orchid": {
    "hex": "#E3DCEF",
    "mode": "light"
  },
  "violetmist": {
    "hex": "#DCD7EB",
    "mode": "light"
  },
  "stone": {
    "hex": "#F0EFEC",
    "mode": "light"
  },
  "pearl": {
    "hex": "#F8F5FA",
    "mode": "light"
  },
  "night": {
    "hex": "#171823",
    "mode": "dark"
  },
  "graphite": {
    "hex": "#262833",
    "mode": "dark"
  },
  "charcoal": {
    "hex": "#1F2027",
    "mode": "dark"
  },
  "deepviolet": {
    "hex": "#21192E",
    "mode": "dark"
  },
  "navy": {
    "hex": "#151F30",
    "mode": "dark"
  },
  "midnight": {
    "hex": "#14131C",
    "mode": "dark"
  },
  "ink": {
    "hex": "#101017",
    "mode": "dark"
  },
  "smoky": {
    "hex": "#2B2734",
    "mode": "dark"
  }
}

class Handler(SimpleHTTPRequestHandler):
    def log_message(self, *_):
        pass

def hex_rgb(value):
    return "rgb(" + ", ".join(str(int(value[i:i+2], 16)) for i in (1, 3, 5)) + ")"

def main():
    server = ThreadingHTTPServer(("127.0.0.1", 0), partial(Handler, directory=str(PUBLIC)))
    threading.Thread(target=server.serve_forever, daemon=True).start()
    try:
        root = f"http://127.0.0.1:{server.server_port}"
        with sync_playwright() as playwright:
            executable = next((path for binary in ("google-chrome", "chromium", "google-chrome-stable", "chromium-browser")
                               if (path := shutil.which(binary))), None)
            assert executable, "Chrome/Chromium missing"
            browser = playwright.chromium.launch(executable_path=executable, headless=True, args=["--no-sandbox"])
            try:
                for name, values in BACKGROUNDS.items():
                    for form, (width,height) in (("desktop",(1440,900)), ("mobile",(390,844))):
                        page = browser.new_page(viewport={"width":width,"height":height}, device_scale_factor=1)
                        errors = []
                        page.on("pageerror", lambda exc: errors.append(str(exc)))
                        response = page.goto(f"{root}/muchocore/ru/?background={name}", wait_until="networkidle")
                        assert response and response.status == 200, (name,form,response)
                        assert page.locator("html").get_attribute("data-mc-background") == name
                        assert page.locator("html").get_attribute("data-mc-palette") == (
                            "midnight" if values["mode"]=="dark" else "titanium")
                        color = page.locator("body").evaluate("(e)=>getComputedStyle(e).backgroundColor")
                        assert color == hex_rgb(values["hex"]), (name,form,color,values["hex"])
                        overflow = page.evaluate("document.documentElement.scrollWidth - innerWidth")
                        assert overflow <= 1, (name,form,overflow)
                        assert not errors, (name,form,errors)
                        assert page.locator('[data-demo-target]').count() == 3
                        page.locator('[data-demo-target]').nth(1).click()
                        assert page.locator('#mc-demo-panel-1').is_visible()
                        page.locator('[data-demo-target]').nth(0).click()
                        file = OUTPUT / f"background-{name}-{form}.jpg"
                        page.screenshot(path=str(file), type="jpeg", quality=82)
                        print(f"BACKGROUND_OK {name} {form} {values['hex']}: {file.name} {file.stat().st_size}")
                        if name in ("snow","ice","steel","lavender","orchid","titanium","graphite","deepviolet","navy","ink"):
                            file_full=OUTPUT/f"background-{name}-{form}-full.jpg"
                            page.screenshot(path=str(file_full),type="jpeg",quality=76,full_page=True)
                        page.close()
            finally:
                browser.close()
    finally:
        server.shutdown()
        server.server_close()

if __name__ == "__main__":
    main()
