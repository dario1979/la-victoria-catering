<?php

namespace Tests\Feature;

use App\Domain\Finance\AccountsPayableManager;
use App\Models\CashMovement;
use App\Models\PurchaseReceipt;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use LogicException;
use Tests\TestCase;

class FinanceOperationsTest extends TestCase
{
    use RefreshDatabase;

    private int $organization;

    private int $branch;

    private User $user;

    protected function setUp(): void
    {
        parent::setUp();
        $this->organization = DB::table('organizations')->insertGetId([
            'name' => 'Finance Org', 'created_at' => now(), 'updated_at' => now(),
        ]);
        $this->branch = DB::table('branches')->insertGetId([
            'organization_id' => $this->organization, 'name' => 'Main', 'active' => true,
            'created_at' => now(), 'updated_at' => now(),
        ]);
        $this->user = User::factory()->create();
        DB::table('organization_user')->insert([
            'organization_id' => $this->organization, 'user_id' => $this->user->id, 'role' => 'admin',
            'created_at' => now(), 'updated_at' => now(),
        ]);
        DB::table('branch_user')->insert([
            'branch_id' => $this->branch, 'user_id' => $this->user->id,
            'created_at' => now(), 'updated_at' => now(),
        ]);
        $this->actingAs($this->user)->withHeaders($this->headers($this->organization, $this->branch));
    }

    public function test_cash_payment_close_difference_ledger_and_export_are_traceable(): void
    {
        $register = $this->withHeader('Idempotency-Key', 'register-1')
            ->postJson('/api/v1/cash-registers', [
                'name' => 'Mostrador', 'authorized_user_ids' => [$this->user->id],
            ])->assertCreated()->json('data');
        $session = $this->withHeader('Idempotency-Key', 'open-1')
            ->postJson("/api/v1/cash-registers/{$register['id']}/open", [
                'opening_balance' => '100.00',
            ])->assertCreated()->json('data');
        $this->withHeader('Idempotency-Key', 'open-conflict')
            ->postJson("/api/v1/cash-registers/{$register['id']}/open", [
                'opening_balance' => '0.00',
            ])->assertUnprocessable()->assertJsonValidationErrors('cash_register_id');
        $customer = $this->customer('Cliente cuenta');
        $order = $this->order($customer, '50.00');

        $payment = $this->withHeader('Idempotency-Key', 'cash-payment-1')
            ->postJson('/api/v1/payments', [
                'order_id' => $order, 'amount' => '30.00', 'method' => 'cash',
                'cash_session_id' => $session['id'],
            ])->assertCreated()->json('data');
        $this->withHeader('Idempotency-Key', 'cash-payment-1')
            ->postJson('/api/v1/payments', [
                'order_id' => $order, 'amount' => '30.00', 'method' => 'cash',
                'cash_session_id' => $session['id'],
            ])->assertCreated()->assertHeader('Idempotency-Replayed', 'true');
        $this->withHeader('Idempotency-Key', 'expense-1')
            ->postJson("/api/v1/cash-sessions/{$session['id']}/movements", [
                'kind' => 'expense', 'amount' => '10.00', 'reason' => 'Mensajería',
            ])->assertCreated();

        $closed = $this->withHeader('Idempotency-Key', 'close-1')
            ->postJson("/api/v1/cash-sessions/{$session['id']}/close", [
                'counted_balance' => '119.00', 'observations' => 'Falta un peso',
            ])->assertOk()->assertJsonPath('data.status', 'closed_with_difference')->json('data');
        self::assertSame(12000, $closed['expected_balance_cents']);
        self::assertSame(-100, $closed['difference_cents']);
        $this->withHeader('Idempotency-Key', 'close-1')
            ->postJson("/api/v1/cash-sessions/{$session['id']}/close", [
                'counted_balance' => '119.00', 'observations' => 'Falta un peso',
            ])->assertOk()->assertHeader('Idempotency-Replayed', 'true');
        $this->assertDatabaseHas('alerts', [
            'deduplication_key' => "cash-session:{$session['id']}:difference", 'status' => 'open',
        ]);
        $this->withHeader('Idempotency-Key', 'approve-difference-1')
            ->postJson("/api/v1/cash-sessions/{$session['id']}/approve-difference")
            ->assertOk()->assertJsonPath('data.difference_approved_by', $this->user->id);
        $this->assertDatabaseHas('alerts', [
            'deduplication_key' => "cash-session:{$session['id']}:difference", 'status' => 'resolved',
        ]);

        $this->getJson("/api/v1/customer-accounts/{$customer}")
            ->assertOk()->assertJsonPath('data.balance_cents', 2000)->assertJsonPath('data.status', 'open');
        $this->assertDatabaseHas('customer_account_entries', [
            'customer_id' => $customer, 'order_id' => $order, 'type' => 'charge', 'amount_cents' => 5000,
        ]);
        $this->assertDatabaseHas('customer_account_entries', [
            'customer_id' => $customer, 'payment_id' => $payment['id'], 'type' => 'payment', 'amount_cents' => -3000,
        ]);
        $this->get("/api/v1/customer-accounts/{$customer}/entries?export=xlsx")
            ->assertOk()->assertHeader(
                'content-type',
                'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet'
            );
    }

