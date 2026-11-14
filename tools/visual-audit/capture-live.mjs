#!/usr/bin/env node
/**
 * Local-only visual audit of REAL MuchoCore pages.
 *
 * Run on the site owner's computer after installing Playwright.
 * The owner enters credentials in the browser; the tool never reads them,
 * exports cookies or uploads screenshots anywhere.
 *
 * npm install --no-save playwright
 * npx playwright install chromium
 * node tools/visual-audit/capture-live.mjs
 */
import { chromium } from 'playwright';
import { readFileSync, mkdirSync, writeFileSync } from 'node:fs';
import { homedir } from 'node:os';
import { resolve, join } from 'node:path';
import { fileURLToPath } from 'node:url';

const here = resolve(fileURLToPath(import.meta.url), '..');
const root = resolve(here, '../..');
const base = (process.env.MUCHO_VISUAL_BASE_URL || 'https://muchocoretest.5v.pl').replace(/\/+$/, '');
const origin = new URL(base);
const allowed = origin.protocol === 'https:' &&
    /^[a-z0-9-]+\.5v\.pl$/i.test(origin.hostname) &&
    !origin.username && !origin.password && !origin.search && !origin.hash && !origin.port;
if (!allowed && !(origin.hostname === 'localhost' && origin.protocol === 'http:')) {
    throw new Error('Use an HTTPS *.5v.pl site or a local test server.');
}
if (process.argv.includes('--help')) {
    console.log('MUCHO_VISUAL_BASE_URL=https://your-site.5v.pl node tools/visual-audit/capture-live.mjs');
    process.exit(0);
}
const source = readFileSync(join(root, 'compat/php7/admin-ui/pages.php'), 'utf8');
const configuredPages = [...source.matchAll(/'([a-z]+)'\s*=>\s*'([^']+)'/g)]
    .filter(([, key]) => !['clients', 'updates', 'backups'].includes(key))
    .map(([, key, title]) => ({ key, title }));
const stamp = new Date().toISOString().replace(/[:.]/g, '-');
const destination = join(homedir(), 'MuchoCore-Visual-Audit', stamp);
mkdirSync(destination, { recursive: true });
const browser = await chromium.launch({ headless: false });
const context = await browser.newContext({ viewport: { width: 1440, height: 900 }, acceptDownloads: false });
const page = await context.newPage();
const results = [];
try {
    await page.goto(base + '/mc/admin.php?page=dashboard', { waitUntil: 'domcontentloaded' });
    console.log('Sign in as the OWNER in the opened browser (and finish any pending core migration).');
    console.log('Return to this terminal and press Enter when the normal admin Dashboard is visible.');
    await new Promise(resolve => process.stdin.once('data', resolve));
    if (await page.locator('body.admin-login, #adminLoginForm').count() > 0) {
        throw new Error('The administrator is not signed in; no private screenshots were captured.');
    }

    // Preserve the server's actual permission-filtered navigation, and also
    // try any source-declared pages that its navigation may place in a group.
    const hrefs = await page.locator('a[href*="page="]').evaluateAll(nodes =>
        nodes.map(a => a.getAttribute('href')).filter(Boolean));
    const additional = hrefs.map(href => {
        try {
            const url = new URL(href, base);
            return url.origin === base && url.searchParams.get('page') || null;
        } catch {
            return null;
        }
    }).filter(Boolean);
    const pages = [...new Set([...configuredPages.map(p => p.key), ...additional])];
    for (const [label, width, height] of [['desktop', 1440, 900], ['mobile', 390, 844]]) {
        await page.setViewportSize({ width, height });
        for (const key of pages) {
            const path = '/mc/admin.php?page=' + encodeURIComponent(key);
            let result = { page: key, viewport: label, requestedPath: path, ok: false };
            try {
                const response = await page.goto(base + path, { waitUntil: 'domcontentloaded', timeout: 30000 });
                await page.waitForTimeout(350);
                const status = response?.status() || 0;
                const actualPath = new URL(page.url()).pathname;
                const signedOut = await page.locator('body.admin-login, #adminLoginForm').count() > 0;
                if (signedOut || status >= 400 || !actualPath.endsWith('/admin.php')) {
                    result.reason = signedOut ? 'Session expired' : 'Unavailable page or redirect';
                    result.http = status;
                } else {
                    const filename = key + '-' + label + '.png';
                    await page.screenshot({ path: join(destination, filename), fullPage: true, animations: 'disabled' });
                    const metrics = await page.evaluate(() => ({
                        title: document.querySelector('.head-title h1,main h1')?.textContent?.trim() || '',
                        documentWidth: document.documentElement.scrollWidth,
                        viewportWidth: window.innerWidth,
                        horizontalOverflow: document.documentElement.scrollWidth > window.innerWidth + 2,
                        brokenImages: [...document.images].filter(x => x.complete && x.naturalWidth === 0).length
                    }));
                    result = { ...result, ...metrics, http: status, filename, ok: true };
                }
            } catch (error) {
                result.reason = error instanceof Error ? error.message : String(error);
            }
            results.push(result);
            console.log('[' + label + '] ' + key + ': ' + (result.ok ? 'captured' : result.reason));
        }
    }
    // Login is captured separately without signing out the working session.
    const guest = await browser.newContext({ viewport: { width: 1440, height: 900 } });
    const guestPage = await guest.newPage();
    await guestPage.goto(base + '/mc/admin.php', { waitUntil: 'domcontentloaded' });
    await guestPage.screenshot({ path: join(destination, 'admin-login-desktop.png'), fullPage: true });
    await guest.close();
} finally {
    writeFileSync(join(destination, 'report.json'), JSON.stringify({
        site: base, createdAt: new Date().toISOString(), pages: results
    }, null, 2));
    await browser.close();
}
console.log('Local screenshots and report: ' + destination);
console.log('This tool used GET navigation only after the owner signed in; it did not export cookies.');
