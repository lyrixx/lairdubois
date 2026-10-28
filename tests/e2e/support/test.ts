import fs from 'node:fs';
import path from 'node:path';
import { test as base, type Page, type Route } from '@playwright/test';

export { expect, type Page } from '@playwright/test';

// The libraries that the site loads from CDNs are served from a local copy, identical to the file
// of the CDN (sha256 compared when added): a CDN that does not answer would hold the event "load"
// of the page, and the test with it (#143). A file of an npm package, at the version of the URL
// (package.json), or else a copy of the file of the CDN, in cdn/<host>/<path>.
const CDN_HOSTS = ['cdn.jsdelivr.net', 'cdnjs.cloudflare.com'];
const CDN_COPIES = path.join(__dirname, '../cdn');
const NPM_FILES = new Map(Object.entries({
    'https://cdn.jsdelivr.net/npm/emojione@4.5/lib/js/emojione.min.js': 'emojione/lib/js/emojione.min.js',
    'https://cdn.jsdelivr.net/npm/mathjax@3/es5/tex-mml-chtml.js': 'mathjax/es5/tex-mml-chtml.js',
    'https://cdnjs.cloudflare.com/ajax/libs/bootstrap-select/1.12.4/css/bootstrap-select.min.css': 'bootstrap-select/dist/css/bootstrap-select.min.css',
    'https://cdnjs.cloudflare.com/ajax/libs/bootstrap-select/1.12.4/js/bootstrap-select.min.js': 'bootstrap-select/dist/js/bootstrap-select.min.js',
    'https://cdnjs.cloudflare.com/ajax/libs/Chart.js/2.7.0/Chart.min.js': 'chart.js-2.7.0/dist/Chart.min.js',
    'https://cdnjs.cloudflare.com/ajax/libs/Chart.js/2.9.4/Chart.min.js': 'chart.js-2.9.4/dist/Chart.min.js',
    'https://cdnjs.cloudflare.com/ajax/libs/jsPlumb/2.2.9/jsplumb.min.js': 'jsplumb-2.2.9/dist/js/jsplumb.min.js',
    'https://cdnjs.cloudflare.com/ajax/libs/jsPlumb/2.6.0/js/jsplumb.min.js': 'jsplumb-2.6.0/dist/js/jsplumb.min.js',
    'https://cdnjs.cloudflare.com/ajax/libs/selectize.js/0.12.4/css/selectize.bootstrap3.css': 'selectize/dist/css/selectize.bootstrap3.css',
    'https://cdnjs.cloudflare.com/ajax/libs/selectize.js/0.12.4/js/standalone/selectize.min.js': 'selectize/dist/js/standalone/selectize.min.js',
    'https://cdnjs.cloudflare.com/ajax/libs/underscore.js/1.8.3/underscore-min.js': 'underscore/underscore-min.js',
}).map(([url, file]) => [url, require.resolve(file)]));

export const test = base.extend({
    context: async ({ context }, use) => {
        // The site serves the dev environment: the web debug toolbar of Symfony would cover the
        // bottom of the window
        await context.addInitScript(() => {
            window.addEventListener('DOMContentLoaded', () => {
                const style = document.createElement('style');
                style.textContent = '.sf-toolbar { display: none !important; }';
                document.head.append(style);
            });
        });

        const withoutCopy = new Set<string>();
        for (const host of CDN_HOSTS) {
            await context.route(`https://${host}/**`, (route) => serveFromCopy(route, withoutCopy));
        }

        await use(context);

        if (withoutCopy.size > 0) {
            throw new Error(`The page requested files of a CDN without local copy, add one (tests/e2e/support/test.ts): ${[...withoutCopy].join(', ')}.`);
        }
    },
});

/**
 * Opens a page, then waits for the end of the requests it sends, before a form loaded in XHR: the
 * sessions have no lock, such a request that writes the session back would erase the CSRF token of
 * the form. /@me/counters.json no longer does (#88).
 */
export async function gotoAndSettle(page: Page, url: string): Promise<void> {
    await page.goto(url);
    await page.waitForLoadState('networkidle');
}

async function serveFromCopy(route: Route, withoutCopy: Set<string>): Promise<void> {
    const url = new URL(route.request().url());
    const copy = path.join(CDN_COPIES, url.hostname, url.pathname);
    const file = NPM_FILES.get(url.origin + url.pathname) ?? (fs.existsSync(copy) ? copy : undefined);
    if (!file) {
        withoutCopy.add(url.href);
        await route.abort();

        return;
    }

    await route.fulfill({ path: file });
}
