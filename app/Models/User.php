<?php

namespace App\Models;

use App\Models\Concerns\Audited;
use Database\Factories\UserFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Laravel\Sanctum\HasApiTokens;

#[Fillable(['name', 'email', 'password', 'locale', 'is_active', 'must_change_password'])]
#[Hidden(['password', 'remember_token', 'two_factor_secret', 'two_factor_recovery_codes'])]
class User extends Authenticatable
{
    /** @use HasFactory<UserFactory> */
    use Audited, HasApiTokens, HasFactory, Notifiable;

    /** @var array<string, mixed>|null */
    private ?array $scopeCache = null;

    protected function casts(): array
    {
        return [
            'email_verified_at' => 'datetime',
            'password' => 'hashed',
            'is_active' => 'boolean',
            'must_change_password' => 'boolean',
            'two_factor_secret' => 'encrypted',
            'two_factor_recovery_codes' => 'encrypted:array',
            'two_factor_confirmed_at' => 'datetime',
            'last_login_at' => 'datetime',
            'password_changed_at' => 'datetime',
        ];
    }

    public function hasTwoFactor(): bool
    {
        return $this->two_factor_secret !== null && $this->two_factor_confirmed_at !== null;
    }

    /** Accounts holding a role listed in config/security.php must sign in in two steps. */
    public function requiresTwoFactor(): bool
    {
        return array_intersect(config('security.two_factor_roles', []), $this->roles()) !== [];
    }

    public function roleAssignments(): HasMany
    {
        return $this->hasMany(RoleAssignment::class);
    }

    /** @return list<string> */
    public function roles(): array
    {
        return array_values(array_unique($this->assignments()->pluck('role')->all()));
    }

    public function hasRole(string ...$roles): bool
    {
        return array_intersect($roles, $this->roles()) !== [];
    }

    /** A teacher with no other role sees only the courses they teach. */
    /** The student record this account belongs to (student portal), if any. */
    public function student(): HasOne
    {
        return $this->hasOne(Student::class);
    }

    /** A portal-only account: linked to a student and holding no staff role. */
    public function isStudentOnly(): bool
    {
        return $this->roles() === [] && $this->student()->exists();
    }

    public function isOnlyTeacher(): bool
    {
        return $this->roles() === ['teacher'];
    }

    public function isUniversityWide(): bool
    {
        return $this->hasRole(...RoleAssignment::UNIVERSITY_WIDE);
    }

    /**
     * College ids this user may see; null means the whole university.
     * A department-scoped role contributes nothing here (see departmentScope()).
     *
     * @return list<int>|null
     */
    public function collegeScope(): ?array
    {
        if ($this->isUniversityWide()) {
            return null;
        }

        return $this->scope()['colleges'];
    }

    /** @return list<int>|null Department ids granted on their own (head, teacher in one department). */
    public function departmentScope(): ?array
    {
        if ($this->isUniversityWide()) {
            return null;
        }

        return $this->scope()['departments'];
    }

    public function canSeeCollege(int $collegeId): bool
    {
        $colleges = $this->collegeScope();

        return $colleges === null || in_array($collegeId, $colleges, true)
            || Department::whereIn('id', $this->departmentScope() ?? [])->where('college_id', $collegeId)->exists();
    }

    /** Roles held for a given college (including university-wide ones). */
    public function hasRoleIn(int $collegeId, string ...$roles): bool
    {
        return $this->assignments()->contains(fn (RoleAssignment $a) => in_array($a->role, $roles, true) && (
            in_array($a->role, RoleAssignment::UNIVERSITY_WIDE, true)
            || $a->college_id === $collegeId
            || ($a->department_id && $a->department?->college_id === $collegeId)
        ));
    }

    public function forgetRoles(): void
    {
        $this->scopeCache = null;
        $this->unsetRelation('roleAssignments');
    }

    private function assignments()
    {
        return $this->relationLoaded('roleAssignments')
            ? $this->roleAssignments
            : $this->roleAssignments()->with('department')->get()->tap(fn ($c) => $this->setRelation('roleAssignments', $c));
    }

    /** @return array{colleges: list<int>, departments: list<int>|null} */
    private function scope(): array
    {
        if ($this->scopeCache !== null) {
            return $this->scopeCache;
        }
        $colleges = [];
        $departments = [];
        foreach ($this->assignments() as $a) {
            if ($a->department_id) {
                $departments[] = (int) $a->department_id;
            } elseif ($a->college_id) {
                $colleges[] = (int) $a->college_id;
            }
        }

        return $this->scopeCache = [
            'colleges' => array_values(array_unique($colleges)),
            'departments' => $departments === [] ? null : array_values(array_unique($departments)),
        ];
    }
}
