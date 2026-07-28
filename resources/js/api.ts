import type { ApiEnvelope, ApiProblem, DataTableQuery, DataTableResponse } from './types';

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
    options: { idempotencyKey?: string; tenant?: boolean; timeoutMs?: number } = {},
): Promise<T> {
    const method = (init.method ?? 'GET').toUpperCase();
    const readOnly = method === 'GET' || method === 'HEAD';
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
    const controller = new AbortController();
    const timeout = setTimeout(() => controller.abort(), options.timeoutMs ?? (readOnly ? 15_000 : 30_000));
    const abortFromCaller = () => controller.abort();
    if (init.signal?.aborted) controller.abort();
    else init.signal?.addEventListener('abort', abortFromCaller, { once: true });
    try {
        response = await fetch(`/api/v1${path}`, {
            ...init, headers, credentials: 'same-origin', cache: 'no-store', signal: controller.signal,
        });
    } catch {
        throw new HttpError({
            message: readOnly
                ? 'No pudimos consultar el servidor. Revisá la conexión y reintentá.'
                : 'Resultado desconocido: el servidor pudo haber aplicado la operación. Consultá su estado y reintentá con la misma clave.',
            status: 0,
            errors: {},
        });
    } finally {
        clearTimeout(timeout);
        init.signal?.removeEventListener('abort', abortFromCaller);
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
    page<T>(path: string, query: DataTableQuery) {
        const params = new URLSearchParams({
            page: String(query.page),
            per_page: String(query.per_page),
            search: query.search,
            sort: query.sort,
            direction: query.direction,
        });
        Object.entries(query.filters).forEach(([key, value]) => {
            if (value !== '') params.set(`filters[${key}]`, value);
        });

        return request<DataTableResponse<T>>(`${path}?${params.toString()}`);
    },
    async exportTable(path: string, query: DataTableQuery) {
        const params = new URLSearchParams({
            search: query.search,
            sort: query.sort,
            direction: query.direction,
            export: 'xlsx',
        });
        Object.entries(query.filters).forEach(([key, value]) => {
            if (value !== '') params.set(`filters[${key}]`, value);
        });
        const headers = new Headers({ Accept: 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet' });
        if (organizationId && branchId) {
            headers.set('X-Organization-ID', String(organizationId));
            headers.set('X-Branch-ID', String(branchId));
        }
        const response = await fetch(`/api/v1${path}?${params.toString()}`, {
            headers, credentials: 'same-origin', cache: 'no-store',
        });
        if (!response.ok) throw new HttpError({ message: 'No se pudo generar el Excel.', status: response.status, errors: {} });
        const blob = await response.blob();
        const disposition = response.headers.get('Content-Disposition') ?? '';
        const filename = disposition.match(/filename="?([^";]+)"?/)?.[1] ?? 'exportacion.xlsx';
        const url = URL.createObjectURL(blob);
        const anchor = document.createElement('a');
        anchor.href = url;
        anchor.download = filename;
        anchor.click();
        setTimeout(() => URL.revokeObjectURL(url), 1000);
    },
    post<T>(path: string, body: unknown, idempotencyKey?: string) {
        return request<ApiEnvelope<T>>(
            path, { method: 'POST', body: JSON.stringify(body) }, { idempotencyKey },
        ).then((response) => response.data);
    },
    patch<T>(path: string, body: unknown) {
        return request<ApiEnvelope<T>>(
            path, { method: 'PATCH', body: JSON.stringify(body) },
        ).then((response) => response.data);
    },
    getRaw<T>(path: string) {
        return request<T>(path);
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
