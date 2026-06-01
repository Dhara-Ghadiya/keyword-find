<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('reddit_posts', function (Blueprint $table) {
            // Foreign keys — nullable so manually-entered posts (no search context) are supported
            $table->unsignedBigInteger('search_id')->nullable()->after('id');
            $table->unsignedBigInteger('result_id')->nullable()->after('search_id');

            // Reddit's own post ID (base-36, e.g. "1kmoqr9")
            $table->string('reddit_post_id', 20)->nullable()->after('result_id');

            // Post author username
            $table->string('author', 100)->nullable()->after('permalink');

            // Complete raw API response for forensic / re-processing use
            $table->json('raw_json')->nullable()->after('total_awards_received');

            // Constraints
            $table->foreign('search_id')
                ->references('id')->on('searches')
                ->nullOnDelete();

            $table->foreign('result_id')
                ->references('id')->on('results')
                ->nullOnDelete();

            // One reddit_post per result (nullable unique allows multiple NULLs)
            $table->unique('result_id');

            $table->index('reddit_post_id');
            $table->index('search_id');
        });
    }

    public function down(): void
    {
        Schema::table('reddit_posts', function (Blueprint $table) {
            $table->dropForeign(['search_id']);
            $table->dropForeign(['result_id']);
            $table->dropUnique(['result_id']);
            $table->dropIndex(['reddit_post_id']);
            $table->dropIndex(['search_id']);
            $table->dropColumn(['search_id', 'result_id', 'reddit_post_id', 'author', 'raw_json']);
        });
    }
};
