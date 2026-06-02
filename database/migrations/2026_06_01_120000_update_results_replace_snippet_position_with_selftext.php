<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('results', function (Blueprint $table) {
            $table->dropColumn(['snippet', 'position']);
            $table->text('selftext')->nullable()->after('url');
        });
    }

    public function down(): void
    {
        Schema::table('results', function (Blueprint $table) {
            $table->dropColumn('selftext');
            $table->text('snippet')->nullable()->after('url');
            $table->unsignedInteger('position')->nullable()->after('snippet');
        });
    }
};
