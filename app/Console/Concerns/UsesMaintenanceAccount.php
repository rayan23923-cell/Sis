<?php

namespace App\Console\Concerns;

use Closure;
use Illuminate\Support\Facades\DB;

/**
 * G6a (B-13, FR-AUD-03): maintenance work runs on the maintenance account (DDL, triggers, grants), never on the
 * application's. The `maintenance` connection exists only when the process was given DB_MAINT_USERNAME (the
 * panel's maintenance commands and scheduler, staging's migrating entrypoint and scheduler); without it, as in
 * development and tests, the work runs on the default connection as before.
 */
trait UsesMaintenanceAccount
{
    protected function onMaintenance(Closure $work): mixed
    {
        if (! config('database.connections.maintenance')) {
            return $work();
        }
        $default = DB::getDefaultConnection();
        DB::setDefaultConnection('maintenance');
        try {
            return $work();
        } finally {
            DB::setDefaultConnection($default);
        }
    }
}
