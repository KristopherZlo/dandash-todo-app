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
            $table->string('mood_color', 16)
                ->default('yellow')
                ->after('gamification_updated_at');
            $table->unsignedTinyInteger('mood_fire_level')
                ->default(50)
                ->after('mood_color');
            $table->string('mood_fire_emoji', 16)
                ->nullable()
                ->after('mood_fire_level');
            $table->unsignedTinyInteger('mood_battery_level')
                ->default(50)
                ->after('mood_fire_emoji');
            $table->string('mood_battery_emoji', 16)
                ->nullable()
                ->after('mood_battery_level');
            $table->timestamp('mood_updated_at')
                ->nullable()
                ->after('mood_battery_emoji');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('users', function (Blueprint $table): void {
            $table->dropColumn([
                'mood_color',
                'mood_fire_level',
                'mood_fire_emoji',
                'mood_battery_level',
                'mood_battery_emoji',
                'mood_updated_at',
            ]);
        });
    }
};
