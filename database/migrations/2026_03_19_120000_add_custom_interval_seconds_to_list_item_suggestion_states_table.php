<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('list_item_suggestion_states', function (Blueprint $table): void {
            $table->unsignedInteger('custom_interval_seconds')
                ->nullable()
                ->after('reset_at');
        });
    }

    public function down(): void
    {
        Schema::table('list_item_suggestion_states', function (Blueprint $table): void {
            $table->dropColumn('custom_interval_seconds');
        });
    }
};
