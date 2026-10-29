import { test, expect } from '../support/test';

// The hub of Mercure, behind the router on the domain and port of the site. No anonymous
// subscriber: the JWT comes from a cookie that the site sets.
test('the hub refuses a subscriber without a JWT', async ({ page }) => {
    const topic = 'https://lairdubois.fr/workflows/1';
    const response = await page.goto(`/.well-known/mercure?match=${encodeURIComponent(topic)}`);
    expect(response?.status()).toBe(401);
});
