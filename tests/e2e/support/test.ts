import { test as base } from '@playwright/test';

export { expect } from '@playwright/test';

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
