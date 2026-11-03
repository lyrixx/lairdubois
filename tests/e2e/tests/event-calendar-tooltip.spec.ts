import { test, expect, gotoAndSettle } from '../support/test';
import { USER, storageStateOf } from '../support/accounts';

// Event 1 of the fixtures, by user, in a month: no other test uses it
const EVENT_ID = 1;

// With markup, an apostrophe and an ampersand: shown as typed, neither read as HTML nor escaped
// twice
const TITLE = "<b>L'atelier & co</b>";

test.use({ storageState: storageStateOf(USER) });

test('shows the title of an event as text in the tooltip of the calendar', async ({ page }) => {
    await gotoAndSettle(page, `/evenements/${EVENT_ID}/edit`);
    const [day, month, year] = (await page.inputValue('input[name="ladb_event_event[startDate]"]')).split('/');
    await page.fill('input[name="ladb_event_event[title]"]', TITLE);
    await Promise.all([
        page.waitForURL(`/evenements/${EVENT_ID}/update`),
        page.click('.ladb-form-save-btn'),
    ]);
    await expect(page.locator('.alert-success')).toBeVisible();

    // A searched day opens the calendar on its month, whatever the date of today
    await gotoAndSettle(page, `/evenements/?q=@day:${year}-${month}-${day}`);
    await page.locator(`#ladb_calendar td.calendar-day-${year}-${month}-${day}`).hover();

    // The tooltip of Bootstrap reads its title as HTML (data-html)
    const tooltip = page.locator('.tooltip.in .tooltip-inner');
    await expect(tooltip).toHaveText(TITLE);
    await expect(tooltip.locator('b')).toHaveCount(0);
});
