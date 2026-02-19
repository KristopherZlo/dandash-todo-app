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
        Schema::create('list_item_events', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('owner_id')->constrained('users')->cascadeOnDelete();
            $table->foreignId('list_link_id')->nullable()->constrained('list_links')->nullOnDelete();
            $table->string('type', 16);
            $table->string('event_type', 24);
            $table->string('text', 255);
            $table->string('normalized_text', 190);
            $table->timestamp('occurred_at')->index();
            $table->foreignId('source_item_id')->nullable()->constrained('list_items')->nullOnDelete();
            $table->json('meta')->nullable();
            $table->timestamps();

            $table->index(['owner_id', 'type', 'event_type', 'occurred_at'], 'list_item_events_owner_type_event_time');
            $table->index(['owner_id', 'list_link_id', 'type', 'event_type'], 'list_item_events_owner_link_type_event');
            $table->index(['owner_id', 'type', 'normalized_text', 'event_type'], 'list_item_events_owner_type_key_event');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('list_item_events');
    }
};

