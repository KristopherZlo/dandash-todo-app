<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::table('list_items', function (Blueprint $table) {
            $table->integer('sort_order')->default(1000)->after('text');
            $table->index('sort_order', 'list_items_sort_order_index');
            $table->index(
                ['owner_id', 'type', 'is_completed', 'sort_order'],
                'list_items_owner_type_completed_sort_index'
            );
        });

        $rows = DB::table('list_items')
            ->select('id', 'owner_id', 'type', 'is_completed')
            ->orderBy('owner_id')
            ->orderBy('type')
            ->orderBy('is_completed')
            ->orderByDesc('created_at')
            ->orderByDesc('id')
            ->get();

        $positionsByGroup = [];
        foreach ($rows as $row) {
            $groupKey = sprintf(
                '%d:%s:%d',
                (int) $row->owner_id,
                (string) $row->type,
                (int) $row->is_completed,
            );

            $positionsByGroup[$groupKey] = ($positionsByGroup[$groupKey] ?? 0) + 1000;

            DB::table('list_items')
                ->where('id', $row->id)
                ->update(['sort_order' => $positionsByGroup[$groupKey]]);
        }
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('list_items', function (Blueprint $table) {
            $table->dropIndex('list_items_owner_type_completed_sort_index');
            $table->dropIndex('list_items_sort_order_index');
            $table->dropColumn('sort_order');
        });
    }
};

