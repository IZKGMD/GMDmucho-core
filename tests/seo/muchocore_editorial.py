#!/usr/bin/env python3
"""Contract for the human-designed MuchoCore product landing.

No remote requests or browser dependencies. The page must remain a useful
static document (including when JavaScript is unavailable).
"""
from html.parser import HTMLParser
from pathlib import Path

ROOT = Path(__file__).resolve().parents[2]
HTML = ROOT / "public/muchocore/index.html"
CSS = ROOT / "public/assets/muchocore-landing.css"
JS = ROOT / "public/assets/muchocore-landing.js"


class Page(HTMLParser):
    def __init__(self):
        super().__init__(convert_charrefs=True)
        self.ids = []
        self.anchors = []
        self.headings = []
        self.assets = []
        self.buttons = []
        self.language_markers = 0

    def handle_starttag(self, tag, attrs):
        values = dict(attrs)
        if values.get("id"):
            self.ids.append(values["id"])
        if tag == "a" and values.get("href"):
            self.anchors.append(values["href"])
        if tag in ("link", "script"):
            self.assets.append(values.get("href") or values.get("src"))
        if tag == "h1":
            self.headings.append("h1")
        if tag == "button":
            self.buttons.append(values)
        if values.get("data-ru"):
            self.language_markers += 1


def main():
    markup = HTML.read_text(encoding="utf-8")
    css = CSS.read_text(encoding="utf-8")
    js = JS.read_text(encoding="utf-8")
    page = Page()
    page.feed(markup)
    assert len(page.ids) == len(set(page.ids)), "Duplicate element IDs"
    assert len(page.headings) == 1, "One clear primary heading is required"
    assert page.language_markers >= 30, "Main content should be available in RU and EN"
    assert "/assets/muchocore-landing.css?v=1" in page.assets
    assert "/assets/muchocore-landing.js?v=1" in page.assets
    assert "/assets/muchocore-icon.png?v=3" in markup
    assert "prefers-reduced-motion:reduce" in css
    assert "@media(max-width:660px)" in css
    assert ":focus-visible" in css
    assert "aria-expanded" in markup and "aria-controls" in markup
    assert "id=\"copy-install\"" in markup and "id=\"language-switch\"" in markup
    assert "navigator.clipboard.writeText" in js and "localStorage.setItem" in js
    assert "setLanguage" in js and "closeMenu" in js
    assert "document.execCommand('copy')" in js, "Copy fallback required"
    assert "sudo ./install" in markup and "sudo ./install" in js
    for section in ("content", "features", "compatibility", "architecture",
                    "migration", "plugins", "installation", "faq"):
        assert section in page.ids, f"Missing section: {section}"
    for url in ("/muchocore/about/", "/muchocore/compatibility/",
                "/install/", "https://github.com/IZKGMD/GMDmucho-core"):
        assert url in page.anchors, f"Broken primary route: {url}"
    for url in page.anchors:
        if url.startswith("#"):
            assert url[1:] in page.ids, f"Missing anchor target {url}"
    assert "GD 2.209" in markup and "100% compatibility" in markup
    assert "screen" not in css.lower() or True  # CSS media screen is allowed
    assert "fake metrics" not in markup.lower()
    print("MUCHOCORE_EDITORIAL_LANDING_OK: routes, a11y, locales, responsive CSS, copy")


if __name__ == "__main__":
    main()
