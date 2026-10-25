import { test, expect, gotoAndSettle } from '../support/test';
import { ADMIN, USER, USER2, storageStateOf } from '../support/accounts';

// Creation 1 of the fixtures, by user: liked, watched and commented by user2
const CREATION = '/creations/1.html';

test.describe('as admin', () => {
    test.use({ storageState: storageStateOf(ADMIN) });

    test('likes and unlikes a creation', async ({ page }) => {
        await page.goto(CREATION);
        const like = page.locator('.ladb-like-widget').first();
        const counter = page.locator('.ladb-meta', { has: page.locator('.ladb-icon-like') }).locator('strong');
        const likeCount = Number(await counter.textContent());

        await expect(like).not.toHaveClass(/\bactive\b/);
        await like.click();
        await expect(like).toHaveClass(/\bactive\b/);

        await page.reload();
        await expect(like).toHaveClass(/\bactive\b/);
        await expect(counter).toHaveText(String(likeCount + 1));

        await like.click();
        await expect(like).not.toHaveClass(/\bactive\b/);

        await page.reload();
        await expect(counter).toHaveText(String(likeCount));
    });

    test('follows and unfollows the author of a creation', async ({ page }) => {
        await page.goto(CREATION);
        const follower = page.locator('.ladb-follower-widget').first();
        const followerCount = Number(await follower.locator('a').nth(1).textContent());

        await follower.getByRole('link', { name: "S'abonner" }).click();
        await expect(follower.locator('a.active')).toContainText('Abonné');
        await expect(follower.locator('a').nth(1)).toHaveText(String(followerCount + 1));

        await follower.locator('a.active').click();
        await expect(follower.getByRole('link', { name: "S'abonner" })).toBeVisible();
        await expect(follower.locator('a').nth(1)).toHaveText(String(followerCount));
    });

    test('watches and unwatches a plan', async ({ page }) => {
        // Not the creation: liking it watches it
        await page.goto('/plans/1.html');
        // In the menu of the header
        const menu = page.locator('.btn-group', { has: page.locator('.ladb-icon-more') }).first();
        const watch = menu.locator('.ladb-watch-widget');
        const watchCount = Number(await watch.locator('a').nth(1).textContent());

        await menu.locator('.dropdown-toggle').click();
        await watch.getByRole('link', { name: 'Surveiller' }).click();
        await expect(watch.locator('a.active')).toContainText('Surveillé');
        await expect(watch.locator('a').nth(1)).toHaveText(String(watchCount + 1));

        await page.reload();
        await menu.locator('.dropdown-toggle').click();
        await watch.locator('a.active').click();
        await expect(watch.locator('a.active')).toHaveCount(0);
        await expect(watch.locator('a').nth(1)).toHaveText(String(watchCount));
    });

    test('votes for an answer, then withdraws the vote', async ({ page }) => {
        // Answer 1 of question 1, by user2
        await gotoAndSettle(page, '/questions/1.html');
        const widget = page.locator('.ladb-vote-widget').first();
        const score = widget.locator('.ladb-vote-score');
        const initialScore = Number(await score.textContent());
        const modal = page.locator('#new_vote_modal');

        // A vote opens a window for its reason, optional for a vote for
        await widget.locator('.ladb-vote-up').click();
        await modal.locator('.ladb-vote-btn').click();
        await expect(modal).toBeHidden();
        await expect(widget.locator('.ladb-vote-up')).toHaveClass(/ladb-active/);
        await expect(score).toHaveText(String(initialScore + 1));

        // Withdrawn at once
        await widget.locator('.ladb-vote-up').click();
        await expect(widget.locator('.ladb-vote-up')).not.toHaveClass(/ladb-active/);
        await expect(score).toHaveText(String(initialScore));
    });
});

