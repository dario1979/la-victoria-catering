<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('operational_failures', function (Blueprint $table): void {
            $table->id();
            $table->uuid('job_uuid')->unique();
            $table->foreignId('organization_id')->nullable()->constrained()->cascadeOnDelete();
            $table->foreignId('branch_id')->nullable()->constrained()->restrictOnDelete();
            $table->string('job_name');
            $table->string('connection', 64);
            $table->string('queue', 64);
            $table->string('correlation_id', 64)->nullable();
            $table->string('status', 24)->default('failed');
            $table->timestamp('failed_at');
            $table->timestamp('resolved_at')->nullable();
            $table->foreignId('resolved_by')->nullable()->constrained('users')->nullOnDelete();
            $table->text('resolution')->nullable();
            $table->timestamps();
            $table->index(['organization_id', 'branch_id', 'status', 'failed_at'], 'operational_failures_scope_status_idx');
        });

        Schema::table('external_webhooks', function (Blueprint $table): void {
            $table->timestamp('reviewed_at')->nullable();
            $table->foreignId('reviewed_by')->nullable()->constrained('users')->nullOnDelete();
            $table->text('resolution')->nullable();
            $table->index(['organization_id', 'branch_id', 'status', 'created_at'], 'external_webhooks_review_idx');
        });
    }

    public function down(): void
    {
        Schema::table('external_webhooks', function (Blueprint $table): void {
            $table->dropIndex('external_webhooks_review_idx');
            $table->dropConstrainedForeignId('reviewed_by');
            $table->dropColumn(['reviewed_at', 'resolution']);
        });
        Schema::dropIfExists('operational_failures');
    }
};
