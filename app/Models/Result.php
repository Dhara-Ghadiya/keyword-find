<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Database\Eloquent\Model;

class Result extends Model
{
    protected $fillable = [
        'search_id',
        'source',
        'external_id',
        'permalink',
        'title',
        'url',
        'selftext',
    ];

    public function search(): BelongsTo
    {
        return $this->belongsTo(Search::class);
    }

    public function redditPost(): HasOne
    {
        return $this->hasOne(RedditPost::class);
    }
}
