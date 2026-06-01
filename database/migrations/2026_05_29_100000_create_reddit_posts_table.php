<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('reddit_posts', function (Blueprint $table) {
            $table->id();
            $table->string('permalink', 191)->unique();
            $table->string('title');
            $table->longText('selftext')->nullable();
            $table->string('url');
            $table->string('thumbnail')->nullable();
            $table->unsignedInteger('ups')->default(0);
            $table->unsignedInteger('downs')->default(0);
            $table->unsignedInteger('total_awards_received')->default(0);
            $table->unsignedBigInteger('created_utc');
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('reddit_posts');
    }
};
