<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('import_batches', function (Blueprint $table): void {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->foreignId('organization_id')->constrained()->cascadeOnDelete();
            $table->foreignId('branch_id')->constrained()->restrictOnDelete();
            $table->foreignId('created_by')->constrained('users')->restrictOnDelete();
            $table->string('type', 40);
            $table->string('status', 32)->default('uploaded');
            $table->string('original_name');
            $table->string('extension', 8);
            $table->string('storage_path');
            $table->char('file_sha256', 64);
            $table->unsignedBigInteger('file_size');
            $table->json('headers');
            $table->json('mapping')->nullable();
            $table->json('preview')->nullable();
            $table->json('errors')->nullable();
            $table->json('created_records')->nullable();
            $table->unsignedInteger('total_rows')->default(0);
            $table->unsignedInteger('valid_rows')->default(0);
            $table->unsignedInteger('error_rows')->default(0);
            $table->string('idempotency_key')->nullable();
            $table->timestamp('validated_at')->nullable();
            $table->timestamp('confirmed_at')->nullable();
            $table->timestamp('completed_at')->nullable();
            $table->timestamp('failed_at')->nullable();
            $table->timestamp('rolled_back_at')->nullable();
            $table->timestamp('file_purged_at')->nullable();
            $table->text('failure_reason')->nullable();
            $table->timestamps();

            $table->index(['organization_id', 'branch_id', 'status', 'created_at']);
            $table->unique(['organization_id', 'branch_id', 'idempotency_key']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('import_batches');
    }
};
