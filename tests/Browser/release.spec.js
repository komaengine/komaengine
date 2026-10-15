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

async function activateTheme(page, name) {
    await page.goto('/admin/themes');
    const card = page.locator('article').filter({ has: page.getByRole('heading', { name, exact: true }) });
    const activate = card.getByRole('button', { name: 'Activate', exact: true });

    if (await activate.count()) {
        await activate.click();
    }

    await expect(card.getByRole('button', { name: 'Active theme', exact: true })).toBeDisabled();
}

test('covers release administration, recovery, themes, and edge states', async ({ page }, testInfo) => {
    const runtimeFailures = observeRuntime(page);

    await login(page);

    await page.goto('/admin/settings');
    const siteTitle = `Koma QA ${testInfo.project.name}`;
    await page.locator('input[wire\\:model="values.site_title"]').fill(siteTitle);
    await page.getByRole('button', { name: 'Save general settings' }).click();
    await expect(page.getByRole('status')).toContainText('General settings saved.');
    await assertPageQuality(page, testInfo, runtimeFailures, 'settings-saved');

    await page.goto('/admin/plugins');
    const plugin = page.locator('[wire\\:key="plugin-hello-koma"]');
    await expect(plugin.getByText('Disabled after boot failure')).toBeVisible();
    await plugin.getByRole('button', { name: 'Clear notice' }).click();
    await expect(plugin.getByText('Disabled after boot failure')).toHaveCount(0);
    await plugin.getByRole('button', { name: 'Enable', exact: true }).click();
    await expect(plugin.getByRole('button', { name: 'Disable', exact: true })).toBeVisible();
    await assertPageQuality(page, testInfo, runtimeFailures, 'plugin-recovered');

    await page.goto('/admin/comments');
    const comment = page.locator('article').filter({ hasText: 'QA moderation queue comment.' });
    await expect(comment).toBeVisible();
    await comment.getByRole('button', { name: 'Hide', exact: true }).click();
    await expect(page.getByRole('status')).toContainText('Comment hide action completed.');
    await page.getByRole('button', { name: /Hidden/ }).click();
    await expect(comment).toBeVisible();
    await comment.getByRole('button', { name: 'Approve', exact: true }).click();
    await expect(page.getByRole('status')).toContainText('Comment approve action completed.');
    await assertPageQuality(page, testInfo, runtimeFailures, 'moderation-round-trip');

    await page.goto(`/admin/series/qa-matrix-${testInfo.project.name}`);
    const downloadPromise = page.waitForEvent('download');
    await page.getByRole('link', { name: 'Download v2 archive' }).click();
    const download = await downloadPromise;
    const archivePath = testInfo.outputPath('round-trip.koma.zip');
    await download.saveAs(archivePath);

    await page.goto('/admin/archives/import');
    await page.locator('input[name="archive"]').setInputFiles(archivePath);
    await page.locator('input[name="conflict_policy"][value="create_unique"]').check();
    await page.getByRole('button', { name: 'Upload, validate, and queue' }).click();
    await expect(page.getByText('Archive retained and queued for validation.')).toBeVisible();
    let currentImport = page.getByText('Current import', { exact: true }).locator('..').locator('..');
    await expect(currentImport.getByText('completed', { exact: true })).toBeVisible({ timeout: 90_000 });
    await expect(page.getByRole('link', { name: 'Open imported series' })).toBeVisible();
    await assertPageQuality(page, testInfo, runtimeFailures, 'archive-import-complete');

    const invalidArchivePath = testInfo.outputPath('invalid.koma.zip');
    await fs.writeFile(invalidArchivePath, 'not a zip archive');
    await page.goto('/admin/archives/import');
    await page.locator('input[name="archive"]').setInputFiles(invalidArchivePath);
    await page.getByRole('button', { name: 'Upload, validate, and queue' }).click();
    currentImport = page.getByText('Current import', { exact: true }).locator('..').locator('..');
    await expect(currentImport.getByText('failed', { exact: true })).toBeVisible({ timeout: 90_000 });
    await expect(page.getByText('Why it failed', { exact: true })).toBeVisible();
    await expect(page.getByText('The original upload remains in private storage for diagnosis or retry.')).toBeVisible();
    await assertPageQuality(page, testInfo, runtimeFailures, 'archive-import-failed');

    for (const theme of [
        { name: 'Kasai', slug: 'kasai' },
        { name: 'Sumi', slug: 'sumi' },
        { name: 'Aozora', slug: 'aozora' },
    ]) {
        await activateTheme(page, theme.name);

        await page.goto('/series/qa-empty-series');
        await expect(page.getByText(/No chapters (have been )?published yet\./)).toBeVisible();
        await assertPageQuality(page, testInfo, runtimeFailures, `${theme.slug}-empty-series`);

        await page.goto('/series/qa-missing-media');
        await expect(page.getByRole('heading', { name: /QA Missing Media and Long Layout State/ })).toBeVisible();
        await assertPageQuality(page, testInfo, runtimeFailures, `${theme.slug}-long-series`);
        await page.getByRole('link', { name: /Start (from|at) Ch\. 1/ }).click();
        await expect(page.getByText('Page 1 is unavailable.', { exact: true })).toBeVisible();
        await assertPageQuality(page, testInfo, runtimeFailures, `${theme.slug}-missing-media`);
    }

    await assertKeyboardReachable(page);
    await page.context().clearCookies();
    await page.evaluate(() => localStorage.clear());

    const privateFailureStart = runtimeFailures.length;
    const privateResponse = await page.goto('/series/qa-private-series');
    expect(privateResponse?.status()).toBe(404);
    const privateNavigationFailures = runtimeFailures.splice(privateFailureStart);
    expect(
        privateNavigationFailures.filter((failure) => !/^console:.*\b404\b/.test(failure)),
        'the expected private-series 404 emitted an unrelated runtime failure',
    ).toEqual([]);

    await page.goto('/series/chainsaw-man');
    await expect(page.getByRole('heading', { name: 'Age confirmation required' })).toBeVisible();
    await assertPageQuality(page, testInfo, runtimeFailures, 'mature-gate');
    await page.getByRole('button', { name: 'I am 18 or older' }).click();
    await expect(page.getByRole('heading', { name: 'Chainsaw Man' })).toBeVisible();
    await assertPageQuality(page, testInfo, runtimeFailures, 'mature-series-confirmed');
});