    public function test_cash_and_customer_entries_are_reversed_without_mutation(): void
    {
        [$register, $session] = $this->openCash();
        $movement = $this->withHeader('Idempotency-Key', 'income-1')
            ->postJson("/api/v1/cash-sessions/{$session}/movements", [
                'kind' => 'income', 'amount' => '12.50', 'reason' => 'Ingreso manual',
            ])->assertCreated()->json('data');
        $this->withHeader('Idempotency-Key', 'reverse-income-1')
            ->postJson("/api/v1/cash-movements/{$movement['id']}/reverse", ['reason' => 'Carga duplicada'])
            ->assertCreated()->assertJsonPath('data.amount_cents', -1250);
        $this->withHeader('Idempotency-Key', 'reverse-income-2')
            ->postJson("/api/v1/cash-movements/{$movement['id']}/reverse", ['reason' => 'Otra vez'])
            ->assertUnprocessable()->assertJsonValidationErrors('movement');

        $customerForPayment = $this->customer('Pago a reversar');
        $order = $this->order($customerForPayment, '15.00');
        $payment = $this->withHeader('Idempotency-Key', 'payment-to-reverse')
            ->postJson('/api/v1/payments', [
                'order_id' => $order, 'amount' => '15.00', 'method' => 'cash',
                'cash_session_id' => $session,
            ])->assertCreated()->json('data');
        $this->withHeader('Idempotency-Key', 'reverse-payment')
            ->postJson("/api/v1/payments/{$payment['id']}/reverse", ['reason' => 'Cobro anulado'])
            ->assertCreated()->assertJsonPath('data.amount_cents', -1500);
        $this->assertDatabaseHas('orders', ['id' => $order, 'paid_total' => 0]);
        $this->getJson("/api/v1/customer-accounts/{$customerForPayment}")
            ->assertJsonPath('data.balance_cents', 1500);

        $customer = $this->customer('Ledger');
        $entry = $this->withHeader('Idempotency-Key', 'charge-1')
            ->postJson("/api/v1/customer-accounts/{$customer}/entries", [
                'type' => 'charge', 'amount' => '40.00', 'description' => 'Saldo inicial',
                'due_on' => today()->subDay()->toDateString(),
            ])->assertCreated()->json('data');
        $this->getJson("/api/v1/customer-accounts/{$customer}")
            ->assertJsonPath('data.status', 'overdue')->assertJsonPath('data.overdue_debt_cents', 4000);
        $this->withHeader('Idempotency-Key', 'reverse-charge-1')
            ->postJson("/api/v1/customer-account-entries/{$entry['id']}/reverse", [
                'description' => 'Anulación autorizada',
            ])->assertCreated()->assertJsonPath('data.amount_cents', -4000);
        $this->getJson("/api/v1/customer-accounts/{$customer}")
            ->assertJsonPath('data.balance_cents', 0)->assertJsonPath('data.status', 'settled');

        $this->expectException(LogicException::class);
        CashMovement::findOrFail($movement['id'])->update(['reason' => 'Manipulado']);
        self::assertNotNull($register);
    }

