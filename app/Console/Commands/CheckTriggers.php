<?php

namespace App\Console\Commands;

use App\Console\Concerns\UsesMaintenanceAccount;
use App\Models\FinalResult;
use App\Models\User;
use App\Notifications\Notice;
use App\Services\Notifier;
use App\Support\ProtectedTriggers;
use Illuminate\Console\Command;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * B-10 (FR-SEC-19), daily: the triggers that protect locked results are there and still refuse. A trigger that is
 * present is also tried, inside a transaction that is always rolled back, so nothing changes either way.
 *
 * It only reports (D-G5-2): putting a trigger back on its own would hide that someone removed it. The repair is
 * a command run on purpose (docs/TESTING_DATABASES.md, "B-10").
 */
class CheckTriggers extends Command
{
    use UsesMaintenanceAccount;

    protected $signature = 'sis:check-triggers';

    protected $description = 'Check that the triggers protecting locked results exist and refuse changes (daily).';

    /** G6a: on the maintenance account, the one that can see (and dump) the triggers. */
    public function handle(Notifier $notifier): int
    {
        return $this->onMaintenance(fn () => $this->work($notifier));
    }

    private function work(Notifier $notifier): int
    {
        $problems = [];
        foreach (ProtectedTriggers::missing() as $name) {
            $problems[] = "{$name}: missing";
        }
        foreach (array_intersect(array_keys(ProtectedTriggers::EXPECTED), ProtectedTriggers::present()) as $name) {
            $verdict = $this->tryIt($name);
            if ($verdict !== null) {
                $problems[] = "{$name}: {$verdict}";
            }
        }

        if ($problems === []) {
            $this->info('The triggers protecting locked results are in place: '.implode(', ', array_keys(ProtectedTriggers::EXPECTED)).'.');

            return self::SUCCESS;
        }

        Log::critical('The triggers protecting locked results are not in place.', ['problems' => $problems]);
        $admins = User::where('is_active', true)->whereHas('roleAssignments', fn ($q) => $q->where('role', 'super_admin'))->get();
        $notifier->send($admins, new Notice(
            'تنبيه: حماية النتائج المقفلة لا تعمل',
            'الفحص اليومي وجد خللاً في حماية قاعدة البيانات للنتائج المقفلة ('.implode('؛ ', $problems).'). راجع مسؤول النظام.',
            null, 'danger'));
        foreach ($problems as $problem) {
            $this->error($problem);
        }

        return self::FAILURE;
    }

    /** null when the trigger refused as it must (or there is no row to try it on), else what went wrong. */
    private function tryIt(string $name): ?string
    {
        $row = FinalResult::where('status', 'locked')->orderBy('id')->value('id');
        if ($row === null) {
            return null; // nothing locked yet: presence is all that can be checked
        }
        [, $words] = ProtectedTriggers::EXPECTED[$name];
        $query = DB::table('final_results')->where('id', $row);

        DB::beginTransaction();
        try {
            str_ends_with($name, '_delete') ? $query->delete() : $query->update(['grade_code' => DB::raw('grade_code')]);

            return 'present but did not refuse';
        } catch (QueryException $e) {
            return str_contains($e->getMessage(), $words) ? null : 'refused with an unexpected error: '.mb_substr($e->getMessage(), 0, 200);
        } finally {
            DB::rollBack();
        }
    }
}
