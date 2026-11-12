#!/usr/bin/env python3
"""Visual regression fixtures for CSS used by dynamic PHP admin and player portal.

These pages contain no invented server data: placeholders are deliberately blank.
They verify the actual committed styles independently from authentication and DB.
"""
from __future__ import annotations
from functools import partial
from http.server import SimpleHTTPRequestHandler, ThreadingHTTPServer
from pathlib import Path
from playwright.sync_api import sync_playwright
import shutil
import threading
import re

PUBLIC=Path(__file__).resolve().parents[2]/"public"
OUTPUT=Path("/tmp/muchocore-ui-preview")
OUTPUT.mkdir(exist_ok=True,parents=True)

class Quiet(SimpleHTTPRequestHandler):
    def log_message(self,*args):
        pass

def php_inline_css(filename, block):
    source=(PUBLIC/filename).read_text(encoding="utf-8")
    styles=re.findall(r"<style[^>]*>(.*?)</style>", source, re.IGNORECASE|re.DOTALL)
    assert len(styles)>block,(filename,len(styles),block)
    assert "<?" not in styles[block], "CSS must not contain PHP expressions"
    return styles[block]

def admin(base):
    live_style=php_inline_css("admin/index.php",3)
    return f"""<!doctype html><html lang="en" data-theme="dark"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1">
<style>{live_style}</style><style>*,*::before,*::after{{box-sizing:border-box}}</style>
<link rel="stylesheet" href="{base}/assets/product-ui.css?v=2">
<link rel="stylesheet" href="{base}/admin/product-admin.css?v=1">
<link rel="stylesheet" href="{base}/admin/admin-simple.css?v=4">
<link rel="stylesheet" href="{base}/admin/admin-polish.css?v=5">
<link rel="stylesheet" href="{base}/admin/admin-workspace.css?v=7">
<link rel="stylesheet" href="{base}/admin/admin-motion.css?v=2">
<link rel="stylesheet" href="{base}/assets/mucho-design-system.css?v=2">
<link rel="stylesheet" href="{base}/admin/mucho-console-v3.css?v=7"></head>
<body class="mc-admin"><div class="mobile-overlay" id="mobileOverlay"></div><div class="shell">
<aside id="adminNavigation" aria-label="Admin navigation"><div class="brandrow"><a class="logo" href="#"><img src="{base}/assets/muchocore-icon.png?v=3" alt="" width="32" height="32">MuchoCore</a></div>
<section class="mc-instance"><span>ACTIVE INSTANCE</span><strong>MuchoCore</strong><a href="#">Player portal ↗</a></section>
<nav class="admin-nav"><p class="nav-title">WORKSPACE</p>
<a class="on" href="#">Overview</a><a href="#">Players</a><a href="#">Levels</a><a href="#">Music</a><a href="#">Moderation</a>
<p class="nav-title">SYSTEM</p><a href="#">Settings</a><a href="#">Backup</a></nav></aside>
<main id="main" tabindex="-1"><header class="head"><button type="button" class="mobile-menu-btn" id="mobileMenuButton" aria-label="Open menu" aria-expanded="false" aria-controls="adminNavigation">☰</button><div class="head-title"><span class="mc-section-caption">MUCHOCORE ADMIN</span><h1>Dashboard</h1></div>
<form class="global-search"><select aria-label="Search type"><option>Players</option></select><input placeholder="Search..." aria-label="Search"><button type="button">Search</button></form><div class="mc-header-actions"><details class="mc-view-menu"><summary aria-label="Interface options">View</summary><div class="mc-view-popover"></div></details></div></header>
<div class="mc-ops"><header class="mc-ops-intro"><div><p class="mc-ops-kicker">MUCHOCORE / WORKSPACE</p><h2>Server overview</h2><p>Players, levels and content at a glance.</p></div><div class="mc-ops-intro-aside"><span class="mc-ops-snapshot">Snapshot · preview server time</span><a class="mc-ops-secondary" href="#">Player portal ↗</a></div></header>

<section class="mc-ops-summary"><div class="mc-ops-summary-item"><span class="mc-ops-summary-top">Players<span>↗</span></span><strong>—</strong><small>Live server figure</small></div><div class="mc-ops-summary-item"><span class="mc-ops-summary-top">Levels<span>↗</span></span><strong>—</strong><small>Stored levels</small></div><div class="mc-ops-summary-item"><span class="mc-ops-summary-top">Songs<span>↗</span></span><strong>—</strong><small>Community tracks</small></div></section>
<div class="mc-ops-content"><div class="mc-ops-rail" role="complementary" aria-label="Operator workspace">
<section class="mc-ops-review"><div class="mc-ops-section-head"><div><p class="mc-ops-kicker">MODERATION</p><h3>Needs attention</h3></div></div>
<nav class="mc-ops-queue" aria-label="Review queues"><a class="mc-ops-attention" href="#"><span class="mc-ops-attention-mark">★</span><span class="mc-ops-attention-copy"><small>LEVEL MODERATION</small><strong>Queue unavailable</strong><span>Review requested ratings</span></span><span class="mc-ops-attention-go">↗</span></a><a class="mc-ops-attention is-clear" href="#"><span class="mc-ops-attention-mark">♪</span><span class="mc-ops-attention-copy"><small>MUSIC MODERATION</small><strong>Music queue is clear</strong><span>Daily uploads unavailable</span></span><span class="mc-ops-attention-go">↗</span></a></nav></section><section class="mc-ops-actions"><div class="mc-ops-section-head"><div><p class="mc-ops-kicker">SHORTCUTS</p><h3>Operator tools</h3></div></div><nav><a href="#"><span><strong>Review levels</strong><small>Ratings and moderation</small></span><span>↗</span></a><a href="#"><span><strong>Publish news</strong><small>In-game announcements</small></span><span>↗</span></a><a href="#"><span><strong>Backups</strong><small>Database snapshots</small></span><span>↗</span></a></nav></section></div><div class="mc-ops-feeds"><section class="mc-ops-panel"><div class="mc-ops-section-head"><div><p class="mc-ops-kicker">LEVELS</p><h3>Latest uploads</h3></div><a href="#">All levels ↗</a></div><p class="mc-ops-empty">No published levels to show yet.</p></section><section class="mc-ops-panel"><div class="mc-ops-section-head"><div><p class="mc-ops-kicker">COMMUNITY</p><h3>New players</h3></div><a href="#">All players ↗</a></div><div class="mc-ops-table-scroll"><table class="mc-ops-table"><thead><tr><th>Player</th><th>Role</th><th class="mc-ops-num">Stars</th></tr></thead></table></div><p class="mc-ops-empty">No example player records shown in this visual test.</p></section>
<section class="mc-ops-panel"><div class="mc-ops-section-head"><div><p class="mc-ops-kicker">CONTENT</p><h3>Latest music</h3></div><a href="#">Manage music ↗</a></div><p class="mc-ops-empty">Actual tracks appear from the server database.</p></section></div>
</div></div></main></div><script src="{base}/assets/product-ui.js"></script></body></html>"""


