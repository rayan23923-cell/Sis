<?php

namespace App\Services;

use App\Models\Student;
use App\Models\User;
use App\Support\AuditContext;
use Illuminate\Support\Facades\DB;

/**
 * Student portal accounts. A student signs in with the university number and a temporary password
 * handed over on paper; the first sign-in forces a new password. Passwords are never stored in clear.
 */
class StudentAccountService
{
    private const ALPHABET = 'abcdefghjkmnpqrstuvwxyz23456789';

    /** Creates the account, or resets its password if it exists. Returns the temporary password. */
    public function issue(Student $student, User $actor): string
    {
        if (in_array($student->status, ['dismissed', 'withdrawn', 'transferred_out'], true)) {
            throw new WorkflowException('لا يُصدر حساب بوابة لطالب حالته «'.(Student::STATUSES[$student->status] ?? $student->status).'».');
        }
        $password = $this->temporaryPassword();

        DB::transaction(fn () => AuditContext::as($actor->id, 'حساب بوابة الطالب', function () use ($student, $password) {
            $user = $student->user_id ? User::find($student->user_id) : null;
            if ($user) {
                $user->update(['password' => $password, 'must_change_password' => true, 'is_active' => true]);

                return;
            }
            $user = User::create([
                'name' => $student->name_ar,
                'email' => $this->loginEmail($student),
                'password' => $password,
                'must_change_password' => true,
                'is_active' => true,
                'locale' => 'ar',
            ]);
            $student->update(['user_id' => $user->id]);
        }));

        return $password;
    }

    /** Internal sign-in address; the student types the university number instead. */
    public function loginEmail(Student $student): string
    {
        return 's'.$student->university_number.'@students.sis';
    }

    private function temporaryPassword(): string
    {
        $out = '';
        for ($i = 0; $i < 10; $i++) {
            $out .= self::ALPHABET[random_int(0, strlen(self::ALPHABET) - 1)];
        }

        return $out;
    }
}
