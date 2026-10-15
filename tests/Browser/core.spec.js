import fs from 'node:fs/promises';
import { expect, test } from '@playwright/test';
import { assertKeyboardReachable, assertPageQuality, observeRuntime } from './helpers/quality.js';

async function login(page) {
    await page.goto('/login');
    await page.locator('input[type="email"]').fill('admin@koma.test');
    await page.locator('input[type="password"]').fill('password');
    await page.getByRole('button', { name: 'Sign in' }).click();
    await expect(page).toHaveURL(/\/admin\/?$/);
}

test('publishes and reads a chapter across the release matrix', async ({ page }, testInfo) => {
    const runtimeFailures = observeRuntime(page);
    const fixtureDirectory = testInfo.outputPath('pages');
    const fixturePaths = [1, 2, 3].map((number) => `${fixtureDirectory}/${String(number).padStart(3, '0')}.png`);

    await fs.mkdir(fixtureDirectory, { recursive: true });
    for (const [index, path] of fixturePaths.entries()) {
        await page.setContent(`<main style="width:240px;height:360px;background:hsl(${index * 90} 70% 65%)"></main>`);
        await page.screenshot({ path, clip: { x: 0, y: 0, width: 240, height: 360 } });
    }

    await login(page);
    await assertKeyboardReachable(page);
    await assertPageQuality(page, testInfo, runtimeFailures, 'admin-dashboard');

    await page.goto('/admin/series/create');
    await page.locator('input[wire\\:model="title"]').fill(`QA Matrix ${testInfo.project.name}`);
    await page.locator('input[wire\\:model="author"]').fill('Koma QA');
    await page.getByRole('button', { name: 'Public', exact: true }).click();
    await page.getByRole('button', { name: 'Create series' }).click();
    await expect(page).toHaveURL(/\/admin\/series\/qa-matrix-/);

    await page.getByRole('link', { name: /Upload chapter/ }).click();
    await page.locator('input[x-ref="input"]').setInputFiles(fixturePaths);
    await expect(page.locator('[role="status"]').first()).toContainText('3 ready', { timeout: 60_000 });
    await page.locator('input[wire\\:model="number"]').fill('1');
    await page.locator('input[wire\\:model="chapterTitle"]').fill('Browser matrix chapter');
    await page.getByRole('button', { name: 'Publish chapter' }).click();
    await expect(page).toHaveURL(/\/admin\/series\/qa-matrix-/);
    await expect(page.getByText('Published', { exact: true })).toBeVisible();

    const publicHref = await page.getByRole('link', { name: 'View on site' }).getAttribute('href');
    expect(publicHref).toBeTruthy();
    await page.goto(publicHref);
    await expect(page.getByText(`QA Matrix ${testInfo.project.name}`, { exact: true })).toBeVisible();
    await assertPageQuality(page, testInfo, runtimeFailures, 'public-series');

    await page.getByRole('link', { name: /Start (from|at) Ch\. 1/ }).click();
    await expect(page.getByRole('group', { name: 'Reader presentation' })).toBeVisible();

    // The mode buttons do nothing until the deferred reader script has run,
    // which a slow third-party font stylesheet can delay. That script fills
    // the page counter when it initialises, so wait for it before clicking.
    await expect(page.locator('#rCounter')).toHaveText(/^\d+ \/ \d+$/);

    for (const name of ['Read pages left to right', 'Read as a vertical webtoon', 'Read pages right to left']) {
        const button = page.getByRole('button', { name });
        await button.click();
        await expect(button).toHaveAttribute('aria-pressed', 'true');
    }

    await assertKeyboardReachable(page);
    await assertPageQuality(page, testInfo, runtimeFailures, 'reader');
});