def login(base):
    """Non-authenticated login layout fixture: real CSS, no credentials or DB."""
    return f"""<!doctype html><html lang="en" data-theme="dark"><head>
<meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1">
<link rel="stylesheet" href="{base}/assets/product-ui.css">
<link rel="stylesheet" href="{base}/admin/product-admin.css">
<link rel="stylesheet" href="{base}/admin/admin-simple.css">
<link rel="stylesheet" href="{base}/admin/admin-polish.css">
<link rel="stylesheet" href="{base}/admin/admin-workspace.css">
<link rel="stylesheet" href="{base}/admin/admin-motion.css">
<link rel="stylesheet" href="{base}/assets/mucho-design-system.css">
<link rel="stylesheet" href="{base}/admin/mucho-console-v3.css"></head>
<body class="admin-login mc-workspace">
<main class="admin-auth-card">
<header><a href="#" class="logo">MuchoCore</a></header>
<span class="mc-auth-eyebrow">MuchoCore Admin</span>
<h1>Administrator sign in</h1>
<form id="adminLoginForm" method="post">
<div id="passwordLoginFields">
<p class="admin-auth-field"><label for="adminUsername">Username</label><input id="adminUsername" name="username" autocomplete="username" placeholder="Administrator" required></p>
<p class="admin-auth-field"><label for="adminPassword">Password</label><input id="adminPassword" name="password" type="password" autocomplete="current-password" placeholder="Password" required></p>
</div>
<button id="normalSignInButton" type="button">Sign in</button>
</form>
<p><a href="#">Recover administrator access</a></p>
</main></body></html>"""

