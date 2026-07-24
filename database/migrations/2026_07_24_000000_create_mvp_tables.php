<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('organizations', function (Blueprint $table): void {
            $table->id();
            $table->string('name');
            $table->timestamps();
        });
        Schema::create('products', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('organization_id')->constrained()->cascadeOnDelete();
            $table->string('name');
            $table->string('unit', 24);
            $table->decimal('price', 14, 2)->default(0);
            $table->timestamps();
        });
        Schema::create('locations', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('organization_id')->constrained()->cascadeOnDelete();
            $table->string('name');
            $table->timestamps();
        });
        Schema::create('inventory_lots', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('product_id')->constrained()->restrictOnDelete();
            $table->foreignId('location_id')->constrained()->restrictOnDelete();
            $table->string('code');
            $table->string('unit', 24);
            $table->decimal('quantity', 14, 3);
            $table->decimal('reserved_quantity', 14, 3)->default(0);
            $table->date('expires_at')->nullable();
            $table->string('status')->default('available');
            $table->timestamps();
            $table->unique(['product_id', 'location_id', 'code']);
        });
        Schema::create('orders', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('organization_id')->constrained()->cascadeOnDelete();
            $table->string('customer_name');
            $table->string('status')->default('draft');
            $table->decimal('total', 14, 2)->default(0);
            $table->decimal('paid_total', 14, 2)->default(0);
            $table->timestamp('required_at')->nullable();
            $table->timestamps();
        });
        Schema::create('order_items', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('order_id')->constrained()->cascadeOnDelete();
            $table->foreignId('product_id')->constrained()->restrictOnDelete();
            $table->decimal('quantity', 14, 3);
            $table->decimal('unit_price', 14, 2);
            $table->timestamps();
        });
        Schema::create('order_transitions', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('order_id')->constrained()->cascadeOnDelete();
            $table->string('from_status');
            $table->string('to_status');
            $table->nullableMorphs('actor');
            $table->boolean('accepted');
            $table->string('reason')->nullable();
            $table->timestamps();
        });
        Schema::create('stock_reservations', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('order_id')->constrained()->cascadeOnDelete();
            $table->foreignId('inventory_lot_id')->constrained()->restrictOnDelete();
            $table->foreignId('product_id')->constrained()->restrictOnDelete();
            $table->foreignId('location_id')->constrained()->restrictOnDelete();
            $table->string('unit', 24);
            $table->decimal('quantity', 14, 3);
            $table->string('status')->default('active');
            $table->timestamps();
        });
        Schema::create('stock_movements', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('product_id')->constrained()->restrictOnDelete();
            $table->foreignId('location_id')->constrained()->restrictOnDelete();
            $table->foreignId('inventory_lot_id')->constrained()->restrictOnDelete();
            $table->string('unit', 24);
            $table->decimal('quantity', 14, 3);
            $table->string('reason');
            $table->string('reference_type')->nullable();
            $table->unsignedBigInteger('reference_id')->nullable();
            $table->timestamps();
        });
        Schema::create('payments', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('order_id')->constrained()->restrictOnDelete();
            $table->decimal('amount', 14, 2);
            $table->string('method');
            $table->string('external_reference')->nullable();
            $table->timestamps();
        });
        Schema::create('cash_entries', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('payment_id')->constrained()->restrictOnDelete();
            $table->decimal('amount', 14, 2);
            $table->string('reason');
            $table->timestamps();
        });
        Schema::create('alerts', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('organization_id')->constrained()->cascadeOnDelete();
            $table->string('deduplication_key');
            $table->string('event');
            $table->string('condition');
            $table->string('severity');
            $table->string('recipient');
            $table->string('action');
            $table->string('status')->default('open');
            $table->unsignedInteger('occurrences')->default(1);
            $table->timestamp('last_seen_at');
            $table->timestamp('resolved_at')->nullable();
            $table->timestamps();
            $table->index(['organization_id', 'deduplication_key', 'status']);
        });
        Schema::create('idempotency_keys', function (Blueprint $table): void {
            $table->id();
            $table->string('scope');
            $table->string('key');
            $table->char('request_hash', 64);
            $table->unsignedSmallInteger('response_status');
            $table->json('response_body');
            $table->timestamps();
            $table->unique(['scope', 'key']);
        });
        Schema::create('audit_logs', function (Blueprint $table): void {
            $table->id();
            $table->string('event');
            $table->nullableMorphs('subject');
            $table->nullableMorphs('actor');
            $table->json('context')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        foreach (['audit_logs', 'idempotency_keys', 'alerts', 'cash_entries', 'payments',
            'stock_movements', 'stock_reservations', 'order_transitions', 'order_items',
            'orders', 'inventory_lots', 'locations', 'products', 'organizations'] as $table) {
            Schema::dropIfExists($table);
        }
    }
};
