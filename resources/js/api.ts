import type { ApiEnvelope, ApiProblem } from './types';

let csrfToken = '';
let organizationId: number | null = null;
let branchId: number | null = null;

export function configureApi(context: { csrfToken?: string; organizationId?: number | null; branchId?: number | null }) {
    if (context.csrfToken !== undefined) csrfToken = context.csrfToken;
    if (context.organizationId !== undefined) organizationId = context.organizationId;
    if (context.branchId !== undefined) branchId = context.branchId;
}

export class HttpError extends Error implements ApiProblem {
    status: number;
    errors: Record<string, string[]>;

    constructor(problem: ApiProblem) {
        super(problem.message);
        this.name = 'HttpError';
        this.status = problem.status;
        this.errors = problem.errors;
    }
}

async function request<T>(
    path: string,
    init: RequestInit = {},
    options: { idempotencyKey?: string; tenant?: boolean } = {},
): Promise<T> {
    const method = init.method ?? 'GET';
    if (!navigator.onLine && method !== 'GET') {
        throw new HttpError({ message: 'Sin conexión. La operación no se envió.', status: 0, errors: {} });
    }
    const headers = new Headers(init.headers);
    headers.set('Accept', 'application/json');
    if (init.body) headers.set('Content-Type', 'application/json');
    if (csrfToken && method !== 'GET') headers.set('X-CSRF-TOKEN', csrfToken);
    if (options.idempotencyKey) headers.set('Idempotency-Key', options.idempotencyKey);
    if (options.tenant !== false && organizationId && branchId) {
        headers.set('X-Organization-ID', String(organizationId));
        headers.set('X-Branch-ID', String(branchId));
    }
    let response: Response;
    try {
        response = await fetch(`/api/v1${path}`, {
            ...init, headers, credentials: 'same-origin', cache: 'no-store',
        });
    } catch {
        throw new HttpError({
            message: 'Resultado desconocido: el servidor pudo haber aplicado la operación. Consultá su estado y reintentá con la misma clave.',
            status: 0,
            errors: {},
        });
    }
    const payload = await response.json().catch(() => ({})) as {
        message?: string; errors?: Record<string, string[]>;
    };
    if (!response.ok) {
        throw new HttpError({
            message: payload.message ?? `La solicitud falló (${response.status}).`,
            status: response.status, errors: payload.errors ?? {},
        });
    }

    return payload as T;
}

export const api = {
    get<T>(path: string) {
        return request<ApiEnvelope<T>>(path).then((response) => response.data);
    },
    post<T>(path: string, body: unknown, idempotencyKey?: string) {
        return request<ApiEnvelope<T>>(
            path, { method: 'POST', body: JSON.stringify(body) }, { idempotencyKey },
        ).then((response) => response.data);
    },
    async csrf() {
        const response = await request<ApiEnvelope<{ token: string }>>('/auth/csrf', {}, { tenant: false });
        configureApi({ csrfToken: response.data.token });
    },
    login(email: string, password: string) {
        return request<ApiEnvelope<import('./types').SessionUser>>(
            '/auth/login', { method: 'POST', body: JSON.stringify({ email, password }) }, { tenant: false },
        ).then((response) => response.data);
    },
    me() {
        return request<ApiEnvelope<import('./types').SessionUser>>('/auth/me', {}, { tenant: false })
            .then((response) => response.data);
    },
    logout() {
        return request<unknown>('/auth/logout', { method: 'POST' }, { tenant: false });
    },
};

export function idempotencyKey(scope: string): string {
    return `${scope}:${crypto.randomUUID()}`;
}

export function errorMessages(error: unknown): string[] {
    if (!(error instanceof HttpError)) return ['Ocurrió un error inesperado.'];
    const fields = Object.values(error.errors).flat();

    return fields.length ? fields : [error.message];
}
