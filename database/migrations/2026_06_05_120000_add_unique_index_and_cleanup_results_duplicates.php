<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Prevents duplicate Reddit posts from being stored in the results table.
 *
 * Root cause: the job's DB::transaction(lockForUpdate) releases the row-lock
 * immediately after the SELECT. Two concurrent FetchKeywordResultsJob instances
 * both pass firstOrCreate's SELECT (seeing no existing row), then both INSERT
 * the same (search_id, external_id) row — producing duplicates.
 *
 * Fixes applied:
 *  1. DELETE existing duplicate rows (keep lowest id — the first successful insert).
 *  2. ADD UNIQUE INDEX on (search_id, external_id) so any future concurrent INSERT
 *     fails with a 1062 Duplicate entry error, which the job now catches and handles.
 */
return new class extends Migration
{
    public function up(): void
    {
        // ── Step 1: Remove duplicate rows ────────────────────────────────────
        // Keep the record with the lowest id (the original successful insert).
        // Delete all later duplicates that share the same (search_id, external_id).
        // NULL external_ids are excluded — they don't conflict in unique indexes.
        DB::statement("
            DELETE r1
            FROM results r1
            INNER JOIN results r2
                ON  r1.search_id   = r2.search_id
                AND r1.external_id = r2.external_id
                AND r1.id          > r2.id
            WHERE r1.external_id IS NOT NULL
        ");

        // ── Step 2: Add the unique constraint ─────────────────────────────────
        // external_id is varchar(255) + utf8mb4 = 255 × 4 = 1020 bytes, which
        // exceeds MySQL/MariaDB's default 1000-byte key limit. Reddit post IDs are
        // ~6–10 chars; Google cache IDs are at most ~50 chars. A 100-char prefix is
        // more than sufficient for uniqueness and fits easily within the byte limit.
        DB::statement(
            'ALTER TABLE results ADD UNIQUE INDEX results_search_external_unique (search_id, external_id(100))'
        );
    }

    public function down(): void
    {
        Schema::table('results', function (Blueprint $table) {
            $table->dropIndex('results_search_external_unique');
        });
    }
};
