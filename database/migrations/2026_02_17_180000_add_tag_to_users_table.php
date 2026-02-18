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
        Schema::table('users', function (Blueprint $table) {
            $table->string('tag', 32)->nullable()->after('name');
        });

        DB::table('users')
            ->orderBy('id')
            ->select('id')
            ->each(function (object $user): void {
                DB::table('users')
                    ->where('id', $user->id)
                    ->update([
                        'tag' => 'user'.$user->id,
                    ]);
            });

        Schema::table('users', function (Blueprint $table) {
            $table->unique('tag');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropUnique('users_tag_unique');
            $table->dropColumn('tag');
        });
    }
};
