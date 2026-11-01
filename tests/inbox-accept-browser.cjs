// Local PHP server + Playwright + the bundled Leaflet vendor directory required.
// PLAYWRIGHT_MODULE=/path/to/playwright LEAFLET_VENDOR_DIR=/path/to/vendor node tests/inbox-accept-browser.cjs
const { chromium } = require(process.env.PLAYWRIGHT_MODULE || 'playwright');
const assert = require('node:assert/strict');
const path = require('node:path');
const base = process.env.BASE_URL || 'http://127.0.0.1:8087';
const vendor = process.env.LEAFLET_VENDOR_DIR;
if (!vendor) throw new Error('Set LEAFLET_VENDOR_DIR to the Docker image’s bundled vendor directory.');

(async () => {
    const browser = await chromium.launch({ executablePath: process.env.CHROME_PATH || '/usr/bin/google-chrome', args: ['--no-sandbox'] });
    try {
        for (const width of [1280, 390]) {
            const page = await browser.newPage({ viewport: { width, height: 900 } });
            const errors = [], writes = [];
            let status = 200, signedIn = true;
            let stories = [
                { id: 1, title: 'Røykutvikling på Majorstuen i Oslo', summary: '' },
                { id: 2, title: 'Fire at recycling centre', summary: 'Fire crews are in Bristol.' }
            ].map(story => ({ ...story, user_id: 2, emoji: '🔥', author: 'reader', published_at: '2026-11-01 12:00:00', latitude: null, longitude: null, updates: [], url: 'https://www.nrk.no/example' }));
            page.on('pageerror', error => errors.push(error.message));
            await page.route('**/*', async route => {
                const url = new URL(route.request().url());
                if (url.hostname === 'unpkg.com') {
                    const asset = url.pathname.replace('/leaflet@1.9.4/', '/leaflet-1.9.4/').replace('/leaflet.markercluster@1.5.3/', '/leaflet.markercluster-1.5.3/');
                    return route.fulfill({ path: path.join(vendor, asset) });
                }
                if (url.hostname === 'nominatim.openstreetmap.org') return route.fulfill({ json: [
                    { lat: '59.92927', lon: '10.71557', display_name: 'Majorstuen, Oslo, Norway' },
                    { lat: '60', lon: '11', display_name: 'Alternative location, Norway' }
                ] });
                if (url.origin !== new URL(base).origin) return route.abort();
                if (url.pathname.endsWith('/api/auth.php')) {
                    if (route.request().method() === 'POST') { signedIn = true; return route.fulfill({ json: { message: 'Signed in' } }); }
                    return route.fulfill({ json: { authenticated: signedIn, user: { id: 4, username: 'tester', role: 'user' } } });
                }
                if (url.pathname.endsWith('/api/articles.php')) {
                    if (route.request().method() === 'POST') {
                        const data = route.request().postDataJSON(); writes.push(data);
                        if (status === 200 || status === 409) stories = stories.filter(story => story.id !== data.id);
                        if (status === 401) signedIn = false;
                        return route.fulfill({ status, json: status === 200 ? { message: 'Placed' } : { error: status === 500 ? 'Temporary failure' : status === 401 ? 'Please sign in' : 'Already placed' } });
                    }
                    return route.fulfill({ json: url.searchParams.get('location') === 'inbox' ? stories : [] });
                }
                return route.continue();
            });
            async function searchFirst(query) {
                await page.locator('#inboxStories').getByRole('button', { name: 'Suggest locations', exact: true }).first().click();
                await page.getByRole('button', { name: `Search ${query}`, exact: true }).click();
                await page.locator('#inboxStories').getByRole('button', { name: 'Accept location', exact: true }).first().waitFor();
            }
            await page.goto(base);
            await page.evaluate(() => openInbox()); await searchFirst('Majorstuen, Oslo');
            const center = await page.evaluate(() => [map.getCenter().lat, map.getCenter().lng]);
            assert.equal(writes.length, 0);
            status = 500;
            await page.getByRole('button', { name: 'Accept location', exact: true }).first().click();
            await page.waitForFunction(() => document.querySelector('#inboxStories').textContent.includes('Temporary failure'));
            assert.equal(await page.locator('#inboxModal').isVisible(), true);
            assert.equal(await page.locator('#inboxStories article').count(), 2);
            status = 200;
            await page.getByRole('button', { name: 'Accept location', exact: true }).first().evaluate(button => { button.click(); button.click(); });
            await page.waitForFunction(() => document.querySelectorAll('#inboxStories article').length === 1);
            await page.waitForFunction(() => document.getElementById('inboxButton').textContent === 'Inbox (1)');
            assert.equal(writes.length, 2, 'A double click only adds one save request');
            assert.deepEqual(writes[1], { action: 'place_inbox', id: 1, latitude: 59.92927, longitude: 10.71557 });
            assert.equal(await page.locator('#inboxModal').isVisible(), true);
            assert.deepEqual(await page.evaluate(() => [map.getCenter().lat, map.getCenter().lng]), center);
            assert.equal(await page.locator('#inboxPlaceModal').isVisible(), false);
            await searchFirst('Bristol'); status = 401;
            await page.getByRole('button', { name: 'Accept location', exact: true }).first().click();
            await page.locator('#authModal').waitFor({ state: 'visible' });
            await page.locator('#authIdentifier').fill('tester'); await page.locator('#authPassword').fill('test-password');
            await page.evaluate(() => document.querySelector('#authModal form').requestSubmit());
            await page.waitForFunction(() => currentUser?.username === 'tester');
            assert.equal(writes.length, 3, 'Signing in does not implicitly accept a guess');
            await page.evaluate(() => openInbox()); await searchFirst('Bristol'); status = 409;
            await page.getByRole('button', { name: 'Accept location', exact: true }).first().click();
            await page.waitForFunction(() => document.getElementById('inboxStatus').textContent === 'The inbox is empty.');
            assert.equal(writes.length, 4);
            assert.equal(await page.locator('#inboxModal').isVisible(), true);
            assert.deepEqual(errors, []);
            await page.close(); console.log(`Inbox direct acceptance passed: ${width}px, save/retry, duplicate clicks, session expiry, conflicts, no map navigation`);
        }
    } finally { await browser.close(); }
})().catch(error => { console.error(error); process.exitCode = 1; });
