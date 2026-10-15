import { defineConfig } from '@playwright/test';

const baseURL = process.env.KOMA_BROWSER_BASE_URL;

if (!baseURL) {
    throw new Error('KOMA_BROWSER_BASE_URL is required. Use tests/Release/browser-qa-smoke.sh.');
}

const desktop = { width: 1440, height: 1000 };
const phone = { width: 390, height: 844 };

function project(name, browserName, viewport) {
    return {
        name,
        use: {
            browserName,
            viewport,
            hasTouch: viewport === phone,
        },
    };
}

export default defineConfig({
    testDir: './tests/Browser',
    outputDir: process.env.KOMA_BROWSER_OUTPUT_DIR || 'storage/framework/testing/browser-qa/results',
    timeout: 180_000,
    expect: { timeout: 15_000 },
    fullyParallel: false,
    workers: 1,
    retries: 0,
    forbidOnly: true,
    reporter: [['line']],
    use: {
        baseURL,
        actionTimeout: 15_000,
        navigationTimeout: 30_000,
        screenshot: 'only-on-failure',
        trace: 'retain-on-failure',
        video: 'retain-on-failure',
    },
    projects: [
        project('chromium-desktop', 'chromium', desktop),
        project('firefox-desktop', 'firefox', desktop),
        project('webkit-desktop', 'webkit', desktop),
        project('chromium-phone', 'chromium', phone),
        project('firefox-phone', 'firefox', phone),
        project('webkit-phone', 'webkit', phone),
    ],
});
