<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class RedditPost extends Model
{
    protected $fillable = [
        'search_id',
        'result_id',
        'reddit_post_id',
        'permalink',
        'title',
        'selftext',
        'url',
        'author',
        'ups',
        'downs',
        'total_awards_received',
        'created_utc',
    ];

    protected function casts(): array
    {
        return [
            'ups'                   => 'integer',
            'downs'                 => 'integer',
            'total_awards_received' => 'integer',
            'created_utc'           => 'integer',
        ];
    }

    // -------------------------------------------------------------------------
    // Relationships
    // -------------------------------------------------------------------------

    public function search(): BelongsTo
    {
        return $this->belongsTo(Search::class);
    }

    public function result(): BelongsTo
    {
        return $this->belongsTo(Result::class);
    }

    // -------------------------------------------------------------------------
    // Accessors
    // -------------------------------------------------------------------------

    public function getRedditUrlAttribute(): string
    {
        return 'https://www.reddit.com' . $this->permalink;
    }

    public function getPostedAtAttribute(): string
    {
        return date('M j, Y', (int) $this->created_utc);
    }
}
