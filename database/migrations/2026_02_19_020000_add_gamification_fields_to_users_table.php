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
        Schema::table('users', function (Blueprint $table): void {
            $table->decimal('xp_progress', 8, 6)
                ->default(0)
                ->after('preferred_owner_id');
            $table->unsignedInteger('productivity_score')
                ->default(0)
                ->after('xp_progress');
            $table->json('productivity_reward_history')
                ->nullable()
                ->after('productivity_score');
            $table->unsignedBigInteger('xp_color_seed')
                ->default(1)
                ->after('productivity_reward_history');
            $table->timestamp('gamification_updated_at')
                ->nullable()
                ->after('xp_color_seed');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('users', function (Blueprint $table): void {
            $table->dropColumn([
                'xp_progress',
                'productivity_score',
                'productivity_reward_history',
                'xp_color_seed',
                'gamification_updated_at',
            ]);
        });
    }
};
