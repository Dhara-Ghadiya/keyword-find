<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('searches', function (Blueprint $table) {
            $columns = [
                'is_fully_synced',
                'last_synced_at',
                'arctic_shift_status',
                'arctic_shift_subreddits',
                'arctic_shift_subreddit_index',
                'arctic_shift_after_timestamp',
                'arctic_shift_cursor',
                'arctic_shift_completed_at',
                'arctic_shift_is_fully_synced',
                'arctic_shift_page_count',
                'reddit_posts_count',
                'historical_posts_count',
            ];

            foreach ($columns as $col) {
                if (Schema::hasColumn('searches', $col)) {
                    $table->dropColumn($col);
                }
            }
        });
    }

    public function down(): void
    {
        Schema::table('searches', function (Blueprint $table) {
            $table->boolean('is_fully_synced')->default(false);
            $table->timestamp('last_synced_at')->nullable();
            $table->string('arctic_shift_status', 20)->nullable();
            $table->text('arctic_shift_subreddits')->nullable();
            $table->tinyInteger('arctic_shift_subreddit_index')->default(0);
            $table->unsignedBigInteger('arctic_shift_after_timestamp')->nullable();
            $table->unsignedBigInteger('arctic_shift_cursor')->nullable();
            $table->timestamp('arctic_shift_completed_at')->nullable();
            $table->boolean('arctic_shift_is_fully_synced')->default(false);
            $table->smallInteger('arctic_shift_page_count')->default(0);
            $table->unsignedInteger('reddit_posts_count')->default(0);
            $table->unsignedInteger('historical_posts_count')->default(0);
        });
    }
};
