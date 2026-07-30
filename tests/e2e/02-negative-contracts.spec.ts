import { expect, test } from '@playwright/test';
import { ApiSession, expectStatus, login } from './support/session';

function dateOffset(days: number): string {
    const date = new Date();
    date.setUTCDate(date.getUTCDate() + days);

    return date.toISOString().slice(0, 10);
}

test.describe.serial('negative operational contracts', () => {
    test('rejects unauthorized roles and cross-tenant access', async ({ browser, page }) => {
        const session = await login(page);
        const api = new ApiSession(page, session);

        const lots = await api.get('/lots?per_page=10');
        expectStatus(lots, 200);
        const ownLotId = lots.body.data[0].id;

        const foreignOrganization = await api.get('/products?per_page=10', {
            organizationId: 999_999,
        });
        expectStatus(foreignOrganization, 403);

        const foreignBranch = await api.get(`/lots/${ownLotId}`, {
            branchId: session.otherBranchId,
        });
        expectStatus(foreignBranch, 404);

        const productionContext = await browser.newContext({
            baseURL: process.env.E2E_BASE_URL ?? 'http://127.0.0.1:18080',
        });
        const productionPage = await productionContext.newPage();
        const productionSession = await login(productionPage, 'produccion@lavictoria.test');
        const productionApi = new ApiSession(productionPage, productionSession);
        const forbidden = await productionApi.post('/suppliers', {
            trade_name: 'No autorizado',
            lead_time_days: 1,
        });
        expectStatus(forbidden, 403);
        await productionContext.close();
    });

    test('enforces idempotency, double-click and simulated timeout handling', async ({ page }) => {
        const session = await login(page);
        const api = new ApiSession(page, session);
        const products = await api.get('/products?search=Docena%20de%20empanadas&per_page=10');
        expectStatus(products, 200);
        const productId = products.body.data[0].id;
        const payload = {
            customer_name: 'Idempotencia E2E',
            items: [{ product_id: productId, quantity: '1.000', unit_price: '10.00' }],
        };

        const first = await api.post('/orders', payload, 'e2e-idempotency-order');
        expectStatus(first, 201);
        const replay = await api.post('/orders', payload, 'e2e-idempotency-order');
        expectStatus(replay, 201);
        expect(replay.response.headers()['idempotency-replayed']).toBe('true');
        expect(replay.body.data.id).toBe(first.body.data.id);

        const conflict = await api.post('/orders', {
            ...payload,
            customer_name: 'Payload diferente E2E',
        }, 'e2e-idempotency-order');
        expectStatus(conflict, 422);
        expect(conflict.body.errors['Idempotency-Key']).toBeTruthy();

        await page.getByRole('button', { name: 'Clientes', exact: true }).click();
        await page.getByRole('button', { name: 'Nuevo cliente' }).click();
        await page.getByLabel('Nombre o razón social').fill('Doble clic E2E');
        let customerWrites = 0;
        const countCustomerWrite = (request: { method(): string; url(): string }) => {
            if (request.method() === 'POST' && request.url().endsWith('/api/v1/customers')) customerWrites++;
        };
        page.on('request', countCustomerWrite);
        await page.getByRole('button', { name: 'Continuar' }).dblclick();
        await expect(page.getByRole('status')).toContainText('Cliente creado');
        expect(customerWrites).toBe(1);
        page.off('request', countCustomerWrite);

        await page.route('**/api/v1/dashboard/summary', async (route) => {
            await route.abort('timedout');
        });
        await page.getByRole('button', { name: 'Resumen', exact: true }).click();
        await expect(page.getByRole('alert')).toContainText('Resultado desconocido');
        await page.unroute('**/api/v1/dashboard/summary');
    });

    test('rejects invalid payments, expired stock, draft production and disabled adapters', async ({ page }) => {
        const session = await login(page);
        const api = new ApiSession(page, session);
        const products = await api.get('/products?search=Docena%20de%20empanadas&per_page=10');
        const productId = products.body.data[0].id;
        const order = await api.post('/orders', {
            customer_name: 'Negativos financieros E2E',
            items: [{ product_id: productId, quantity: '1.000', unit_price: '10.00' }],
        }, 'e2e-negative-order');
        expectStatus(order, 201);

        const noOpenCash = await api.post('/payments', {
            order_id: order.body.data.id,
            amount: '1.00',
            method: 'cash',
        }, 'e2e-no-open-cash');
        expectStatus(noOpenCash, 422);
        expect(noOpenCash.body.errors.cash_session_id).toBeTruthy();

        const overpayment = await api.post('/payments', {
            order_id: order.body.data.id,
            amount: '11.00',
            method: 'transfer',
        }, 'e2e-overpayment');
        expectStatus(overpayment, 422);
        expect(overpayment.body.errors.amount).toBeTruthy();

        const mercadoPago = await api.post('/payments', {
            order_id: order.body.data.id,
            amount: '1.00',
            method: 'mercadopago',
        }, 'e2e-mp-disabled');
        expectStatus(mercadoPago, 409);

        const arca = await api.post('/fiscal-documents', {}, 'e2e-arca-disabled');
        expectStatus(arca, 409);

        const push = await api.post('/push-subscriptions', {
            endpoint: 'https://push.example.test/e2e',
            public_key: 'public-e2e',
            auth_token: 'auth-e2e',
        });
        expectStatus(push, 409);

        const location = await api.post('/locations', {
            name: 'Vencidos E2E',
            active: true,
        });
        expectStatus(location, 201);
        const expiredProduct = await api.post('/products', {
            name: 'Producto sólo vencido E2E',
            type: 'finished_product',
            unit: 'unit',
            minimum_stock: '0.000',
            price: '20.00',
            active: true,
        });
        expectStatus(expiredProduct, 201);
        const expiredLot = await api.post('/lots/adjustments', {
            product_id: expiredProduct.body.data.id,
            location_id: location.body.data.id,
            code: 'SOLO-VENCIDO-E2E',
            unit: 'unit',
            expires_at: dateOffset(-1),
            quantity: '5.000',
            reason: 'Negativo E2E',
            type: 'receipt',
        }, 'e2e-only-expired-lot');
        expectStatus(expiredLot, 201);
        const expiredOrder = await api.post('/orders', {
            customer_name: 'Pedido lote vencido E2E',
            items: [{
                product_id: expiredProduct.body.data.id,
                quantity: '1.000',
                unit_price: '20.00',
            }],
        }, 'e2e-expired-order');
        expectStatus(expiredOrder, 201);
        const expiredConfirmation = await api.post(
            `/orders/${expiredOrder.body.data.id}/transitions`,
            { status: 'confirmed' },
            'e2e-expired-confirm',
        );
        expectStatus(expiredConfirmation, 422);
        expect(expiredConfirmation.body.errors.stock).toBeTruthy();

        const recipes = await api.get('/recipes?filter_status=approved&per_page=10');
        expectStatus(recipes, 200);
        const draftProduction = await api.post('/production-orders', {
            order_id: order.body.data.id,
            recipe_id: recipes.body.data[0].id,
            planned_quantity: '1.000',
            unit: 'unit',
        }, 'e2e-draft-production');
        expectStatus(draftProduction, 422);
    });

    test('serializes concurrent purchase receipts', async ({ page }) => {
        const session = await login(page);
        const api = new ApiSession(page, session);
        const suppliers = await api.get('/suppliers?search=Proveedor%20Piloto%20E2E&per_page=10');
        const catalog = await api.get('/supplier-products?search=ING-E2E&per_page=10');
        const locations = await api.get('/locations?search=Dep%C3%B3sito%20Piloto%20E2E&per_page=10');
        expectStatus(suppliers, 200);
        expectStatus(catalog, 200);
        expectStatus(locations, 200);

        const order = await api.post('/purchase-orders', {
            supplier_id: suppliers.body.data[0].id,
            ordered_at: dateOffset(0),
            currency: 'ARS',
            items: [{
                supplier_product_id: catalog.body.data[0].id,
                quantity: '1.000',
                unit_price: '100.00',
            }],
        }, 'e2e-concurrent-purchase');
        expectStatus(order, 201);
        expectStatus(await api.post(
            `/purchase-orders/${order.body.data.id}/transitions`,
            { status: 'approved' },
            'e2e-concurrent-approved',
        ), 200);
        expectStatus(await api.post(
            `/purchase-orders/${order.body.data.id}/transitions`,
            { status: 'sent' },
            'e2e-concurrent-sent',
        ), 200);

        const receipt = (code: string) => ({
            received_at: new Date(Date.now() - 60_000).toISOString(),
            items: [{
                purchase_order_item_id: order.body.data.items[0].id,
                location_id: locations.body.data[0].id,
                received_quantity: '1.000',
                accepted_quantity: '1.000',
                rejected_quantity: '0.000',
                lot_code: code,
                expires_at: dateOffset(30),
            }],
        });
        const results = await Promise.all([
            api.post(`/purchase-orders/${order.body.data.id}/receipts`, receipt('CONCURRENT-A'), 'e2e-concurrent-a'),
            api.post(`/purchase-orders/${order.body.data.id}/receipts`, receipt('CONCURRENT-B'), 'e2e-concurrent-b'),
        ]);
        expect(results.map((result) => result.response.status()).sort()).toEqual([201, 422]);

        const detail = await api.get(`/purchase-orders/${order.body.data.id}`);
        expectStatus(detail, 200);
        expect(detail.body.data.status).toBe('received');
        expect(detail.body.data.items[0].received_quantity).toBe('1.000');
    });
});
