import { test, expect, gotoAndSettle, type Page } from '../support/test';
import { USER, storageStateOf } from '../support/accounts';

test.use({ storageState: storageStateOf(USER) });

// The workspace of workflow 1 of the fixtures, by user. It loads its tasks once connected to the
// websocket server (Gos WebSocket). An edition answers with JSON: the board only changes on the
// message the server pushes to every window of the workflow.
async function openWorkspace(page: Page): Promise<void> {
    await gotoAndSettle(page, '/processus/1.html?layout=workspace');
    // "Connexion...", then "Chargement des tâches..."
    await expect(page.locator('.ladb-workflow-workspace .ladb-loading-panel')).toBeHidden();
    await expect(page.locator('.ladb-workflow-task-widget', { hasText: 'Débiter les planches' })).toBeVisible();
}

test('connects to the websocket server, then loads the tasks', async ({ page }) => {
    await openWorkspace(page);
});

test('edits a workflow, and its other windows follow through the websocket', async ({ page, context }) => {
    const other = await context.newPage();
    await openWorkspace(page);
    await openWorkspace(other);

    const title = `Poncer au grain 240 (${Date.now()})`;
    await page.locator('.ladb-add-task-btn.ladb-visible-desktop').click();
    const modal = page.locator('#workflow_modal');
    await modal.locator('input[type=text]').first().fill(title);
    // Bootstrap leaves aria-hidden on its windows: no role locator in them
    const response = page.waitForResponse((response) => response.url().includes('/task/create'));
    await modal.locator('button[type=submit]').click();
    expect((await response).status()).toBe(200);
    await expect(modal).toBeHidden();

    const task = page.locator('.ladb-workflow-task-widget', { hasText: title });
    const otherTask = other.locator('.ladb-workflow-task-widget', { hasText: title });
    await expect(task).toBeVisible();
    await expect(otherTask).toBeVisible();
    // Without an incoming connection: workable (Task::STATUS_WORKABLE)
    await expect(otherTask.locator('.ladb-box')).toHaveClass(/ladb-status-2/);

    // Done (Task::STATUS_DONE)
    await task.locator('.ladb-btn-done').click();
    await expect(otherTask.locator('.ladb-box')).toHaveClass(/ladb-status-4/);
});
