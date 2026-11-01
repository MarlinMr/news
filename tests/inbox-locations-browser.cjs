// Run against a local PHP server (never production). Requires Playwright/Chromium.
// BASE_URL=http://127.0.0.1:8087 PLAYWRIGHT_MODULE=/path/to/playwright node tests/inbox-locations-browser.cjs
const { chromium } = require(process.env.PLAYWRIGHT_MODULE || 'playwright');
const assert = require('node:assert/strict');
const base = process.env.BASE_URL || 'http://127.0.0.1:8087';

(async () => {
    const browser = await chromium.launch({ executablePath: process.env.CHROME_PATH || '/usr/bin/google-chrome', args: ['--no-sandbox'] });
    try {
        for (const width of [1280, 390]) {
            const page = await browser.newPage({ viewport: { width, height: 900 } });
            const errors = [], searches = [];
            let writes = 0, signedIn = false, placementStatus = 200;
            const placements = [];
            page.on('pageerror', error => errors.push(error.message));
            const stories = Array.from({ length: 21 }, (_, index) => ({
                id: index + 1, title: index === 0 ? 'Røykutvikling på Majorstuen i Oslo' : index === 1 ? 'Protests in London and Paris' : `Story ${index + 1}`,
                summary: '', author: 'test-reader', emoji: '📰', published_at: '2026-11-01 12:00:00', url: 'https://www.nrk.no/example',
                latitude: null, longitude: null
            }));
            await page.route('**/*', async route => {
                const url = new URL(route.request().url());
                if (url.pathname.endsWith('/api/auth.php')) {
                    if (url.searchParams.get('action') === 'login') {
                        signedIn = true; return route.fulfill({ json: { message: 'Logged in' } });
                    }
                    return route.fulfill({ json: { authenticated: signedIn, user: { id: 4, username: 'tester', role: 'user' } } });
                }
                if (url.pathname.endsWith('/api/articles.php')) {
                    if (route.request().method() !== 'GET') {
                        writes++; placements.push(route.request().postDataJSON());
                        return route.fulfill({ status: placementStatus, json: placementStatus === 200 ? { message: 'Placed' } : { error: placementStatus === 401 ? 'Please sign in' : 'Already placed by another user' } });
                    }
                    return route.fulfill({ json: stories });
                }
                if (url.hostname === 'nominatim.openstreetmap.org') {
                    searches.push({ query: url.searchParams.get('q'), time: Date.now() });
                    return route.fulfill({ json: [{ lat: '59.92927', lon: '10.71557', display_name: 'Majorstuen, Oslo, Norway' },
                        { lat: '60', lon: '11', display_name: 'Alternative location, Norway' }] });
                }
                if (url.origin !== new URL(base).origin) return route.abort();
                return route.continue(); // Use the actual local PHP suggestion endpoint.
            });
            await page.goto(`${base}/inbox-locations.html`);
            await page.getByRole('button', { name: 'Search Majorstuen, Oslo', exact: true }).waitFor();
            assert.equal(await page.locator('article').count(), 20);
            assert.equal(searches.length, 0, 'Never automatically contact the external geocoder');
            const first = page.locator('article').first();
            await first.getByRole('button', { name: 'Search all guesses for this story' }).click();
            await page.waitForFunction(() => document.querySelectorAll('article:first-child .result').length === 6);
            assert.equal(searches.length, 3);
            for (let i = 1; i < searches.length; i++) assert(searches[i].time - searches[i - 1].time >= 1000);
            await first.getByRole('button', { name: 'Search Majorstuen, Oslo', exact: true }).click();
            await page.waitForFunction(() => document.getElementById('stop').disabled);
            assert.equal(searches.length, 3, 'Repeated searches reuse the page cache');
            const review = new URL(await first.getByRole('link', { name: 'Review and place on map ↗' }).first().getAttribute('href'));
            assert.equal(review.searchParams.get('review_inbox'), '1');
            assert.equal(review.searchParams.get('lat'), '59.92927');
            assert.equal(writes, 0);
            await first.locator('form input').fill('Belfast');
            await first.locator('form').getByRole('button', { name: 'Search', exact: true }).click();
            await page.waitForFunction(() => document.querySelectorAll('article:first-child .result').length === 8);
            assert.equal(searches.length, 4);
            await page.locator('article').nth(1).getByRole('button', { name: 'Search all guesses for this story' }).click();
            await page.getByRole('button', { name: 'Stop searches' }).click();
            await page.waitForFunction(() => document.getElementById('stop').disabled);
            assert(searches.length < 6, 'Stopping prevents the remaining queued searches');
            await page.getByRole('button', { name: 'Next', exact: true }).click();
            assert.equal(await page.locator('article').count(), 1);
            await page.getByRole('button', { name: 'Previous', exact: true }).click();
            await page.waitForFunction(() => document.querySelectorAll('article:first-child .result').length === 8);
            assert((await page.locator('article').first().textContent()).includes('Custom search: Belfast'));
            await page.locator('#filter').fill('London');
            assert.equal(await page.locator('article').count(), 1);
            await page.getByRole('button', { name: 'Search London', exact: true }).waitFor();
            await page.locator('#filter').fill('no matching title');
            assert.equal(await page.locator('article').count(), 0);
            assert.equal(writes, 0);
            assert.deepEqual(errors, []);
            await page.locator('#filter').fill('');
            await page.waitForFunction(() => document.querySelectorAll('article:first-child .result').length === 8);
            await page.locator('article').first().getByRole('button', { name: 'Accept location', exact: true }).first().click();
            assert.equal(writes, 0, 'Signed-out users cannot save');
            await page.locator('#username').fill('tester'); await page.locator('#password').fill('test-password');
            await page.locator('#signIn').click();
            await page.waitForFunction(() => document.getElementById('authStatus').textContent.includes('Signed in as tester'));
            assert.equal(writes, 0, 'Signing in must not implicitly accept a location');
            await page.locator('article').first().getByRole('button', { name: 'Accept location', exact: true }).first().evaluate(button => { button.click(); button.click(); });
            await page.waitForFunction(() => document.getElementById('saveStatus').textContent.startsWith('Placed'));
            assert.equal(writes, 1, 'Double clicks do not issue duplicate writes');
            assert.deepEqual(placements[0], { action: 'place_inbox', id: 1, latitude: 59.92927, longitude: 10.71557 });
            assert.equal(await page.locator('article[data-article-id="1"]').count(), 0);
            const second = page.locator('article[data-article-id="2"]');
            await second.getByRole('button', { name: 'Search London', exact: true }).click();
            await second.getByRole('button', { name: 'Accept location', exact: true }).first().waitFor();
            placementStatus = 401;
            await second.getByRole('button', { name: 'Accept location', exact: true }).first().click();
            await page.waitForFunction(() => document.getElementById('signInPanel').open && !document.getElementById('signInPanel').hidden);
            assert.equal(await second.count(), 1, 'Expired sessions keep the story for retry');
            await page.locator('#password').fill('test-password'); await page.locator('#signIn').click();
            await page.waitForFunction(() => document.getElementById('authStatus').textContent.includes('Signed in as tester'));
            placementStatus = 409;
            await second.getByRole('button', { name: 'Accept location', exact: true }).first().click();
            await page.waitForFunction(() => document.getElementById('saveStatus').textContent.includes('Already placed'));
            assert.equal(await page.locator('article[data-article-id="2"]').count(), 0);
            assert.equal(writes, 3);
            assert.deepEqual(errors, []);
            await page.close();
            console.log(`Inbox review passed: ${width}px, searches, direct acceptance, login/session expiry, double-click and conflict handling`);
        }
    } finally { await browser.close(); }
})().catch(error => { console.error(error); process.exitCode = 1; });
