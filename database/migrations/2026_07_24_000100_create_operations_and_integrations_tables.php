<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('recipes', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('product_id')->constrained()->restrictOnDelete();
            $table->unsignedInteger('version');
            $table->decimal('expected_yield', 14, 3);
            $table->string('yield_unit', 24);
            $table->string('status')->default('draft');
            $table->timestamp('approved_at')->nullable();
            $table->timestamps();
            $table->unique(['product_id', 'version']);
        });
        Schema::create('recipe_items', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('recipe_id')->constrained()->cascadeOnDelete();
            $table->foreignId('ingredient_product_id')->constrained('products')->restrictOnDelete();
            $table->decimal('quantity', 14, 3);
            $table->string('unit', 24);
            $table->timestamps();
        });
        Schema::create('production_batches', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('recipe_id')->constrained()->restrictOnDelete();
            $table->string('status')->default('planned');
            $table->decimal('planned_quantity', 14, 3);
            $table->decimal('actual_yield', 14, 3)->nullable();
            $table->decimal('waste_quantity', 14, 3)->nullable();
            $table->string('unit', 24);
            $table->timestamp('started_at')->nullable();
            $table->timestamp('completed_at')->nullable();
            $table->timestamps();
        });
        Schema::create('suppliers', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('organization_id')->constrained()->cascadeOnDelete();
            $table->string('name');
            $table->timestamps();
        });
        Schema::create('purchase_orders', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('supplier_id')->constrained()->restrictOnDelete();
            $table->string('status')->default('draft');
            $table->date('expected_on')->nullable();
            $table->timestamps();
        });
        Schema::create('purchase_order_items', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('purchase_order_id')->constrained()->cascadeOnDelete();
            $table->foreignId('product_id')->constrained()->restrictOnDelete();
            $table->decimal('ordered_quantity', 14, 3);
            $table->decimal('received_quantity', 14, 3)->default(0);
            $table->string('unit', 24);
            $table->timestamps();
        });
        Schema::create('integration_deliveries', function (Blueprint $table): void {
            $table->id();
            $table->string('integration');
            $table->string('operation');
            $table->string('idempotency_key');
            $table->string('status')->default('pending');
            $table->unsignedSmallInteger('attempts')->default(0);
            $table->json('request_payload');
            $table->json('response_payload')->nullable();
            $table->text('last_error')->nullable();
            $table->timestamps();
            $table->unique(['integration', 'operation', 'idempotency_key']);
        });
    }

    public function down(): void
    {
        foreach (['integration_deliveries', 'purchase_order_items', 'purchase_orders', 'suppliers',
            'production_batches', 'recipe_items', 'recipes'] as $table) {
            Schema::dropIfExists($table);
        }
    }
};
