import http from 'k6/http';
import { check, fail, group, sleep } from 'k6';
import { Trend } from 'k6/metrics';

const baseUrl = (__ENV.BASE_URL || 'http://127.0.0.1:18080').replace(/\/$/, '');
const readDuration = new Trend('operational_read_duration', true);
const writeDuration = new Trend('operational_write_duration', true);
const isolated = __ENV.LOAD_SCOPE === 'isolated';

export const options = {
    summaryTrendStats: ['avg', 'min', 'med', 'max', 'p(90)', 'p(95)', 'p(99)'],
    scenarios: {
        nominal: {
            executor: 'ramping-vus',
            stages: [
                { duration: __ENV.RAMP || '10s', target: Number(__ENV.VUS || 5) },
                { duration: __ENV.DURATION || '30s', target: Number(__ENV.VUS || 5) },
                { duration: '5s', target: 0 },
            ],
            gracefulRampDown: '5s',
        },
    },
    thresholds: {
        checks: ['rate==1'],
        http_req_failed: ['rate==0'],
        operational_read_duration: ['p(95)<500', 'p(99)<1000'],
        operational_write_duration: ['p(95)<1500', 'p(99)<2500'],
    },
};

function json(value, label) {
    try {
        return JSON.parse(value);
    } catch {
        fail(`${label} no contiene JSON válido`);
    }
}

function measuredGet(path, headers, label) {
    const response = http.get(`${baseUrl}/api/v1${path}`, {
        headers,
        tags: { operation: 'read', endpoint: label },
    });
    readDuration.add(response.timings.duration, { endpoint: label });
    check(response, { [`${label}: HTTP 200`]: (result) => result.status === 200 });
    return response;
}

function measuredPost(path, payload, headers, label) {
    const response = http.post(`${baseUrl}/api/v1${path}`, JSON.stringify(payload), {
        headers: {
            ...headers,
            'Content-Type': 'application/json',
            'Idempotency-Key': `load:${label}:${__VU}:${__ITER}`,
        },
        tags: { operation: 'write', endpoint: label },
    });
    writeDuration.add(response.timings.duration, { endpoint: label });
    check(response, { [`${label}: HTTP exitoso`]: (result) => result.status >= 200 && result.status < 300 });
}

export function setup() {
    if (!__ENV.EMAIL || !__ENV.PASSWORD) fail('EMAIL y PASSWORD son obligatorios');
    const csrf = http.get(`${baseUrl}/api/v1/auth/csrf`, { tags: { operation: 'read', endpoint: 'login-csrf' } });
    check(csrf, { 'csrf: HTTP 200': (response) => response.status === 200 });
    const token = csrf.json('data.token');
    const login = http.post(`${baseUrl}/api/v1/auth/login`, JSON.stringify({
        email: __ENV.EMAIL,
        password: __ENV.PASSWORD,
    }), {
        headers: { Accept: 'application/json', 'Content-Type': 'application/json', 'X-CSRF-TOKEN': token },
        tags: { operation: 'write', endpoint: 'login' },
    });
    check(login, { 'login: HTTP 200': (response) => response.status === 200 });
    const session = login.json('data');
    const organization = session.organizations[0];
    if (!organization) fail('La sesión demo no tiene una organización activa');
    const branch = session.branches.find((candidate) => (
        candidate.organization_id === organization.id && candidate.active
    ));
    if (!branch) fail('La sesión demo no tiene una sucursal activa');
    const cookies = http.cookieJar().cookiesForURL(baseUrl);

    return {
        token,
        cookies,
        organizationId: String(organization.id),
        branchId: String(branch.id),
    };
}

export default function (session) {
    const jar = http.cookieJar();
    Object.entries(session.cookies).forEach(([name, values]) => jar.set(baseUrl, name, values[0]));
    const headers = {
        Accept: 'application/json',
        'X-CSRF-TOKEN': session.token,
        'X-Organization-ID': session.organizationId,
        'X-Branch-ID': session.branchId,
    };

    group('lecturas nominales', () => {
        measuredGet('/dashboard/summary', headers, 'dashboard');
        measuredGet('/orders?per_page=25&sort=id&direction=desc', headers, 'orders-table');
        measuredGet('/products?per_page=25&search=Piloto', headers, 'product-search');
        measuredGet('/notifications?per_page=25', headers, 'notifications');
        measuredGet('/orders?per_page=25&export=xlsx', headers, 'small-export');
    });

    if (isolated) {
        const mutations = [
            ['ORDER_PATH', 'ORDER_PAYLOAD', 'order-create'],
            ['CONFIRM_PATH', 'CONFIRM_PAYLOAD', 'order-confirm'],
            ['RECEIPT_PATH', 'RECEIPT_PAYLOAD', 'purchase-receipt'],
            ['PRODUCTION_PATH', 'PRODUCTION_PAYLOAD', 'production'],
            ['PAYMENT_PATH', 'PAYMENT_PAYLOAD', 'payment'],
        ];
        for (const [pathKey, payloadKey, label] of mutations) {
            if (__ENV[pathKey] && __ENV[payloadKey]) {
                measuredPost(__ENV[pathKey], json(__ENV[payloadKey], payloadKey), headers, label);
            }
        }
    }
    sleep(Number(__ENV.THINK_TIME || 1));
}

export function handleSummary(data) {
    return {
        stdout: `Perfil operativo finalizado. checks=${data.metrics.checks?.values?.rate ?? 0}\n`,
        'performance-summary.json': JSON.stringify(data, null, 2),
    };
}
