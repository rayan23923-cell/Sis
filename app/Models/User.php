<?php

namespace App\Models;

use App\Models\Concerns\Audited;
use Database\Factories\UserFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;

#[Fillable(['name', 'email', 'password', 'locale', 'is_active'])]
#[Hidden(['password', 'remember_token'])]
class User extends Authenticatable
{
    /** @use HasFactory<UserFactory> */
    use Audited, HasFactory, Notifiable;

    /** @var array<string, mixed>|null */
    private ?array $scopeCache = null;

    protected function casts(): array
    {
        return [
            'email_verified_at' => 'datetime',
            'password' => 'hashed',
            'is_active' => 'boolean',
        ];
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
