<?php

namespace App\Services;

use App\Models\User;
use App\Notifications\Notice;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Notification;

/** Who to tell about what. Failures here never stop the action that caused them. */
class Notifier
{
    /** @param  iterable<User>|User  $users */
    public function send(iterable|User $users, Notice $notice): void
    {
        $users = $users instanceof User ? collect([$users]) : collect($users);
        $users = $users->filter(fn ($u) => $u instanceof User && $u->is_active)->unique('id');
        if ($users->isEmpty()) {
            return;
        }
        try {
            Notification::send($users, $notice);
        } catch (\Throwable $e) {
            report($e);
        }
    }

    /** Staff holding one of $roles over the college (university-wide holders included). */
    public function staffOf(int $collegeId, string ...$roles): Collection
    {
        return User::where('is_active', true)->whereHas('roleAssignments', fn ($q) => $q->whereIn('role', $roles))->get()
            ->filter(fn (User $u) => $u->hasRoleIn($collegeId, ...$roles))->values();
    }
}
