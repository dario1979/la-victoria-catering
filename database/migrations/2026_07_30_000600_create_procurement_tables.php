<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('suppliers', function (Blueprint $table): void {
            $table->renameColumn('name', 'trade_name');
            $table->string('legal_name')->nullable();
            $table->string('tax_id', 32)->nullable();
            $table->string('email')->nullable();
            $table->string('phone', 64)->nullable();
            $table->string('contact_name')->nullable();
            $table->text('address')->nullable();
            $table->string('payment_terms')->nullable();
            $table->unsignedSmallInteger('lead_time_days')->default(0);
            $table->text('notes')->nullable();
            $table->boolean('active')->default(true);
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('updated_by')->nullable()->constrained('users')->nullOnDelete();
            $table->index(['organization_id', 'trade_name']);
            $table->unique(['organization_id', 'tax_id']);
        });

        Schema::create('supplier_products', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('organization_id')->constrained()->cascadeOnDelete();
            $table->foreignId('supplier_id')->constrained()->restrictOnDelete();
            $table->foreignId('product_id')->constrained()->restrictOnDelete();
            $table->string('supplier_code')->nullable();
            $table->string('purchase_unit', 24);
            $table->decimal('conversion_factor', 18, 6);
            $table->decimal('minimum_quantity', 14, 3)->default(0);
            $table->unsignedSmallInteger('lead_time_days')->default(0);
            $table->boolean('preferred')->default(false);
            $table->boolean('active')->default(true);
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('updated_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->unique(['organization_id', 'supplier_id', 'product_id']);
            $table->index(['organization_id', 'product_id', 'active']);
        });

        Schema::create('supplier_product_prices', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('supplier_product_id')->constrained()->cascadeOnDelete();
            $table->decimal('price', 14, 2);
            $table->char('currency', 3)->default('ARS');
            $table->date('valid_from');
            $table->date('valid_until')->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->index(['supplier_product_id', 'valid_from', 'valid_until']);
        });

        Schema::table('purchase_orders', function (Blueprint $table): void {
            $table->renameColumn('expected_on', 'expected_at');
            $table->foreignId('organization_id')->nullable()->constrained()->cascadeOnDelete();
            $table->foreignId('branch_id')->nullable()->constrained()->restrictOnDelete();
            $table->string('number')->nullable();
            $table->date('ordered_at')->nullable();
            $table->char('currency', 3)->default('ARS');
            $table->string('payment_terms')->nullable();
            $table->text('notes')->nullable();
            $table->decimal('subtotal', 14, 2)->default(0);
            $table->decimal('tax_total', 14, 2)->default(0);
            $table->decimal('total', 14, 2)->default(0);
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('updated_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('approved_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('approved_at')->nullable();
            $table->foreignId('sent_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('sent_at')->nullable();
            $table->foreignId('cancelled_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('cancelled_at')->nullable();
            $table->unique(['organization_id', 'number']);
            $table->index(['organization_id', 'branch_id', 'status', 'expected_at']);
        });

        Schema::table('purchase_order_items', function (Blueprint $table): void {
            $table->renameColumn('ordered_quantity', 'quantity');
            $table->renameColumn('unit', 'purchase_unit');
            $table->foreignId('supplier_product_id')->nullable()->constrained()->restrictOnDelete();
            $table->string('product_name')->nullable();
            $table->string('supplier_code')->nullable();
            $table->string('base_unit', 24)->nullable();
            $table->decimal('conversion_factor', 18, 6)->default(1);
            $table->decimal('unit_price', 14, 2)->default(0);
            $table->decimal('tax_amount', 14, 2)->default(0);
            $table->decimal('subtotal', 14, 2)->default(0);
            $table->decimal('total', 14, 2)->default(0);
        });

        DB::table('purchase_orders')->orderBy('id')->each(function (object $order): void {
            $organizationId = DB::table('suppliers')->where('id', $order->supplier_id)->value('organization_id');
            $branchId = $organizationId
                ? DB::table('branches')->where('organization_id', $organizationId)->where('active', true)->orderBy('id')->value('id')
                : null;
            DB::table('purchase_orders')->where('id', $order->id)->update([
                'organization_id' => $organizationId,
                'branch_id' => $branchId,
                'number' => "OC-LEGACY-{$order->id}",
                'ordered_at' => substr((string) $order->created_at, 0, 10),
                'updated_at' => now(),
            ]);
        });
        DB::table('purchase_order_items')->orderBy('id')->each(function (object $item): void {
            $product = DB::table('products')->where('id', $item->product_id)->first(['name', 'unit']);
            DB::table('purchase_order_items')->where('id', $item->id)->update([
                'product_name' => $product?->name,
                'base_unit' => $product?->unit,
                'conversion_factor' => '1.000000',
                'unit_price' => '0.00',
                'tax_amount' => '0.00',
                'subtotal' => '0.00',
                'total' => '0.00',
                'updated_at' => now(),
            ]);
        });

        Schema::create('purchase_order_transitions', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('purchase_order_id')->constrained()->cascadeOnDelete();
            $table->string('from_status', 32);
            $table->string('to_status', 32);
            $table->foreignId('actor_id')->nullable()->constrained('users')->nullOnDelete();
            $table->boolean('accepted');
            $table->string('reason')->nullable();
            $table->timestamps();
        });

        Schema::create('purchase_receipts', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('organization_id')->constrained()->cascadeOnDelete();
            $table->foreignId('branch_id')->constrained()->restrictOnDelete();
            $table->foreignId('purchase_order_id')->constrained()->restrictOnDelete();
            $table->string('number');
            $table->timestamp('received_at');
            $table->text('notes')->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->unique(['organization_id', 'number']);
            $table->index(['organization_id', 'branch_id', 'received_at']);
        });

        Schema::create('purchase_receipt_items', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('purchase_receipt_id')->constrained()->cascadeOnDelete();
            $table->foreignId('purchase_order_item_id')->constrained()->restrictOnDelete();
            $table->foreignId('product_id')->constrained()->restrictOnDelete();
            $table->foreignId('location_id')->constrained()->restrictOnDelete();
            $table->decimal('received_quantity', 14, 3);
            $table->decimal('accepted_quantity', 14, 3);
            $table->decimal('rejected_quantity', 14, 3)->default(0);
            $table->string('discrepancy_type', 32)->nullable();
            $table->string('discrepancy_reason')->nullable();
            $table->string('lot_code');
            $table->timestamp('manufactured_at')->nullable();
            $table->date('expires_at')->nullable();
            $table->decimal('actual_unit_cost', 14, 2)->nullable();
            $table->foreignId('inventory_lot_id')->nullable()->constrained()->restrictOnDelete();
            $table->foreignId('stock_movement_id')->nullable()->constrained()->restrictOnDelete();
            $table->timestamps();
            $table->unique(['purchase_receipt_id', 'purchase_order_item_id']);
            $table->unique('stock_movement_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('purchase_receipt_items');
        Schema::dropIfExists('purchase_receipts');
        Schema::dropIfExists('purchase_order_transitions');
        Schema::table('purchase_order_items', function (Blueprint $table): void {
            $table->dropConstrainedForeignId('supplier_product_id');
            $table->dropColumn([
                'product_name', 'supplier_code', 'base_unit', 'conversion_factor',
                'unit_price', 'tax_amount', 'subtotal', 'total',
            ]);
            $table->renameColumn('quantity', 'ordered_quantity');
            $table->renameColumn('purchase_unit', 'unit');
        });
        Schema::dropIfExists('supplier_product_prices');
        Schema::dropIfExists('supplier_products');
        Schema::table('purchase_orders', function (Blueprint $table): void {
            $table->dropUnique(['organization_id', 'number']);
            $table->dropIndex(['organization_id', 'branch_id', 'status', 'expected_at']);
            $table->dropConstrainedForeignId('cancelled_by');
            $table->dropConstrainedForeignId('sent_by');
            $table->dropConstrainedForeignId('approved_by');
            $table->dropConstrainedForeignId('updated_by');
            $table->dropConstrainedForeignId('created_by');
            $table->dropConstrainedForeignId('branch_id');
            $table->dropConstrainedForeignId('organization_id');
            $table->dropColumn([
                'number', 'ordered_at', 'currency', 'payment_terms', 'notes',
                'subtotal', 'tax_total', 'total', 'approved_at', 'sent_at', 'cancelled_at',
            ]);
            $table->renameColumn('expected_at', 'expected_on');
        });
        Schema::table('suppliers', function (Blueprint $table): void {
            $table->dropUnique(['organization_id', 'tax_id']);
            $table->dropIndex(['organization_id', 'trade_name']);
            $table->dropConstrainedForeignId('updated_by');
            $table->dropConstrainedForeignId('created_by');
            $table->dropColumn([
                'legal_name', 'tax_id', 'email', 'phone', 'contact_name', 'address',
                'payment_terms', 'lead_time_days', 'notes', 'active',
            ]);
            $table->renameColumn('trade_name', 'name');
        });
    }
};
