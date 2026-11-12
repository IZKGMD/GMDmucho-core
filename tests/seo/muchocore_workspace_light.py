#!/usr/bin/env python3
"""Verify the actual MuchoCore light CSS, not a painted mock-up or dummy data.

Reuses the HTML structures and production stylesheets from the dark-mode
regression suite. Checks contrast, backgrounds, overflow, module consistency,
touch targets, and compact density at desktop and mobile sizes.
"""
from __future__ import annotations

from functools import partial
from http.server import ThreadingHTTPServer
from playwright.sync_api import sync_playwright
from muchocore_workspace_visual import (
    PUBLIC, OUTPUT, Quiet, admin, login, admin_module_fixtures,
)
import re
import shutil
import threading


def rgb(style: str) -> tuple[int, int, int]:
    values = re.findall(r"[\d.]+", style)
    assert len(values) >= 3, ("unexpected color", style)
    return tuple(int(float(v)) for v in values[:3])


def contrast(a: tuple[int, int, int], b: tuple[int, int, int]) -> float:
    def luminance(values):
        channels = []
        for value in values:
            c = value / 255
            channels.append(c / 12.92 if c <= 0.04045 else ((c + 0.055) / 1.055) ** 2.4)
        return 0.2126 * channels[0] + 0.7152 * channels[1] + 0.0722 * channels[2]
    hi, lo = sorted((luminance(a), luminance(b)), reverse=True)
    return (hi + 0.05) / (lo + 0.05)


def computed(page, selector, property_name):
    return page.locator(selector).first.evaluate(
        "([el,prop]) => getComputedStyle(el).getPropertyValue(prop)",
        property_name
    )


def inspect_colors(page, width, name):
    background = rgb(computed(page, "body", "background-color"))
    assert background == (245, 246, 249), (name, width, "wrong canvas", background)
    if name == "login":
        card = rgb(computed(page, ".admin-auth-card", "background-color"))
        assert card == (255, 255, 255), (name, width, "login card is not white", card)
        label_color = rgb(computed(page, ".admin-auth-card h1", "color"))
        assert contrast(label_color, card) >= 7, (name, width, "low login headline contrast")
        button = "#normalSignInButton"
    else:
        sidebar = rgb(computed(page, ".shell>aside", "background-color"))
        assert sidebar == (24, 28, 39), (name, width, "sidebar not neutral ink", sidebar)
        canvas = rgb(computed(page, ".shell>main", "background-color"))
        assert canvas == background, (name, width, "mixed canvas", canvas)
        title = rgb(computed(page, ".head-title h1", "color"))
        assert contrast(title, canvas) >= 7, (name, width, "low page title contrast")
        nav_fg = rgb(computed(page, ".admin-nav a.on", "color"))
        nav_bg = rgb(computed(page, ".admin-nav a.on", "background-color"))
        assert contrast(nav_fg, nav_bg) >= 6, (name, width, "selected nav lacks contrast")
        button = ".global-search button"
    fg = rgb(computed(page, button, "color"))
    bg = rgb(computed(page, button, "background-color"))
    assert contrast(fg, bg) >= 4.5, (name, width, "primary action low contrast", fg, bg)


def main():
    executable = next(
        (path for command in ("google-chrome", "chromium", "chromium-browser", "google-chrome-stable")
         if (path := shutil.which(command))), None
    )
    assert executable, "Chromium not available"
    server = ThreadingHTTPServer(("127.0.0.1", 0), partial(Quiet, directory=str(PUBLIC)))
    threading.Thread(target=server.serve_forever, daemon=True).start()
    try:
        origin = f"http://127.0.0.1:{server.server_port}"
        with sync_playwright() as playwright:
            browser = playwright.chromium.launch(
                executable_path=executable, headless=True, args=["--no-sandbox"]
            )
            try:
                for name, build in (("admin", admin), ("login", login)):
                    for width, height in ((1440, 900), (768, 1024), (390, 844), (320, 740)):
                        page = browser.new_page(viewport={"width": width, "height": height})
                        errors = []
                        page.on("pageerror", lambda e: errors.append(str(e)))
                        page.goto(origin + "/index.html", wait_until="domcontentloaded")
                        markup = build(origin).replace('data-theme="dark"', 'data-theme="light"')
                        page.set_content(markup, wait_until="networkidle")
                        inspect_colors(page, width, name)
                        assert not errors, (name, width, errors)
                        overflow = page.evaluate("document.documentElement.scrollWidth - innerWidth")
                        assert overflow <= 1, (name, width, "horizontal scroll", overflow)
                        page.screenshot(
                            path=str(OUTPUT / f"muchocore-light-{name}-{width}.jpg"),
                            type="jpeg", quality=82
                        )
                        if name == "admin":
                            modules = admin_module_fixtures()
                            for module, markup in modules.items():
                                page.evaluate("""([module,html]) => {
                                    document.body.dataset.page = module;
                                    document.querySelector('.mc-ops').innerHTML = html;
                                    document.body.classList.remove('mc-compact');
                                }""", [module, markup])
                                assert page.evaluate("document.documentElement.dataset.theme") == "light"
                                if page.locator(".mc-ops .card").count():
                                    card_color = rgb(computed(page, ".mc-ops .card", "background-color"))
                                    assert card_color == (255, 255, 255), (
                                        module, width, "unexpected tinted card", card_color
                                    )
                                overflow = page.evaluate("document.documentElement.scrollWidth - innerWidth")
                                assert overflow <= 1, (module, width, "horizontal scroll", overflow)
                                if module in ("players", "levels"):
                                    summary = page.locator(".admin-record>summary")
                                    regular = summary.bounding_box()["height"]
                                    page.locator("body").evaluate("(el) => el.classList.add('mc-compact')")
                                    compact = summary.bounding_box()["height"]
                                    assert compact <= regular - 2, (
                                        module, width, "compact mode does not change row density", regular, compact
                                    )
                                if module == "rating":
                                    stars = page.locator(".rating-stars-pick button")
                                    assert stars.count() == 2
                                    for button in stars.all():
                                        color = rgb(button.evaluate("(el) => getComputedStyle(el).backgroundColor"))
                                        assert color == (255, 255, 255), (
                                            module, width, "inactive star button became primary", color
                                        )
                                if width <= 390:
                                    for field in page.locator(".mc-ops input:not([type=checkbox]):not([type=radio])").all()[:4]:
                                        bounds = field.bounding_box()
                                        assert bounds and bounds["x"] >= -1 and bounds["x"] + bounds["width"] <= width + 1, (
                                            module, width, "input clipped", bounds
                                        )
                                if width in (1440, 390):
                                    page.screenshot(
                                        path=str(OUTPUT / f"muchocore-light-{module}-{width}.jpg"),
                                        type="jpeg", quality=82
                                    )
                        page.close()
                        print("LIGHT_THEME_OK", name, width)
            finally:
                browser.close()
    finally:
        server.shutdown()


if __name__ == "__main__":
    main()
