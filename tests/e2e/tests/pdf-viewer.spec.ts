import path from 'node:path';
import { test, expect, gotoAndSettle } from '../support/test';
import { USER2, storageStateOf } from '../support/accounts';

// The manuals of tool 1 of the fixtures, which has none: no other test proposes one
const TOOL_MANUALS = '/knowledge/124/1/manual.xhr';

const VIEWER = /\/pdfjs\/web\/viewer\.html/;

test.use({ storageState: storageStateOf(USER2) });

test('shows a manual in the PDF viewer, which does not run strings as code', async ({ page }) => {
    await gotoAndSettle(page, '/outils/1.html');

    // The form of the proposals, in the modal that the thumbnail of the field opens
    await page.locator(`.thumbnail:has(a[href$="${TOOL_MANUALS}"]) .ladb-picture-box`).click();
    const form = page.locator('#proposals_modal form.ladb-value-proposal-form');
    await expect(form).toBeVisible();

    const upload = page.waitForResponse((response) => new URL(response.url()).pathname.endsWith('/upload'));
    await form.locator('input[type=file]').setInputFiles(path.join(__dirname, '../files/plan.pdf'));
    expect((await upload).status()).toBe(200);
    await expect(form.locator('li.ladb-resource')).toContainText('plan.pdf');

    await form.locator('select[id$="_sourceType"]').selectOption('1');
    const create = page.waitForResponse((response) => new URL(response.url()).pathname.endsWith('/manual/create'));
    await form.locator('button.btn-primary').click();
    expect((await create).status()).toBe(200);

    await gotoAndSettle(page, TOOL_MANUALS);
    const viewerResponse = page.waitForResponse((response) => VIEWER.test(response.url()));
    await page.getByRole('link', { name: 'Consulter' }).click();

    // Set by nginx (the map of infrastructure/docker/services/php/nginx/conf.d/default.conf)
    expect((await viewerResponse).headers()['content-security-policy']).toBe("script-src 'self'");

    const viewer = page.frameLocator('#content iframe');
    await expect(viewer.locator('.page[data-page-number="1"] .textLayer')).toContainText('Plan du banc');

    // The grey pixels of the edges of the letters: the page is drawn, not only its text layer
    const canvas = viewer.locator('.page[data-page-number="1"] canvas');
    expect(await canvas.evaluate((element: HTMLCanvasElement) => element
        .getContext('2d')
        .getImageData(0, 0, element.width, element.height)
        .data.some((value) => value > 0 && value < 255))).toBe(true);

    const frame = page.frame({ url: VIEWER });
    expect(await frame.evaluate(() => (window as any).PDFViewerApplicationOptions.get('isEvalSupported'))).toBe(false);
});
