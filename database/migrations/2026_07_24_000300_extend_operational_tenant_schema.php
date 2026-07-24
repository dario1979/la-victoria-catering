<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('customers', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('organization_id')->constrained()->cascadeOnDelete();
            $table->string('name');
            $table->string('tax_id', 32)->nullable();
            $table->string('tax_condition', 64)->nullable();
            $table->string('email')->nullable();
            $table->string('phone', 64)->nullable();
            $table->json('addresses')->nullable();
            $table->decimal('credit_limit', 14, 2)->nullable();
            $table->boolean('active')->default(true);
            $table->timestamps();
            $table->index(['organization_id', 'name']);
            $table->unique(['organization_id', 'tax_id']);
        });

        Schema::table('products', function (Blueprint $table): void {
            $table->string('type', 32)->default('finished_product')->after('name');
            $table->decimal('minimum_stock', 14, 3)->default(0)->after('unit');
            $table->boolean('active')->default(true)->after('price');
        });
        Schema::table('locations', function (Blueprint $table): void {
            $table->foreignId('branch_id')->nullable()->after('organization_id')->constrained()->restrictOnDelete();
            $table->boolean('active')->default(true);
        });
        Schema::table('orders', function (Blueprint $table): void {
            $table->foreignId('branch_id')->nullable()->after('organization_id')->constrained()->restrictOnDelete();
            $table->foreignId('customer_id')->nullable()->after('branch_id')->constrained()->restrictOnDelete();
            $table->string('delivery_method', 32)->nullable();
            $table->text('delivery_notes')->nullable();
            $table->timestamp('delivered_at')->nullable();
            $table->foreignId('delivered_by')->nullable()->constrained('users')->nullOnDelete();
        });
        Schema::table('production_batches', function (Blueprint $table): void {
            $table->foreignId('organization_id')->nullable()->after('id')->constrained()->cascadeOnDelete();
            $table->foreignId('branch_id')->nullable()->after('organization_id')->constrained()->restrictOnDelete();
            $table->foreignId('order_id')->nullable()->after('branch_id')->constrained()->restrictOnDelete();
        });
        Schema::table('stock_movements', function (Blueprint $table): void {
            $table->foreignId('organization_id')->nullable()->after('id')->constrained()->cascadeOnDelete();
            $table->foreignId('branch_id')->nullable()->after('organization_id')->constrained()->restrictOnDelete();
            $table->string('type', 32)->default('adjustment')->after('quantity');
            $table->foreignId('performed_by')->nullable()->constrained('users')->nullOnDelete();
            $table->string('idempotency_key')->nullable();
            $table->unique(['organization_id', 'idempotency_key']);
        });
        Schema::table('alerts', function (Blueprint $table): void {
            $table->foreignId('branch_id')->nullable()->after('organization_id')->constrained()->restrictOnDelete();
            $table->nullableMorphs('related');
            $table->timestamp('read_at')->nullable();
            $table->timestamp('acknowledged_at')->nullable();
            $table->foreignId('acknowledged_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('resolved_by')->nullable()->constrained('users')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('alerts', function (Blueprint $table): void {
            $table->dropConstrainedForeignId('resolved_by');
            $table->dropConstrainedForeignId('acknowledged_by');
            $table->dropColumn(['related_type', 'related_id', 'read_at', 'acknowledged_at']);
            $table->dropConstrainedForeignId('branch_id');
        });
        Schema::table('stock_movements', function (Blueprint $table): void {
            $table->dropUnique(['organization_id', 'idempotency_key']);
            $table->dropConstrainedForeignId('performed_by');
            $table->dropConstrainedForeignId('branch_id');
            $table->dropConstrainedForeignId('organization_id');
            $table->dropColumn(['type', 'idempotency_key']);
        });
        Schema::table('production_batches', function (Blueprint $table): void {
            $table->dropConstrainedForeignId('order_id');
            $table->dropConstrainedForeignId('branch_id');
            $table->dropConstrainedForeignId('organization_id');
        });
        Schema::table('orders', function (Blueprint $table): void {
            $table->dropConstrainedForeignId('delivered_by');
            $table->dropConstrainedForeignId('customer_id');
            $table->dropConstrainedForeignId('branch_id');
            $table->dropColumn(['delivery_method', 'delivery_notes', 'delivered_at']);
        });
        Schema::table('locations', function (Blueprint $table): void {
            $table->dropConstrainedForeignId('branch_id');
            $table->dropColumn('active');
        });
        Schema::table('products', function (Blueprint $table): void {
            $table->dropColumn(['type', 'minimum_stock', 'active']);
        });
        Schema::dropIfExists('customers');
    }
};
