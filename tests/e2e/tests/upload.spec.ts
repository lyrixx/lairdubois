import path from 'node:path';
import { test, expect, type Page } from '../support/test';
import { USER, storageStateOf } from '../support/accounts';

test.use({ storageState: storageStateOf(USER) });

const FILES = path.join(__dirname, '../files');

// The upload actions (core_picture_upload, core_resource_upload) end with exit(0): WebTestCase
// cannot call them. jQuery File Upload sends each file, then shows its thumbnail and writes the
// ids in the form.
async function openNewPlan(page: Page): Promise<void> {
    await page.goto('/plans/new');
    await page.locator('#warning_plan_modal [data-dismiss=modal].btn-primary').click();
}

// The answer of the upload action: a failure shows nothing in the page
async function upload(page: Page, section: string, filename: string): Promise<void> {
    const response = page.waitForResponse((response) => new URL(response.url()).pathname.endsWith('/upload'));
    await page.locator(`#${section}_fileupload input[type=file]`).setInputFiles(path.join(FILES, filename));
    expect((await response).status()).toBe(200);
}

async function uploadResource(page: Page, filename: string): Promise<void> {
    await upload(page, 'resources', filename);
    await expect(page.locator('#resources_thumbnails li.ladb-resource')).toContainText(filename);
    await expect(page.locator('#ladb_wonder_plan_resources')).toHaveValue(/^\d+$/);
}

test('uploads a PDF file', async ({ page }) => {
    await openNewPlan(page);
    await uploadResource(page, 'plan.pdf');
});

test('uploads an SVG file', async ({ page }) => {
    await openNewPlan(page);
    await uploadResource(page, 'plan.svg');
});

test('uploads a picture, then creates the plan', async ({ page }) => {
    await openNewPlan(page);
    await uploadResource(page, 'plan.pdf');

    await upload(page, 'pictures', 'picture.jpg');
    const thumbnail = page.locator('#pictures_thumbnails li.ladb-picture img');
    await expect(thumbnail).toBeVisible();
    // A thumbnail made by the server (Liip Imagine)
    await expect.poll(() => thumbnail.evaluate((img: HTMLImageElement) => img.naturalWidth)).toBeGreaterThan(0);
    await expect(page.locator('#ladb_wonder_plan_pictures')).toHaveValue(/^\d+$/);

    await page.locator('#ladb_wonder_plan_title').fill('Plan d’un banc de jardin');
    await page.locator('#ladb_wonder_plan_body').fill('Les cotes du banc, au format PDF.');
    const tagInput = page.locator('#ladb_wonder_plan_tags_token_input');
    await tagInput.fill('banc');
    await tagInput.press('Enter');

    await page.locator('.ladb-form-save-btn').click();

    // The draft
    await expect(page).toHaveURL(/\/plans\/\d+-plan-dun-banc-de-jardin(-\d+)?\.html$/);
    await expect(page.locator('h1')).toContainText('Plan d’un banc de jardin');
    await expect(page.getByText('Ce plan est un brouillon.')).toBeVisible();
    await expect(page.getByText('Télécharger le plan')).toBeVisible();
});
