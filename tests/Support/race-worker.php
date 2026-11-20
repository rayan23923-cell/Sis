<?php

/**
 * Test-only: one competitor in a concurrency test. Boots the app, loads what a request would have loaded,
 * waits for a shared start instant, then runs one service call and prints a JSON line.
 *
 * php tests/Support/race-worker.php <start-at> <action> <args...>
 * save: <offering> <user> <enrollment> <component> <mark>
 * withdraw: <offering> <enrollment> <user> (through OfferingAdminController, as the admin screen does)
 */

use App\Http\Controllers\OfferingAdminController;
use App\Models\AdministrativeOrder;
use App\Models\CourseOffering;
use App\Models\Enrollment;
use App\Models\FinalResult;
use App\Models\Student;
use App\Models\User;
use App\Services\EnrollmentService;
use App\Services\GradebookService;
use App\Services\WorkflowException;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

require __DIR__.'/../../vendor/autoload.php';
$app = require __DIR__.'/../../bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();

function asAdmin(User $user, array $input, Closure $call): mixed
{
    $request = Request::create('/race', 'POST', $input);
    $request->setUserResolver(fn () => $user);
    $request->setLaravelSession(app('session')->driver('array'));
    Auth::setUser($user);
    app()->instance('request', $request);

    return $call($request);
}

[, $startAt, $action] = $argv;
$args = array_map('intval', array_slice($argv, 3));

// what the request has in hand before the competing writes (route-model binding, the signed-in user)
$prepared = match ($action) {
    'enroll' => fn () => [Student::with('currentProgram')->findOrFail($args[0]), CourseOffering::findOrFail($args[1]), User::findOrFail($args[2])],
    'save' => fn () => [CourseOffering::findOrFail($args[0]), User::findOrFail($args[1])],
    'withdraw' => fn () => [CourseOffering::findOrFail($args[0]), Enrollment::findOrFail($args[1]), User::findOrFail($args[2])],
    'submit', 'approve', 'lock', 'return' => fn () => [CourseOffering::findOrFail($args[0]), User::findOrFail($args[1])],
    'correct' => fn () => [FinalResult::findOrFail($args[0]), AdministrativeOrder::findOrFail($args[1]), User::findOrFail($args[2]), $args[3], $args[4]],
};
$in = $prepared();

while (microtime(true) < (float) $startAt) {
    usleep(200);
}

try {
    $g = app(GradebookService::class);
    match ($action) {
        'enroll' => app(EnrollmentService::class)->enroll($in[0], $in[1], $in[2]),
        // the admin screen goes through the controller, as a request would (validation, authorisation, the service)
        'withdraw' => asAdmin($in[2], ['reason' => 'انسحاب'], fn ($r) => app(OfferingAdminController::class)->withdraw($r, $in[0], $in[1])),
        'save' => $g->saveMarks($in[0], $in[1], [$args[2] => [$args[3] => ['mark' => $args[4]]]]),
        'submit' => $g->submit($in[0], $in[1]),
        'approve' => $g->approve($in[0], $in[1]),
        'lock' => $g->lock($in[0], $in[1]),
        'return' => $g->returnToTeacher($in[0], $in[1], 'إعادة'),
        'correct' => $g->correct($in[0], [$in[3] => ['mark' => $in[4]]], $in[1], $in[2], 'تصحيح متزامن'),
    };
    echo json_encode(['ok' => true, 'pid' => getmypid()]), "\n";
} catch (WorkflowException $e) {
    echo json_encode(['ok' => false, 'refused' => true, 'error' => $e->getMessage()], JSON_UNESCAPED_UNICODE), "\n";
} catch (Throwable $e) {
    echo json_encode(['ok' => false, 'refused' => false, 'error' => get_class($e).': '.mb_substr($e->getMessage(), 0, 200)], JSON_UNESCAPED_UNICODE), "\n";
}
