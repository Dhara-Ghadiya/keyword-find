<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Search extends Model
{
    protected $fillable = [
        'keyword',
        'reddit_after',
        'total_fetched',
        'reddit_sync_status',
    ];

    protected function casts(): array
    {
        return [
            'total_fetched' => 'integer',
        ];
    }

    // -------------------------------------------------------------------------
    // Relationships
    // -------------------------------------------------------------------------

    public function results(): HasMany
    {
        return $this->hasMany(Result::class);
    }

    public function redditPosts(): HasMany
    {
        return $this->hasMany(RedditPost::class);
    }

    // -------------------------------------------------------------------------
    // Pagination helpers
    // -------------------------------------------------------------------------

    /**
     * Number of completed Reddit batches, derived from total posts fetched so far.
     */
    public function getCurrentBatch(): int
    {
        return (int) ceil(($this->total_fetched ?: 0) / 25);
    }

    /**
     * Batch number for the next Reddit fetch.
     */
    public function getNextBatch(): int
    {
        return $this->getCurrentBatch() + 1;
    }

    /**
     * True when Reddit pagination is fully exhausted.
     * After-null AND a terminal status together are the signal — no separate boolean column.
     */
    public function isFullyDone(): bool
    {
        return in_array($this->reddit_sync_status, ['completed', 'no_results'], true)
            && $this->reddit_after === null;
    }
}
