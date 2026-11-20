<?php

namespace App\Jobs;

use App\Integrations\Connectors\LibrarySync;
use App\Models\Connector;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Throwable;

/** «مزامنة الآن» and the scheduled sync: never inside a user's request (ADR-008). */
class SyncLibraryConnector implements ShouldQueue, ShouldBeUnique
{
    use Dispatchable, InteractsWithQueue, Queueable;

    public int $tries = 1;

    public int $timeout = 1800;

    public int $uniqueFor = 1800;

    public function __construct(public bool $full = false) {}

    public function handle(LibrarySync $sync): void
    {
        $c = Connector::active('library');
        if (! $c) {
            return;
        }
        try {
            $sync->run($c, $this->full);
        } catch (Throwable) {
            // already written on the connector (last_error) and in integration_logs
        }
    }
}
