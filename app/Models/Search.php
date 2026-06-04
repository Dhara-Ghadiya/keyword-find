<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Search extends Model
{
    protected $fillable = [
        'keyword',
        'status',
        'reddit_after',
        'last_synced_at',
        'is_fully_synced',
        'total_fetched',
    ];

    protected function casts(): array
    {
        return [
            'last_synced_at'  => 'datetime',
            'is_fully_synced' => 'boolean',
            'total_fetched'   => 'integer',
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
     * True when there are more Reddit posts available to fetch.
     * Returns true on first fetch (total_fetched = 0) or when an after token exists.
     */
    public function hasMoreResults(): bool
    {
        return ! $this->is_fully_synced
            && ($this->reddit_after !== null || $this->total_fetched === 0);
    }

    /**
     * Which batch we are currently on (completed batches).
     */
    public function getCurrentBatch(): int
    {
        return (int) ceil($this->total_fetched / 25);
    }

    /**
     * The batch number for the next fetch.
     */
    public function getNextBatch(): int
    {
        return $this->getCurrentBatch() + 1;
    }
}
