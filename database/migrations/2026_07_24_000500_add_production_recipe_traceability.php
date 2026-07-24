<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('recipes', function (Blueprint $table): void {
            $table->decimal('theoretical_waste_percent', 5, 2)->nullable()->after('yield_unit');
        });
        Schema::table('inventory_lots', function (Blueprint $table): void {
            $table->foreignId('organization_id')->nullable()->after('id')->constrained()->cascadeOnDelete();
            $table->foreignId('branch_id')->nullable()->after('organization_id')->constrained()->restrictOnDelete();
            $table->timestamp('manufactured_at')->nullable()->after('reserved_quantity');
            $table->foreignId('production_batch_id')->nullable()->after('status')
                ->constrained('production_batches')->restrictOnDelete();
            $table->foreignId('recipe_id')->nullable()->after('production_batch_id')
                ->constrained()->restrictOnDelete();
            $table->unsignedInteger('recipe_version')->nullable()->after('recipe_id');
            $table->foreignId('created_by')->nullable()->after('recipe_version')
                ->constrained('users')->nullOnDelete();
            $table->unique('production_batch_id');
            $table->index(['organization_id', 'branch_id', 'product_id', 'status', 'expires_at'], 'lots_fefo_tenant_idx');
        });
        Schema::table('production_batches', function (Blueprint $table): void {
            $table->json('recipe_snapshot')->nullable()->after('recipe_id');
            $table->foreignId('destination_location_id')->nullable()->after('waste_quantity')
                ->constrained('locations')->restrictOnDelete();
            $table->timestamp('manufactured_at')->nullable()->after('destination_location_id');
            $table->date('expires_at')->nullable()->after('manufactured_at');
            $table->text('observations')->nullable()->after('expires_at');
            $table->foreignId('completed_by')->nullable()->after('completed_at')
                ->constrained('users')->nullOnDelete();
        });
        Schema::create('production_consumptions', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('organization_id')->constrained()->cascadeOnDelete();
            $table->foreignId('branch_id')->constrained()->restrictOnDelete();
            $table->foreignId('production_batch_id')->constrained()->cascadeOnDelete();
            $table->unsignedInteger('snapshot_item_index');
            $table->foreignId('ingredient_product_id')->constrained('products')->restrictOnDelete();
            $table->foreignId('inventory_lot_id')->constrained()->restrictOnDelete();
            $table->decimal('quantity', 14, 3);
            $table->string('unit', 24);
            $table->foreignId('stock_movement_id')->unique()->constrained()->restrictOnDelete();
            $table->foreignId('performed_by')->constrained('users')->restrictOnDelete();
            $table->timestamps();
            $table->unique(
                ['production_batch_id', 'snapshot_item_index', 'inventory_lot_id'],
                'production_consumption_lot_unique'
            );
            $table->index(['inventory_lot_id', 'production_batch_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('production_consumptions');
        Schema::table('production_batches', function (Blueprint $table): void {
            $table->dropConstrainedForeignId('completed_by');
            $table->dropConstrainedForeignId('destination_location_id');
            $table->dropColumn(['recipe_snapshot', 'manufactured_at', 'expires_at', 'observations']);
        });
        Schema::table('inventory_lots', function (Blueprint $table): void {
            $table->dropIndex('lots_fefo_tenant_idx');
            $table->dropUnique(['production_batch_id']);
            $table->dropConstrainedForeignId('created_by');
            $table->dropConstrainedForeignId('recipe_id');
            $table->dropConstrainedForeignId('production_batch_id');
            $table->dropConstrainedForeignId('branch_id');
            $table->dropConstrainedForeignId('organization_id');
            $table->dropColumn(['manufactured_at', 'recipe_version']);
        });
        Schema::table('recipes', function (Blueprint $table): void {
            $table->dropColumn('theoretical_waste_percent');
        });
    }
};
