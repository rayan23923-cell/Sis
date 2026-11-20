<?php

namespace Tests\Support;

use App\Models\Program;
use App\Services\PolicyResolver;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Carbon;

/** Test-only job: writes which grade_scale version the resolver gave this job, and in which process. */
class RecordPolicyVersionJob implements ShouldQueue
{
    use Queueable;

    public function __construct(public string $label, public int $programId, public string $on, public string $file) {}

    public function handle(PolicyResolver $resolver): void
    {
        $version = $resolver->forProgram(Program::findOrFail($this->programId), Carbon::parse($this->on))->get('grade_scale')->version;
        file_put_contents($this->file, json_encode(['job' => $this->label, 'pid' => getmypid(), 'version' => $version, 'resolver' => spl_object_id($resolver)])."\n", FILE_APPEND | LOCK_EX);
    }
}
