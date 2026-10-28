import fs from 'node:fs/promises';
import { expect, test } from '@playwright/test';
import { assertKeyboardReachable, assertPageQuality, observeRuntime } from './helpers/quality.js';

// Display names come from each theme's themes/*/theme.json.
const builtInThemes = [
    { name: 'Aozora', slug: 'aozora' },
    { name: 'Kasai', slug: 'kasai' },
    { name: 'Sumi', slug: 'sumi' },
    { name: 'Tankōbon', slug: 'tankobon' },
];

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

test('admin sub-menus pop out in the sidebar and the narrow drawer', async ({ page }, testInfo) => {
    await login(page);
    // Settings is the current section, so Series & Chapters is collapsed.
    await page.goto('/admin/settings');
    const drawer = page.locator('#admin-navigation');
    const section = drawer.getByRole('link', { name: 'Series & Chapters' });

    if (!testInfo.project.name.endsWith('-phone')) {
        // Wide sidebar: hovering pops the sub-menu out to the right.
        await section.hover();
        await expect(page.locator('[data-nav-submenu="series-chapters"]').getByRole('link', { name: 'All Series' })).toBeVisible();

        // Narrow window with a mouse: the drawer pops it out beside itself,
        // outside the drawer's scrolling area.
        await page.setViewportSize({ width: 900, height: 900 });
        await page.reload();
        await page.getByRole('button', { name: 'Open navigation' }).click();
        await section.hover();
        const flyout = page.locator('[data-nav-flyout="series-chapters"]');
        await expect(flyout.getByRole('link', { name: 'All Series' })).toBeVisible();
        const drawerBox = await drawer.boundingBox();
        const flyoutBox = await flyout.boundingBox();
        expect(flyoutBox.x).toBeGreaterThanOrEqual(drawerBox.x + drawerBox.width);

        // Moving onto the pop-out keeps it open long enough to choose an item.
        await flyout.getByRole('link', { name: 'All Series' }).hover();
        await flyout.getByRole('link', { name: 'All Series' }).click();
        await expect(page).toHaveURL(/\/admin\/series\/?$/);

        // Keyboard and touch users expand the section in place instead.
        await page.goto('/admin/settings');
        await page.getByRole('button', { name: 'Open navigation' }).click();
        await page.getByRole('button', { name: 'Show Series & Chapters submenu' }).click();
        await expect(page.locator('[data-nav-inline="series-chapters"]').getByRole('link', { name: 'All Series' })).toBeVisible();

        return;
    }

    // Touch screen: the first tap on the section expands it in place, and a
    // sub-item then navigates.
    await page.getByRole('button', { name: 'Open navigation' }).click();
    const touchOnly = await page.evaluate(() => !matchMedia('(hover: hover) and (pointer: fine)').matches);
    if (touchOnly) {
        await section.tap();
        await expect(page).toHaveURL(/\/admin\/settings/);
    } else {
        await page.getByRole('button', { name: 'Show Series & Chapters submenu' }).tap();
    }
    const inline = page.locator('[data-nav-inline="series-chapters"]');
    await expect(inline.getByRole('link', { name: 'All Series' })).toBeVisible();
    await inline.getByRole('link', { name: 'All Series' }).tap();
    await expect(page).toHaveURL(/\/admin\/series\/?$/);
});

test('chapters can be unpublished and published in bulk from the series editor', async ({ page }, testInfo) => {
    await login(page);
    await page.goto(`/admin/series/qa-matrix-${testInfo.project.name}`);
    const bulk = page.getByTestId('chapter-bulk-actions');
    const chapterRow = page.locator('[wire\\:key^="ch-"]').first();
    const selectAll = page.getByRole('checkbox', { name: 'Select all chapters' });

    await selectAll.check();
    await expect(bulk.getByText('1 chapter selected')).toBeVisible();
    await bulk.getByRole('button', { name: 'Unpublish', exact: true }).click();
    await expect(page.getByTestId('chapter-notice')).toHaveText('Returned 1 chapter to draft.');
    await expect(chapterRow.getByText('Draft', { exact: true })).toBeVisible();
    await expect(selectAll).not.toBeChecked();

    await page.getByRole('checkbox', { name: 'Select chapter 1' }).check();
    await bulk.getByRole('button', { name: 'Publish', exact: true }).click();
    await expect(page.getByTestId('chapter-notice')).toHaveText('Published 1 chapter.');
    await expect(chapterRow.getByText('Published', { exact: true })).toBeVisible();
});

