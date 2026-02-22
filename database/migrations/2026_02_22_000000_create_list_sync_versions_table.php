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
        Schema::create('list_sync_versions', function (Blueprint $table): void {
            $table->id();
            $table->string('scope_key', 120)->unique();
            $table->foreignId('owner_id')->constrained('users')->cascadeOnDelete();
            $table->foreignId('list_link_id')->nullable()->constrained('list_links')->cascadeOnDelete();
            $table->string('type', 16)->index();
            $table->unsignedBigInteger('version')->default(0);
            $table->timestamps();

            $table->index(
                ['owner_id', 'type', 'list_link_id'],
                'list_sync_versions_owner_type_link_idx'
            );
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('list_sync_versions');
    }
};

