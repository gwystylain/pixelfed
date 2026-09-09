<?php

namespace App\Geo\Jobs;

use App\Geo\Services\StatusGeoService;
use App\Models\Status;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

/**
 * Fork feature: geo feed. See docs/fork/GEO_FEED.md
 *
 * Assigns a post its map position, and the nearest city if the author did
 * not choose one.
 *
 * Unique per status for a short window: an album attaches media one row at a
 * time and each attachment would otherwise queue its own copy of this.
 */
class ResolveStatusGeoJob implements ShouldBeUnique, ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public $tries = 2;

    public $timeout = 60;

    public $uniqueFor = 30;

    public function __construct(public int $statusId, public bool $force = false) {}

    /**
     * The force flag is part of the key on purpose. A re-derive queued
     * because the author moved the post must never be swallowed as a
     * duplicate of the routine pass still pending from publishing it.
     */
    public function uniqueId(): string
    {
        return 'geo:status:'.$this->statusId.($this->force ? ':force' : '');
    }

    public function handle(StatusGeoService $statusGeo): void
    {
        $status = Status::find($this->statusId);

        if (! $status) {
            return;
        }

        $statusGeo->resolve($status, $this->force);
    }
}
