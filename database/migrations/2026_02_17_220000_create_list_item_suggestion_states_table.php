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
        Schema::create('list_item_suggestion_states', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('owner_id')->constrained('users')->cascadeOnDelete();
            $table->string('type', 16);
            $table->string('suggestion_key', 190);
            $table->unsignedTinyInteger('dismissed_count')->default(0);
            $table->timestamp('hidden_until')->nullable()->index();
            $table->timestamp('retired_at')->nullable()->index();
            $table->timestamps();

            $table->unique(['owner_id', 'type', 'suggestion_key'], 'lis_owner_type_key_unique');
            $table->index(['owner_id', 'type']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('list_item_suggestion_states');
    }
};