    public function test_reconciliation_enforces_amount_state_and_tenant_boundaries(): void
    {
        [, $session] = $this->openCash();
        $movement = $this->withHeader('Idempotency-Key', 'reconcile-income')
            ->postJson("/api/v1/cash-sessions/{$session}/movements", [
                'kind' => 'income', 'amount' => '25.00', 'reason' => 'Transferencia',
            ])->assertCreated()->json('data');
        $reconciliation = $this->withHeader('Idempotency-Key', 'reconciliation-1')
            ->postJson('/api/v1/reconciliations', [
                'provider' => 'bank', 'internal_type' => 'cash_movement',
                'internal_id' => $movement['id'], 'external_reference' => 'BANK-001',
                'external_amount' => '24.00', 'external_date' => today()->toDateString(),
            ])->assertCreated()->assertJsonPath('data.status', 'pending')
            ->assertJsonPath('data.difference_cents', -100)->json('data');
        $this->withHeader('Idempotency-Key', 'match-invalid')
            ->postJson("/api/v1/reconciliations/{$reconciliation['id']}/status", ['status' => 'matched'])
            ->assertUnprocessable()->assertJsonValidationErrors('status');
        $this->withHeader('Idempotency-Key', 'resolve-1')
            ->postJson("/api/v1/reconciliations/{$reconciliation['id']}/status", [
                'status' => 'resolved', 'observations' => 'Comisión bancaria documentada',
            ])->assertOk()->assertJsonPath('data.status', 'resolved');

        [$otherOrg, $otherBranch, $otherUser] = $this->tenant('finance');
        $this->actingAs($otherUser)->withHeaders($this->headers($otherOrg, $otherBranch))
            ->postJson('/api/v1/reconciliations', [
                'provider' => 'bank', 'internal_type' => 'cash_movement',
                'internal_id' => $movement['id'], 'external_reference' => 'BANK-FOREIGN',
                'external_amount' => '25.00', 'external_date' => today()->toDateString(),
            ])->assertNotFound();
    }

    public function test_purchase_receipt_creates_a_payable_with_manual_payment_and_reversal(): void
    {
        $supplier = DB::table('suppliers')->insertGetId([
            'organization_id' => $this->organization, 'trade_name' => 'Molino',
            'created_at' => now(), 'updated_at' => now(),
        ]);
        $product = DB::table('products')->insertGetId([
            'organization_id' => $this->organization, 'name' => 'Harina',
            'type' => 'raw_material', 'unit' => 'kg', 'minimum_stock' => '0.000',
            'price' => '0.00', 'active' => true, 'created_at' => now(), 'updated_at' => now(),
        ]);
        $purchaseOrder = DB::table('purchase_orders')->insertGetId([
            'organization_id' => $this->organization, 'branch_id' => $this->branch,
            'supplier_id' => $supplier, 'number' => 'OC-FIN-1', 'status' => 'partially_received',
            'ordered_at' => today(), 'currency' => 'ARS', 'subtotal' => '40.00',
            'tax_total' => '0.00', 'total' => '40.00', 'created_at' => now(), 'updated_at' => now(),
        ]);
        $orderItem = DB::table('purchase_order_items')->insertGetId([
            'purchase_order_id' => $purchaseOrder, 'product_id' => $product,
            'product_name' => 'Harina', 'purchase_unit' => 'kg', 'base_unit' => 'kg',
            'conversion_factor' => '1.000000', 'quantity' => '2.000', 'received_quantity' => '1.000',
            'unit_price' => '20.00', 'tax_amount' => '0.00', 'subtotal' => '40.00', 'total' => '40.00',
            'created_at' => now(), 'updated_at' => now(),
        ]);
        $receipt = DB::table('purchase_receipts')->insertGetId([
            'organization_id' => $this->organization, 'branch_id' => $this->branch,
            'purchase_order_id' => $purchaseOrder, 'number' => 'RC-FIN-1',
            'received_at' => now(), 'created_by' => $this->user->id,
            'created_at' => now(), 'updated_at' => now(),
        ]);
        $location = DB::table('locations')->insertGetId([
            'organization_id' => $this->organization, 'branch_id' => $this->branch,
            'name' => 'Recepción', 'active' => true, 'created_at' => now(), 'updated_at' => now(),
        ]);
        DB::table('purchase_receipt_items')->insert([
            'purchase_receipt_id' => $receipt, 'purchase_order_item_id' => $orderItem,
            'product_id' => $product, 'location_id' => $location,
            'received_quantity' => '1.000', 'accepted_quantity' => '1.000',
            'rejected_quantity' => '0.000', 'lot_code' => 'FIN-1',
            'created_at' => now(), 'updated_at' => now(),
        ]);

        $payable = app(AccountsPayableManager::class)->fromReceipt(PurchaseReceipt::findOrFail($receipt));
        self::assertSame(2000, $payable->total_cents);
        $payment = $this->withHeader('Idempotency-Key', 'payable-payment-1')
            ->postJson("/api/v1/accounts-payable/{$payable->id}/payments", [
                'amount' => '10.00', 'method' => 'transfer', 'external_reference' => 'TR-1',
            ])->assertCreated()->json('data');
        $this->assertDatabaseHas('accounts_payable', [
            'id' => $payable->id, 'paid_cents' => 1000, 'status' => 'partial',
        ]);
        $this->withHeader('Idempotency-Key', 'payable-reverse-1')
            ->postJson("/api/v1/accounts-payable-payments/{$payment['id']}/reverse")
            ->assertCreated()->assertJsonPath('data.amount_cents', -1000);
        $this->assertDatabaseHas('accounts_payable', [
            'id' => $payable->id, 'paid_cents' => 0, 'status' => 'open',
        ]);
    }

