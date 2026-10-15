import { expect, test } from '@playwright/test';
import { assertKeyboardReachable, assertPageQuality, fillLivewire, observeRuntime } from './helpers/quality.js';

test('installs Koma through the browser', async ({ page }, testInfo) => {
    const runtimeFailures = observeRuntime(page);
    const databasePassword = process.env.KOMA_BROWSER_DB_PASSWORD;
    const setupToken = process.env.KOMA_BROWSER_SETUP_TOKEN;

    expect(databasePassword).toBeTruthy();
    expect(setupToken).toMatch(/^[a-f0-9]{64}$/);

    await page.goto('/install');
    await expect(page.getByRole('heading', { name: 'Authorize this installer' })).toBeVisible();
    await fillLivewire(page, page.getByLabel('Setup token'), setupToken);
    await page.getByRole('button', { name: 'Unlock setup' }).click();
    await expect(page.getByRole('heading', { name: 'Choose your language' })).toBeVisible();
    await assertKeyboardReachable(page);
    await page.getByRole('button', { name: 'Continue' }).click();

    await expect(page.getByRole('heading', { name: 'Welcome to the five-minute install' })).toBeVisible();
    await expect(page.getByText('NO', { exact: true })).toHaveCount(0);
    await page.getByRole('button', { name: 'Continue' }).click();

    await expect(page.getByRole('heading', { name: 'Database connection' })).toBeVisible();
    await expect(page.getByRole('option', { name: 'SQLite (not used with Docker Compose)' })).toBeDisabled();
    await expect(page.getByText('KOMA_DB_PASSWORD', { exact: true })).toBeVisible();
    await fillLivewire(page, page.locator('input[type="password"]'), databasePassword);
    await page.getByRole('button', { name: 'Test connection' }).click();
    await expect(page.getByText('Connection successful.')).toBeVisible();
    await page.getByRole('button', { name: 'Continue' }).click();

    await expect(page.getByRole('heading', { name: 'Where should pages live?' })).toBeVisible();
    await page.getByRole('button', { name: 'Continue' }).click();

    await expect(page.getByRole('heading', { name: 'Set up your site' })).toBeVisible();
    await fillLivewire(page, page.locator('input[wire\\:model\\.live="siteTitle"]'), 'Koma Browser QA');
    await fillLivewire(page, page.locator('input[wire\\:model\\.live="adminName"]'), 'QA Administrator');
    await fillLivewire(page, page.locator('input[wire\\:model\\.live="adminEmail"]'), 'qa-admin@koma.test');
    await fillLivewire(page, page.locator('input[wire\\:model\\.live="adminPassword"]'), 'QA-Release-Check-2026!');
    await fillLivewire(page, page.locator('input[wire\\:model\\.live="adminPasswordConfirmation"]'), 'QA-Release-Check-2026!');
    await page.waitForLoadState('networkidle');
    await page.getByRole('button', { name: 'Install Koma Engine' }).click();

    await expect(page.getByRole('heading', { name: 'Koma Engine is installed' })).toBeVisible({ timeout: 60_000 });
    await assertPageQuality(page, testInfo, runtimeFailures, 'installer-complete');
    await expect(page.getByRole('link', { name: 'Open dashboard' })).toBeVisible();
});
