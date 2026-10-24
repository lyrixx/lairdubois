import { test as setup, expect } from '../support/test';
import { ACCOUNTS, storageStateOf } from '../support/accounts';

// Logs each account in once, through the login form: the tests reuse its session (in the
// database of the site, reset before the suite)
for (const username of ACCOUNTS) {
    setup(`log in as ${username}`, async ({ page }) => {
        await page.goto('/login');
        await page.locator('#username').fill(username);
        await page.locator('#password').fill(username);
        await page.locator('form[action$="/login_check"] [type=submit]').click();

        await expect(page.locator('form[action$="/login_check"]')).toHaveCount(0);
        await page.context().storageState({ path: storageStateOf(username) });
    });
}