    public function test_finance_permissions_are_checked_before_payload_validation(): void
    {
        [$organization, $branch, $production] = $this->tenant('production');

        $this->actingAs($production)->withHeaders($this->headers($organization, $branch))
            ->postJson('/api/v1/cash-registers', [])
            ->assertForbidden();
        $this->getJson('/api/v1/customer-accounts')->assertForbidden();
        $this->postJson('/api/v1/reconciliations', [])->assertForbidden();
    }

    private function openCash(): array
    {
        $register = $this->withHeader('Idempotency-Key', 'register-'.str()->uuid())
            ->postJson('/api/v1/cash-registers', ['name' => 'Caja '.str()->uuid()])
            ->assertCreated()->json('data');
        $session = $this->withHeader('Idempotency-Key', 'open-'.str()->uuid())
            ->postJson("/api/v1/cash-registers/{$register['id']}/open", ['opening_balance' => '0.00'])
            ->assertCreated()->json('data.id');

        return [$register['id'], $session];
    }

    private function customer(string $name): int
    {
        return DB::table('customers')->insertGetId([
            'organization_id' => $this->organization, 'name' => $name, 'active' => true,
            'created_at' => now(), 'updated_at' => now(),
        ]);
    }

    private function order(int $customer, string $total): int
    {
        return DB::table('orders')->insertGetId([
            'organization_id' => $this->organization, 'branch_id' => $this->branch,
            'customer_id' => $customer, 'customer_name' => 'Cliente cuenta',
            'status' => 'confirmed', 'total' => $total, 'paid_total' => '0.00',
            'created_at' => now(), 'updated_at' => now(),
        ]);
    }

    private function tenant(string $role): array
    {
        $organization = DB::table('organizations')->insertGetId([
            'name' => "Other {$role}", 'created_at' => now(), 'updated_at' => now(),
        ]);
        $branch = DB::table('branches')->insertGetId([
            'organization_id' => $organization, 'name' => 'Other', 'active' => true,
            'created_at' => now(), 'updated_at' => now(),
        ]);
        $user = User::factory()->create();
        DB::table('organization_user')->insert([
            'organization_id' => $organization, 'user_id' => $user->id, 'role' => $role,
            'created_at' => now(), 'updated_at' => now(),
        ]);
        DB::table('branch_user')->insert([
            'branch_id' => $branch, 'user_id' => $user->id,
            'created_at' => now(), 'updated_at' => now(),
        ]);

        return [$organization, $branch, $user];
    }

    private function headers(int $organization, int $branch): array
    {
        return [
            'X-Organization-ID' => (string) $organization,
            'X-Branch-ID' => (string) $branch,
        ];
    }
}