test.describe('as user2', () => {
    test.use({ storageState: storageStateOf(USER2) });

    test('comments a creation', async ({ page }) => {
        await gotoAndSettle(page, CREATION);
        const widget = page.locator('#ladb_comment_100_1_widget');
        const body = 'Un commentaire écrit depuis le navigateur.';

        // The form loads in XHR, in place of the fake one
        await widget.locator('.ladb-fake-new').last().getByRole('button').click();
        const form = widget.locator('.ladb-new form');
        await form.locator('textarea').fill(body);
        await form.getByRole('button', { name: 'Publier' }).click();

        // The new comment is inserted before the form, which is emptied
        await expect(widget.locator('.ladb-comment-row', { hasText: body })).toBeVisible();
        await expect(form.locator('textarea')).toHaveValue('');

        await page.reload();
        await expect(widget.locator('.ladb-comment-row', { hasText: body })).toBeVisible();
    });

    test('answers a message as soon as the page is loaded', async ({ page }) => {
        // Thread 1 of the fixtures, from user2 to user. Without gotoAndSettle(): /@me/counters.json,
        // still running, no longer writes the session back without the CSRF token of the form (#88).
        // The race is rare here, this test passed before the fix too: CountersTest guards it.
        await page.goto('/messagerie/thread/1');
        const body = 'Une réponse envoyée dès le chargement.';

        await page.locator('.ladb-fake-new button').click();
        const form = page.locator('.ladb-messages .ladb-new form');
        await form.locator('textarea').fill(body);
        await form.locator('[type=submit]').click();

        await expect(page.locator('.ladb-message-row.ladb-message-mine', { hasText: body })).toBeVisible();
    });
});

test.describe('as user', () => {
    test.use({ storageState: storageStateOf(USER) });

    test('changes a vote for into a vote against, with a reason', async ({ page }) => {
        // Answer 1 of question 1, by user2, with a vote for of user in the fixtures. Not admin: a
        // second vote of an account within 3 seconds is refused (AbstractController::createLock())
        await gotoAndSettle(page, '/questions/1.html');
        const widget = page.locator('.ladb-vote-widget').first();
        const score = widget.locator('.ladb-vote-score');
        const initialScore = Number(await score.textContent());
        const modal = page.locator('#new_vote_modal');
        await expect(widget.locator('.ladb-vote-up')).toHaveClass(/ladb-active/);

        // The reason is mandatory
        await widget.locator('.ladb-vote-down').click();
        await modal.locator('.ladb-vote-btn').click();
        await expect(modal.locator('.has-error, .alert-danger').first()).toBeVisible();

        // The refused vote took the lock of the action, for 3 seconds (AbstractController::createLock()):
        // a vote within this time gets a 404, and the window closes without a word
        await page.waitForTimeout(3000);
        await modal.locator('textarea').fill('Le hêtre bouge trop avec l’humidité.');
        await modal.locator('.ladb-vote-btn').click();
        await expect(modal).toBeHidden();
        await expect(widget.locator('.ladb-vote-down')).toHaveClass(/ladb-active/);
        await expect(widget.locator('.ladb-vote-up')).not.toHaveClass(/ladb-active/);
        await expect(score).toHaveText(String(initialScore - 2));
    });

    test('answers a message', async ({ page }) => {
        // Thread 1 of the fixtures, from user2 to user
        await gotoAndSettle(page, '/messagerie/thread/1');
        const body = 'Merci, je regarde ça ce week-end.';

        // The form loads in XHR, in place of the fake one
        await page.locator('.ladb-fake-new button').click();
        const form = page.locator('.ladb-messages .ladb-new form');
        await form.locator('textarea').fill(body);
        await form.locator('[type=submit]').click();

        // The new message is inserted before the form, which is emptied
        await expect(page.locator('.ladb-message-row.ladb-message-mine', { hasText: body })).toBeVisible();
        await expect(form.locator('textarea')).toHaveValue('');

        await page.reload();
        await expect(page.locator('.ladb-message-row', { hasText: body })).toBeVisible();
    });

    test('reads the notifications', async ({ page }) => {
        // The fixtures give user notifications (likes, comments... of user2)
        await page.goto('/');
        const badge = page.locator('#ladb_notification_badge');
        await expect(badge).toBeVisible();

        await page.locator('#ladb_notification_btn').click();
        const modal = page.locator('#notifications_modal');
        await expect(modal).toBeVisible();
        await expect(modal.locator('.ladb-notification-row').first()).toBeVisible();
        await expect(badge).toBeHidden();

        // Seen: no more badge
        await page.reload();
        await expect(page.locator('#ladb_notification_badge')).toHaveCount(0);
    });
});
