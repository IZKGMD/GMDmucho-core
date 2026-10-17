#!/usr/bin/env python3
"""Local SEO/AI-discovery contract: no external requests or credentials."""
from __future__ import annotations

import json
import re
from html.parser import HTMLParser
from pathlib import Path
from urllib.parse import urlparse
from xml.etree import ElementTree

ROOT = Path(__file__).resolve().parents[2]
BASE = "https://muchogdps.space"
EXPECTED = {
    f"{BASE}/": "public/index.html",
    f"{BASE}/muchocore/": "public/muchocore/index.html",
    f"{BASE}/muchocore/about/": "public/muchocore/about/index.html",
    f"{BASE}/muchocore/compatibility/": "public/muchocore/compatibility/index.html",
}


class Metadata(HTMLParser):
    def __init__(self) -> None:
        super().__init__()
        self.canonical: list[str] = []
        self.description: list[str] = []
        self.robots: list[str] = []
        self.links: list[str] = []
        self.ldjson: list[dict] = []
        self._script = False
        self._buf: list[str] = []
        self._title = False
        self.titles: list[str] = []
        self._titlebuf: list[str] = []

    def handle_starttag(self, tag: str, attrs: list[tuple[str, str | None]]) -> None:
        a = dict(attrs)
        if tag == "link" and a.get("rel") == "canonical":
            self.canonical.append(a.get("href") or "")
        if tag == "a" and a.get("href"):
            self.links.append(a["href"] or "")
        if tag == "meta" and a.get("name") == "description":
            self.description.append(a.get("content") or "")
        if tag == "meta" and a.get("name") == "robots":
            self.robots.append(a.get("content") or "")
        if tag == "script" and a.get("type") == "application/ld+json":
            self._script = True
            self._buf = []
        if tag == "title":
            self._title = True
            self._titlebuf = []

    def handle_data(self, data: str) -> None:
        if self._script:
            self._buf.append(data)
        if self._title:
            self._titlebuf.append(data)

    def handle_endtag(self, tag: str) -> None:
        if tag == "script" and self._script:
            self.ldjson.append(json.loads("".join(self._buf)))
            self._script = False
        if tag == "title" and self._title:
            self.titles.append("".join(self._titlebuf).strip())
            self._title = False


def require(ok: bool, message: str) -> None:
    if not ok:
        raise AssertionError(message)


def main() -> None:
    sitemap = ElementTree.fromstring((ROOT / "public/sitemap.xml").read_text())
    ns = "{http://www.sitemaps.org/schemas/sitemap/0.9}"
    urls = []
    for node in sitemap.findall(f"{ns}url"):
        loc = node.findtext(f"{ns}loc")
        lastmod = node.findtext(f"{ns}lastmod")
        require(loc is not None and loc.startswith(BASE + "/"), f"Bad sitemap URL: {loc}")
        require(lastmod is not None and bool(re.fullmatch(r"20\d\d-\d\d-\d\d", lastmod)), f"Bad lastmod: {loc}")
        urls.append(loc)
    require(len(urls) == len(set(urls)), "Sitemap contains duplicate URLs")
    require(set(urls) == set(EXPECTED), "Sitemap must match public canonical HTML pages")

    pages = {}
    for url, filepath in EXPECTED.items():
        file = ROOT / filepath
        require(file.is_file(), f"Missing sitemap destination: {filepath}")
        html = file.read_text(encoding="utf-8")
        parser = Metadata()
        parser.feed(html)
        require(parser.canonical == [url], f"Wrong/duplicate canonical for {url}")
        require(len(parser.titles) == 1 and len(parser.titles[0]) > 24,
                f"Missing descriptive title on {url}")
        require(len(parser.description) == 1 and len(parser.description[0]) > 65,
                f"Missing meta description on {url}")
        require(not any("noindex" in rule.lower() for rule in parser.robots), f"Noindex: {url}")
        require(parser.ldjson, f"Missing JSON-LD on {url}")
        require(all(x.get("@context") == "https://schema.org" for x in parser.ldjson),
                f"Malformed Schema.org context on {url}")
        pages[url] = parser

    product = pages[BASE + "/muchocore/"]
    schema = {x.get("@type"): x for x in product.ldjson}
    require("SoftwareApplication" in schema and "WebSite" in schema, "Product graph incomplete")
    require(schema["SoftwareApplication"]["@id"] == BASE + "/muchocore/#software",
            "Inconsistent product ID")
    require("softwareVersion" not in schema["SoftwareApplication"],
            "Hardcoded version will become stale; link GitHub releases instead")
    require(any(x.get("@type") == "AboutPage" for x in pages[BASE + "/muchocore/about/"].ldjson),
            "Missing AboutPage")
    require(any(x.get("@type") == "TechArticle"
                for x in pages[BASE + "/muchocore/compatibility/"].ldjson),
            "Missing compatibility article")
    for target in ["/muchocore/about/", "/muchocore/compatibility/"]:
        require(target in product.links, f"Missing product-to-page link: {target}")
    require("/muchocore/" in pages[BASE + "/"].links, "Community homepage does not link to product")

    robots = (ROOT / "public/robots.txt").read_text(encoding="utf-8")
    require("Sitemap: " + BASE + "/sitemap.xml" in robots, "No sitemap discovery")
    for bot in ["OAI-SearchBot", "GPTBot", "Googlebot", "Bingbot"]:
        require(re.search(r"User-agent:\s*" + re.escape(bot) + r"\s*\nAllow:\s*/", robots) is not None,
                f"Missing intended crawl rule for {bot}")
    require((ROOT / "public/llms.txt").read_bytes() == (ROOT / "llms.txt").read_bytes(),
            "Public and repository llms.txt differ")
    llms = (ROOT / "public/llms.txt").read_text()
    require("Not independently confirmed 100% compatible" in llms,
            "Clearly qualify 2.209 support rather than overstating coverage")
    caddy = (ROOT / "docker/Caddyfile").read_text()
    require("uri strip_prefix /muchocore" in caddy and "file_server" in caddy,
            "Caddy must serve the knowledge pages as static HTML")
    print("MUCHOCORE_AI_DISCOVERY_OK: sitemap, canonicals, structured data, links, crawler access, llms.txt")


if __name__ == "__main__":
    main()
