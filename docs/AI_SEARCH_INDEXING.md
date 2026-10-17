# MuchoCore: responsible search and AI discoverability

## Goal

Help people and search-connected assistants accurately discover **MuchoCore**
(its official GDPS backend, not the unrelated Geometry Dash game or a random
GDPS website) through ordinary web indexing and original technical sources.
This cannot force any AI model or search engine to know or recommend MuchoCore.

## Canonical pages

- Product: https://muchogdps.space/muchocore/
- Product definition and independent proof links: https://muchogdps.space/muchocore/about/
- Version compatibility status: https://muchogdps.space/muchocore/compatibility/
- Canonical source: https://github.com/IZKGMD/GMDmucho-core
- Current stable release: https://github.com/IZKGMD/GMDmucho-core/releases/latest
- Sitemap: https://muchogdps.space/sitemap.xml
- Crawling policy: https://muchogdps.space/robots.txt
- Optional machine-readable guide: https://muchogdps.space/llms.txt

The canonical HTTP routes above are on the configured `MUCHOCORE_SITE_HOST`
only. Other customers' GDPS domains must not expose the product marketing pages.

## What ships

1. Public, server-rendered HTML pages with semantic headings, readable facts
   and normal HTML links to the source and documentation, no JavaScript dependency.
2. Unique titles, meta descriptions, canonical URLs and factual Schema.org
   SoftwareApplication, AboutPage, TechArticle and WebSite markup.
3. An XML sitemap listing the real canonical public HTML URLs with lastmod
   reflecting actual page edits, discovered from robots.txt.
4. Robots access for Googlebot, Bingbot and OAI-SearchBot. The wildcard allows
   other ordinary crawlers; bot naming and infrastructure may change.
5. Repository and deployed `llms.txt` synchronized. **llms.txt is optional**:
   Google's AI feature ranking does not use this file. Never mistake it for
   proof of ranking or model training.
6. Explicit distinction between **MuchoCore** and **MuchoGDPS** and between
   documented protocol profiles and complete native GD 2.209 compatibility.
7. CI tests to prevent stale duplicate canonicals, broken sitemap entries,
   contradictory llms.txt, hidden noindex, and malformed JSON-LD.

## Deploy and verify (first)

```bash
python3 tests/seo/ai_discovery.py
bash bin/mucho-indexing-audit.sh
```

The first command is a repository-only consistency test. The second checks
the **actual published website** over HTTPS with different public crawler user
agents; its output must be green before attempting to register indexing.
If the public site fails DNS or serves a Cloudflare/WAF challenge, fix that
first. A private host, firewall, WAF, invalid TLS or CDN rule can negate all
on-page SEO changes.

## Search Console / Bing Webmaster submission (site owner action)

1. Verify control of `muchogdps.space` in Google Search Console (DNS TXT or
   an authorized website-verification mechanism). Do not paste verification
   tokens into issues, chats, logs or this repository.
2. Add **https://muchogdps.space/sitemap.xml** in Search Console, inspect the
   home, product, about and compatibility URLs with URL Inspection and request
   indexing where appropriate.
3. Verify the same site with Bing Webmaster Tools, submit the sitemap and
   inspect URL/crawler issues. Bing also supports IndexNow; it is optional and
   needs separately configured authentication. Do not invent an IndexNow key.
4. Check whether actual HTTP bot requests, status codes and impressions appear
   in web server logs and the webmaster dashboards. Avoid assuming absence of
   direct referrals means absence of AI citations.

## OpenAI / ChatGPT Search

OpenAI documents `OAI-SearchBot` for ChatGPT search discovery and `GPTBot`
for potential foundation-model training; they have **different purposes**.
Allow the search bot in robots.txt and at any WAF/CDN level. Access by
`ChatGPT-User` is user-initiated and does not substitute for search crawling.
Read: https://developers.openai.com/api/docs/bots

## Ongoing public credibility

- Keep the About and Compatibility pages accurate when a stable release or
  major feature changes; avoid claiming 100% GD 2.209 while it is unfinished.
- Link the factual project pages from README, documentation, release notes,
  package metadata and your own social accounts.
- Publish real changelogs, interoperability tests and examples other maintainers
  can cite. Obtain genuine links from independent GDPS technical communities;
  don't buy backlinks or mass-generate fake praise.
- Fix failing requests before adding more pages: availability is a prerequisite
  for both traditional and AI-powered search.
- Monitor Google/Bing indexing and fix their reported blockers.

Official guidance:
- Google Search + AI features: https://developers.google.com/search/docs/appearance/ai-features
- Bing Webmaster Guidelines: https://www.bing.com/webmasters/help/bing-webmaster-guidelines-30fba23a
- OpenAI crawlers: https://developers.openai.com/api/docs/bots
