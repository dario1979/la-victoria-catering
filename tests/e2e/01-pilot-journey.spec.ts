import { expect, test } from '@playwright/test';
import { ApiSession, expectStatus, login } from './support/session';

function dateOffset(days: number): string {
    const date = new Date();
    date.setUTCDate(date.getUTCDate() + days);

    return date.toISOString().slice(0, 10);
}

test('operational pilot journey crosses sales, stock, procurement, production and finance', async ({ page }, testInfo) => {
    test.setTimeout(210_000);
    const session = await login(page);
    const api = new ApiSession(page, session);

    await page.getByLabel('Sucursal').selectOption(String(session.otherBranchId));
    await expect(page.getByLabel('Sucursal')).toHaveValue(String(session.otherBranchId));
    await expect(page.locator('.user-chip small')).toHaveText('Producción');
    await page.getByLabel('Sucursal').selectOption(String(session.branchId));
    await expect(page.getByLabel('Sucursal')).toHaveValue(String(session.branchId));
    await expect(page.locator('.user-chip small')).toHaveText('Centro');

    const customer = await api.post('/customers', {
        name: 'Cliente Piloto E2E',
        email: 'piloto-e2e@example.test',
        credit_limit: '1000.00',
        active: true,
    });
    expectStatus(customer, 201);

    const finishedProduct = await api.post('/products', {
        name: 'Bandeja Piloto E2E',
        type: 'finished_product',
        unit: 'unit',
        minimum_stock: '2.000',
        price: '100.00',
        active: true,
    });
    expectStatus(finishedProduct, 201);

    const ingredient = await api.post('/products', {
        name: 'Ingrediente Piloto E2E',
        type: 'raw_material',
        unit: 'kg',
        minimum_stock: '1.000',
        price: '0.00',
        active: true,
    });
    expectStatus(ingredient, 201);

    const location = await api.post('/locations', {
        name: 'Depósito Piloto E2E',
        active: true,
    });
    expectStatus(location, 201);

    const expiredLot = await api.post('/lots/adjustments', {
        product_id: finishedProduct.body.data.id,
        location_id: location.body.data.id,
        code: 'E2E-VENCIDO',
        unit: 'unit',
        expires_at: dateOffset(-1),
        quantity: '8.000',
        reason: 'Preparación E2E',
        type: 'receipt',
    }, 'e2e-lot-expired');
    expectStatus(expiredLot, 201);

    const firstLot = await api.post('/lots/adjustments', {
        product_id: finishedProduct.body.data.id,
        location_id: location.body.data.id,
        code: 'E2E-FEFO-PRIMERO',
        unit: 'unit',
        expires_at: dateOffset(2),
        quantity: '8.000',
        reason: 'Preparación E2E',
        type: 'receipt',
    }, 'e2e-lot-first');
    expectStatus(firstLot, 201);

    const laterLot = await api.post('/lots/adjustments', {
        product_id: finishedProduct.body.data.id,
        location_id: location.body.data.id,
        code: 'E2E-FEFO-DESPUES',
        unit: 'unit',
        expires_at: dateOffset(10),
        quantity: '8.000',
        reason: 'Preparación E2E',
        type: 'receipt',
    }, 'e2e-lot-later');
    expectStatus(laterLot, 201);

    const orderPayload = {
        customer_id: customer.body.data.id,
        required_at: new Date(Date.now() + 86_400_000).toISOString(),
        items: [{
            product_id: finishedProduct.body.data.id,
            quantity: '5.000',
            unit_price: '100.00',
        }],
    };
    const order = await api.post('/orders', orderPayload, 'e2e-order-create');
    expectStatus(order, 201);
    const confirmed = await api.post(
        `/orders/${order.body.data.id}/transitions`,
        { status: 'confirmed' },
        'e2e-order-confirm',
    );
    expectStatus(confirmed, 200);
    expect(confirmed.body.data.status).toBe('confirmed');

    const orderDetail = await api.get(`/orders/${order.body.data.id}`);
    expectStatus(orderDetail, 200);
    expect(orderDetail.body.data.reservations).toHaveLength(1);
    expect(orderDetail.body.data.reservations[0].inventory_lot_id).toBe(firstLot.body.data.id);
    expect(orderDetail.body.data.reservations[0].inventory_lot_id).not.toBe(expiredLot.body.data.id);
    expect(orderDetail.body.data.reservations[0].inventory_lot_id).not.toBe(laterLot.body.data.id);

    const shortageOrder = await api.post('/orders', {
        customer_id: customer.body.data.id,
        items: [{
            product_id: finishedProduct.body.data.id,
            quantity: '99.000',
            unit_price: '100.00',
        }],
    }, 'e2e-order-shortage');
    expectStatus(shortageOrder, 201);
    const shortage = await api.post(
        `/orders/${shortageOrder.body.data.id}/transitions`,
        { status: 'confirmed' },
        'e2e-order-shortage-confirm',
    );
    expectStatus(shortage, 422);
    expect(shortage.body.errors.stock).toBeTruthy();

    const supplier = await api.post('/suppliers', {
        trade_name: 'Proveedor Piloto E2E',
        legal_name: 'Proveedor Piloto E2E SA',
        tax_id: '30-71111111-9',
        email: 'proveedor-e2e@example.test',
        lead_time_days: 2,
        payment_terms: 'Cuenta corriente a 15 días',
        active: true,
    });
    expectStatus(supplier, 201);

    const catalog = await api.post('/supplier-products', {
        supplier_id: supplier.body.data.id,
        product_id: ingredient.body.data.id,
        supplier_code: 'ING-E2E',
        purchase_unit: 'kg',
        conversion_factor: '1.000000',
        minimum_quantity: '1.000',
        lead_time_days: 2,
        preferred: true,
        active: true,
        price: '100.00',
        currency: 'ARS',
        price_valid_from: dateOffset(0),
    });
    expectStatus(catalog, 201);

    const purchaseOrder = await api.post('/purchase-orders', {
        supplier_id: supplier.body.data.id,
        ordered_at: dateOffset(0),
        expected_at: dateOffset(2),
        currency: 'ARS',
        payment_terms: 'Cuenta corriente a 15 días',
        items: [{
            supplier_product_id: catalog.body.data.id,
            quantity: '2.000',
            unit_price: '100.00',
        }],
    }, 'e2e-purchase-create');
    expectStatus(purchaseOrder, 201);
    const purchaseItem = purchaseOrder.body.data.items[0];

    const approved = await api.post(
        `/purchase-orders/${purchaseOrder.body.data.id}/transitions`,
        { status: 'approved' },
        'e2e-purchase-approved',
    );
    expectStatus(approved, 200);
    expect(approved.body.data.status).toBe('approved');
    const sent = await api.post(
        `/purchase-orders/${purchaseOrder.body.data.id}/transitions`,
        { status: 'sent' },
        'e2e-purchase-sent',
    );
    expectStatus(sent, 200);
    expect(sent.body.data.status).toBe('sent');

    const receivedAt = new Date(Date.now() - 60_000).toISOString();
    const firstReceipt = await api.post(`/purchase-orders/${purchaseOrder.body.data.id}/receipts`, {
        received_at: receivedAt,
        notes: 'Recepción parcial E2E',
        items: [{
            purchase_order_item_id: purchaseItem.id,
            location_id: location.body.data.id,
            received_quantity: '0.800',
            accepted_quantity: '0.600',
            rejected_quantity: '0.200',
            discrepancy_type: 'damaged',
            discrepancy_reason: 'Envase dañado en ensayo',
            lot_code: 'ING-LOTE-E2E',
            expires_at: dateOffset(90),
            actual_unit_cost: '100.00',
        }],
    }, 'e2e-receipt-partial');
    expectStatus(firstReceipt, 201);

    const partialOrder = await api.get(`/purchase-orders/${purchaseOrder.body.data.id}`);
    expectStatus(partialOrder, 200);
    expect(partialOrder.body.data.status).toBe('partially_received');
    const receiptDetail = await api.get(`/purchase-receipts/${firstReceipt.body.data.id}`);
    expectStatus(receiptDetail, 200);
    expect(receiptDetail.body.data.items[0].inventory_lot.code).toBe('ING-LOTE-E2E');
    expect(receiptDetail.body.data.items[0].stock_movement.type).toBe('purchase_receipt');

    const finalReceipt = await api.post(`/purchase-orders/${purchaseOrder.body.data.id}/receipts`, {
        received_at: receivedAt,
        notes: 'Saldo recibido E2E',
        items: [{
            purchase_order_item_id: purchaseItem.id,
            location_id: location.body.data.id,
            received_quantity: '1.400',
            accepted_quantity: '1.400',
            rejected_quantity: '0.000',
            lot_code: 'ING-LOTE-E2E',
            expires_at: dateOffset(90),
            actual_unit_cost: '100.00',
        }],
    }, 'e2e-receipt-final');
    expectStatus(finalReceipt, 201);
    const receivedOrder = await api.get(`/purchase-orders/${purchaseOrder.body.data.id}`);
    expect(receivedOrder.body.data.status).toBe('received');

    const ingredientLots = await api.get('/lots?search=ING-LOTE-E2E&per_page=10');
    expectStatus(ingredientLots, 200);
    expect(ingredientLots.body.data).toHaveLength(1);
    expect(ingredientLots.body.data[0].quantity).toBe('2.000');

    const recipe = await api.post('/recipes', {
        product_id: finishedProduct.body.data.id,
        expected_yield: '10.000',
        yield_unit: 'unit',
        theoretical_waste_percent: '5.00',
        status: 'approved',
        items: [{
            ingredient_product_id: ingredient.body.data.id,
            quantity: '1.000',
            unit: 'kg',
        }],
    });
    expectStatus(recipe, 201);

    const production = await api.post('/production-orders', {
        order_id: order.body.data.id,
        recipe_id: recipe.body.data.id,
        planned_quantity: '10.000',
        unit: 'unit',
    }, 'e2e-production-create');
    expectStatus(production, 201);
    const requirements = await api.get(`/production-orders/${production.body.data.id}/requirements`);
    expectStatus(requirements, 200);
    expect(requirements.body.data.can_produce).toBe(true);
    expect(requirements.body.data.ingredients[0].candidate_lots[0].lot_id).toBe(ingredientLots.body.data[0].id);

    const started = await api.post(
        `/production-orders/${production.body.data.id}/start`,
        {},
        'e2e-production-start',
    );
    expectStatus(started, 200);
    expect(started.body.data.status).toBe('in_progress');

    const completed = await api.post(`/production-orders/${production.body.data.id}/complete`, {
        actual_yield: '9.000',
        unit: 'unit',
        waste_quantity: '1.000',
        destination_location_id: location.body.data.id,
        manufactured_at: new Date().toISOString(),
        expires_at: dateOffset(4),
        observations: 'Lote piloto E2E verificado',
    }, 'e2e-production-complete');
    expectStatus(completed, 200);
    expect(completed.body.data.status).toBe('completed');
    expect(completed.body.data.produced_lot.quantity).toBe('9.000');

    const traceability = await api.get(`/production-orders/${production.body.data.id}/traceability`);
    expectStatus(traceability, 200);
    expect(traceability.body.data.consumed_lots).toHaveLength(1);
    expect(traceability.body.data.produced_lot.id).toBe(completed.body.data.produced_lot.id);

    const register = await api.post('/cash-registers', {
        name: 'Caja Piloto E2E',
        authorized_user_ids: [session.userId],
    }, 'e2e-cash-register');
    expectStatus(register, 201);
    const cashSession = await api.post(`/cash-registers/${register.body.data.id}/open`, {
        opening_balance: '100.00',
        observations: 'Apertura del ensayo E2E',
    }, 'e2e-cash-open');
    expectStatus(cashSession, 201);

    const payment = await api.post('/payments', {
        order_id: order.body.data.id,
        amount: '200.00',
        method: 'cash',
        cash_session_id: cashSession.body.data.id,
    }, 'e2e-cash-payment');
    expectStatus(payment, 201);

    const closedCash = await api.post(`/cash-sessions/${cashSession.body.data.id}/close`, {
        counted_balance: '300.00',
        observations: 'Arqueo E2E sin diferencia',
    }, 'e2e-cash-close');
    expectStatus(closedCash, 200);
    expect(closedCash.body.data.status).toBe('closed');
    expect(closedCash.body.data.difference_cents).toBe(0);

    const customerAccount = await api.get(`/customer-accounts/${customer.body.data.id}`);
    expectStatus(customerAccount, 200);
    expect(customerAccount.body.data.balance_cents).toBe(30_000);

    const payables = await api.get('/accounts-payable?per_page=10');
    expectStatus(payables, 200);
    expect(payables.body.meta.total).toBeGreaterThanOrEqual(2);
    expect(payables.body.data.every((payable: { status: string }) => payable.status === 'open')).toBe(true);

    const reconciliation = await api.post('/reconciliations', {
        provider: 'cash',
        internal_type: 'payment',
        internal_id: payment.body.data.id,
        external_reference: 'E2E-ARQUEO-001',
        external_amount: '200.00',
        external_date: dateOffset(0),
        observations: 'Conciliación del cobro piloto',
    }, 'e2e-reconciliation-create');
    expectStatus(reconciliation, 201);
    expect(reconciliation.body.data.difference_cents).toBe(0);
    const reconciled = await api.post(`/reconciliations/${reconciliation.body.data.id}/status`, {
        status: 'matched',
        observations: 'Evidencia coincidente',
    }, 'e2e-reconciliation-match');
    expectStatus(reconciled, 200);
    expect(reconciled.body.data.status).toBe('matched');

    const alerts = await api.get('/alerts?filter_status=open&per_page=100');
    expectStatus(alerts, 200);
    expect(alerts.body.meta.total).toBeGreaterThan(0);
    const alert = alerts.body.data.find((candidate: { acknowledged_at?: string | null }) => !candidate.acknowledged_at)
        ?? alerts.body.data[0];
    const acknowledged = await api.post(`/alerts/${alert.id}/acknowledge`, {});
    expectStatus(acknowledged, 200);
    expect(acknowledged.body.data.acknowledged_at).toBeTruthy();

    await expect.poll(async () => {
        const notifications = await api.get('/notifications?per_page=100');
        expectStatus(notifications, 200);

        return notifications.body.meta.total;
    }, {
        message: 'The scheduler and worker should materialize an internal notification.',
        timeout: 90_000,
        intervals: [2_000, 5_000, 10_000],
    }).toBeGreaterThan(0);

    await page.getByRole('button', { name: 'Pedidos', exact: true }).click();
    await page.getByLabel('Buscar').fill('Cliente Piloto E2E');
    await expect(page.getByRole('table', { name: 'Pedidos' })).toContainText('Cliente Piloto E2E');
    await page.screenshot({
        path: testInfo.outputPath('pilot-order-verified.png'),
        fullPage: true,
    });

    const downloadPromise = page.waitForEvent('download');
    await page.getByRole('button', { name: 'Descargar Excel' }).click();
    const download = await downloadPromise;
    expect(download.suggestedFilename()).toMatch(/\.xlsx$/);

    await page.getByRole('button', { name: 'Cerrar sesión' }).click();
    await expect(page.getByRole('heading', { name: 'Bienvenido a tu jornada.' })).toBeVisible();
});
