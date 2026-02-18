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
        Schema::table('list_item_suggestion_states', function (Blueprint $table): void {
            $table->timestamp('reset_at')->nullable()->index()->after('retired_at');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('list_item_suggestion_states', function (Blueprint $table): void {
            $table->dropColumn('reset_at');
        });
    }
};