test('series can be made public and deleted in bulk from the series list', async ({ page }, testInfo) => {
    const title = `QA Bulk ${testInfo.project.name}`;

    await login(page);
    await page.goto('/admin/series/create');
    await page.locator('input[wire\\:model="title"]').fill(title);
    await page.getByRole('button', { name: 'Private', exact: true }).click();
    await page.getByRole('button', { name: 'Create series' }).click();
    await expect(page).toHaveURL(/\/admin\/series\/qa-bulk-/);

    await page.goto(`/admin/series?q=${encodeURIComponent(title)}`);
    const bulk = page.getByTestId('series-bulk-actions');
    const selectAll = page.getByRole('checkbox', { name: 'Select all series on this page' });

    await selectAll.check();
    await expect(bulk.getByText('1 series selected')).toBeVisible();
    await bulk.getByRole('button', { name: 'Make public', exact: true }).click();
    await expect(page.getByTestId('series-notice')).toHaveText('Made 1 series public. 1 series stays hidden from readers until it has a published chapter.');
    await expect(selectAll).not.toBeChecked();
    await expect(page.getByRole('checkbox', { name: `Select ${title}` })).not.toBeChecked();

    await page.getByRole('checkbox', { name: `Select ${title}` }).check();
    page.once('dialog', (dialog) => dialog.accept());
    await bulk.getByRole('button', { name: 'Delete', exact: true }).click();
    await expect(page.getByTestId('series-notice')).toHaveText('Deleted 1 series.');
    await expect(page.getByRole('checkbox', { name: `Select ${title}` })).toHaveCount(0);
});

test('a custom logo and icon show in every theme, the admin bar, and the sign-in page', async ({ page }, testInfo) => {
    const runtimeFailures = observeRuntime(page);
    const logoPath = testInfo.outputPath('qa-logo.png');
    const iconPath = testInfo.outputPath('qa-icon.png');

    // Drawn on a canvas so the logo has a transparent background in every browser.
    const drawPng = (width, height, draw) => page.evaluate(([w, h, source]) => {
        const canvas = document.createElement('canvas');
        canvas.width = w;
        canvas.height = h;
        new Function('context', source)(canvas.getContext('2d'));

        return canvas.toDataURL('image/png').split(',')[1];
    }, [width, height, draw]);

    await fs.writeFile(logoPath, Buffer.from(await drawPng(320, 80, "context.fillStyle = '#c2410c'; context.font = '800 44px sans-serif'; context.fillText('QA LOGO', 40, 56);"), 'base64'));
    await fs.writeFile(iconPath, Buffer.from(await drawPng(128, 128, "context.fillStyle = '#1d4ed8'; context.fillRect(16, 16, 96, 96);"), 'base64'));

    await login(page);
    await page.goto('/admin/settings/branding');
    await page.locator('#branding-logoUpload').setInputFiles(logoPath);
    await expect(page.getByTestId('branding-notice')).toHaveText('Logo saved.');
    await page.locator('#branding-iconUpload').setInputFiles(iconPath);
    await expect(page.getByTestId('branding-notice')).toHaveText('Site icon saved.');
    await expect(page.getByTestId('logoUpload-preview').locator('img')).toBeVisible();
    await assertPageQuality(page, testInfo, runtimeFailures, 'branding-settings');

    await page.goto('/admin');
    await expect(page.getByTestId('admin-site-logo').locator('img')).toBeVisible();

    for (const theme of builtInThemes) {
        await activateTheme(page, theme.name);
        await page.goto('/series/qa-empty-series');

        const logo = page.locator('img.brand-logo:visible').first();
        await expect(logo).toBeVisible();
        const size = await logo.evaluate((image) => ({
            natural: image.naturalWidth / image.naturalHeight,
            shown: image.clientWidth / image.clientHeight,
            height: image.clientHeight,
        }));
        expect(Math.abs(size.natural - size.shown), `${theme.slug} logo is stretched`).toBeLessThan(0.1);
        expect(size.height).toBeLessThanOrEqual(40);

        const iconHref = await page.locator('link[rel="icon"]').getAttribute('href');
        expect(iconHref).toContain('/branding/icon-');
        await assertPageQuality(page, testInfo, runtimeFailures, `${theme.slug}-logo`);
    }

    await activateTheme(page, 'Aozora');
    await page.context().clearCookies();
    await page.goto('/login');
    await expect(page.locator('img[src*="/branding/logo-"]')).toBeVisible();
    await assertPageQuality(page, testInfo, runtimeFailures, 'sign-in-logo');

    await login(page);
    await page.goto('/admin/settings/branding');
    page.once('dialog', (dialog) => dialog.accept());
    await page.getByTestId('logoUpload-preview').locator('xpath=..').getByRole('button', { name: 'Remove' }).click();
    await expect(page.getByTestId('branding-notice')).toHaveText('Logo removed. The theme shows its own mark again.');
    page.once('dialog', (dialog) => dialog.accept());
    await page.getByTestId('iconUpload-preview').locator('xpath=..').getByRole('button', { name: 'Remove' }).click();
    await expect(page.getByTestId('branding-notice')).toHaveText('Site icon removed. Browsers use the default icon again.');
});

