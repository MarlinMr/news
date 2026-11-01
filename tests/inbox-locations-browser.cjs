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
            let writes = 0;
            page.on('pageerror', error => errors.push(error.message));
            const stories = Array.from({ length: 21 }, (_, index) => ({
                id: index + 1, title: index === 0 ? 'Røykutvikling på Majorstuen i Oslo' : index === 1 ? 'Protests in London and Paris' : `Story ${index + 1}`,
                summary: '', author: 'test-reader', emoji: '📰', published_at: '2026-11-01 12:00:00', url: 'https://www.nrk.no/example',
                latitude: null, longitude: null
            }));
            await page.route('**/*', async route => {
                const url = new URL(route.request().url());
                if (url.pathname.endsWith('/api/articles.php')) {
                    if (route.request().method() !== 'GET') writes++;
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
            await page.close();
            console.log(`Inbox review passed: ${width}px, pagination/filtering, guesses, rate limiting, cache, cancellation, no writes`);
        }
    } finally { await browser.close(); }
})().catch(error => { console.error(error); process.exitCode = 1; });