def portal(base):
    live_style=php_inline_css("dashboard/index.php",0)
    return f"""<!doctype html><html lang="en" data-theme="dark"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1">
<style>{live_style}</style><style>*,*::before,*::after{{box-sizing:border-box}}</style>
<link rel="stylesheet" href="{base}/assets/mucho-design-system.css?v=2">
<link rel="stylesheet" href="{base}/muchocore-theme.css?v=3">
<link rel="stylesheet" href="{base}/assets/mucho-portal-v3.css?v=2"></head>
<body class="dashboard-page"><div class="shell"><header class="topbar"><a class="brand" href="#"><img class="mc-brand-mark" src="{base}/assets/muchocore-icon.png?v=3" alt=""><span class="mc-portal-brand-copy"><strong>MuchoCore</strong><small>PLAYER PORTAL</small></span></a><nav class="nav"><a href="#">Players</a><a href="#">Music</a><a href="#">Clans</a><a class="btn" href="#">Login</a></nav></header>
<section class="mc-community-home"><div class="mc-community-discovery"><div class="mc-community-overline"><span>PLAYER PORTAL</span><span>MuchoCore</span></div>
<h1>Explore your<br><span>community.</span></h1><p>Search player profiles, follow the leaderboard and discover music uploaded by your community.</p>
<form class="search mc-community-search"><label for="testSearch">Find a player</label><div class="mc-community-search-row"><input id="testSearch" placeholder="Search username..." type="search"><button class="btn" type="button">Search ↗</button></div></form>
<nav class="mc-community-shortcuts"><a href="#">Leaderboard ↗</a><a href="#">Clans ↗</a><a href="#">Music ↗</a></nav>
<section class="dashboard-stats mc-community-statistics"><div class="dashboard-stat"><span class="dashboard-stat-label">Players</span><div class="dashboard-stat-value">—</div><small class="dashboard-stat-detail">Live total</small></div>
<div class="dashboard-stat"><span class="dashboard-stat-label">Levels</span><div class="dashboard-stat-value">—</div><small class="dashboard-stat-detail">Published</small></div><div class="dashboard-stat"><span class="dashboard-stat-label">Songs</span><div class="dashboard-stat-value">—</div><small class="dashboard-stat-detail">Community music</small></div>
<div class="dashboard-stat"><span class="dashboard-stat-label">Clans</span><div class="dashboard-stat-value">—</div><small class="dashboard-stat-detail">Active groups</small></div></section></div>
<aside class="panel portal-panel mc-community-access"><div><div class="portal-head"><div class="portal-head-copy"><div class="portal-eyebrow">ACCOUNT ACCESS</div><h2 class="portal-title">Sign in to upload</h2><p class="portal-description">Use your GDPS account to manage uploads and your profile.</p></div><div class="portal-mark">♪</div></div><div class="form-divider"></div></div><form><div class="field"><label for="loginUser">Username</label><input id="loginUser" placeholder="Username"></div><div class="field"><label for="loginPass">Password</label><input id="loginPass" type="password" placeholder="Password"></div><button class="btn" type="button">Sign in to upload</button></form></aside></section>
<section class="mc-community-spotlight"><div class="mc-community-spotlight-top"><div><span class="mc-community-label">FROM THE SERVER</span><h2>Community spotlight</h2></div><a href="#">View leaderboard ↗</a></div><div class="mc-community-featured mc-community-none"><span><small>TOP PLAYER</small><strong>Server data is not loaded in this preview</strong><span>Live player stats are shown on the running server.</span></span></div></section></div></body></html>"""

