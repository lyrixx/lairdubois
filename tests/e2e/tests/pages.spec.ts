import { test, expect } from '../support/test';
import { ADMIN, USER, storageStateOf } from '../support/accounts';

// The pages of the production stack (castor qa:prod-smoke sets E2E_PROD): its cache, its CSS and
// JS, its configuration. Every route is called anonymous, as user and as admin by the smoke test of
// PHPUnit (tests/Smoke/RouteSmokeTest.php), in the test environment: here, the lists and the
// content of each type (CLAUDE.md, "Fixtures"), and the pages of an account.
test.skip(!process.env.E2E_PROD, 'Pages of the production stack only (castor qa:prod-smoke).');

const PAGES = [
    '/',
    '/creations/', '/creations/1.html',
    '/ateliers/', '/ateliers/1.html',
    '/plans/', '/plans/1.html',
    '/pas-a-pas/', '/pas-a-pas/1.html', '/pas-a-pas/articles/1.html',
    '/trouvailles/', '/trouvailles/1.html',
    '/evenements/', '/evenements/1.html',
    '/annonces/', '/annonces/1.html',
    '/questions/', '/questions/1.html',
    '/processus/', '/processus/1.html',
    '/collections/', '/collections/1.html',
    '/promouvoir/', '/promouvoir/1.html',
    '/xylotheque/', '/xylotheque/1.html',
    '/fournisseurs/', '/fournisseurs/1.html',
    '/ecoles/', '/ecoles/1.html',
    '/livres/', '/livres/1.html',
    '/logiciels/', '/logiciels/1.html',
    '/outils/', '/outils/1.html',
    '/blog/', '/blog/1.html',
    '/faq/', '/faq/1.html',
    '/financement/',
    '/rechercher/creations?q=table',
    '/@user', '/@user/a-propos', '/@team',
];

const SITE_HOST = new URL(process.env.E2E_BASE_URL ?? 'https://localhost').host;

// Only for an account
const ACCOUNT_PAGES = ['/notifications/', '/messagerie/', '/messagerie/thread/1', '/@me/parametres'];

const ROLES = [
    { name: 'anonymous', storageState: { cookies: [], origins: [] }, pages: PAGES },
    { name: USER, storageState: storageStateOf(USER), pages: [...PAGES, ...ACCOUNT_PAGES] },
    { name: ADMIN, storageState: storageStateOf(ADMIN), pages: [...PAGES, ...ACCOUNT_PAGES] },
];

for (const role of ROLES) {
    test.describe(role.name, () => {
        test.use({ storageState: role.storageState });

        for (const url of role.pages) {
            test(`shows ${url}`, async ({ page }) => {
                // The files of the site that the page loads: CSS and JS of the build, assets of
                // the bundles, images of Liip Imagine
                const failures: string[] = [];
                page.on('response', (response) => {
                    if (new URL(response.url()).host === SITE_HOST && response.status() >= 400) {
                        failures.push(`${response.status()} ${response.url()}`);
                    }
                });

                const response = await page.goto(url);
                expect(response?.status(), url).toBe(200);
                await expect(page.locator('link[rel=stylesheet][href*="/build/"]').first()).toBeAttached();
                await page.waitForLoadState('load');
                expect(failures, 'Files of the site in error').toEqual([]);
            });
        }
    });
}