test('the public home page passes the quality checks in every built-in theme', async ({ page }, testInfo) => {
    const runtimeFailures = observeRuntime(page);

    for (const theme of builtInThemes) {
        await login(page);
        await activateTheme(page, theme.name);
        // Readers see the home page signed out.
        await page.context().clearCookies();
        await page.goto('/');

        // Tankōbon shows only its featured series; the catalog themes list the
        // long QA title, which exposes update cards that overflow the page.
        if (theme.slug !== 'tankobon') {
            await expect(page.getByText(/QA Missing Media and Long Layout State/).first()).toBeVisible();
        }
        await assertPageQuality(page, testInfo, runtimeFailures, `${theme.slug}-home`);
    }
});

test('the system health page reports the Docker stack as healthy', async ({ page }, testInfo) => {
    const runtimeFailures = observeRuntime(page);

    await login(page);
    await page.goto('/admin/health');

    const summary = page.getByTestId('health-summary');
    await expect(summary.getByRole('heading', { name: 'Everything looks healthy' })).toBeVisible();
    await expect(page.getByTestId('health-check-installed')).toContainText('OK');
    await expect(page.getByTestId('health-scheduler')).toContainText('OK');
    await expect(page.getByTestId('health-worker-mode')).toContainText('Docker Compose runs the scheduler and the queue worker');
    await expect(page.getByTestId('health-cron')).toHaveCount(0);

    await summary.getByRole('button', { name: 'Check again' }).click();
    await expect(summary.getByRole('heading', { name: 'Everything looks healthy' })).toBeVisible();
    await assertPageQuality(page, testInfo, runtimeFailures, 'system-health');
});

test('the Updates page explains the optional update check and starts with it off', async ({ page }, testInfo) => {
    const runtimeFailures = observeRuntime(page);

    await login(page);
    await page.goto('/admin/updates');

    const checks = page.getByTestId('update-checks');
    await expect(checks).toContainText('sends nothing about your site');
    // Not clicked: turning checks on would fetch the real feed from komaengine.com.
    await expect(checks.getByRole('button', { name: 'Enable update checks' })).toBeVisible();
    await expect(page.getByTestId('update-notice')).toHaveCount(0);
    await assertPageQuality(page, testInfo, runtimeFailures, 'updates');
});
