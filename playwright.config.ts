import { defineConfig } from '@playwright/test';

const baseURL = process.env.E2E_BASE_URL ?? 'http://127.0.0.1:18080';

export default defineConfig({
    testDir: './tests/e2e',
    fullyParallel: false,
    workers: 1,
    retries: 0,
    timeout: 120_000,
    expect: { timeout: 10_000 },
    outputDir: 'test-results',
    reporter: process.env.CI
        ? [
            ['line'],
            ['junit', { outputFile: 'test-results/playwright-junit.xml' }],
            ['html', { outputFolder: 'playwright-report', open: 'never' }],
        ]
        : [['list'], ['html', { outputFolder: 'playwright-report', open: 'never' }]],
    use: {
        baseURL,
        browserName: 'chromium',
        locale: 'es-AR',
        timezoneId: 'America/Argentina/Buenos_Aires',
        acceptDownloads: true,
        screenshot: 'only-on-failure',
        trace: 'retain-on-failure',
        video: 'retain-on-failure',
    },
    projects: [
        {
            name: 'chromium-1440x900',
            use: { viewport: { width: 1440, height: 900 } },
        },
        {
            name: 'chromium-1024x768',
            grep: /@viewport/,
            use: { viewport: { width: 1024, height: 768 } },
        },
        {
            name: 'chromium-768x1024',
            grep: /@viewport/,
            use: { viewport: { width: 768, height: 1024 } },
        },
        {
            name: 'chromium-390x844',
            grep: /@viewport/,
            use: { viewport: { width: 390, height: 844 } },
        },
        {
            name: 'chromium-360x800',
            grep: /@viewport/,
            use: { viewport: { width: 360, height: 800 } },
        },
    ],
});
