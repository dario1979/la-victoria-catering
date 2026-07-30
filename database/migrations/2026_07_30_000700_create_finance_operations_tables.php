<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('cash_registers', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('organization_id')->constrained()->cascadeOnDelete();
            $table->foreignId('branch_id')->constrained()->restrictOnDelete();
            $table->string('name');
            $table->boolean('active')->default(true);
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->unique(['organization_id', 'branch_id', 'name']);
            $table->index(['organization_id', 'branch_id', 'active']);
        });

        Schema::create('cash_register_user', function (Blueprint $table): void {
            $table->foreignId('cash_register_id')->constrained()->cascadeOnDelete();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->timestamps();
            $table->primary(['cash_register_id', 'user_id']);
        });

        Schema::create('cash_sessions', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('organization_id')->constrained()->cascadeOnDelete();
            $table->foreignId('branch_id')->constrained()->restrictOnDelete();
            $table->foreignId('cash_register_id')->constrained()->restrictOnDelete();
            $table->string('status', 32)->default('open');
            $table->bigInteger('opening_balance_cents');
            $table->bigInteger('expected_balance_cents')->nullable();
            $table->bigInteger('counted_balance_cents')->nullable();
            $table->bigInteger('difference_cents')->nullable();
            $table->text('observations')->nullable();
            $table->foreignId('opened_by')->constrained('users')->restrictOnDelete();
            $table->foreignId('closed_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('difference_approved_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('opened_at');
            $table->timestamp('closed_at')->nullable();
            $table->timestamp('difference_approved_at')->nullable();
            $table->timestamps();
            $table->index(['organization_id', 'branch_id', 'status']);
            $table->index(['cash_register_id', 'status']);
        });

        Schema::create('cash_movements', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('organization_id')->constrained()->cascadeOnDelete();
            $table->foreignId('branch_id')->constrained()->restrictOnDelete();
            $table->foreignId('cash_session_id')->constrained()->restrictOnDelete();
            $table->string('kind', 32);
            $table->bigInteger('amount_cents');
            $table->string('reason');
            $table->nullableMorphs('reference');
            $table->foreignId('reversal_of_id')->nullable()->constrained('cash_movements')->restrictOnDelete();
            $table->foreignId('performed_by')->constrained('users')->restrictOnDelete();
            $table->timestamp('occurred_at');
            $table->string('idempotency_key');
            $table->timestamps();
            $table->unique('reversal_of_id');
            $table->unique(['organization_id', 'branch_id', 'idempotency_key']);
            $table->index(['organization_id', 'branch_id', 'occurred_at']);
        });

        Schema::table('payments', function (Blueprint $table): void {
            $table->foreignId('organization_id')->nullable()->after('id')->constrained()->cascadeOnDelete();
            $table->foreignId('branch_id')->nullable()->after('organization_id')->constrained()->restrictOnDelete();
            $table->foreignId('customer_id')->nullable()->after('order_id')->constrained()->restrictOnDelete();
            $table->foreignId('cash_session_id')->nullable()->after('customer_id')->constrained()->restrictOnDelete();
            $table->bigInteger('amount_cents')->nullable()->after('amount');
            $table->string('status', 24)->default('recorded')->after('external_reference');
            $table->foreignId('reversal_of_id')->nullable()->constrained('payments')->restrictOnDelete();
            $table->foreignId('recorded_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('occurred_at')->nullable();
            $table->unique('reversal_of_id');
            $table->index(['organization_id', 'branch_id', 'occurred_at']);
        });

        DB::table('payments')->orderBy('id')->each(function (object $payment): void {
            $order = DB::table('orders')->find($payment->order_id);
            DB::table('payments')->where('id', $payment->id)->update([
                'organization_id' => $order?->organization_id,
                'branch_id' => $order?->branch_id,
                'customer_id' => $order?->customer_id,
                'amount_cents' => (int) round(((float) $payment->amount) * 100),
                'occurred_at' => $payment->created_at,
            ]);
        });

        Schema::create('customer_account_entries', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('organization_id')->constrained()->cascadeOnDelete();
            $table->foreignId('branch_id')->constrained()->restrictOnDelete();
            $table->foreignId('customer_id')->constrained()->restrictOnDelete();
            $table->string('type', 24);
            $table->bigInteger('amount_cents');
            $table->string('description');
            $table->foreignId('order_id')->nullable()->constrained()->restrictOnDelete();
            $table->foreignId('payment_id')->nullable()->constrained()->restrictOnDelete();
            $table->foreignId('reversal_of_id')->nullable()->constrained('customer_account_entries')->restrictOnDelete();
            $table->date('due_on')->nullable();
            $table->timestamp('occurred_at');
            $table->foreignId('performed_by')->constrained('users')->restrictOnDelete();
            $table->string('idempotency_key');
            $table->timestamps();
            $table->unique('order_id');
            $table->unique('payment_id');
            $table->unique('reversal_of_id');
            $table->unique(['organization_id', 'idempotency_key']);
            $table->index(['organization_id', 'customer_id', 'occurred_at']);
            $table->index(['organization_id', 'due_on']);
        });

        Schema::create('accounts_payable', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('organization_id')->constrained()->cascadeOnDelete();
            $table->foreignId('branch_id')->constrained()->restrictOnDelete();
            $table->foreignId('supplier_id')->constrained()->restrictOnDelete();
            $table->foreignId('purchase_order_id')->constrained()->restrictOnDelete();
            $table->foreignId('purchase_receipt_id')->constrained()->restrictOnDelete();
            $table->string('document');
            $table->date('document_date');
            $table->date('due_on');
            $table->string('currency', 3)->default('ARS');
            $table->bigInteger('total_cents');
            $table->bigInteger('paid_cents')->default(0);
            $table->string('status', 24)->default('open');
            $table->timestamps();
            $table->unique('purchase_receipt_id');
            $table->index(['organization_id', 'branch_id', 'status', 'due_on']);
        });

        Schema::create('accounts_payable_payments', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('organization_id')->constrained()->cascadeOnDelete();
            $table->foreignId('branch_id')->constrained()->restrictOnDelete();
            $table->foreignId('account_payable_id')->constrained('accounts_payable')->restrictOnDelete();
            $table->bigInteger('amount_cents');
            $table->string('method', 24);
            $table->string('external_reference')->nullable();
            $table->foreignId('reversal_of_id')->nullable()->constrained('accounts_payable_payments')->restrictOnDelete();
            $table->foreignId('performed_by')->constrained('users')->restrictOnDelete();
            $table->timestamp('occurred_at');
            $table->string('idempotency_key');
            $table->timestamps();
            $table->unique('reversal_of_id');
            $table->unique(['organization_id', 'idempotency_key']);
            $table->index(['organization_id', 'branch_id', 'occurred_at']);
        });

        Schema::create('reconciliations', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('organization_id')->constrained()->cascadeOnDelete();
            $table->foreignId('branch_id')->constrained()->restrictOnDelete();
            $table->string('provider', 32);
            $table->string('internal_type', 32);
            $table->unsignedBigInteger('internal_id');
            $table->string('external_reference');
            $table->bigInteger('internal_amount_cents');
            $table->bigInteger('external_amount_cents');
            $table->bigInteger('difference_cents');
            $table->date('external_date');
            $table->string('status', 24)->default('pending');
            $table->text('observations')->nullable();
            $table->foreignId('reconciled_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('reconciled_at')->nullable();
            $table->timestamps();
            $table->unique(['organization_id', 'provider', 'external_reference']);
            $table->index(['organization_id', 'branch_id', 'status', 'external_date']);
            $table->index(['internal_type', 'internal_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('reconciliations');
        Schema::dropIfExists('accounts_payable_payments');
        Schema::dropIfExists('accounts_payable');
        Schema::dropIfExists('customer_account_entries');
        Schema::table('payments', function (Blueprint $table): void {
            $table->dropUnique(['reversal_of_id']);
            $table->dropConstrainedForeignId('recorded_by');
            $table->dropConstrainedForeignId('reversal_of_id');
            $table->dropConstrainedForeignId('cash_session_id');
            $table->dropConstrainedForeignId('customer_id');
            $table->dropConstrainedForeignId('branch_id');
            $table->dropConstrainedForeignId('organization_id');
            $table->dropColumn(['amount_cents', 'status', 'occurred_at']);
        });
        Schema::dropIfExists('cash_movements');
        Schema::dropIfExists('cash_sessions');
        Schema::dropIfExists('cash_register_user');
        Schema::dropIfExists('cash_registers');
    }
};
