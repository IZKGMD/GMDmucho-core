#!/usr/bin/env python3
"""Screenshot real MuchoCore product pages with three logo-based palette alternatives."""
from __future__ import annotations
from functools import partial
from http.server import SimpleHTTPRequestHandler, ThreadingHTTPServer
from pathlib import Path
import threading
import shutil
from playwright.sync_api import sync_playwright

PUBLIC = Path(__file__).resolve().parents[2] / "public"
OUTPUT = Path("/tmp/muchocore-ui-preview")
OUTPUT.mkdir(parents=True, exist_ok=True)
COLOR = {
    "amethyst": ("rgb(245, 243, 249)", "rgb(120, 85, 167)"),
    "titanium": ("rgb(236, 239, 243)", "rgb(106, 87, 168)"),
    "midnight": ("rgb(20, 19, 28)", "rgb(186, 156, 238)"),
}
class Handler(SimpleHTTPRequestHandler):
    def log_message(self, *_):
        pass

def main():
    server = ThreadingHTTPServer(("127.0.0.1",0),partial(Handler,directory=str(PUBLIC)))
    runner = threading.Thread(target=server.serve_forever,daemon=True)
    runner.start()
    try:
        root = f"http://127.0.0.1:{server.server_port}"
        with sync_playwright() as p:
            executable = next((p for name in ("google-chrome", "google-chrome-stable", "chromium", "chromium-browser") if (p := shutil.which(name))), None)
            assert executable, "system Chromium/Chrome missing"
            browser = p.chromium.launch(executable_path=executable,headless=True,args=["--no-sandbox"])
            try:
                for mode,(background,accent) in COLOR.items():
                    for size,(width,height) in {
                        "desktop":(1440,900),
                        "mobile":(390,844)
                    }.items():
                        page = browser.new_page(viewport={"width":width,"height":height},device_scale_factor=1)
                        errors = []
                        page.on("pageerror",lambda error: errors.append(str(error)))
                        res = page.goto(f"{root}/muchocore/ru/?palette={mode}",wait_until="networkidle")
                        assert res and res.status == 200, (mode, size, res)
                        assert page.locator("html").get_attribute("data-mc-palette") == mode
                        colors = page.evaluate("""() => {
                          const root = getComputedStyle(document.documentElement);
                          return {
                            background: getComputedStyle(document.body).backgroundColor,
                            accent: getComputedStyle(document.querySelector('.mc-hero-message h1 em')).color,
                            overflow: document.documentElement.scrollWidth - innerWidth
                          };
                        }""")
                        assert colors["background"] == background, (mode,size,colors)
                        assert colors["accent"] == accent, (mode,size,colors)
                        assert colors["overflow"] <= 1, (mode,size,colors)
                        assert page.locator('[data-demo-target]').count() == 3
                        assert not errors, (mode,size,errors)
                        file = OUTPUT / f"palette-{mode}-{size}.jpg"
                        page.screenshot(path=str(file),type="jpeg",quality=88)
                        page.locator('[data-demo-target]').nth(1).click()
                        assert page.locator('#mc-demo-panel-1').is_visible()
                        full = OUTPUT / f"palette-{mode}-{size}-full.jpg"
                        page.screenshot(path=str(full),type="jpeg",quality=83,full_page=True)
                        print(f"PALETTE_OK {mode} {size}: {file.name} {file.stat().st_size}B; {full.name} {full.stat().st_size}B")
                        page.close()
            finally:
                browser.close()
    finally:
        server.shutdown()
        server.server_close()

if __name__ == "__main__":
    main()
