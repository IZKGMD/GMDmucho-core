#!/usr/bin/env python3
"""MuchoCore bilingual product-site contract.

Test the actual static HTML for both locales; no JS translation needed.
"""
from html.parser import HTMLParser
from pathlib import Path

ROOT = Path(__file__).resolve().parents[2]
PAGES = {
    "en": ROOT / "public/muchocore/index.html",
    "ru": ROOT / "public/muchocore/ru/index.html",
}
CSS = ROOT / "public/assets/muchocore-landing.css"
JS = ROOT / "public/assets/muchocore-landing.js"


class Page(HTMLParser):
    def __init__(self):
        super().__init__(convert_charrefs=True)
        self.ids = []
        self.links = []
        self.assets = []
        self.h1 = 0
        self.images = []

    def handle_starttag(self, tag, attrs):
        a = dict(attrs)
        if a.get("id"):
            self.ids.append(a["id"])
        if tag == "a" and a.get("href"):
            self.links.append(a["href"])
        if tag in ("link", "script"):
            self.assets.append(a.get("href") or a.get("src"))
        if tag == "h1":
            self.h1 += 1
        if tag == "img":
            self.images.append(a.get("src"))


def main():
    css = CSS.read_text(encoding="utf-8")
    js = JS.read_text(encoding="utf-8")
    assert "color-scheme:dark" in css, "Dark mode is the default"
    assert "--bg:#0c0d12" in css and "--accent:#ae9cf7" in css
    assert "@media(max-width:600px)" in css, "Mobile-specific typography required"
    assert "prefers-reduced-motion:reduce" in css
    assert ":focus-visible" in css, "Keyboard focus must be visible"
    assert "document.documentElement.lang === 'ru'" in js
    assert "navigator.clipboard" in js and "document.execCommand('copy')" in js
    assert "matchMedia" in js and "aria-expanded" in js
    assert "setLanguage" not in js, "Language must not depend on JS"
    for lang, file in PAGES.items():
        html = file.read_text(encoding="utf-8")
        page = Page()
        page.feed(html)
        assert f'<html lang="{lang}">' in html
        assert page.h1 == 1, f"{lang} needs exactly one H1"
        assert len(page.ids) == len(set(page.ids)), f"Duplicate IDs on {lang}"
        assert "/assets/muchocore-landing.css?v=3" in page.assets
        assert "/assets/muchocore-landing.js?v=3" in page.assets
        assert "/assets/muchocore-icon.png?v=3" in page.images
        assert 'id="copy-install"' in html and 'id="menu-toggle"' in html
        assert "sudo ./install" in html
        assert "IZK" in html and "2026" in html
        assert '<blockquote>' in html and '</blockquote>' in html
        for path in ("/muchocore/", "/muchocore/ru/",
                     "/muchocore/about/", "/muchocore/compatibility/",
                     "/install/", "https://github.com/IZKGMD/GMDmucho-core"):
            assert path in page.links, f"Missing {path} on {lang}"
        for target in ("content", "overview", "features", "architecture",
                       "compatibility", "install", "faq"):
            assert target in page.ids, f"{lang}: missing {target}"
        for href in page.links:
            if href.startswith("#"):
                assert href[1:] in page.ids, f"Broken {lang} anchor {href}"
        assert "100%" in html and "2.209" in html, "Don't overclaim GD compatibility"
        assert "data-ru=" not in html, "No JS-only translations"
        if lang == "ru":
            assert "Всё для людей и главное — бесплатно." in html
            assert "Ядро для" in html and "своего GDPS." in html
            assert "Выбрать способ установки" in html
            assert "Частые вопросы." in html
        else:
            assert "Everything for people — and most importantly, free." in html
            assert "The backend for" in html and "your GDPS." in html
            assert "Choose an installation method" in html
            assert "A few straight answers." in html
    print("MUCHOCORE_BILINGUAL_SITE_OK: canonical static RU/EN, author quote, mobile/a11y, deployment links")


if __name__ == "__main__":
    main()
