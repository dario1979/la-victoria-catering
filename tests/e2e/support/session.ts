import { expect, type APIResponse, type Page } from '@playwright/test';

type LoginData = {
    id: number;
    organizations: Array<{ id: number; name: string }>;
    branches: Array<{ id: number; organization_id: number; name: string; active: boolean }>;
};

export type SessionContext = {
    userId: number;
    organizationId: number;
    branchId: number;
    otherBranchId: number;
    csrfToken: string;
};

export async function login(
    page: Page,
    email = 'admin@lavictoria.test',
    password = process.env.E2E_PASSWORD ?? '123456',
): Promise<SessionContext> {
    await page.goto('/');
    await expect(page.getByRole('heading', { name: 'Bienvenido a tu jornada.' })).toBeVisible();

    const loginResponsePromise = page.waitForResponse((response) => (
        response.url().endsWith('/api/v1/auth/login') && response.request().method() === 'POST'
    ));
    await page.getByLabel('Correo electrónico').fill(email);
    await page.getByLabel('Contraseña', { exact: true }).fill(password);
    await page.getByRole('button', { name: 'Ingresar', exact: true }).click();

    const loginResponse = await loginResponsePromise;
    expect(loginResponse.status()).toBe(200);
    const loginBody = await loginResponse.json() as { data: LoginData };
    await expect(page.getByRole('heading', { level: 1, name: 'Resumen' })).toBeVisible();

    const organizationId = loginBody.data.organizations[0]?.id;
    const branchId = loginBody.data.branches.find(
        (branch) => branch.organization_id === organizationId && branch.active,
    )?.id;
    const otherBranchId = loginBody.data.branches.find(
        (branch) => branch.organization_id === organizationId && branch.active && branch.id !== branchId,
    )?.id;
    expect(organizationId).toBeTruthy();
    expect(branchId).toBeTruthy();
    expect(otherBranchId).toBeTruthy();

    const csrfResponse = await page.request.get('/api/v1/auth/csrf');
    expect(csrfResponse.status()).toBe(200);
    const csrfBody = await csrfResponse.json() as { data: { token: string } };

    return {
        userId: loginBody.data.id,
        organizationId,
        branchId,
        otherBranchId,
        csrfToken: csrfBody.data.token,
    };
}

export class ApiSession {
    constructor(
        private readonly page: Page,
        private readonly session: SessionContext,
    ) {}

    async request(
        method: string,
        path: string,
        options: {
            data?: unknown;
            key?: string;
            headers?: Record<string, string>;
            branchId?: number;
            organizationId?: number;
        } = {},
    ): Promise<{ response: APIResponse; body: any }> {
        const headers: Record<string, string> = {
            Accept: 'application/json',
            'X-CSRF-TOKEN': this.session.csrfToken,
            'X-Organization-ID': String(options.organizationId ?? this.session.organizationId),
            'X-Branch-ID': String(options.branchId ?? this.session.branchId),
            ...options.headers,
        };
        if (options.key) headers['Idempotency-Key'] = options.key;

        const response = await this.page.request.fetch(`/api/v1${path}`, {
            method,
            data: options.data,
            headers,
            failOnStatusCode: false,
        });
        const contentType = response.headers()['content-type'] ?? '';
        const body = contentType.includes('json') ? await response.json() : await response.body();

        return { response, body };
    }

    get(path: string, options: Parameters<ApiSession['request']>[2] = {}) {
        return this.request('GET', path, options);
    }

    post(path: string, data: unknown, key?: string, options: Parameters<ApiSession['request']>[2] = {}) {
        return this.request('POST', path, { ...options, data, key });
    }

    patch(path: string, data: unknown, options: Parameters<ApiSession['request']>[2] = {}) {
        return this.request('PATCH', path, { ...options, data });
    }

    put(path: string, data: unknown, options: Parameters<ApiSession['request']>[2] = {}) {
        return this.request('PUT', path, { ...options, data });
    }
}

export function expectStatus(result: { response: APIResponse }, status: number) {
    expect(result.response.status(), `Unexpected response: ${result.response.url()}`).toBe(status);
}
