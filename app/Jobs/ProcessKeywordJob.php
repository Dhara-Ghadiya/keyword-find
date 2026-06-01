<?php

namespace App\Jobs;

use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Log;

class ProcessKeywordJob implements ShouldQueue
{
    use Queueable;

    public function __construct(
        public string $keyword
    ) {}

    public function handle(): void
    {
        Log::info('Processing keyword: ' . $this->keyword);
    }
}