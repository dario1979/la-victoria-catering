<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('pilot_scenarios', function (Blueprint $table): void {
            $table->id();
            $table->string('identifier')->unique();
            $table->foreignId('organization_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('branch_id')->nullable()->constrained()->nullOnDelete();
            $table->string('status', 24)->default('seeded');
            $table->json('participants');
            $table->json('fixtures');
            $table->json('result')->nullable();
            $table->json('report_paths')->nullable();
            $table->timestamp('rehearsed_at')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('pilot_scenarios');
    }
};
