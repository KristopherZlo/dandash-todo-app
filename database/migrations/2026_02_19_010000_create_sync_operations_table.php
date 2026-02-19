<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('sync_operations', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('user_id')->constrained('users')->cascadeOnDelete();
            $table->string('op_id', 120);
            $table->string('action', 64);
            $table->string('status', 24)->default('processing');
            $table->json('result')->nullable();
            $table->timestamps();

            $table->unique(['user_id', 'op_id'], 'sync_operations_user_op_unique');
            $table->index(['user_id', 'created_at'], 'sync_operations_user_created_idx');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('sync_operations');
    }
};
