#!/usr/bin/env python3
"""Structural, bilingual and accessibility contract for MuchoCore's rebuilt site."""
from html.parser import HTMLParser
from pathlib import Path

ROOT = Path(__file__).resolve().parents[2]
PAGES = {"en": ROOT / "public/muchocore/index.html",
         "ru": ROOT / "public/muchocore/ru/index.html"}
CSS = ROOT / "public/assets/mucho-product-v3.css"
JS = ROOT / "public/assets/mucho-product-v3.js"

class Page(HTMLParser):
    def __init__(self):
        super().__init__()
        self.ids = []
        self.links = []
        self.h1 = 0
        self.images = []
        self.buttons = []
        self.tabs = []
    def handle_starttag(self, tag, attrs):
        a = dict(attrs)
        if a.get("id"):
            self.ids.append(a["id"])
        if tag == "a" and a.get("href"):
            self.links.append(a["href"])
        if tag == "img":
            self.images.append(a.get("src"))
        if tag == "button":
            self.buttons.append(a)
        if tag == "h1":
            self.h1 += 1
        if a.get("role") == "tab":
            self.tabs.append(a)

def main():
    css = CSS.read_text(encoding="utf-8")
    js = JS.read_text(encoding="utf-8")
    assert "--mc-paper:#101017" in css
    assert "--mc-brand:#ba9cee" in css
    assert "@media(max-width:650px)" in css
    assert "prefers-reduced-motion" in css and ":focus-visible" in css
    assert "radial-gradient" not in css and "linear-gradient" not in css
    assert "aria-selected" in js and "ArrowRight" in js and "ArrowLeft" in js
    assert "navigator.clipboard" in js and "document.execCommand('copy')" in js
    assert "aria-expanded" in js and "setLanguage" not in js
    for lang, path in PAGES.items():
        html = path.read_text(encoding="utf-8")
        doc = Page()
        doc.feed(html)
        assert f'<html lang="{lang}">' in html
        assert doc.h1 == 1, (lang, doc.h1)
        assert len(doc.ids) == len(set(doc.ids)), (lang, "duplicate IDs")
        assert "/assets/mucho-product-v3.css?v=1" in html
        assert "/assets/mucho-product-v3.js?v=1" in html
        assert "/assets/muchocore-icon.png?v=3" in doc.images
        assert len(doc.tabs) == 3
        for i, tab in enumerate(doc.tabs):
            assert tab.get("aria-controls") == f"mc-demo-panel-{i}"
            assert tab.get("id") == f"mc-demo-tab-{i}"
        assert all(id_ in doc.ids for id_ in (
            "content", "overview", "features", "architecture",
            "compatibility", "install", "faq", "menu-toggle",
            "main-navigation", "copy-install", "install-commands"))
        assert '<blockquote>' in html and '</blockquote>' in html
        assert "sudo ./install" in html
        assert "2.209" in html and "1.0" in html and "2.2" in html
        assert not "100% supported" in html and not "100% compatible" in html
        for link in ("/muchocore/", "/muchocore/ru/", "/muchocore/about/",
                     "/muchocore/compatibility/", "/install/",
                     "https://github.com/IZKGMD/GMDmucho-core"):
            assert link in doc.links, (lang, link)
        for url in doc.links:
            if url.startswith("#"):
                assert url[1:] in doc.ids, (lang, url)
        if lang == "ru":
            assert "Всё для людей и главное — бесплатно." in html
            assert "Ядро для" in html and "твоего GDPS." in html
        else:
            assert "Everything for people — and most importantly, free." in html
            assert "The backend for" in html and "your own GDPS." in html
    print("MUCHO_SITE_V3_OK: original bilingual structure, real protocol specimen, no fake stats, mobile/keyboard support")

if __name__ == "__main__":
    main()