def main():
    browser_path=next((val for cmd in ("google-chrome","chromium","chromium-browser","google-chrome-stable") if (val:=shutil.which(cmd))),None)
    assert browser_path,"Chromium not available"
    server=ThreadingHTTPServer(("127.0.0.1",0),partial(Quiet,directory=str(PUBLIC)))
    threading.Thread(target=server.serve_forever,daemon=True).start()
    try:
        base=f"http://127.0.0.1:{server.server_port}"
        with sync_playwright() as p:
            browser=p.chromium.launch(executable_path=browser_path,headless=True,args=["--no-sandbox"])
            try:
                for name,build in (("admin",admin),("portal",portal),("login",login)):
                    for width,height in ((1440,900),(768,1024),(390,844),(320,740)):
                        page=browser.new_page(viewport={"width":width,"height":height},device_scale_factor=1)
                        errors=[]
                        page.on("pageerror",lambda error:errors.append(str(error)))
                        if name=="admin":
                            # Give the fixture a normal same-origin document so saved
                            # density settings can be checked across HTML reloads.
                            page.goto(base+"/index.html",wait_until="domcontentloaded")
                        page.set_content(build(base),wait_until="networkidle")
                        page.locator("body").wait_for()
                        body_color=page.locator("body").evaluate("(e)=>getComputedStyle(e).backgroundColor")
                        assert body_color==("rgb(16, 18, 24)" if name=="admin" else "rgb(16, 16, 23)"),(name,width,body_color)
                        over=page.evaluate("document.documentElement.scrollWidth-innerWidth")
                        assert over<=1,(name,width,"horizontal overflow",over, page.evaluate("""() => [...document.querySelectorAll('body *')].filter(el => el.getBoundingClientRect().right > innerWidth + 1).slice(0, 8).map(el => [el.tagName, el.className, Math.round(el.getBoundingClientRect().right)])"""))
                        assert not errors,(name,width,errors)
                        if name=="admin":
                            widths=page.evaluate("""() => ({
                                main:document.querySelector('.shell>main').getBoundingClientRect().width,
                                ops:document.querySelector('.mc-ops').getBoundingClientRect().width
                            })""")
                            assert widths["ops"] >= widths["main"]-100,(name,width,widths)
                            assert page.locator(".mc-ops-review").count()==1,("review rail missing",width)
                            assert page.locator(".mc-ops-rail").count()==1,("work rail missing",width)
                            actions=page.locator('.mc-ops-actions').bounding_box()
                            assert actions is not None,(name,width,'operator tools missing')
                            assert actions["x"] >= page.locator('.mc-ops-feeds').bounding_box()["x"],(name,width,'tools rendered under fixed sidebar',actions)
                            assert actions["width"] >= 200,(name,width,'operator tools collapsed',actions)
                            sidebar=page.locator(".shell>aside").evaluate("(e)=>getComputedStyle(e).backgroundColor")
                            assert sidebar=="rgb(21, 24, 33)",sidebar
                            menu=page.locator("#mobileMenuButton")
                            nav=page.locator("#adminNavigation")
                            if width<=980:
                                assert menu.is_visible(),("mobile menu trigger hidden",width)
                                trigger=menu.bounding_box()
                                assert trigger and 0<=trigger["x"] and trigger["x"]+trigger["width"]<=width,("menu trigger clipped",width,trigger)
                                assert nav.evaluate("(e)=>e.inert"),("closed mobile menu remains keyboard accessible",width)
                                menu.click()
                                assert page.locator("body").evaluate("(e)=>e.classList.contains('mobile-menu-open')"),("drawer failed to open",width)
                                assert menu.get_attribute("aria-expanded")=="true",("drawer aria-expanded incorrect",width)
                                assert not nav.evaluate("(e)=>e.inert"),("open drawer is inert",width)
                                assert page.locator("#main").evaluate("(e)=>e.inert"),("main should be inert while drawer open",width)
                                # The drawer animates for 150ms: await its settled position,
                                # otherwise an immediate bounding box reports an in-flight frame.
                                page.wait_for_function("""() => {
                                    const nav=document.getElementById('adminNavigation');
                                    return nav && Math.abs(nav.getBoundingClientRect().x) <= 1;
                                }""",timeout=2500)
                                drawer=nav.bounding_box()
                                assert drawer and drawer["x"]>=-1 and drawer["x"]<width,("drawer off-screen when open",width,drawer)
                                open_file=OUTPUT/f"muchocore-hybrid-admin-menu-open-{width}.jpg"
                                page.screenshot(path=str(open_file),type="jpeg",quality=80)
                                page.keyboard.press("Escape")
                                assert menu.get_attribute("aria-expanded")=="false",("escape failed to close mobile drawer",width)
                                assert nav.evaluate("(e)=>e.inert"),("closed drawer stayed focusable",width)
                                assert not page.locator("#main").evaluate("(e)=>e.inert"),("main stayed inert after closing",width)
                                menu.click()
                                overlay=page.locator("#mobileOverlay")
                                overlay.wait_for(state="visible")
                                # Verify that the backdrop is genuinely clickable after the
                                # open transition, rather than forcing a click through it.
                                page.wait_for_function("""() => {
                                    const overlay=document.getElementById('mobileOverlay');
                                    return overlay && document.elementFromPoint(innerWidth-12,25)===overlay;
                                }""",timeout=2500)
                                overlay.click(position={"x":width-12,"y":25})
                                assert menu.get_attribute("aria-expanded")=="false",("overlay failed to close drawer",width)
                                # Capture the resting closed state, not the fading backdrop.
                                page.wait_for_function("""() => {
                                    const overlay=document.getElementById('mobileOverlay');
                                    if(!overlay)return false;
                                    const style=getComputedStyle(overlay);
                                    return style.opacity==='0' && style.visibility==='hidden';
                                }""",timeout=2500)
                            else:
                                assert not menu.is_visible(),("desktop hamburger unexpectedly visible",width)
                                assert not nav.evaluate("(e)=>e.inert"),("desktop sidebar should remain interactive",width)
                            # Use the production styles on a temporary data-free record,
                            # not fabricated player data or a mock settings handler.
                            page.evaluate("""() => {
                                const host=document.createElement('div');
                                host.id='densityProbe';
                                host.className='admin-records';
                                host.innerHTML='<details class="card admin-record"><summary>Record fixture</summary><div class="record-body"><button type="button">Save</button></div></details>';
                                document.querySelector('.mc-ops').append(host);
                            }""")
                            kpi=page.locator(".mc-ops-summary-item").first
                            record=page.locator("#densityProbe .admin-record > summary")
                            regular_kpi=kpi.bounding_box()["height"]
                            regular_record=record.bounding_box()["height"]
                            view=page.locator(".mc-view-menu > summary")
                            assert view.is_visible(),("compact View control not available",width)
                            view.click()
                            density=page.locator(".mc-density-toggle")
                            assert density.is_visible(),("density preference missing",width)
                            assert density.get_attribute("aria-pressed")=="false"
                            density.click()
                            assert density.get_attribute("aria-pressed")=="true",("density toggle did not activate",width)
                            assert page.locator("body").evaluate("(e)=>e.classList.contains('mc-compact')"),("compact class missing",width)
                            assert kpi.bounding_box()["height"]<=regular_kpi-4,("KPI density unchanged",width,regular_kpi,kpi.bounding_box())
                            assert record.bounding_box()["height"]<=regular_record-4,("record density unchanged",width,regular_record,record.bounding_box())
                            if width<=980:
                                assert page.locator(".admin-nav a").first.bounding_box()["height"]>=43,("compact touch target too small",width)
                            if width in (1440,390):
                                page.locator("#densityProbe").evaluate("(e)=>e.remove()")
                                view.click()
                                compact_file=OUTPUT/f"muchocore-admin-compact-{width}.jpg"
                                page.screenshot(path=str(compact_file),type="jpeg",quality=80)
                                view.click()
                            if width==390:
                                assert page.evaluate("localStorage.getItem('mucho.control.compact')")=="true",("compact preference not saved",width)
                                page.set_content(build(base),wait_until="networkidle")
                                assert page.locator("body").evaluate("(e)=>e.classList.contains('mc-compact')"),("saved compact preference not restored",width)
                                view=page.locator(".mc-view-menu > summary")
                                view.click()
                                density=page.locator(".mc-density-toggle")
                            density.click()
                            assert density.get_attribute("aria-pressed")=="false",("density toggle did not reset",width)
                            assert not page.locator("body").evaluate("(e)=>e.classList.contains('mc-compact')")
                            view.click()
                            if width not in (1440,390):
                                page.locator("#densityProbe").evaluate("(e)=>e.remove()")
                        elif name=="login":
                            card=page.locator(".admin-auth-card").bounding_box()
                            assert card and card["x"]>=-1 and card["x"]+card["width"]<=width+1,(name,width,"auth card clipped",card)
                            for selector in ("#adminUsername","#adminPassword","#normalSignInButton"):
                                control=page.locator(selector).bounding_box()
                                assert control and control["x"]>=-1 and control["x"]+control["width"]<=width+1,(name,width,"auth field clipped",selector,control)
                            assert page.locator("#normalSignInButton").is_visible(),(name,width,"sign-in action missing")
                        else:
                            label=page.locator(".mc-community-search>label").bounding_box()
                            search_row=page.locator(".mc-community-search-row").bounding_box()
                            assert label and search_row and search_row["y"] >= label["y"]+label["height"],(name,width,label,search_row)
                            panel=page.locator(".mc-community-access").evaluate("(e)=>getComputedStyle(e).backgroundColor")
                            assert panel=="rgb(33, 25, 46)",panel
                        file=OUTPUT/f"muchocore-hybrid-{name}-fixture-{width}.jpg"
                        page.screenshot(path=str(file),type="jpeg",quality=80)
                        print(f"WORKSPACE_VISUAL_OK {name} {width}: {file.name} {file.stat().st_size} bytes")
                        page.close()
            finally:
                browser.close()
    finally:
        server.shutdown()
        server.server_close()

if __name__=="__main__":
    main()
