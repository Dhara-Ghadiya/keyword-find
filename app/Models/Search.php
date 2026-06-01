<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Model;

class Search extends Model
{
    protected $fillable = [
        'keyword',
        'status',
    ];

    public function results(): HasMany
    {
        return $this->hasMany(Result::class);
    }

    public function redditPosts(): HasMany
    {
        return $this->hasMany(RedditPost::class);
    }
}
