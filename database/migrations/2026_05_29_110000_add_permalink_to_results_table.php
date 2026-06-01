<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('results', function (Blueprint $table) {
            // Stores the Reddit-internal path (e.g. /r/SEO/comments/abc123/slug/)
            // Nullable because non-Reddit sources (Google, suggestions) have no permalink.
            $table->string('permalink', 500)->nullable()->after('external_id');
        });
    }

    public function down(): void
    {
        Schema::table('results', function (Blueprint $table) {
            $table->dropColumn('permalink');
        });
    }
};
