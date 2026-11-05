import { test, expect, type Page } from '../support/test';

const MARKUP = '<b>value</b>';

function gotoSearch(page: Page, list: string, query: string): Promise<unknown> {
    return page.goto(`${list}?q=${encodeURIComponent(query)}`);
}

test('builds no tag from a value of the query', async ({ page }) => {
    await gotoSearch(page, '/creations/', `@sort-recent:a">${MARKUP} @license:${MARKUP}`);

    const box = page.locator('.ladb-search-box');
    const facets = box.locator('.ladb-facet');
    await expect(facets).toHaveCount(2);
    await expect(box.locator('b')).toHaveCount(0);

    // An unknown order is the default one for the server (SearchUtils::getSorterOrder())
    await expect(facets.first().locator('.ladb-facet-name')).toHaveText('Récentes');
    await expect(facets.first().locator('.ladb-sorter-action i')).toHaveClass('ladb-icon-sort-desc');

    await expect(facets.last().locator('input')).toHaveValue(MARKUP);
});

test('adds no class from the value of a sorter', async ({ page }) => {
    await gotoSearch(page, '/creations/', '@sort-recent:"desc hidden"');

    await expect(page.locator('.ladb-sorter .ladb-sorter-action i')).toHaveClass('ladb-icon-sort-desc');
});

test('shows the facets and the order of a query', async ({ page }) => {
    await gotoSearch(page, '/creations/', '@tag:bois @sort-popular-views:asc chaise');

    const box = page.locator('.ladb-search-box');
    await expect(box.locator('.ladb-input > input')).toHaveValue('chaise');

    const tag = box.locator('.ladb-facet.ladb-filter');
    await expect(tag.locator('.ladb-icon-tag')).toHaveCount(1);
    await expect(tag.locator('.ladb-facet-name')).toHaveText('Tag : ');
    await expect(tag.locator('input')).toHaveValue('bois');

    const sorter = box.locator('.ladb-facet.ladb-sorter');
    await expect(sorter.locator('.ladb-icon-eye')).toHaveCount(1);
    await expect(sorter.locator('.ladb-facet-name')).toHaveText('Plus vues');
    const order = sorter.locator('.ladb-sorter-action i');
    await expect(order).toHaveClass('ladb-icon-sort-asc');

    await Promise.all([
        page.waitForURL((url) => url.searchParams.get('q') === 'chaise @tag:"bois" @sort-popular-views:desc'),
        order.click(),
    ]);
    await expect(order).toHaveClass('ladb-icon-sort-desc');
});

test('shows the default order of a sorter without value', async ({ page }) => {
    await gotoSearch(page, '/creations/', '@sort-recent');
    await expect(page.locator('.ladb-sorter .ladb-sorter-action i')).toHaveClass('ladb-icon-sort-desc');

    await gotoSearch(page, '/annonces/', '@sort-price');
    await expect(page.locator('.ladb-sorter .ladb-sorter-action i')).toHaveClass('ladb-icon-sort-asc');

    await gotoSearch(page, '/annonces/', '@sort-price:desc');
    await expect(page.locator('.ladb-sorter .ladb-sorter-action i')).toHaveClass('ladb-icon-sort-desc');
});

test('keeps the markup of a label, written in the templates', async ({ page }) => {
    await gotoSearch(page, '/boiseux', '@account-type:3');

    const name = page.locator('.ladb-facet.ladb-filter .ladb-facet-name');
    await expect(name).toContainText('Passionné-e-s');
    await expect(name.locator('.ladb-hidden-mobile')).toHaveText(' par le travail du bois');
});
