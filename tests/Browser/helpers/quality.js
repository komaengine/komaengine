import AxeBuilder from '@axe-core/playwright';
import { expect } from '@playwright/test';

const axeTags = ['wcag2a', 'wcag2aa', 'wcag21a', 'wcag21aa'];

export function observeRuntime(page) {
    const failures = [];

    page.on('console', (message) => {
        if (message.type() === 'error') {
            failures.push(`console: ${message.text()}`);
        }
    });
    page.on('pageerror', (error) => {
        const detail = error?.stack || error?.message || JSON.stringify(error) || String(error);
        failures.push(`pageerror: ${detail}`);
    });
    page.on('requestfailed', (request) => {
        const reason = request.failure()?.errorText || 'unknown error';
        const expectedWebKitDownloadInterruption = reason.includes('Frame load interrupted')
            && request.method() === 'GET'
            && /\/export\?schema=2(?:&|$)/.test(request.url());

        if (!reason.includes('ERR_ABORTED')
            && !reason.includes('NS_BINDING_ABORTED')
            && reason !== 'Load request cancelled'
            && !expectedWebKitDownloadInterruption) {
            failures.push(`request: ${request.method()} ${request.url()} (${reason})`);
        }
    });
    page.on('response', (response) => {
        if (response.status() >= 500) {
            failures.push(`response: ${response.status()} ${response.url()}`);
        }
    });

    return failures;
}

export async function fillLivewire(page, locator, value) {
    await locator.fill(value);
    await page.waitForTimeout(500);
    await page.waitForLoadState('networkidle');
}

export async function assertPageQuality(page, testInfo, runtimeFailures, label) {
    await page.waitForLoadState('domcontentloaded');
    const screenshotPath = testInfo.outputPath(`${label}.png`);

    await page.screenshot({
        path: screenshotPath,
        fullPage: true,
        animations: 'disabled',
    });

    await testInfo.attach(`${label}-screenshot`, {
        path: screenshotPath,
        contentType: 'image/png',
    });

    const layout = await page.evaluate(() => ({
        clientWidth: document.documentElement.clientWidth,
        scrollWidth: document.documentElement.scrollWidth,
        brokenImages: [...document.images]
            .filter((image) => image.complete && image.naturalWidth === 0)
            .map((image) => image.currentSrc || image.src),
    }));

    await testInfo.attach(`${label}-layout`, {
        body: JSON.stringify(layout, null, 2),
        contentType: 'application/json',
    });

    expect(layout.scrollWidth, `${label} has horizontal overflow`).toBeLessThanOrEqual(layout.clientWidth + 1);
    expect(layout.brokenImages, `${label} has broken loaded images`).toEqual([]);

    const accessibility = await new AxeBuilder({ page }).withTags(axeTags).analyze();

    await testInfo.attach(`${label}-accessibility`, {
        body: JSON.stringify(accessibility, null, 2),
        contentType: 'application/json',
    });

    expect(accessibility.violations, `${label} has automated WCAG A/AA violations`).toEqual([]);
    expect(runtimeFailures, `${label} emitted browser/runtime failures`).toEqual([]);
}

export async function assertKeyboardReachable(page) {
    await page.locator('body').click({ position: { x: 1, y: 1 } });
    await page.keyboard.press('Tab');

    await expect.poll(async () => page.evaluate(() => {
        const element = document.activeElement;

        return element && element !== document.body && element !== document.documentElement;
    })).toBe(true);
}
