<?php

namespace Tests\Feature\Infra;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\File;
use Tests\TestCase;

/**
 * B-11 (FR-REL-08): the Windows panel refuses to migrate when `backup:run --before-update` fails, so the command
 * must fail (exit 1, reason last) whenever an update is pending and no backup could be made.
 */
class BeforeUpdateBackupTest extends TestCase
{
    use RefreshDatabase;

    private string $tmp;

    protected function setUp(): void
    {
        parent::setUp();
        $this->tmp = sys_get_temp_dir().'/sis-b11-'.uniqid();
        File::ensureDirectoryExists($this->tmp.'/migrations');
        // the backups "folder" is a file, so no backup can be written there
        file_put_contents($this->tmp.'/not-a-folder', '');
        config(['backup.path' => $this->tmp.'/not-a-folder']);
    }

    protected function tearDown(): void
    {
        File::deleteDirectory($this->tmp);
        parent::tearDown();
    }

    public function test_no_pending_update_needs_no_backup(): void
    {
        $this->artisan('backup:run', ['--before-update' => true])->expectsOutputToContain('No update to apply')->assertExitCode(0);
    }

    public function test_a_pending_update_whose_backup_fails_fails_the_command(): void
    {
        file_put_contents($this->tmp.'/migrations/2099_01_01_000000_b11_pending.php', "<?php\nreturn new class extends Illuminate\\Database\\Migrations\\Migration { public function up(): void {} };\n");
        app('migrator')->path($this->tmp.'/migrations');

        $this->artisan('backup:run', ['--before-update' => true])->expectsOutputToContain('Backup failed')->assertExitCode(1);
    }
}
