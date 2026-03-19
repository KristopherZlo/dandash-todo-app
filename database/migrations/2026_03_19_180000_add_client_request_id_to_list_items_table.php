<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('list_items', function (Blueprint $table): void {
            $table->string('client_request_id', 120)->nullable()->after('text');
        });
    }

    public function down(): void
    {
        Schema::table('list_items', function (Blueprint $table): void {
            $table->dropColumn('client_request_id');
        });
    }
};
