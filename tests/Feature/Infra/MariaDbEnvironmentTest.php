<?php

namespace Tests\Feature\Infra;

use App\Models\AcademicYear;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\Group;
use Tests\TestCase;

/**
 * Checks that only mean something on the production database (B-05, FR-QA-01).
 * Skipped on SQLite; the MariaDB CI job runs them against every supported server version.
 */
#[Group('mariadb')]
class MariaDbEnvironmentTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        if (DB::connection()->getDriverName() !== 'mariadb') {
            $this->markTestSkipped('Runs on MariaDB only (DB_CONNECTION=mariadb).');
        }
    }

    public function test_server_is_the_expected_mariadb_version(): void
    {
        $version = (string) DB::scalar('select version()');
        $this->assertStringContainsStringIgnoringCase('mariadb', $version);

        // set by CI from the job matrix, so a wrong service image cannot pass silently
        $expected = (string) env('SIS_EXPECTED_DB_VERSION', '');
        if ($expected !== '') {
            $this->assertStringStartsWith($expected, $version);
        }
    }

    public function test_session_runs_in_strict_mode(): void
    {
        $mode = (string) DB::scalar('select @@SESSION.sql_mode');
        $this->assertStringContainsString('STRICT_TRANS_TABLES', $mode);

        // a value too long for its column must fail, not be cut silently
        $this->expectException(\Illuminate\Database\QueryException::class);
        AcademicYear::create(['name' => str_repeat('x', 500), 'starts_on' => '2025-09-01', 'ends_on' => '2026-08-31']);
    }

    public function test_tables_are_innodb_with_utf8mb4(): void
    {
        $rows = DB::select('select table_name as t, engine as e, table_collation as c from information_schema.tables where table_schema = database() and table_type = ?', ['BASE TABLE']);
        $this->assertNotEmpty($rows);
        foreach ($rows as $r) {
            $this->assertSame('InnoDB', $r->e, "{$r->t} must be InnoDB (transactions and row locks)");
            $this->assertStringStartsWith('utf8mb4', $r->c, "{$r->t} must store Arabic text as utf8mb4");
        }
    }

    public function test_arabic_text_round_trips(): void
    {
        $text = 'الفصل الدراسي الأول ٢٠٢٥–٢٠٢٦ ✓ 𝟙';   // includes a 4-byte character
        $year = AcademicYear::create(['name' => '2025-2026', 'starts_on' => '2025-09-01', 'ends_on' => '2026-08-31']);
        $id = DB::table('academic_terms')->insertGetId(['academic_year_id' => $year->id, 'code' => 'S1', 'name_ar' => $text]);
        $this->assertSame($text, DB::table('academic_terms')->where('id', $id)->value('name_ar'));
    }

    public function test_row_locks_are_available_inside_transactions(): void
    {
        $year = AcademicYear::create(['name' => '2025-2026', 'starts_on' => '2025-09-01', 'ends_on' => '2026-08-31']);
        $locked = DB::transaction(fn () => AcademicYear::whereKey($year->id)->lockForUpdate()->first());
        $this->assertSame($year->id, $locked->id);
        // the variable was renamed: 10.11 has only tx_isolation, 11.1+ has transaction_isolation (tx_isolation deprecated)
        $var = DB::selectOne("show variables where Variable_name in ('transaction_isolation', 'tx_isolation')");
        $this->assertSame('REPEATABLE-READ', $var->Value);
    }
}
