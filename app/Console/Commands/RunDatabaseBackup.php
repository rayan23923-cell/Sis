<?php

namespace App\Console\Commands;

use App\Console\Concerns\UsesMaintenanceAccount;
use App\Services\DatabaseBackupService;
use Illuminate\Console\Command;
use Illuminate\Database\Migrations\Migrator;
use Illuminate\Support\Facades\Cache;

/**
 * Backups for the offline server (from FileNest). Scheduled hourly: backs up once
 * a day when `backup.daily` is on (the offline package turns it on).
 * `--before-update`: the control panel runs it before migrating on every start;
 * it backs up only when there are migrations to run (a new version).
 * `--force`: back up now (the panel's button).
 */
class RunDatabaseBackup extends Command
{
    use UsesMaintenanceAccount;

    protected $signature = 'backup:run {--force : Back up now} {--before-update : Back up only if there are pending migrations}';

    protected $description = 'Create a backup of the database and uploaded files (daily, before an update, or now).';

    /** G6a: on the maintenance account, the one that can see (and dump) the triggers. */
    public function handle(DatabaseBackupService $backups, Migrator $migrator): int
    {
        return $this->onMaintenance(fn () => $this->work($backups, $migrator));
    }

    private function work(DatabaseBackupService $backups, Migrator $migrator): int
    {
        if ($this->option('before-update')) {
            if (! $this->hasPendingMigrations($migrator)) {
                $this->info('No update to apply; skipping.');

                return self::SUCCESS;
            }

            return $this->backup($backups, 'before-update');
        }

        if ($this->option('force')) {
            return $this->backup($backups);
        }

        if (! config('backup.daily')) {
            $this->info('Daily backup is off; skipping.');

            return self::SUCCESS;
        }

        if (Cache::get('backup:last_date') === now()->toDateString()) {
            $this->info('A backup already ran today; skipping.');

            return self::SUCCESS;
        }

        return $this->backup($backups);
    }

    private function backup(DatabaseBackupService $backups, string $suffix = ''): int
    {
        try {
            $result = $backups->create($suffix);
        } catch (\Throwable $e) {
            // B-11: a failed backup is a failed command (the panel then refuses to migrate); the reason comes last,
            // where the panel shows it.
            report($e);
            $this->error('Backup failed: '.$e->getMessage());

            return self::FAILURE;
        }
        Cache::forever('backup:last_date', now()->toDateString());

        $this->info("Backup created: {$backups->dir()}".DIRECTORY_SEPARATOR.$result['filename']);

        return self::SUCCESS;
    }

    /** A fresh install (no migrations table yet) has nothing to lose. */
    private function hasPendingMigrations(Migrator $migrator): bool
    {
        if (! $migrator->repositoryExists()) {
            return false;
        }

        $files = $migrator->getMigrationFiles(array_merge([database_path('migrations')], $migrator->paths()));

        return array_diff(array_keys($files), $migrator->getRepository()->getRan()) !== [];
    }
}
