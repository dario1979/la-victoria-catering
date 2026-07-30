<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('integration_deliveries', function (Blueprint $table): void {
            $table->foreignId('organization_id')->nullable()->after('id')->constrained()->cascadeOnDelete();
            $table->foreignId('branch_id')->nullable()->after('organization_id')->constrained()->restrictOnDelete();
            $table->timestamp('available_at')->nullable();
            $table->timestamp('processed_at')->nullable();
            $table->timestamp('failed_at')->nullable();
            $table->timestamp('dead_lettered_at')->nullable();
            $table->string('correlation_id')->nullable();
            $table->index(['status', 'available_at']);
        });
        Schema::table('external_webhooks', function (Blueprint $table): void {
            $table->foreignId('organization_id')->nullable()->after('id')->constrained()->cascadeOnDelete();
            $table->foreignId('branch_id')->nullable()->after('organization_id')->constrained()->restrictOnDelete();
            $table->boolean('signature_valid')->nullable();
            $table->string('payload_hash', 64)->nullable();
        });

        Schema::create('payment_gateway_transactions', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('organization_id')->constrained()->cascadeOnDelete();
            $table->foreignId('branch_id')->constrained()->restrictOnDelete();
            $table->foreignId('payment_id')->constrained()->restrictOnDelete();
            $table->string('provider', 32);
            $table->string('internal_status', 32)->default('pending');
            $table->string('external_status', 64)->nullable();
            $table->string('external_id')->nullable();
            $table->bigInteger('amount_cents');
            $table->bigInteger('refunded_cents')->default(0);
            $table->string('idempotency_key');
            $table->timestamp('last_synced_at')->nullable();
            $table->text('last_error')->nullable();
            $table->timestamps();
            $table->unique(['provider', 'external_id']);
            $table->unique(['organization_id', 'idempotency_key']);
            $table->index(['organization_id', 'branch_id', 'internal_status']);
        });

        Schema::create('fiscal_documents', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('organization_id')->constrained()->cascadeOnDelete();
            $table->foreignId('branch_id')->constrained()->restrictOnDelete();
            $table->foreignId('order_id')->nullable()->constrained()->restrictOnDelete();
            $table->string('internal_status', 32)->default('pending');
            $table->string('document_type', 16);
            $table->unsignedInteger('point_of_sale');
            $table->string('currency', 3)->default('ARS');
            $table->bigInteger('net_cents');
            $table->bigInteger('tax_cents');
            $table->bigInteger('total_cents');
            $table->string('external_id')->nullable();
            $table->string('cae')->nullable();
            $table->date('cae_expires_on')->nullable();
            $table->json('safe_request');
            $table->json('safe_response')->nullable();
            $table->string('idempotency_key');
            $table->text('last_error')->nullable();
            $table->timestamps();
            $table->unique(['organization_id', 'idempotency_key']);
            $table->index(['organization_id', 'branch_id', 'internal_status']);
        });

        Schema::create('notification_preferences', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('channel', 24);
            $table->boolean('enabled')->default(true);
            $table->time('quiet_hours_start')->nullable();
            $table->time('quiet_hours_end')->nullable();
            $table->string('timezone', 64)->default('UTC');
            $table->timestamps();
            $table->unique(['user_id', 'channel']);
        });
        Schema::create('push_subscriptions', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('endpoint_hash', 64);
            $table->text('endpoint');
            $table->text('public_key');
            $table->text('auth_token');
            $table->boolean('active')->default(true);
            $table->timestamp('last_used_at')->nullable();
            $table->timestamps();
            $table->unique(['user_id', 'endpoint_hash']);
        });
        Schema::create('notification_deliveries', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('organization_id')->constrained()->cascadeOnDelete();
            $table->foreignId('branch_id')->nullable()->constrained()->restrictOnDelete();
            $table->foreignId('alert_id')->nullable()->constrained()->restrictOnDelete();
            $table->foreignId('user_id')->constrained()->restrictOnDelete();
            $table->string('channel', 24);
            $table->string('status', 24)->default('pending');
            $table->string('deduplication_key');
            $table->string('subject');
            $table->text('message');
            $table->string('action');
            $table->unsignedSmallInteger('attempts')->default(0);
            $table->timestamp('available_at');
            $table->timestamp('sent_at')->nullable();
            $table->timestamp('delivered_at')->nullable();
            $table->timestamp('failed_at')->nullable();
            $table->text('last_error')->nullable();
            $table->timestamps();
            $table->unique(['user_id', 'channel', 'deduplication_key']);
            $table->index(['status', 'available_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('notification_deliveries');
        Schema::dropIfExists('push_subscriptions');
        Schema::dropIfExists('notification_preferences');
        Schema::dropIfExists('fiscal_documents');
        Schema::dropIfExists('payment_gateway_transactions');
        Schema::table('external_webhooks', function (Blueprint $table): void {
            $table->dropConstrainedForeignId('branch_id');
            $table->dropConstrainedForeignId('organization_id');
            $table->dropColumn(['signature_valid', 'payload_hash']);
        });
        Schema::table('integration_deliveries', function (Blueprint $table): void {
            $table->dropConstrainedForeignId('branch_id');
            $table->dropConstrainedForeignId('organization_id');
            $table->dropColumn(['available_at', 'processed_at', 'failed_at', 'dead_lettered_at', 'correlation_id']);
        });
    }
};
