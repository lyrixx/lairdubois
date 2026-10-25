import { test as base, type Page } from '@playwright/test';

export { expect, type Page } from '@playwright/test';

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
        await use(context);
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
