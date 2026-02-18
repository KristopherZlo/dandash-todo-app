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
        Schema::table('list_items', function (Blueprint $table) {
            $table->foreignId('list_link_id')
                ->nullable()
                ->after('owner_id')
                ->constrained('list_links')
                ->cascadeOnDelete();

            $table->index(
                ['list_link_id', 'type', 'is_completed', 'sort_order'],
                'list_items_link_type_completed_sort_index'
            );
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('list_items', function (Blueprint $table) {
            $table->dropIndex('list_items_link_type_completed_sort_index');
            $table->dropConstrainedForeignId('list_link_id');
        });
    }
};

