import type { Locator } from '@playwright/test';
import { test, expect, gotoAndSettle } from '../support/test';
import { USER, storageStateOf } from '../support/accounts';

// The dates that moment handles in the browser (its French locale, vendor/moment/moment/locale/fr.js):
// the calendar of the events (clndr) and the date pickers of the event form
// (bootstrap-datetimepicker). On a fixed month, whatever the date of today

test.use({ storageState: storageStateOf(USER) });

/**
 * Types a date in a field with a picker. Focusing the field rewrites its value, today's date when
 * it is empty, and loses the selection of fill(): the typed date would come after it, and be
 * ignored. Hence the click first.
 */
async function typeDate(field: Locator, date: string): Promise<void> {
    await field.click();
    await field.fill(date);
    await field.blur();
}

test('shows the calendar of the events in French, on the month of a searched day', async ({ page }) => {
    await gotoAndSettle(page, '/evenements/?q=@day:2026-03-15');

    const calendar = page.locator('#ladb_calendar');
    await expect(calendar.locator('.month')).toHaveText('mars 2026');
    // The week starts on Monday: the 1st of March 2026, a Sunday, is its last day
    await expect(calendar.locator('.header-day')).toHaveText(['l', 'm', 'm', 'j', 'v', 's', 'd']);
    await expect(calendar.locator('td.calendar-day-2026-03-01')).toHaveClass(/\bcalendar-dow-6\b/);
    await expect(calendar.locator('td.calendar-day-2026-03-15 .day-contents')).toHaveText('15');
});

test('picks the dates of an event in French, the end never before the start', async ({ page }) => {
    // The new event warning first: nothing saved, no event of the fixtures touched
    await gotoAndSettle(page, '/evenements/new');
    const warning = page.locator('#warning_event_modal');
    await warning.locator('.btn-primary[data-dismiss="modal"]').click();
    await expect(warning).toBeHidden();

    const startDate = page.locator('input[name="ladb_event_event[startDate]"]');
    const endDate = page.locator('input[name="ladb_event_event[endDate]"]');

    // A typed date is read in the format of the field, and opens the picker on its month
    await typeDate(startDate, '15/03/2026');
    await startDate.click();
    const picker = page.locator('.bootstrap-datetimepicker-widget .datepicker-days');
    await expect(picker.locator('th.picker-switch')).toHaveText('mars 2026');
    await expect(picker.locator('th.dow')).toHaveText(['lu', 'ma', 'me', 'je', 've', 'sa', 'di']);
    await expect(picker.locator('td.day.active')).toHaveText('15');

    await picker.locator('td.day[data-day="20/03/2026"]').click();
    await expect(startDate).toHaveValue('20/03/2026');

    await typeDate(endDate, '18/03/2026');
    await expect(endDate).toHaveValue('18/03/2026');

    // A start after the end moves the end to it (moment.max() of the form), a start before leaves it
    await typeDate(startDate, '25/03/2026');
    await expect(startDate).toHaveValue('25/03/2026');
    await expect(endDate).toHaveValue('25/03/2026');

    await typeDate(startDate, '10/03/2026');
    await expect(startDate).toHaveValue('10/03/2026');
    await expect(endDate).toHaveValue('25/03/2026');
});
