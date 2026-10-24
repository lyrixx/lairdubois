import { defineConfig, devices } from '@playwright/test';

// Set by the service "playwright" (infrastructure/docker/docker-compose.yml)
const baseURL = process.env.E2E_BASE_URL;
const router = process.env.E2E_ROUTER;
if (!baseURL || !router) {
    throw new Error('E2E_BASE_URL and E2E_ROUTER are not set: run the suite with castor qa:e2e.');
}

const chromium = {
    ...devices['Desktop Chrome'],
    launchOptions: {
        // The domain of the site, and the port of a worktree, lead to the router in the network
        // of the stack
        args: [`--host-resolver-rules=MAP ${new URL(baseURL).hostname} ${router}`],
    },
};

export default defineConfig({
    testDir: './tests',
    outputDir: '../../var/e2e/test-results',
    // One database for the whole suite (castor qa:e2e resets it before)
    workers: 1,
    forbidOnly: !!process.env.CI,
    reporter: process.env.CI ? [['github'], ['list']] : 'list',
    // The dev environment compiles the assets on demand (Assetic controller)
    expect: { timeout: 10_000 },
    use: {
        baseURL,
        // Certificates of mkcert, or self-signed
        ignoreHTTPSErrors: true,
        locale: 'fr-FR',
        trace: 'retain-on-failure',
        screenshot: 'only-on-failure',
    },
    projects: [
        {
            name: 'setup',
            testMatch: /\.setup\.ts$/,
            use: chromium,
        },
        {
            name: 'chromium',
            dependencies: ['setup'],
            use: chromium,
        },
    ],
});
