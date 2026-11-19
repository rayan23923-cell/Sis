<?php

namespace App\Integrations\Connectors;

use App\Models\Connector;
use App\Models\IntegrationLog;
use App\Models\LibraryStatus;
use App\Models\Student;
use Illuminate\Support\Str;
use Throwable;

/**
 * The library connector's work, run by the scheduler (sis:connectors-sync) and by «مزامنة الآن»:
 *  - members_sync: students go to FileNest as readers, card number = university number. A full push once a day,
 *    otherwise only the students changed since the last push. Students who left are sent as inactive.
 *  - clearance: FileNest answers, for every student, the books not returned and the fines not paid.
 */
class LibrarySync
{
    private const ACTIVE = ['active', 'hosted', 'postponed'];

    /** @return array{members: ?int, statuses: ?int} */
    public function run(Connector $c, bool $full = false): array
    {
        $client = LibraryClient::for($c);
        $out = ['members' => null, 'statuses' => null];
        $state = $c->state ?? [];

        try {
            if ($c->toolOn('members_sync')) {
                $since = $full || empty($state['members_full_at']) || \Illuminate\Support\Carbon::parse($state['members_full_at'])->diffInHours(now()) >= 24 ? null : ($state['members_cursor'] ?? null);
                $started = now()->toDateTimeString();
                $out['members'] = $this->pushMembers($client, $since);
                $state['members_cursor'] = $started;
                if ($since === null) {
                    $state['members_full_at'] = $started;
                }
                $this->log('members_sync', ['since' => $since], ['sent' => $out['members']]);
            }
            if ($c->toolOn('clearance')) {
                $out['statuses'] = $this->pullClearance($client);
                $this->log('clearance', [], ['students' => $out['statuses']]);
            }
        } catch (Throwable $e) {
            $c->forceFill(['state' => $state])->saveQuietly();
            $c->failed($e instanceof ConnectorException ? $e->getMessage() : 'خطأ غير متوقع أثناء المزامنة.');
            $this->log('sync', [], null, $e->getMessage());
            throw $e;
        }

        $c->forceFill(['state' => $state, 'last_sync_at' => now(), 'last_seen_at' => now(), 'last_error' => null, 'last_error_at' => null])->saveQuietly();

        return $out;
    }

    private function pushMembers(LibraryClient $client, ?string $since): int
    {
        $sent = 0;
        Student::query()
            ->when($since, fn ($q) => $q->where('updated_at', '>=', $since))
            ->with('currentProgram')
            ->select(['id', 'university_number', 'name_ar', 'phone', 'email', 'status'])
            ->chunkById(200, function ($students) use ($client, &$sent) {
                $client->syncMembers($students->map(fn (Student $s) => [
                    'card_number' => (string) $s->university_number,
                    'name' => $s->name_ar,
                    'stage' => $s->currentProgram?->stage ? (string) $s->currentProgram->stage : null,
                    'phone' => $s->phone,
                    'email' => $s->email,
                    'active' => in_array($s->status, self::ACTIVE, true),
                ])->values()->all());
                $sent += $students->count();
            });

        return $sent;
    }

    private function pullClearance(LibraryClient $client): int
    {
        $n = 0;
        $now = now();
        Student::query()->select(['id', 'university_number'])->chunkById(500, function ($students) use ($client, $now, &$n) {
            $answer = $client->clearance($students->pluck('university_number')->map(fn ($x) => (string) $x)->all());
            $byCard = $answer['members'] ?? [];
            $rows = $students->map(function (Student $s) use ($byCard, $now) {
                $m = $byCard[(string) $s->university_number] ?? null;

                return [
                    'student_id' => $s->id,
                    'is_member' => $m !== null,
                    'active_loans' => max(0, (int) ($m['active_loans'] ?? 0)),
                    'overdue_loans' => max(0, (int) ($m['overdue_loans'] ?? 0)),
                    'fines_due' => max(0, (int) ($m['fines_due'] ?? 0)),
                    'synced_at' => $now,
                ];
            })->all();
            LibraryStatus::upsert($rows, ['student_id'], ['is_member', 'active_loans', 'overdue_loans', 'fines_due', 'synced_at']);
            $n += count($rows);
        });

        return $n;
    }

    private function log(string $operation, array $payload, ?array $response, ?string $error = null): void
    {
        IntegrationLog::create([
            'system' => 'library', 'operation' => $operation, 'idempotency_key' => 'library:'.$operation.':'.Str::uuid(),
            'status' => $error ? 'failed' : 'done', 'attempts' => 1, 'payload' => $payload, 'response' => $response, 'error' => $error ? mb_substr($error, 0, 1000) : null,
        ]);
    }
}
