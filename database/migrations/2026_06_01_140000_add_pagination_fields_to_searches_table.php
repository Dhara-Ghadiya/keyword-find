<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('searches', function (Blueprint $table) {
            $table->string('reddit_after')->nullable()->after('keyword');
            $table->timestamp('last_synced_at')->nullable()->after('reddit_after');
            $table->boolean('is_fully_synced')->default(false)->after('last_synced_at');
            $table->unsignedInteger('total_fetched')->default(0)->after('is_fully_synced');

            $table->index('keyword');
        });
    }

    public function down(): void
    {
        Schema::table('searches', function (Blueprint $table) {
            $table->dropIndex(['keyword']);
            $table->dropColumn(['reddit_after', 'last_synced_at', 'is_fully_synced', 'total_fetched']);
        });
    }
};
